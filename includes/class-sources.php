<?php
/**
 * Source records: what content the chatbot knows and how its last indexing went.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Sources.
 */
final class Sources {

	/**
	 * One source.
	 *
	 * @param string $type Source type.
	 * @param string $id   Source identifier.
	 * @return array|null
	 */
	public static function get( $type, $id ) {
		global $wpdb;

		$table = Install::table( 'sources' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE chatbot_id = %d AND source_type = %s AND source_identifier = %s", Install::CHATBOT_ID, $type, (string) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}

	/**
	 * Sources with a given identifier, whatever their type (a post id is unique, its type can change).
	 *
	 * @param string $id Source identifier.
	 * @return array[]
	 */
	public static function for_identifier( $id ) {
		global $wpdb;

		$table = Install::table( 'sources' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE chatbot_id = %d AND source_identifier = %s", Install::CHATBOT_ID, (string) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Every source.
	 *
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;

		$table = Install::table( 'sources' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE chatbot_id = %d", Install::CHATBOT_ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Record a source as indexed. Returns its row id.
	 *
	 * @param array  $doc             Normalized document.
	 * @param string $embedding_model Model the chunks were embedded with, or an empty string.
	 * @param string $warning         A non-fatal problem, such as embeddings that failed.
	 * @return int
	 */
	public static function upsert_indexed( array $doc, $embedding_model, $warning = '' ) {
		global $wpdb;

		$table = Install::table( 'sources' );
		$now   = current_time( 'mysql', true );

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT INTO {$table} (chatbot_id, source_type, source_identifier, post_type, title, canonical_url, content_hash, source_version, source_status, language, embedding_model, last_seen_at, last_indexed_at, last_error, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s, %s, 'indexed', %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE post_type = VALUES(post_type), title = VALUES(title), canonical_url = VALUES(canonical_url), content_hash = VALUES(content_hash),
					source_version = VALUES(source_version), source_status = 'indexed', language = VALUES(language), embedding_model = VALUES(embedding_model),
					last_seen_at = VALUES(last_seen_at), last_indexed_at = VALUES(last_indexed_at), last_error = VALUES(last_error), updated_at = VALUES(updated_at)",
				Install::CHATBOT_ID,
				$doc['source_type'],
				$doc['source_id'],
				$doc['post_type'],
				$doc['title'],
				$doc['url'],
				$doc['content_hash'],
				isset( $doc['metadata']['modified'] ) ? substr( (string) $doc['metadata']['modified'], 0, 32 ) : '',
				$doc['language'],
				$embedding_model,
				$now,
				$now,
				'' === $warning ? null : substr( $warning, 0, 500 ),
				$now,
				$now
			)
		);

		$source = self::get( $doc['source_type'], $doc['source_id'] );

		return $source ? (int) $source['id'] : 0;
	}

	/**
	 * Record a failed attempt. The hash is cleared so the next run tries again.
	 *
	 * @param array  $doc     Normalized document.
	 * @param string $message Error message.
	 * @return void
	 */
	public static function mark_error( array $doc, $message ) {
		global $wpdb;

		$table = Install::table( 'sources' );
		$now   = current_time( 'mysql', true );

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT INTO {$table} (chatbot_id, source_type, source_identifier, post_type, title, canonical_url, content_hash, source_status, language, last_seen_at, last_error, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s, '', 'error', %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE title = VALUES(title), canonical_url = VALUES(canonical_url), content_hash = '', source_status = 'error',
					last_seen_at = VALUES(last_seen_at), last_error = VALUES(last_error), updated_at = VALUES(updated_at)",
				Install::CHATBOT_ID,
				$doc['source_type'],
				$doc['source_id'],
				$doc['post_type'],
				$doc['title'],
				$doc['url'],
				$doc['language'],
				$now,
				substr( (string) $message, 0, 500 ),
				$now,
				$now
			)
		);
	}

	/**
	 * Note that a source was checked and found unchanged.
	 *
	 * @param int $source_row_id Source row id.
	 * @return void
	 */
	public static function touch( $source_row_id ) {
		global $wpdb;

		$wpdb->update(
			Install::table( 'sources' ),
			array( 'last_seen_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $source_row_id )
		);
	}

	/**
	 * Forget the hash of every source so the next sync processes all of them again.
	 *
	 * @return void
	 */
	public static function forget_hashes() {
		global $wpdb;

		$table = Install::table( 'sources' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET content_hash = '' WHERE chatbot_id = %d", Install::CHATBOT_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Per source type: how many are indexed, failed or stale, and when the last one was indexed.
	 *
	 * "Stale" means indexed, but embedded with a different model than the one in use now.
	 *
	 * @param string $current_model Embedding model in use, or an empty string when embeddings are off.
	 * @return array<string,array{documents: int, errors: int, stale: int, last_sync: string}>
	 */
	public static function summary( $current_model = '' ) {
		global $wpdb;

		$table = Install::table( 'sources' );
		$stale = '' === $current_model ? '0' : $wpdb->prepare( "(source_status = 'indexed' AND embedding_model <> %s)", $current_model );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT source_type, SUM(source_status = 'indexed') AS docs, SUM(source_status = 'error') AS errors, SUM({$stale}) AS stale, MAX(last_indexed_at) AS last_sync FROM {$table} WHERE chatbot_id = %d GROUP BY source_type", Install::CHATBOT_ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$out   = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['source_type'] ] = array(
				'documents' => (int) $row['docs'],
				'errors'    => (int) $row['errors'],
				'stale'     => (int) $row['stale'],
				'last_sync' => (string) $row['last_sync'],
			);
		}

		return $out;
	}

	/**
	 * Sources whose type is not one of the given types.
	 *
	 * @param string[] $types Types to keep.
	 * @return array[]
	 */
	public static function outside_types( array $types ) {
		$out = array();

		foreach ( self::all() as $row ) {
			if ( ! in_array( $row['source_type'], $types, true ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * The most recent failures, for the status screen.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function recent_errors( $limit = 5 ) {
		global $wpdb;

		$table = Install::table( 'sources' );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT title, canonical_url, last_error, updated_at FROM {$table} WHERE chatbot_id = %d AND last_error IS NOT NULL AND last_error <> '' ORDER BY updated_at DESC LIMIT %d", Install::CHATBOT_ID, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
