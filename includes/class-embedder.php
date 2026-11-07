<?php
/**
 * Embedding adapter: the chatbot's view of Kriti AI's embedding providers.
 *
 * Chat completion and embeddings are separate concerns (spec section 12); this class knows only about embeddings.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Embedder.
 */
final class Embedder {

	const BATCH = 16;

	/**
	 * Vector store in use.
	 *
	 * @return Vector_Store
	 */
	public static function store() {
		$store = apply_filters( 'kai_chatbot_vector_store', new Mysql_Vector_Store() );

		return $store instanceof Vector_Store ? $store : new Mysql_Vector_Store();
	}

	/**
	 * Whether embeddings are switched on and a real provider can make them.
	 *
	 * The mock provider returns hashed vectors with no meaning, so it does not count unless a test allows it.
	 *
	 * @return bool
	 */
	public static function ready() {
		if ( ! Settings::get( 'embeddings' ) || ! Text_Provider::available() ) {
			return false;
		}

		return '' !== self::provider();
	}

	/**
	 * Provider slug to use: the chosen one, else Kriti AI's default. Empty when none can be used.
	 *
	 * @return string
	 */
	public static function provider() {
		if ( ! Text_Provider::available() ) {
			return '';
		}

		$enabled = \KritiAI\Provider_Manager::enabled_slugs( 'embedding' );

		if ( ! apply_filters( 'kai_chatbot_allow_mock_embeddings', false ) ) {
			$enabled = array_values( array_diff( $enabled, array( 'mock' ) ) );
		}

		// The chatbot's own choice first, then the default set in Kriti AI → Settings, then the first one that works.
		$wanted = (string) Settings::get( 'embedding_provider' );

		if ( '' !== $wanted && in_array( $wanted, $enabled, true ) ) {
			return $wanted;
		}

		$default = (string) \KritiAI\Provider_Manager::default_provider( 'embedding' );

		if ( '' !== $default && in_array( $default, $enabled, true ) ) {
			return $default;
		}

		return $enabled ? $enabled[0] : '';
	}

	/**
	 * Model to use: the chosen one, else the provider's default.
	 *
	 * @return string
	 */
	public static function model() {
		$slug = self::provider();

		if ( '' === $slug ) {
			return '';
		}

		$chosen = trim( (string) Settings::get( 'embedding_model' ) );

		return '' !== $chosen ? $chosen : \KritiAI\Provider_Manager::default_embedding_model( $slug );
	}

	/**
	 * Embed texts. Vectors are returned at unit length.
	 *
	 * @param string[] $texts Texts.
	 * @param string   $operation embed_index|embed_query, for the usage log.
	 * @return array|\WP_Error {vectors: float[][], provider: string, model: string, dimensions: int}
	 */
	public static function embed( array $texts, $operation = 'embed_index' ) {
		$slug  = self::provider();
		$model = self::model();

		if ( '' === $slug ) {
			return new \WP_Error( 'kai_chatbot_no_embeddings', __( 'No embedding provider is set up.', 'kriti-ai-chatbot' ) );
		}

		$vectors = array();
		$tokens  = 0;
		$dims    = 0;
		$texts   = array_values( $texts );

		foreach ( array_chunk( $texts, self::BATCH ) as $batch ) {
			$batch  = array_map(
				static function ( $text ) {
					return function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, 6000, 'UTF-8' ) : substr( (string) $text, 0, 6000 );
				},
				$batch
			);
			$result = \KritiAI\Provider_Manager::embed( $batch, array( 'model' => $model ), $slug );

			if ( is_wp_error( $result ) ) {
				self::log( $slug, $model, $operation, $tokens, 'error' );

				return $result;
			}

			if ( count( $result['vectors'] ) !== count( $batch ) ) {
				self::log( $slug, $model, $operation, $tokens, 'error' );

				return new \WP_Error( 'kai_chatbot_bad_embeddings', __( 'The provider returned the wrong number of embeddings.', 'kriti-ai-chatbot' ) );
			}

			foreach ( $result['vectors'] as $vector ) {
				$dims = $dims ? $dims : count( $vector );

				if ( count( $vector ) !== $dims || ! $dims ) {
					self::log( $slug, $model, $operation, $tokens, 'error' );

					return new \WP_Error( 'kai_chatbot_bad_embeddings', __( 'The provider returned vectors of different sizes.', 'kriti-ai-chatbot' ) );
				}

				$vectors[] = Mysql_Vector_Store::normalize( array_map( 'floatval', $vector ) );
			}

			$tokens += (int) $result['tokens_in'];
		}

		self::log( $slug, $model, $operation, $tokens, 'ok' );

		return array(
			'vectors'    => $vectors,
			'provider'   => $slug,
			'model'      => $model,
			'dimensions' => $dims,
		);
	}

	/**
	 * Embed a visitor's question. Repeated questions are served from a short cache.
	 *
	 * @param string $query Question.
	 * @return array|\WP_Error Same shape as embed(), with one vector.
	 */
	public static function embed_query( $query ) {
		$key    = 'kai_chat_q_' . md5( self::provider() . '|' . self::model() . '|' . strtolower( trim( $query ) ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['vectors'][0] ) ) {
			return $cached;
		}

		$result = self::embed( array( $query ), 'embed_query' );

		if ( ! is_wp_error( $result ) ) {
			set_transient( $key, $result, HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Write a line of the usage log.
	 *
	 * @param string $provider  Provider.
	 * @param string $model     Model.
	 * @param string $operation Operation.
	 * @param int    $tokens    Input tokens.
	 * @param string $status    ok|error.
	 * @return void
	 */
	private static function log( $provider, $model, $operation, $tokens, $status ) {
		Usage::log( $operation, $provider, $model, $tokens, 0, $status );
	}
}
