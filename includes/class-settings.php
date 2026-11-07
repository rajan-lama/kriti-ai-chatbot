<?php
/**
 * Settings: defaults, reading and sanitizing.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
final class Settings {

	const OPTION = 'kai_chatbot_settings';

	/**
	 * Cached settings for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$post_types = array( 'post', 'page' );

		if ( function_exists( 'edd_get_download_price' ) ) {
			$post_types[] = 'download';
		}

		return array(
			'enabled'              => 1,
			'post_types'           => $post_types,
			'extra_meta_keys'      => '',
			'exclude_ids'          => '',
			'exclude_urls'         => '',
			'exclude_terms'        => '',
			'embeddings'           => 1,
			'embedding_provider'   => '',
			'embedding_model'      => '',
			'vector_threshold'     => 0.5,
			'suggested_prompts'    => '',
			'delete_on_uninstall'  => 0,
			'chunk_words'          => 400,
			'chunk_overlap'        => 50,
			'top_k'                => 5,
			'confidence_threshold' => 0.4,
			'tone'                 => 'professional',
			'length'               => 'concise',
			'if_uncertain'         => 'say_dont_know',
			'contact_text'         => '',
			'citations'            => 1,
			'site_instructions'    => '',
			'provider'             => '',
			'title'                => __( 'Ask us anything', 'kriti-ai-chatbot' ),
			'greeting'             => __( 'Hi! Ask me a question about this site and I will answer from our content.', 'kriti-ai-chatbot' ),
			'placeholder'          => __( 'Type your question…', 'kriti-ai-chatbot' ),
			'color'                => '#2f4bd6',
			'position'             => 'right',
			'retention_days'       => 90,
			'rate_limit'           => 10,
			'max_message_chars'    => 500,
			'history_turns'        => 4,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}

		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Forget the cached settings.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Allowed retention choices: days, 0 for forever, -1 for not storing conversations at all.
	 *
	 * @return array<int,string>
	 */
	public static function retention_choices() {
		return array(
			30  => __( '30 days', 'kriti-ai-chatbot' ),
			90  => __( '90 days', 'kriti-ai-chatbot' ),
			180 => __( '180 days', 'kriti-ai-chatbot' ),
			365 => __( '1 year', 'kriti-ai-chatbot' ),
			0   => __( 'Forever', 'kriti-ai-chatbot' ),
			-1  => __( 'Do not store conversations', 'kriti-ai-chatbot' ),
		);
	}

	/**
	 * Sanitize the settings form. Used as the `sanitize_callback` of register_setting().
	 *
	 * @param mixed $input   Posted values.
	 * @param bool  $unslash Whether the values come from $_POST and carry slashes.
	 * @return array
	 */
	public static function sanitize( $input, $unslash = true ) {
		$input = is_array( $input ) ? ( $unslash ? wp_unslash( $input ) : $input ) : array();
		$old   = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		$out   = array();

		$out['enabled']   = empty( $input['enabled'] ) ? 0 : 1;
		$out['citations'] = empty( $input['citations'] ) ? 0 : 1;

		// These two live on the Sources page; saving the Settings page must not clear them.
		if ( isset( $input['post_types'] ) ) {
			$public = array_keys( get_post_types( array( 'public' => true ) ) );
			$chosen = is_array( $input['post_types'] ) ? array_map( 'sanitize_key', $input['post_types'] ) : array();

			$out['post_types'] = array_values( array_intersect( $chosen, array_diff( $public, array( 'attachment' ) ) ) );
		} else {
			$out['post_types'] = (array) $old['post_types'];
		}

		if ( isset( $input['extra_meta_keys'] ) ) {
			// One meta key per line; keys only.
			$keys                   = preg_split( '/\R/', (string) $input['extra_meta_keys'] );
			$keys                   = array_filter(
				array_map( 'trim', $keys ),
				static function ( $key ) {
					return (bool) preg_match( '/^[A-Za-z0-9_\-]{1,191}$/', $key );
				}
			);
			$out['extra_meta_keys'] = implode( "\n", array_unique( $keys ) );
		} else {
			$out['extra_meta_keys'] = (string) $old['extra_meta_keys'];
		}

		// Exclusion rules also live on the Sources page.
		foreach ( array( 'exclude_ids', 'exclude_urls', 'exclude_terms' ) as $field ) {
			$out[ $field ] = isset( $input[ $field ] ) ? self::clean_list( $input[ $field ], $field ) : (string) $old[ $field ];
		}

		$out['embeddings']          = array_key_exists( 'embeddings_present', $input ) ? ( empty( $input['embeddings'] ) ? 0 : 1 ) : (int) $old['embeddings'];
		$out['embedding_provider']  = isset( $input['embedding_provider'] ) ? sanitize_key( $input['embedding_provider'] ) : (string) $old['embedding_provider'];
		$out['embedding_model']     = isset( $input['embedding_model'] ) ? preg_replace( '/[^A-Za-z0-9._:\/\-]/', '', (string) $input['embedding_model'] ) : (string) $old['embedding_model'];
		$vector                     = isset( $input['vector_threshold'] ) ? (float) $input['vector_threshold'] : (float) $old['vector_threshold'];
		$out['vector_threshold']    = max( 0.0, min( 1.0, $vector ) );
		$out['delete_on_uninstall'] = array_key_exists( 'uninstall_present', $input ) ? ( empty( $input['delete_on_uninstall'] ) ? 0 : 1 ) : (int) $old['delete_on_uninstall'];

		if ( isset( $input['suggested_prompts'] ) ) {
			$prompts                  = array_slice( array_filter( array_map( 'sanitize_text_field', preg_split( '/\R/', (string) $input['suggested_prompts'] ) ) ), 0, 4 );
			$out['suggested_prompts'] = implode( "\n", array_map( static function ( $p ) {
				return function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 120, 'UTF-8' ) : substr( $p, 0, 120 );
			}, $prompts ) );
		} else {
			$out['suggested_prompts'] = (string) $old['suggested_prompts'];
		}

		$out['chunk_words']          = self::int_between( $input, 'chunk_words', 100, 1200, $old['chunk_words'] );
		$out['chunk_overlap']        = self::int_between( $input, 'chunk_overlap', 0, 200, $old['chunk_overlap'] );
		$out['top_k']                = self::int_between( $input, 'top_k', 1, 12, $old['top_k'] );
		$out['rate_limit']           = self::int_between( $input, 'rate_limit', 1, 120, $old['rate_limit'] );
		$out['max_message_chars']    = self::int_between( $input, 'max_message_chars', 50, 2000, $old['max_message_chars'] );
		$out['history_turns']        = self::int_between( $input, 'history_turns', 0, 10, $old['history_turns'] );
		$threshold                   = isset( $input['confidence_threshold'] ) ? (float) $input['confidence_threshold'] : (float) $old['confidence_threshold'];
		$out['confidence_threshold'] = max( 0.0, min( 1.0, $threshold ) );

		if ( $out['chunk_overlap'] >= $out['chunk_words'] ) {
			$out['chunk_overlap'] = (int) floor( $out['chunk_words'] / 4 );
		}

		$out['tone']         = self::one_of( $input, 'tone', array( 'professional', 'friendly', 'casual' ), $old['tone'] );
		$out['length']       = self::one_of( $input, 'length', array( 'concise', 'detailed' ), $old['length'] );
		$out['if_uncertain'] = self::one_of( $input, 'if_uncertain', array( 'say_dont_know', 'suggest_contact' ), $old['if_uncertain'] );

		$out['contact_text']      = isset( $input['contact_text'] ) ? sanitize_textarea_field( $input['contact_text'] ) : '';
		$out['site_instructions'] = isset( $input['site_instructions'] ) ? sanitize_textarea_field( $input['site_instructions'] ) : '';
		$out['provider']          = isset( $input['provider'] ) ? sanitize_key( $input['provider'] ) : '';

		$out['title']       = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : $old['title'];
		$out['greeting']    = isset( $input['greeting'] ) ? sanitize_textarea_field( $input['greeting'] ) : $old['greeting'];
		$out['placeholder'] = isset( $input['placeholder'] ) ? sanitize_text_field( $input['placeholder'] ) : $old['placeholder'];
		$out['color']       = isset( $input['color'] ) && sanitize_hex_color( $input['color'] ) ? sanitize_hex_color( $input['color'] ) : $old['color'];
		$out['position']    = self::one_of( $input, 'position', array( 'right', 'left' ), $old['position'] );

		$retention              = isset( $input['retention_days'] ) ? (int) $input['retention_days'] : (int) $old['retention_days'];
		$out['retention_days']  = array_key_exists( $retention, self::retention_choices() ) ? $retention : (int) $old['retention_days'];

		self::$cache = null;

		return $out;
	}

	/**
	 * Clean one of the exclusion lists.
	 *
	 * @param mixed  $value Posted text.
	 * @param string $field exclude_ids|exclude_urls|exclude_terms.
	 * @return string One entry per line.
	 */
	private static function clean_list( $value, $field ) {
		$items = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $value ) ) );
		$out   = array();

		foreach ( $items as $item ) {
			if ( 'exclude_ids' === $field && ctype_digit( $item ) ) {
				$out[] = $item;
			} elseif ( 'exclude_urls' === $field ) {
				$out[] = substr( sanitize_text_field( $item ), 0, 190 );
			} elseif ( 'exclude_terms' === $field ) {
				$out[] = sanitize_title( $item );
			}
		}

		return implode( "\n", array_unique( array_filter( $out ) ) );
	}

	/**
	 * Read an integer from the input, clamped.
	 *
	 * @param array  $input   Input.
	 * @param string $key     Key.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @param int    $default Fallback.
	 * @return int
	 */
	private static function int_between( $input, $key, $min, $max, $default ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return (int) $default;
		}

		return max( $min, min( $max, (int) $input[ $key ] ) );
	}

	/**
	 * Read a choice from the input.
	 *
	 * @param array  $input   Input.
	 * @param string $key     Key.
	 * @param array  $allowed Allowed values.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function one_of( $input, $key, array $allowed, $default ) {
		return isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ? $input[ $key ] : $default;
	}
}
