<?php
/**
 * Vectors in the chunks table, compared in PHP.
 *
 * This is the baseline that needs nothing but the WordPress database. It reads vectors in small pages
 * so memory stays flat, and it is meant for knowledge bases of a few thousand chunks. Past
 * MAX_SCAN chunks it stops and says so; that is the point to move to an external store.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL vector store.
 */
final class Mysql_Vector_Store implements Vector_Store {

	const MAX_SCAN = 20000;
	const PAGE     = 500;

	/**
	 * Pack a vector into 32-bit floats (little endian, the same on every host).
	 *
	 * @param float[] $vector Vector.
	 * @return string
	 */
	public static function pack( array $vector ) {
		return pack( 'g*', ...array_map( 'floatval', array_values( $vector ) ) );
	}

	/**
	 * Unpack a stored vector.
	 *
	 * @param string $blob Packed floats.
	 * @return float[]
	 */
	public static function unpack( $blob ) {
		$values = unpack( 'g*', (string) $blob );

		return is_array( $values ) ? array_values( $values ) : array();
	}

	/**
	 * Scale a vector to unit length, so a dot product is the cosine similarity.
	 *
	 * @param float[] $vector Vector.
	 * @return float[]
	 */
	public static function normalize( array $vector ) {
		$sum = 0.0;

		foreach ( $vector as $x ) {
			$sum += $x * $x;
		}

		if ( $sum <= 0.0 ) {
			return $vector;
		}

		$norm = sqrt( $sum );

		return array_map(
			static function ( $x ) use ( $norm ) {
				return $x / $norm;
			},
			$vector
		);
	}

	/**
	 * Store a vector on its chunk row.
	 *
	 * @param int     $chunk_id Chunk id.
	 * @param float[] $vector   Unit-length vector.
	 * @param string  $provider Provider slug.
	 * @param string  $model    Model.
	 * @return bool
	 */
	public function upsert( $chunk_id, array $vector, $provider, $model ) {
		global $wpdb;

		$ok = $wpdb->update(
			Install::table( 'chunks' ),
			array(
				'embedding'            => self::pack( $vector ),
				'embedding_provider'   => $provider,
				'embedding_model'      => $model,
				'embedding_dimensions' => count( $vector ),
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $chunk_id )
		);

		return false !== $ok;
	}

	/**
	 * Closest chunks by cosine similarity.
	 *
	 * @param float[] $query_vector Unit-length query vector.
	 * @param string  $model        Model the chunks must have been embedded with.
	 * @param int     $limit        How many.
	 * @return array
	 */
	public function search( array $query_vector, $model, $limit ) {
		global $wpdb;

		$chunks  = Install::table( 'chunks' );
		$sources = Install::table( 'sources' );
		$dims    = count( $query_vector );
		$best    = array();
		$last_id = 0;
		$scanned = 0;

		while ( $scanned < self::MAX_SCAN ) {
			// Only chunks of sources that are indexed right now: a source that failed or was removed is invisible at once.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"SELECT c.id, c.embedding FROM {$chunks} c INNER JOIN {$sources} s ON s.id = c.source_id
					WHERE c.id > %d AND c.chatbot_id = %d AND s.source_status = 'indexed' AND c.embedding_model = %s AND c.embedding_dimensions = %d AND c.embedding IS NOT NULL
					ORDER BY c.id LIMIT %d",
					$last_id,
					Install::CHATBOT_ID,
					$model,
					$dims,
					self::PAGE
				),
				ARRAY_A
			);

			if ( ! $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				$vector  = self::unpack( $row['embedding'] );

				if ( count( $vector ) !== $dims ) {
					continue;
				}

				$dot = 0.0;

				foreach ( $vector as $i => $x ) {
					$dot += $x * $query_vector[ $i ];
				}

				$best[ $last_id ] = $dot;
			}

			$scanned += count( $rows );

			// Keep only a few more than needed, so memory does not grow with the knowledge base.
			if ( count( $best ) > $limit * 4 ) {
				arsort( $best );
				$best = array_slice( $best, 0, $limit * 2, true );
			}
		}

		arsort( $best );
		$out = array();

		foreach ( array_slice( $best, 0, max( 1, (int) $limit ), true ) as $chunk_id => $score ) {
			$out[] = array(
				'chunk_id' => (int) $chunk_id,
				'score'    => round( (float) $score, 6 ),
			);
		}

		return $out;
	}

	/**
	 * Clear the vectors of a source's chunks.
	 *
	 * @param int $source_id Source row id.
	 * @return void
	 */
	public function delete_by_source( $source_id ) {
		global $wpdb;

		$chunks = Install::table( 'chunks' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$chunks} SET embedding = NULL, embedding_provider = '', embedding_model = '', embedding_dimensions = 0 WHERE source_id = %d", (int) $source_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Health.
	 *
	 * @return array
	 */
	public function health_check() {
		global $wpdb;

		$chunks = Install::table( 'chunks' );
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$chunks} WHERE chatbot_id = %d AND embedding IS NOT NULL", Install::CHATBOT_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $total > self::MAX_SCAN ) {
			return array(
				'ok'      => false,
				'chunks'  => $total,
				/* translators: %s: number of chunks. */
				'message' => sprintf( __( 'More than %s embedded passages. The built-in store compares them one by one; use an external vector store for a knowledge base this size.', 'kriti-ai-chatbot' ), number_format_i18n( self::MAX_SCAN ) ),
			);
		}

		return array(
			'ok'      => true,
			'chunks'  => $total,
			'message' => '',
		);
	}
}
