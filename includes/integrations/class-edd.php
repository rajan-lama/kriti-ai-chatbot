<?php
/**
 * Easy Digital Downloads.
 *
 * Static knowledge (what a product is, which licence options it has, where to buy it) is indexed like any page.
 * Live data (the price right now) is never taken from the index: it is read from EDD when a question is answered.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * EDD integration.
 */
final class EDD {

	/**
	 * Hooks, only when EDD is active.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'edd_get_download_price' ) ) {
			return;
		}

		add_filter( 'kai_chatbot_document', array( __CLASS__, 'enrich_document' ), 10, 2 );
		add_filter( 'kai_chatbot_live_facts', array( __CLASS__, 'live_facts' ), 10, 2 );
	}

	/**
	 * Add licence options and the purchase link to a download's document. No prices.
	 *
	 * @param array    $doc  Document.
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public static function enrich_document( array $doc, \WP_Post $post ) {
		if ( 'download' !== $post->post_type ) {
			return $doc;
		}

		$extra = '';

		if ( edd_has_variable_prices( $post->ID ) ) {
			$names = array();

			foreach ( (array) edd_get_variable_prices( $post->ID ) as $price ) {
				if ( ! empty( $price['name'] ) ) {
					$names[] = '- ' . wp_strip_all_tags( $price['name'] );
				}
			}

			if ( $names ) {
				$extra .= "\n\n## License options\n" . implode( "\n", $names );
			}
		}

		$extra .= "\n\n## Purchase\nBuy " . $doc['title'] . ': ' . self::purchase_url( $post->ID );

		$doc['content']              = trim( $doc['content'] . $extra );
		$doc['metadata']['edd']      = true;
		$doc['metadata']['variable'] = (bool) edd_has_variable_prices( $post->ID );

		return $doc;
	}

	/**
	 * Current prices of the downloads that were retrieved.
	 *
	 * @param string[] $facts  Facts so far.
	 * @param array[]  $chunks Retrieved chunks.
	 * @return string[]
	 */
	public static function live_facts( array $facts, array $chunks ) {
		$seen = array();

		foreach ( $chunks as $chunk ) {
			if ( 'download' !== $chunk['post_type'] || isset( $seen[ $chunk['source_id'] ] ) ) {
				continue;
			}

			$seen[ $chunk['source_id'] ] = true;
			$id                          = (int) $chunk['source_id'];

			// The product may have been unpublished since it was indexed.
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}

			$title = wp_strip_all_tags( get_the_title( $id ) );

			if ( edd_has_variable_prices( $id ) ) {
				$parts = array();

				foreach ( (array) edd_get_variable_prices( $id ) as $price ) {
					$parts[] = wp_strip_all_tags( $price['name'] ) . ': ' . self::format( isset( $price['amount'] ) ? $price['amount'] : 0 );
				}

				$facts[] = 'Current price of ' . $title . ' by licence option: ' . implode( '; ', $parts ) . '.';
			} else {
				$facts[] = 'Current price of ' . $title . ': ' . self::format( edd_get_download_price( $id ) ) . '.';
			}

			$facts[] = 'Add ' . $title . ' to the cart: ' . self::purchase_url( $id );
		}

		return $facts;
	}

	/**
	 * Link that adds a download to the cart.
	 *
	 * @param int $download_id Download id.
	 * @return string
	 */
	private static function purchase_url( $download_id ) {
		return esc_url_raw(
			add_query_arg(
				array(
					'edd_action'  => 'add_to_cart',
					'download_id' => (int) $download_id,
				),
				edd_get_checkout_uri()
			)
		);
	}

	/**
	 * A price as the store shows it.
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	private static function format( $amount ) {
		if ( (float) $amount <= 0 ) {
			return 'Free';
		}

		return html_entity_decode( wp_strip_all_tags( edd_currency_filter( edd_format_amount( $amount ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
