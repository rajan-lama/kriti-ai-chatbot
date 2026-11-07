<?php
/**
 * Retrieval: keyword search and vector search, fused, then checked against the live site.
 *
 * Keyword search is MySQL FULLTEXT re-ranked by how much of the question a passage covers. Vector search
 * runs when embeddings are available. The two ranked lists are combined with reciprocal rank fusion, so
 * neither scale (a keyword score, a cosine) has to be compared with the other.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Retriever.
 */
final class Retriever {

	const RRF_K = 60;

	/**
	 * Words that carry no meaning for a search.
	 *
	 * @return string[]
	 */
	private static function stopwords() {
		$words = 'a an and are as at be but by can could do does did for from had has have how i if in into is it its me my no not of on or our so than that the their them then there these they this those to us was we were what when where which who why will with would you your about any also get got just may might much must please should tell want';

		return (array) apply_filters( 'kai_chatbot_stopwords', explode( ' ', $words ) );
	}

	/**
	 * Meaningful words of a text, lower-cased.
	 *
	 * @param string $text Text.
	 * @return string[] Unique terms in order of appearance.
	 */
	public static function terms( $text ) {
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$stops = array_flip( self::stopwords() );
		$out   = array();
		$words = preg_split( '/[^\p{L}\p{N}]+/u', (string) wp_check_invalid_utf8( $text, true ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( is_array( $words ) ? $words : array() as $word ) {
			if ( isset( $stops[ $word ] ) || ( strlen( $word ) < 2 && ! ctype_digit( $word ) ) ) {
				continue;
			}

			$out[ $word ] = true;
		}

		return array_keys( $out );
	}

	/**
	 * Cheap stemming so "licenses" finds "license" and "websites" finds "website".
	 *
	 * @param string $word Word.
	 * @return string
	 */
	public static function stem( $word ) {
		$len = strlen( $word );

		if ( $len > 4 && 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}

		if ( $len > 4 && preg_match( '/(ches|shes|sses|xes)$/', $word ) ) {
			return substr( $word, 0, -2 );
		}

		if ( $len > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
			return substr( $word, 0, -1 );
		}

		return $word;
	}

	/**
	 * Find the passages that best answer a question.
	 *
	 * @param string $query Question (and, for a follow-up, the earlier one).
	 * @param int    $top_k How many passages to return.
	 * @return array {
	 *   chunks: array[], keyword: float, vector: float, mode: string
	 * } Each chunk has chunk_id, title, url, heading, content, source_type, source_id, post_type, chunk_index,
	 * score (keyword, 0 to 1) and vector_score (cosine, or null).
	 */
	public static function search( $query, $top_k ) {
		$terms  = self::terms( $query );
		$empty  = array(
			'chunks'  => array(),
			'keyword' => 0.0,
			'vector'  => 0.0,
			'mode'    => 'keyword',
		);
		$limit  = max( 20, $top_k * 6 );
		$by_id  = array();
		$kw     = array();
		$vector = array();
		$mode   = 'keyword';

		if ( $terms ) {
			foreach ( self::keyword_rank( $terms, $limit ) as $row ) {
				$by_id[ $row['chunk_id'] ] = $row;
				$kw[]                      = $row['chunk_id'];
			}
		}

		if ( Embedder::ready() ) {
			$embedded = Embedder::embed_query( $query );

			if ( is_wp_error( $embedded ) ) {
				// A provider problem never breaks chat: it falls back to keywords only.
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( 'Kriti AI Chatbot: query embedding failed, using keywords only. ' . $embedded->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} else {
				$mode = 'hybrid';

				foreach ( Embedder::store()->search( $embedded['vectors'][0], $embedded['model'], $limit ) as $hit ) {
					$vector[ $hit['chunk_id'] ] = $hit['score'];
				}
			}
		}

		if ( ! $kw && ! $vector ) {
			return array_merge( $empty, array( 'mode' => $mode ) );
		}

		$missing = array_diff( array_keys( $vector ), array_keys( $by_id ) );

		foreach ( self::rows_by_id( $missing ) as $row ) {
			$by_id[ $row['chunk_id'] ] = $row;
		}

		// Reciprocal rank fusion: each list adds 1 / (k + rank).
		$fused = array();

		foreach ( $kw as $rank => $chunk_id ) {
			$fused[ $chunk_id ] = ( isset( $fused[ $chunk_id ] ) ? $fused[ $chunk_id ] : 0 ) + 1 / ( self::RRF_K + $rank + 1 );
		}

		$rank = 0;
		arsort( $vector );

		foreach ( $vector as $chunk_id => $score ) {
			if ( isset( $by_id[ $chunk_id ] ) ) {
				$fused[ $chunk_id ] = ( isset( $fused[ $chunk_id ] ) ? $fused[ $chunk_id ] : 0 ) + 1 / ( self::RRF_K + ++$rank );
			}
		}

		arsort( $fused );

		$per_doc  = array();
		$eligible = array();
		$chosen   = array();

		foreach ( array_keys( $fused ) as $chunk_id ) {
			$row = $by_id[ $chunk_id ];
			$key = $row['source_type'] . ':' . $row['source_id'];

			// An indexed page may have been unpublished, protected or switched off since it was indexed.
			// The index is never trusted on its own: eligibility is checked again for every page about to be used.
			if ( ! isset( $eligible[ $key ] ) ) {
				$eligible[ $key ] = Sync_Manager::is_eligible( (int) $row['source_id'] );
			}

			if ( ! $eligible[ $key ] ) {
				continue;
			}

			// At most two passages per page, so one long page cannot crowd out the rest.
			if ( isset( $per_doc[ $key ] ) && $per_doc[ $key ] >= 2 ) {
				continue;
			}

			$per_doc[ $key ]       = isset( $per_doc[ $key ] ) ? $per_doc[ $key ] + 1 : 1;
			$row['vector_score']   = isset( $vector[ $chunk_id ] ) ? $vector[ $chunk_id ] : null;
			$chosen[]              = $row;

			if ( count( $chosen ) >= $top_k ) {
				break;
			}
		}

		$best_kw  = 0.0;
		$best_vec = 0.0;

		foreach ( $chosen as $row ) {
			$best_kw  = max( $best_kw, (float) $row['score'] );
			$best_vec = max( $best_vec, (float) $row['vector_score'] );
		}

		return array(
			'chunks'  => $chosen,
			'keyword' => $best_kw,
			'vector'  => $best_vec,
			'mode'    => $mode,
		);
	}

	/**
	 * Keyword candidates, best first, each with a score from 0 to 1.
	 *
	 * @param string[] $terms Terms.
	 * @param int      $limit Candidates.
	 * @return array[]
	 */
	private static function keyword_rank( array $terms, $limit ) {
		$stems = array_values( array_unique( array_map( array( __CLASS__, 'stem' ), $terms ) ) );
		$rows  = self::fulltext( $terms, $limit );

		if ( ! $rows ) {
			$rows = self::like( $stems, $limit );
		}

		if ( ! $rows ) {
			return array();
		}

		$max_ft = 0.0;

		foreach ( $rows as $row ) {
			$max_ft = max( $max_ft, (float) $row['ft'] );
		}

		foreach ( $rows as $i => $row ) {
			$haystack = array_flip( array_map( array( __CLASS__, 'stem' ), self::terms( $row['heading'] . ' ' . $row['content'] ) ) );
			$hit      = 0;

			foreach ( $stems as $stem ) {
				if ( isset( $haystack[ $stem ] ) ) {
					++$hit;
				}
			}

			$coverage          = $hit / count( $stems );
			$ft_share          = $max_ft > 0 ? (float) $row['ft'] / $max_ft : 0.0;
			$rows[ $i ]['score'] = round( 0.75 * $coverage + 0.25 * $ft_share, 4 );
			$rows[ $i ]['chunk_id'] = (int) $row['chunk_id'];
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return $rows;
	}

	/**
	 * The select list shared by every query: a chunk and the facts about its source.
	 *
	 * @return string
	 */
	private static function columns() {
		return 'c.id AS chunk_id, s.source_type, s.source_identifier AS source_id, s.post_type, c.chunk_index, s.title, s.canonical_url AS url, c.heading, c.content';
	}

	/**
	 * FULLTEXT search over chunks of sources that are indexed.
	 *
	 * @param string[] $terms Terms.
	 * @param int      $limit Candidates to fetch.
	 * @return array[]
	 */
	private static function fulltext( array $terms, $limit ) {
		global $wpdb;

		$chunks  = Install::table( 'chunks' );
		$sources = Install::table( 'sources' );
		$match   = implode( ' ', $terms );
		$cols    = self::columns();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT {$cols}, MATCH(c.heading, c.content) AGAINST (%s IN NATURAL LANGUAGE MODE) AS ft
				FROM {$chunks} c INNER JOIN {$sources} s ON s.id = c.source_id
				WHERE c.chatbot_id = %d AND s.source_status = 'indexed' AND MATCH(c.heading, c.content) AGAINST (%s IN NATURAL LANGUAGE MODE)
				ORDER BY ft DESC LIMIT %d",
				$match,
				Install::CHATBOT_ID,
				$match,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fallback for what FULLTEXT ignores (very short words, words in too many rows).
	 *
	 * @param string[] $stems Stems.
	 * @param int      $limit Candidates to fetch.
	 * @return array[]
	 */
	private static function like( array $stems, $limit ) {
		global $wpdb;

		$chunks  = Install::table( 'chunks' );
		$sources = Install::table( 'sources' );
		$where   = array();

		// Each fragment is prepared on its own, so no value is ever concatenated into the query.
		foreach ( array_slice( $stems, 0, 6 ) as $stem ) {
			$like    = '%' . $wpdb->esc_like( $stem ) . '%';
			$where[] = $wpdb->prepare( '(c.heading LIKE %s OR c.content LIKE %s)', $like, $like );
		}

		if ( ! $where ) {
			return array();
		}

		// Every value in $where went through $wpdb->prepare() above; table names and the limit are plugin-controlled.
		$sql = 'SELECT ' . self::columns() . ', 0 AS ft FROM ' . $chunks . ' c INNER JOIN ' . $sources . ' s ON s.id = c.source_id WHERE c.chatbot_id = ' . (int) Install::CHATBOT_ID . " AND s.source_status = 'indexed' AND (" . implode( ' OR ', $where ) . ') LIMIT ' . (int) $limit; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Chunk rows by id (for chunks that only the vector search found).
	 *
	 * @param int[] $ids Chunk ids.
	 * @return array[]
	 */
	private static function rows_by_id( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return array();
		}

		$chunks  = Install::table( 'chunks' );
		$sources = Install::table( 'sources' );
		$marks   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$cols    = self::columns();
		$sql     = $wpdb->prepare( "SELECT {$cols} FROM {$chunks} c INNER JOIN {$sources} s ON s.id = c.source_id WHERE s.source_status = 'indexed' AND c.id IN ({$marks})", $ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.UnfinishedPrepare
		$rows    = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out     = array();

		foreach ( (array) $rows as $row ) {
			$row['score']    = 0.0;
			$row['chunk_id'] = (int) $row['chunk_id'];
			$out[]           = $row;
		}

		return $out;
	}
}
