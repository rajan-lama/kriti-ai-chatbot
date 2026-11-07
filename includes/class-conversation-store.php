<?php
/**
 * Conversations, messages, feedback and learning events.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Stores chat history. Never stores an IP address, user agent, session token or account detail.
 */
final class Conversation_Store {

	/**
	 * Whether conversations are stored at all.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (int) Settings::get( 'retention_days' ) >= 0;
	}

	/**
	 * Whether an id looks like one we issued.
	 *
	 * @param mixed $id Candidate id.
	 * @return bool
	 */
	public static function is_valid_id( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id );
	}

	/**
	 * Whether a conversation exists.
	 *
	 * @param string $id Conversation id.
	 * @return bool
	 */
	public static function exists( $id ) {
		global $wpdb;

		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}

		$table = Install::table( 'conversations' );

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE id = %s AND chatbot_id = %d", $id, Install::CHATBOT_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Use the given conversation if it exists, otherwise start one.
	 *
	 * @param string|null $id       Client-supplied id.
	 * @param string      $page_url Page the visitor is on.
	 * @return string The conversation id.
	 */
	public static function open( $id, $page_url = '' ) {
		global $wpdb;

		if ( $id && self::exists( $id ) ) {
			return $id;
		}

		$id = wp_generate_uuid4();

		if ( self::enabled() ) {
			$now  = current_time( 'mysql', true );
			$days = (int) Settings::get( 'retention_days' );

			$wpdb->insert(
				Install::table( 'conversations' ),
				array(
					'id'              => $id,
					'chatbot_id'      => Install::CHATBOT_ID,
					'status'          => 'open',
					'locale'          => strtolower( substr( determine_locale(), 0, 5 ) ),
					'page_url'        => substr( (string) $page_url, 0, 255 ),
					'message_count'   => 0,
					'started_at'      => $now,
					'last_message_at' => $now,
					'expires_at'      => $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null,
				)
			);
		}

		return $id;
	}

	/**
	 * Add a message.
	 *
	 * @param string $conversation_id Conversation id.
	 * @param string $role            user|assistant.
	 * @param string $content         Text.
	 * @param array  $extra           question, citations, confidence, answered, mode, provider, model, input_tokens, output_tokens, latency_ms.
	 * @return int Message id, or 0 when nothing was stored.
	 */
	public static function add( $conversation_id, $role, $content, array $extra = array() ) {
		global $wpdb;

		if ( ! self::enabled() ) {
			return 0;
		}

		$wpdb->insert(
			Install::table( 'messages' ),
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'message_content' => $content,
				'question'        => isset( $extra['question'] ) ? $extra['question'] : null,
				'citations_json'  => isset( $extra['citations'] ) ? wp_json_encode( $extra['citations'] ) : null,
				'confidence'      => isset( $extra['confidence'] ) ? (float) $extra['confidence'] : 0,
				'answered'        => isset( $extra['answered'] ) ? (int) (bool) $extra['answered'] : 1,
				'mode'            => isset( $extra['mode'] ) ? $extra['mode'] : '',
				'provider'        => isset( $extra['provider'] ) ? substr( $extra['provider'], 0, 40 ) : '',
				'model'           => isset( $extra['model'] ) ? substr( $extra['model'], 0, 100 ) : '',
				'input_tokens'    => isset( $extra['input_tokens'] ) ? (int) $extra['input_tokens'] : 0,
				'output_tokens'   => isset( $extra['output_tokens'] ) ? (int) $extra['output_tokens'] : 0,
				'latency_ms'      => isset( $extra['latency_ms'] ) ? (int) $extra['latency_ms'] : 0,
				'created_at'      => current_time( 'mysql', true ),
			)
		);

		$id    = (int) $wpdb->insert_id;
		$table = Install::table( 'conversations' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET message_count = message_count + 1, last_message_at = %s WHERE id = %s", current_time( 'mysql', true ), $conversation_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $id;
	}

	/**
	 * The last turns of a conversation, oldest first.
	 *
	 * @param string $conversation_id Conversation id.
	 * @param int    $turns           Number of question/answer pairs.
	 * @return array<array{role: string, content: string}>
	 */
	public static function recent( $conversation_id, $turns ) {
		global $wpdb;

		$turns = (int) $turns;

		if ( $turns < 1 || ! self::enabled() || ! self::is_valid_id( $conversation_id ) ) {
			return array();
		}

		$table = Install::table( 'messages' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT role, message_content AS content FROM {$table} WHERE conversation_id = %s ORDER BY id DESC LIMIT %d", $conversation_id, $turns * 2 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_reverse( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Record a visitor's rating of an answer. The conversation id is the proof of ownership, so a rating
	 * only lands on a message that belongs to that conversation.
	 *
	 * @param string $conversation_id Conversation id.
	 * @param int    $message_id      Message id.
	 * @param string $rating          helpful|not_helpful.
	 * @param string $note            Optional text.
	 * @return true|\WP_Error
	 */
	public static function set_feedback( $conversation_id, $message_id, $rating, $note = '' ) {
		global $wpdb;

		if ( ! in_array( $rating, array( 'helpful', 'not_helpful' ), true ) || ! self::is_valid_id( $conversation_id ) ) {
			return new \WP_Error( 'FEEDBACK_INVALID', __( 'That rating is not valid.', 'kriti-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$table = Install::table( 'messages' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, question FROM {$table} WHERE id = %d AND conversation_id = %s AND role = 'assistant'", (int) $message_id, $conversation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return new \WP_Error( 'MESSAGE_NOT_FOUND', __( 'That answer was not found.', 'kriti-ai-chatbot' ), array( 'status' => 404 ) );
		}

		$wpdb->update( $table, array( 'feedback' => $rating ), array( 'id' => (int) $message_id ) );

		if ( 'not_helpful' === $rating ) {
			self::log_event(
				'not_helpful',
				$conversation_id,
				array(
					'question'   => (string) $row['question'],
					'message_id' => (int) $message_id,
					'note'       => substr( sanitize_textarea_field( $note ), 0, 500 ),
				)
			);
		}

		return true;
	}

	/**
	 * Record a learning event: something the administrator may want to act on.
	 *
	 * Events are signals, never knowledge: nothing here is ever added to the index by itself.
	 *
	 * @param string      $type            unanswered|not_helpful.
	 * @param string|null $conversation_id Conversation id.
	 * @param array       $payload         Details.
	 * @return void
	 */
	public static function log_event( $type, $conversation_id, array $payload ) {
		global $wpdb;

		if ( ! self::enabled() ) {
			return;
		}

		$wpdb->insert(
			Install::table( 'learning_events' ),
			array(
				'chatbot_id'         => Install::CHATBOT_ID,
				'conversation_id'    => $conversation_id,
				'event_type'         => $type,
				'event_payload_json' => wp_json_encode( $payload ),
				'review_status'      => 'new',
				'created_at'         => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Delete conversations that were last active more than $days days ago, with their learning events.
	 *
	 * @param int $days Age in days.
	 * @return int Conversations deleted.
	 */
	public static function delete_older_than( $days ) {
		global $wpdb;

		$convs  = Install::table( 'conversations' );
		$msgs   = Install::table( 'messages' );
		$events = Install::table( 'learning_events' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$msgs} m INNER JOIN {$convs} c ON c.id = m.conversation_id WHERE c.last_message_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$convs} WHERE last_message_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete every conversation, message and learning event (the visitor's own words).
	 *
	 * @return int Conversations deleted.
	 */
	public static function delete_all() {
		global $wpdb;

		$convs = Install::table( 'conversations' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$convs}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( array( 'messages', 'conversations', 'learning_events' ) as $name ) {
			$table = Install::table( $name );
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $count;
	}
}
