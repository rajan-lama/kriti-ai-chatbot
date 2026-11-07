<?php
/**
 * Turns a WordPress post into a normalized document. Raw HTML is never indexed.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Content normalization.
 */
final class Normalizer {

	/**
	 * Normalize one post.
	 *
	 * Returns the document shape the AI/RAG backend's /v1/sync accepts, plus the content hash.
	 *
	 * @param \WP_Post $post Post.
	 * @return array {source_type, source_id, post_type, title, url, content, category, language, status, content_hash, metadata}
	 */
	public static function normalize( \WP_Post $post ) {
		$body = $post->post_content;

		// The excerpt often states the point of a page better than its first paragraph.
		if ( '' !== trim( $post->post_excerpt ) ) {
			$body = '<p>' . $post->post_excerpt . "</p>\n" . $body;
		}

		foreach ( self::extra_meta_keys() as $key ) {
			$value = get_post_meta( $post->ID, $key, true );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$body .= "\n" . $value;
			}
		}

		$terms = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}

			$names = wp_get_post_terms( $post->ID, $taxonomy->name, array( 'fields' => 'names' ) );

			if ( is_array( $names ) ) {
				$terms = array_merge( $terms, $names );
			}
		}

		$locale = determine_locale();
		$doc    = array(
			'source_type'  => substr( $post->post_type, 0, 32 ),
			'source_id'    => (string) $post->ID,
			'post_type'    => $post->post_type,
			'title'        => trim( (string) wp_check_invalid_utf8( wp_strip_all_tags( get_the_title( $post ) ), true ) ),
			'url'          => (string) get_permalink( $post ),
			'content'      => self::html_to_text( $body ),
			'category'     => implode( ', ', array_unique( array_map( 'wp_strip_all_tags', $terms ) ) ),
			'language'     => strtolower( substr( $locale, 0, 2 ) ),
			'status'       => 'published',
			'content_hash' => '',
			'metadata'     => array(
				'modified' => $post->post_modified_gmt,
			),
		);

		/**
		 * Adjust a normalized document, for example to add live-independent facts from an integration.
		 *
		 * @param array    $doc  Document.
		 * @param \WP_Post $post Post.
		 */
		$doc = apply_filters( 'kai_chatbot_document', $doc, $post );

		$doc['content_hash'] = self::hash( $doc );

		return $doc;
	}

	/**
	 * SHA-256 of the normalized content (with the fields that are shown to visitors).
	 *
	 * @param array $doc Document.
	 * @return string
	 */
	public static function hash( array $doc ) {
		return hash( 'sha256', implode( "\n", array( $doc['title'], $doc['url'], $doc['category'], $doc['content'] ) ) );
	}

	/**
	 * Convert HTML into plain text that keeps headings, lists, tables and links.
	 *
	 * Headings become "## Heading" lines, which the chunker splits on.
	 *
	 * @param string $html HTML or block markup.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$text = (string) $html;

		$text = strip_shortcodes( $text );
		$text = preg_replace( '/\[\/?[a-z][a-z0-9_\-]*(?:\s[^\]]*)?\]/i', '', $text );
		$text = preg_replace( '/<!--.*?-->/s', '', $text );

		// Chrome, scripts and anything that is not content.
		$text = preg_replace( '#<(script|style|nav|form|iframe|noscript|svg|button|select|textarea|template|object|embed|header|footer|aside)\b[^>]*>.*?</\1\s*>#is', ' ', $text );

		// Headings.
		$text = preg_replace_callback(
			'#<h([1-6])\b[^>]*>(.*?)</h\1\s*>#is',
			static function ( $m ) {
				$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[2] ) ) );
				return '' === $title ? "\n\n" : "\n\n" . str_repeat( '#', max( 2, (int) $m[1] ) ) . ' ' . $title . "\n\n";
			},
			$text
		);

		// Links keep their address, so an answer can point to it.
		$text = preg_replace_callback(
			'#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>#is',
			static function ( $m ) {
				$label = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[3] ) ) );
				$href  = trim( html_entity_decode( $m[2] ) );

				if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
					$href = home_url( $href );
				}

				if ( '' === $label || ! preg_match( '#^https?://#i', $href ) || $label === $href ) {
					return $label . ' ';
				}

				return $label . ' (' . $href . ') ';
			},
			$text
		);

		$text = preg_replace( '#<li\b[^>]*>#i', "\n- ", $text );
		$text = preg_replace( '#</t[dh]\s*>#i', ' | ', $text );
		$text = preg_replace( '#</tr\s*>#i', "\n", $text );
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = preg_replace( '#</?(p|div|ul|ol|table|thead|tbody|tr|blockquote|section|article|figure|figcaption|pre|hr|dl|dt|dd)\b[^>]*>#i', "\n", $text );

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/ ?\| ?\n/', "\n", $text );

		$lines = array();
		$seen  = array();

		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				$lines[] = '';
				continue;
			}

			// Drop exact repeats of long lines (repeated call-to-action blocks).
			if ( strlen( $line ) > 60 ) {
				$key = md5( $line );

				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
			}

			$lines[] = $line;
		}

		$text = implode( "\n", $lines );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		// Broken byte sequences (a cut-off emoji, a bad import) are dropped so every later step can rely on valid UTF-8.
		return trim( (string) wp_check_invalid_utf8( $text, true ) );
	}

	/**
	 * Extra custom fields to index, from the settings.
	 *
	 * @return string[]
	 */
	private static function extra_meta_keys() {
		$keys = preg_split( '/\R/', (string) Settings::get( 'extra_meta_keys' ) );

		return array_values( array_filter( array_map( 'trim', $keys ) ) );
	}
}
