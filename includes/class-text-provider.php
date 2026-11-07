<?php
/**
 * Bridge to the text providers of the Kriti AI plugin.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Text generation through Kriti AI. The chatbot never holds a provider key of its own.
 */
final class Text_Provider {

	/**
	 * Whether Kriti AI is active.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( '\\KritiAI\\Provider_Manager' );
	}

	/**
	 * Enabled text providers, slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function choices() {
		if ( ! self::available() ) {
			return array();
		}

		$out = array();

		foreach ( \KritiAI\Provider_Manager::enabled_slugs( 'text' ) as $slug ) {
			$out[ $slug ] = ucfirst( $slug );
		}

		return $out;
	}

	/**
	 * The provider that will be used: the chosen one, else Kriti AI's default. Empty when none is usable.
	 *
	 * @return string
	 */
	public static function slug() {
		if ( ! self::available() ) {
			return '';
		}

		$wanted  = (string) Settings::get( 'provider' );
		$enabled = \KritiAI\Provider_Manager::enabled_slugs( 'text' );

		if ( '' !== $wanted && in_array( $wanted, $enabled, true ) ) {
			return $wanted;
		}

		return (string) \KritiAI\Provider_Manager::default_provider( 'text' );
	}

	/**
	 * Generate text.
	 *
	 * @param string $prompt Prompt.
	 * @param array  $params max_tokens, temperature.
	 * @return array|\WP_Error|null Result, an error, or null when no real provider is available (the mock one does not count).
	 */
	public static function generate( $prompt, array $params = array() ) {
		/**
		 * Supply a provider object with generate_text( $prompt, $params ), for tests or other AI plugins.
		 *
		 * @param object|false|null $provider Provider object, false for none, null to use Kriti AI.
		 */
		$provider = apply_filters( 'kai_chatbot_text_provider', null );
		$slug     = 'filter';

		// Returning false turns text generation off, so answers fall back to the best passage.
		if ( false === $provider ) {
			return null;
		}

		if ( ! is_object( $provider ) ) {
			$slug = self::slug();

			if ( '' === $slug || 'mock' === $slug ) {
				return null;
			}

			$provider = \KritiAI\Provider_Manager::get( $slug );

			if ( ! $provider || ! $provider->is_enabled() ) {
				return null;
			}
		}

		try {
			$result = $provider->generate_text( $prompt, $params );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'kai_chatbot_provider_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$content = is_array( $result ) && isset( $result['content'] ) ? trim( (string) $result['content'] ) : '';

		if ( '' === $content ) {
			return new \WP_Error( 'kai_chatbot_empty_answer', __( 'The AI provider returned an empty answer.', 'kriti-ai-chatbot' ) );
		}

		return array(
			'content'    => $content,
			'tokens_in'  => isset( $result['tokens_in'] ) ? (int) $result['tokens_in'] : 0,
			'tokens_out' => isset( $result['tokens_out'] ) ? (int) $result['tokens_out'] : 0,
			'provider'   => $slug,
			'model'      => isset( $result['model'] ) ? (string) $result['model'] : '',
		);
	}
}
