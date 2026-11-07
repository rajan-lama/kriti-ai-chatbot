<?php
/**
 * Indexing jobs: a database queue with retries and exponential backoff.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs. A job is safe to run twice: indexing is keyed by source and content hash.
 */
final class Jobs {

	const MAX_ATTEMPTS   = 5;
	const BASE_BACKOFF   = 60;
	const STALE_RUNNING  = 300;
	const KEEP_DONE_DAYS = 7;

	/**
	 * Queue a job. A pending job for the same source is reused, so repeated events do not pile up.
	 *
	 * @param string $job_type    index|delete.
	 * @param string $identifier  Source identifier (post id).
	 * @param string $source_type Source type, when known.
	 * @param int    $delay       Seconds to wait before it may run.
	 * @return int Job id.
	 */
	public static function enqueue( $job_type, $identifier, $source_type = '', $delay = 0 ) {
		global $wpdb;

		$table = Install::table( 'index_jobs' );
		$now   = current_time( 'mysql', true );
		$when  = gmdate( 'Y-m-d H:i:s', time() + max( 0, (int) $delay ) );

		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE chatbot_id = %d AND job_type = %s AND source_identifier = %s AND status = 'pending' ORDER BY id LIMIT 1", Install::CHATBOT_ID, $job_type, (string) $identifier ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET scheduled_at = LEAST(scheduled_at, %s), updated_at = %s WHERE id = %d", $when, $now, $existing ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return $existing;
		}

		$wpdb->insert(
			$table,
			array(
				'chatbot_id'        => Install::CHATBOT_ID,
				'job_type'          => $job_type,
				'source_type'       => $source_type,
				'source_identifier' => (string) $identifier,
				'status'            => 'pending',
				'attempts'          => 0,
				'scheduled_at'      => $when,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Take the next due jobs. A job is claimed with a conditional update, so two workers never run the same one.
	 *
	 * @param int $limit How many.
	 * @return array[] Claimed jobs.
	 */
	public static function claim( $limit ) {
		global $wpdb;

		$table = Install::table( 'index_jobs' );
		$now   = current_time( 'mysql', true );

		// A worker that died leaves its job "running"; give it back.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', updated_at = %s WHERE status = 'running' AND started_at < %s", $now, gmdate( 'Y-m-d H:i:s', time() - self::STALE_RUNNING ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$due = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE chatbot_id = %d AND status = 'pending' AND scheduled_at <= %s ORDER BY scheduled_at, id LIMIT %d", Install::CHATBOT_ID, $now, max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array();

		foreach ( (array) $due as $job ) {
			$won = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', attempts = attempts + 1, started_at = %s, updated_at = %s WHERE id = %d AND status = 'pending'", $now, $now, $job['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( 1 === (int) $won ) {
				$job['attempts'] = (int) $job['attempts'] + 1;
				$out[]           = $job;
			}
		}

		return $out;
	}

	/**
	 * A job finished.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function complete( $job_id ) {
		global $wpdb;

		$wpdb->update(
			Install::table( 'index_jobs' ),
			array(
				'status'        => 'done',
				'completed_at'  => current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
				'error_code'    => '',
				'error_message' => null,
			),
			array( 'id' => (int) $job_id )
		);
	}

	/**
	 * A job failed. A transient failure is retried after 1, 2, 4 and 8 minutes; then it stays failed
	 * until an administrator retries it.
	 *
	 * @param array  $job     The job (with attempts).
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param bool   $retry   Whether trying again can help.
	 * @return void
	 */
	public static function fail( array $job, $code, $message, $retry = true ) {
		global $wpdb;

		$attempts = (int) $job['attempts'];
		$again    = $retry && $attempts < self::MAX_ATTEMPTS;
		$delay    = self::BASE_BACKOFF * ( 2 ** max( 0, $attempts - 1 ) );

		$wpdb->update(
			Install::table( 'index_jobs' ),
			array(
				'status'        => $again ? 'pending' : 'failed',
				'scheduled_at'  => $again ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : $job['scheduled_at'],
				'completed_at'  => $again ? null : current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
				'error_code'    => substr( (string) $code, 0, 60 ),
				'error_message' => substr( (string) $message, 0, 500 ),
			),
			array( 'id' => (int) $job['id'] )
		);
	}

	/**
	 * Hand a claimed job back without counting the attempt (used when a run ran out of time).
	 *
	 * @param array $job The claimed job.
	 * @return void
	 */
	public static function release( array $job ) {
		global $wpdb;

		$wpdb->update(
			Install::table( 'index_jobs' ),
			array(
				'status'     => 'pending',
				'attempts'   => max( 0, (int) $job['attempts'] - 1 ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $job['id'] )
		);
	}

	/**
	 * Counts by status.
	 *
	 * @return array{pending: int, running: int, failed: int, done: int}
	 */
	public static function counts() {
		global $wpdb;

		$table = Install::table( 'index_jobs' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE chatbot_id = %d GROUP BY status", Install::CHATBOT_ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array(
			'pending' => 0,
			'running' => 0,
			'failed'  => 0,
			'done'    => 0,
		);

		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Jobs that failed for good, newest first.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function recent_failures( $limit = 10 ) {
		global $wpdb;

		$table = Install::table( 'index_jobs' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT job_type, source_identifier, attempts, error_code, error_message, updated_at FROM {$table} WHERE chatbot_id = %d AND status = 'failed' ORDER BY updated_at DESC LIMIT %d", Install::CHATBOT_ID, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Put failed jobs back in the queue.
	 *
	 * @return int Jobs retried.
	 */
	public static function retry_failed() {
		global $wpdb;

		$table = Install::table( 'index_jobs' );
		$now   = current_time( 'mysql', true );

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', attempts = 0, scheduled_at = %s, updated_at = %s WHERE chatbot_id = %d AND status = 'failed'", $now, $now, Install::CHATBOT_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete finished jobs after a week.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$table = Install::table( 'index_jobs' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'done' AND completed_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DONE_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove every job.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = Install::table( 'index_jobs' );
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
