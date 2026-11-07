<?php
/**
 * Conversation retention and the privacy policy text.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes old conversations.
 */
final class Retention {

	const HOOK = 'kai_chatbot_retention';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_text' ) );
	}

	/**
	 * Delete conversations older than the retention period.
	 *
	 * @return int Number of conversations deleted.
	 */
	public static function run() {
		$days = (int) Settings::get( 'retention_days' );

		if ( $days < 0 ) {
			return Conversation_Store::delete_all();
		}

		if ( 0 === $days ) {
			return 0;
		}

		return Conversation_Store::delete_older_than( $days );
	}

	/**
	 * Suggested text for Settings → Privacy.
	 *
	 * @return void
	 */
	public static function privacy_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$days = (int) Settings::get( 'retention_days' );

		if ( $days < 0 ) {
			$stored = __( 'Questions and answers are not stored.', 'kriti-ai-chatbot' );
		} elseif ( 0 === $days ) {
			$stored = __( 'Questions and answers are stored until the site owner deletes them.', 'kriti-ai-chatbot' );
		} else {
			/* translators: %d: number of days. */
			$stored = sprintf( _n( 'Questions and answers are stored for %d day.', 'Questions and answers are stored for %d days.', $days, 'kriti-ai-chatbot' ), $days );
		}

		$sent = __( 'The question, and the passages of this site\'s content that best match it, are sent to the AI provider chosen by the site owner to find and write the answer. The text of the site\'s own pages is also sent to that provider, in advance, to prepare it for searching.', 'kriti-ai-chatbot' );

		wp_add_privacy_policy_content(
			'Kriti AI Chatbot',
			wp_kses_post(
				'<h3>' . esc_html__( 'Chatbot', 'kriti-ai-chatbot' ) . '</h3><p>' . esc_html__( 'When a visitor asks the chatbot a question, the text of the question is processed to answer it. No name, email address or IP address is stored with the question.', 'kriti-ai-chatbot' ) . ' ' . esc_html( $stored ) . ' ' . esc_html( $sent ) . '</p>'
			)
		);
	}
}
