<?php
/**
 * The visitor-facing flows: a question, a rating, a session. Checks, rate limit, safe errors.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Chat service.
 */
final class Chat_Service {

	/**
	 * Handle one question.
	 *
	 * @param string      $message         Question.
	 * @param string|null $conversation_id Conversation id from an earlier answer.
	 * @param array       $context         page_url, post_id, post_type.
	 * @return array|\WP_Error Orchestrator result plus request_id, or an error carrying `status` and `request_id`.
	 */
	public static function handle( $message, $conversation_id, array $context ) {
		$request_id = 'req_' . wp_generate_password( 12, false );
		$settings   = Settings::all();

		if ( empty( $settings['enabled'] ) ) {
			return self::error( 'CHATBOT_DISABLED', __( 'The chatbot is switched off.', 'kriti-ai-chatbot' ), 403, $request_id );
		}

		$message = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', wp_strip_all_tags( (string) wp_check_invalid_utf8( (string) $message, true ) ) ) );

		if ( '' === $message ) {
			return self::error( 'EMPTY_MESSAGE', __( 'Please type a question.', 'kriti-ai-chatbot' ), 400, $request_id );
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $message, 'UTF-8' ) : strlen( $message );

		if ( $length > (int) $settings['max_message_chars'] ) {
			/* translators: %d: maximum number of characters. */
			return self::error( 'MESSAGE_TOO_LONG', sprintf( __( 'Please keep your question under %d characters.', 'kriti-ai-chatbot' ), (int) $settings['max_message_chars'] ), 400, $request_id );
		}

		$limited = self::rate_limit( $request_id );

		if ( $limited ) {
			return $limited;
		}

		$context = array(
			'page_url'  => isset( $context['page_url'] ) ? substr( esc_url_raw( (string) $context['page_url'] ), 0, 255 ) : '',
			'post_id'   => isset( $context['post_id'] ) ? absint( $context['post_id'] ) : 0,
			'post_type' => isset( $context['post_type'] ) ? sanitize_key( $context['post_type'] ) : '',
		);

		try {
			$result = Orchestrator::answer( $message, is_string( $conversation_id ) && '' !== $conversation_id ? $conversation_id : null, $context );
		} catch ( \Throwable $e ) {
			// What went wrong stays in the log; the visitor gets a plain sentence.
			self::log( $request_id, 'orchestrator_exception', $e->getMessage() );

			return self::error( 'ASSISTANT_FAILED', __( 'The assistant could not answer right now. Please try again in a moment.', 'kriti-ai-chatbot' ), 500, $request_id );
		}

		$result['request_id'] = $request_id;

		return $result;
	}

	/**
	 * Rate an answer.
	 *
	 * @param string $conversation_id Conversation id.
	 * @param int    $message_id      Message id.
	 * @param string $rating          helpful|not_helpful.
	 * @param string $note            Optional text.
	 * @return true|\WP_Error
	 */
	public static function feedback( $conversation_id, $message_id, $rating, $note = '' ) {
		$request_id = 'req_' . wp_generate_password( 12, false );
		$limited    = self::rate_limit( $request_id );

		if ( $limited ) {
			return $limited;
		}

		$result = Conversation_Store::set_feedback( (string) $conversation_id, (int) $message_id, (string) $rating, (string) $note );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			return self::error( $result->get_error_code(), $result->get_error_message(), isset( $data['status'] ) ? $data['status'] : 400, $request_id );
		}

		return true;
	}

	/**
	 * Start a conversation, so the widget can hold an id before the first question.
	 *
	 * @return array|\WP_Error {conversation_id}
	 */
	public static function session() {
		$request_id = 'req_' . wp_generate_password( 12, false );

		if ( ! Settings::get( 'enabled' ) ) {
			return self::error( 'CHATBOT_DISABLED', __( 'The chatbot is switched off.', 'kriti-ai-chatbot' ), 403, $request_id );
		}

		$limited = self::rate_limit( $request_id );

		if ( $limited ) {
			return $limited;
		}

		return array(
			'conversation_id' => Conversation_Store::open( null ),
			'request_id'      => $request_id,
		);
	}

	/**
	 * What the widget may know. Nothing secret: no keys, no instructions, no provider names.
	 *
	 * @return array
	 */
	public static function public_config() {
		$prompts = array_values( array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'suggested_prompts' ) ) ) ) );

		return array(
			'enabled'     => (bool) Settings::get( 'enabled' ),
			'title'       => (string) Settings::get( 'title' ),
			'greeting'    => (string) Settings::get( 'greeting' ),
			'placeholder' => (string) Settings::get( 'placeholder' ),
			'color'       => (string) Settings::get( 'color' ),
			'position'    => (string) Settings::get( 'position' ),
			'max_chars'   => (int) Settings::get( 'max_message_chars' ),
			'citations'   => (bool) Settings::get( 'citations' ),
			'prompts'     => $prompts,
		);
	}

	/**
	 * Count a request against the visitor's limit.
	 *
	 * @param string $request_id Request id.
	 * @return \WP_Error|null Error when over the limit.
	 */
	private static function rate_limit( $request_id ) {
		$limit = Rate_Limiter::hit( (int) Settings::get( 'rate_limit' ) );

		if ( $limit['allowed'] ) {
			return null;
		}

		return self::error( 'RATE_LIMITED', __( 'You are asking too fast. Please wait a moment and try again.', 'kriti-ai-chatbot' ), 429, $request_id, array( 'retry_after' => $limit['retry_after'] ) );
	}

	/**
	 * Build an error in the plugin's one error format.
	 *
	 * @param string $code       Machine code.
	 * @param string $message    Safe message for the visitor.
	 * @param int    $status     HTTP status.
	 * @param string $request_id Request id.
	 * @param array  $extra      Extra data (retry_after).
	 * @return \WP_Error
	 */
	private static function error( $code, $message, $status, $request_id, array $extra = array() ) {
		return new \WP_Error(
			$code,
			$message,
			array_merge(
				array(
					'status'     => $status,
					'request_id' => $request_id,
				),
				$extra
			)
		);
	}

	/**
	 * Log the real reason for a failure, with the id the visitor can quote. Never the visitor's text.
	 *
	 * @param string $request_id Request id.
	 * @param string $code       Code.
	 * @param string $detail     Detail.
	 * @return void
	 */
	private static function log( $request_id, $code, $detail ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( sprintf( 'Kriti AI Chatbot [%s] %s: %s', $request_id, $code, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
