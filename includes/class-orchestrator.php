<?php
/**
 * Conversation orchestrator: one question in, one grounded answer out.
 *
 * Steps (spec section 9): conversation context, retrieval, evidence evaluation, prompt, model,
 * citations from what was actually retrieved, and storing the exchange.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrator.
 */
final class Orchestrator {

	/**
	 * Answer a question.
	 *
	 * @param string      $message         Question.
	 * @param string|null $conversation_id Conversation id.
	 * @param array       $context         Page context.
	 * @return array {
	 *   conversation_id, message_id, answer, answer_status (grounded|extract|unanswered), answered,
	 *   sources, confidence, usage, mode, latency_ms
	 * }
	 */
	public static function answer( $message, $conversation_id, array $context ) {
		$started  = microtime( true );
		$settings = Settings::all();
		$conv_id  = Conversation_Store::open( $conversation_id, isset( $context['page_url'] ) ? (string) $context['page_url'] : '' );
		$history  = Conversation_Store::recent( $conv_id, (int) $settings['history_turns'] );
		$found    = self::retrieve( $message, $history );
		$chunks   = $found['chunks'];

		Conversation_Store::add( $conv_id, 'user', $message );

		// Evidence evaluation: keyword coverage OR a close enough meaning match. Neither is the model's own opinion.
		$enough = $chunks && ( $found['keyword'] >= (float) $settings['confidence_threshold'] || $found['vector'] >= (float) $settings['vector_threshold'] );

		if ( ! $enough ) {
			// Not enough evidence: say so, and do not spend an AI request on it.
			return self::finish( $conv_id, $message, self::dont_know_text(), array(), 'unanswered', max( $found['keyword'], $found['vector'] ), array(), $started );
		}

		$confidence = max( $found['keyword'], $found['vector'] );

		/**
		 * Facts that must be read live instead of from the index, such as current prices.
		 *
		 * @param string[] $facts  Facts, one line each.
		 * @param array[]  $chunks Retrieved chunks.
		 */
		$live   = (array) apply_filters( 'kai_chatbot_live_facts', array(), $chunks );
		$prompt = Prompt_Builder::build( $message, $chunks, $history, $live );
		$result = Text_Provider::generate(
			$prompt,
			array(
				'max_tokens'  => 'detailed' === $settings['length'] ? 900 : 500,
				'temperature' => 0.2,
			)
		);

		if ( is_array( $result ) ) {
			Usage::log( 'chat', $result['provider'], $result['model'], $result['tokens_in'], $result['tokens_out'], 'ok', $conv_id );

			list( $answer, $sources ) = self::link_citations( $result['content'], $chunks, (bool) $settings['citations'] );

			return self::finish( $conv_id, $message, $answer, $sources, 'grounded', $confidence, $result, $started );
		}

		if ( is_wp_error( $result ) ) {
			Usage::log( 'chat', (string) Text_Provider::slug(), '', 0, 0, 'error', $conv_id );

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Kriti AI Chatbot: provider failed, showing an extract. ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// No provider, or it failed: show the best passage instead of nothing.
		list( $answer, $sources ) = self::extract( $chunks, (bool) $settings['citations'] );

		return self::finish( $conv_id, $message, $answer, $sources, 'extract', $confidence, array(), $started );
	}

	/**
	 * Search, using the previous question too when this one is a short follow-up like "and the price?".
	 *
	 * @param string  $message Question.
	 * @param array[] $history Earlier turns.
	 * @return array
	 */
	private static function retrieve( $message, array $history ) {
		$query = $message;

		if ( count( Retriever::terms( $message ) ) < 3 ) {
			foreach ( array_reverse( $history ) as $turn ) {
				if ( 'user' === $turn['role'] ) {
					$query = $turn['content'] . ' ' . $message;
					break;
				}
			}
		}

		return Retriever::search( $query, (int) Settings::get( 'top_k' ) );
	}

	/**
	 * Text shown when the content has no answer.
	 *
	 * @return string
	 */
	private static function dont_know_text() {
		$text    = __( 'I could not find an answer to that in our content. Could you rephrase it, or ask about something else on this site?', 'kriti-ai-chatbot' );
		$contact = trim( (string) Settings::get( 'contact_text' ) );

		if ( 'suggest_contact' === Settings::get( 'if_uncertain' ) && '' !== $contact ) {
			$text = __( 'I could not find an answer to that in our content.', 'kriti-ai-chatbot' ) . ' ' . $contact;
		}

		return $text;
	}

	/**
	 * Turn the model's [n] markers (numbers of retrieved passages) into numbers of the sources list.
	 *
	 * Sources are only ever pages that were retrieved; a number the model made up is dropped. When the
	 * model cites nothing, the best match is shown, because the answer was built from the retrieved text.
	 *
	 * @param string  $answer    Model output.
	 * @param array[] $chunks    Retrieved chunks, in prompt order.
	 * @param bool    $citations Whether to show sources.
	 * @return array{0: string, 1: array}
	 */
	private static function link_citations( $answer, array $chunks, $citations ) {
		$answer  = trim( $answer );
		$sources = array();
		$by_doc  = array();
		$map     = array();

		foreach ( $chunks as $i => $chunk ) {
			$doc = $chunk['source_type'] . ':' . $chunk['source_id'];

			if ( ! isset( $by_doc[ $doc ] ) ) {
				$by_doc[ $doc ] = count( $by_doc ) + 1;
				$sources[]      = self::citation( $chunk );
			}

			$map[ $i + 1 ] = $by_doc[ $doc ];
		}

		if ( ! $citations ) {
			return array( trim( preg_replace( '/\s?\[\d{1,3}\]/', '', $answer ) ), array() );
		}

		$cited = array();
		$text  = preg_replace_callback(
			'/\s?\[(\d{1,3})\]/',
			static function ( $m ) use ( $map, &$cited ) {
				$n = (int) $m[1];

				if ( ! isset( $map[ $n ] ) ) {
					return '';
				}

				$cited[ $map[ $n ] ] = true;

				return ' [' . $map[ $n ] . ']';
			},
			$answer
		);

		// Only the pages the answer relied on, renumbered so [1], [2] match the list shown.
		$keep = $cited ? array_keys( $cited ) : array( 1 );
		sort( $keep );
		$shown    = array();
		$renumber = array();

		foreach ( $keep as $n ) {
			$shown[]          = $sources[ $n - 1 ];
			$renumber[ $n ]   = count( $shown );
		}

		$text = preg_replace_callback(
			'/\[(\d{1,3})\]/',
			static function ( $m ) use ( $renumber ) {
				return isset( $renumber[ (int) $m[1] ] ) ? '[' . $renumber[ (int) $m[1] ] . ']' : '';
			},
			$text
		);

		return array( trim( $text ), $shown );
	}

	/**
	 * Answer without a model: the opening of the best matching passage.
	 *
	 * @param array[] $chunks    Retrieved chunks.
	 * @param bool    $citations Whether to show sources.
	 * @return array{0: string, 1: array}
	 */
	private static function extract( array $chunks, $citations ) {
		$best    = $chunks[0];
		$snippet = trim( $best['content'] );

		if ( strlen( $snippet ) > 700 ) {
			$snippet = substr( $snippet, 0, 700 );
			$cut     = max( (int) strrpos( $snippet, '. ' ), (int) strrpos( $snippet, "\n" ) );
			$snippet = ( $cut > 300 ? substr( $snippet, 0, $cut + 1 ) : $snippet ) . '…';
		}

		/* translators: 1: page title, 2: excerpt of the page. */
		$text = sprintf( __( 'Here is what I found on “%1$s”:%2$s', 'kriti-ai-chatbot' ), $best['title'], "\n\n" . $snippet );

		return array( $text, $citations ? array( self::citation( $best ) ) : array() );
	}

	/**
	 * A citation. `source_ref` is internal (analytics); the public response only carries title and url.
	 *
	 * @param array $chunk Chunk.
	 * @return array
	 */
	private static function citation( array $chunk ) {
		return array(
			'title'      => $chunk['title'],
			'url'        => $chunk['url'],
			'source_ref' => $chunk['source_type'] . ':' . $chunk['source_id'],
		);
	}

	/**
	 * Store the answer and build the response.
	 *
	 * @param string $conv_id    Conversation id.
	 * @param string $question   The visitor's question.
	 * @param string $answer     Answer text.
	 * @param array  $sources    Citations.
	 * @param string $status     grounded|extract|unanswered.
	 * @param float  $confidence Evidence score.
	 * @param array  $result     Text_Provider result, or an empty array.
	 * @param float  $started    microtime at the start.
	 * @return array
	 */
	private static function finish( $conv_id, $question, $answer, array $sources, $status, $confidence, array $result, $started ) {
		$answered = 'unanswered' !== $status;
		$tokens   = array(
			isset( $result['tokens_in'] ) ? (int) $result['tokens_in'] : 0,
			isset( $result['tokens_out'] ) ? (int) $result['tokens_out'] : 0,
		);
		$latency  = (int) round( ( microtime( true ) - $started ) * 1000 );

		$message_id = Conversation_Store::add(
			$conv_id,
			'assistant',
			$answer,
			array(
				'question'      => $question,
				'citations'     => $sources,
				'confidence'    => $confidence,
				'answered'      => $answered,
				'mode'          => $status,
				'provider'      => isset( $result['provider'] ) ? $result['provider'] : '',
				'model'         => isset( $result['model'] ) ? $result['model'] : '',
				'input_tokens'  => $tokens[0],
				'output_tokens' => $tokens[1],
				'latency_ms'    => $latency,
			)
		);

		if ( ! $answered ) {
			Conversation_Store::log_event(
				'unanswered',
				$conv_id,
				array(
					'question'   => $question,
					'confidence' => round( (float) $confidence, 3 ),
				)
			);
		}

		return array(
			'conversation_id' => $conv_id,
			'message_id'      => $message_id,
			'answer'          => $answer,
			'answer_status'   => $status,
			'answered'        => $answered,
			'sources'         => $sources,
			'confidence'      => round( (float) $confidence, 3 ),
			'usage'           => array(
				'input_tokens'  => $tokens[0],
				'output_tokens' => $tokens[1],
			),
			'mode'            => $status,
			'latency_ms'      => $latency,
		);
	}
}
