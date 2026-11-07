<?php
/**
 * Per-visitor rate limiting without storing the visitor.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counter keyed by a salted hash of the address. The address itself is never stored.
 */
final class Rate_Limiter {

	/**
	 * Count a request and say whether it is allowed.
	 *
	 * @param int $limit  Requests allowed per window.
	 * @param int $window Window in seconds.
	 * @return array{allowed: bool, retry_after: int}
	 */
	public static function hit( $limit, $window = 60 ) {
		$key   = 'kai_chat_rl_' . substr( hash_hmac( 'sha256', self::client_ip(), wp_salt( 'auth' ) ), 0, 32 );
		$state = get_transient( $key );

		if ( ! is_array( $state ) || $state['reset'] <= time() ) {
			$state = array(
				'count' => 0,
				'reset' => time() + (int) $window,
			);
		}

		++$state['count'];
		set_transient( $key, $state, max( 1, $state['reset'] - time() ) );

		return array(
			'allowed'     => $state['count'] <= (int) $limit,
			'retry_after' => max( 1, $state['reset'] - time() ),
		);
	}

	/**
	 * The visitor's address. Only REMOTE_ADDR is trusted; behind a proxy, supply the address with the filter.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Address used for rate limiting.
		 *
		 * @param string $ip Address from REMOTE_ADDR.
		 */
		return (string) apply_filters( 'kai_chatbot_client_ip', $ip );
	}
}
