<?php
/**
 * Dashboard numbers.
 *
 * Each figure has one definition, written next to it, so a number on the dashboard can be explained.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics.
 */
final class Analytics {

	/**
	 * The shape every caller can rely on.
	 */
	const EMPTY_SUMMARY = array(
		'total_conversations'  => 0,
		'total_questions'      => 0,
		'questions_today'      => 0,
		'questions_this_month' => 0,
		'avg_response_ms'      => 0,
		'answered_percent'     => 0.0,
		'unanswered'           => 0,
		'helpful'              => 0,
		'not_helpful'          => 0,
		'helpful_percent'      => 0.0,
		'token_input'          => 0,
		'token_output'         => 0,
		'sources'              => 0,
		'chunks'               => 0,
		'embedded_chunks'      => 0,
		'jobs'                 => array(),
		'last_sync'            => '',
		'top_questions'        => array(),
		'top_sources'          => array(),
		'unanswered_list'      => array(),
	);

	/**
	 * Summary of everything.
	 *
	 * - questions: assistant replies, one per visitor question.
	 * - answered %: the share of questions where enough evidence was found to answer. This is
	 *   "coverage", not the model's confidence in what it wrote.
	 * - helpful %: of the answers that were rated, how many were rated helpful.
	 * - tokens: reported by the AI providers, summed from the usage log (answers and embeddings).
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;

		$msgs   = Install::table( 'messages' );
		$convs  = Install::table( 'conversations' );
		$events = Install::table( 'learning_events' );
		$usage  = Install::table( 'usage_logs' );
		$srcs   = Install::table( 'sources' );
		$out    = self::EMPTY_SUMMARY;
		$today  = gmdate( 'Y-m-d 00:00:00', time() );
		$month  = gmdate( 'Y-m-01 00:00:00', time() );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['total_conversations'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$convs}" );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, SUM(created_at >= %s) AS today, SUM(created_at >= %s) AS month, AVG(latency_ms) AS latency,
					SUM(answered = 0) AS unanswered, SUM(feedback = 'helpful') AS up, SUM(feedback = 'not_helpful') AS down
				FROM {$msgs} WHERE role = 'assistant'",
				$today,
				$month
			),
			ARRAY_A
		);

		if ( $row && (int) $row['total'] > 0 ) {
			$out['total_questions']      = (int) $row['total'];
			$out['questions_today']      = (int) $row['today'];
			$out['questions_this_month'] = (int) $row['month'];
			$out['avg_response_ms']      = (int) round( (float) $row['latency'] );
			$out['unanswered']           = (int) $row['unanswered'];
			$out['answered_percent']     = round( 100 * ( $out['total_questions'] - $out['unanswered'] ) / $out['total_questions'], 1 );
			$out['helpful']              = (int) $row['up'];
			$out['not_helpful']          = (int) $row['down'];

			$rated = $out['helpful'] + $out['not_helpful'];

			$out['helpful_percent'] = $rated > 0 ? round( 100 * $out['helpful'] / $rated, 1 ) : 0.0;
		}

		$tokens = $wpdb->get_row( "SELECT SUM(input_units) AS tin, SUM(output_units) AS tout FROM {$usage} WHERE status = 'ok'", ARRAY_A );

		if ( $tokens ) {
			$out['token_input']  = (int) $tokens['tin'];
			$out['token_output'] = (int) $tokens['tout'];
		}

		$top = $wpdb->get_results( "SELECT question, COUNT(*) AS n FROM {$msgs} WHERE role = 'assistant' AND question IS NOT NULL AND question <> '' GROUP BY question ORDER BY n DESC, MAX(id) DESC LIMIT 10", ARRAY_A );

		foreach ( (array) $top as $item ) {
			$out['top_questions'][] = array(
				'question' => $item['question'],
				'count'    => (int) $item['n'],
			);
		}

		$events_rows = $wpdb->get_results( "SELECT event_payload_json, created_at FROM {$events} WHERE event_type = 'unanswered' AND review_status = 'new' ORDER BY id DESC LIMIT 500", ARRAY_A );
		$grouped     = array();

		foreach ( (array) $events_rows as $event ) {
			$payload = json_decode( (string) $event['event_payload_json'], true );
			$text    = is_array( $payload ) && ! empty( $payload['question'] ) ? (string) $payload['question'] : '';

			if ( '' === $text ) {
				continue;
			}

			if ( ! isset( $grouped[ $text ] ) ) {
				$grouped[ $text ] = array(
					'question'   => $text,
					'count'      => 0,
					'last_asked' => $event['created_at'],
					'sum'        => 0.0,
				);
			}

			++$grouped[ $text ]['count'];
			$grouped[ $text ]['sum'] += isset( $payload['confidence'] ) ? (float) $payload['confidence'] : 0.0;
		}

		usort(
			$grouped,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		foreach ( array_slice( $grouped, 0, 20 ) as $item ) {
			$out['unanswered_list'][] = array(
				'question'   => $item['question'],
				'count'      => $item['count'],
				'last_asked' => $item['last_asked'],
				'confidence' => round( $item['sum'] / $item['count'], 2 ),
			);
		}

		$cites = $wpdb->get_col( "SELECT citations_json FROM {$msgs} WHERE role = 'assistant' AND answered = 1 AND citations_json IS NOT NULL ORDER BY id DESC LIMIT 1000" );

		$out['sources'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$srcs} WHERE chatbot_id = %d AND source_status = 'indexed'", Install::CHATBOT_ID ) );
		$last           = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(last_indexed_at) FROM {$srcs} WHERE chatbot_id = %d", Install::CHATBOT_ID ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out['last_sync'] = (string) $last;
		$counts           = Knowledge_Store::counts();
		$out['chunks']    = $counts['chunks'];
		$out['embedded_chunks'] = $counts['embedded'];
		$out['jobs']      = Jobs::counts();

		$by_source = array();

		foreach ( (array) $cites as $json ) {
			foreach ( (array) json_decode( (string) $json, true ) as $source ) {
				if ( empty( $source['source_ref'] ) ) {
					continue;
				}

				$key = $source['source_ref'];

				if ( ! isset( $by_source[ $key ] ) ) {
					$by_source[ $key ] = array(
						'title' => isset( $source['title'] ) ? $source['title'] : $key,
						'url'   => isset( $source['url'] ) ? $source['url'] : '',
						'count' => 0,
					);
				}

				++$by_source[ $key ]['count'];
			}
		}

		usort(
			$by_source,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		$out['top_sources'] = array_slice( $by_source, 0, 10 );

		return $out;
	}
}
