<?php
/**
 * Writes a source and its chunks as one unit.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge store.
 */
final class Knowledge_Store {

	/**
	 * Replace everything stored for a source.
	 *
	 * Runs in a transaction: a failure leaves the previous version in place. Chunk keys are stable
	 * (source, position, content), so repeating the same job gives the same rows.
	 *
	 * @param array      $doc        Normalized document.
	 * @param array[]    $chunks     Chunks from Chunker::chunk().
	 * @param array|null $embeddings Result of Embedder::embed() for the chunks, or null.
	 * @param string     $warning    Non-fatal problem to record on the source.
	 * @return int|\WP_Error Source row id.
	 */
	public static function replace_source( array $doc, array $chunks, $embeddings, $warning = '' ) {
		global $wpdb;

		$table = Install::table( 'chunks' );
		$now   = current_time( 'mysql', true );
		$model = is_array( $embeddings ) ? $embeddings['model'] : '';

		$wpdb->query( 'START TRANSACTION' );

		$source_id = Sources::upsert_indexed( $doc, $model, $warning );

		if ( ! $source_id ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'kai_chatbot_store_failed', __( 'The source could not be saved.', 'kriti-ai-chatbot' ) );
		}

		$wpdb->delete( $table, array( 'source_id' => $source_id ) );

		// A chunk key is made from the source, the position and the text, so another row with the same key
		// can only be a stale leftover of this same page (for example after its source row was lost).
		$keys = array();

		foreach ( $chunks as $index => $chunk ) {
			$keys[] = sha1( $doc['source_type'] . ':' . $doc['source_id'] . ':' . $index . ':' . $chunk['content'] );
		}

		if ( $keys ) {
			$marks = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE chatbot_id = %d AND source_id <> %d AND chunk_key IN ({$marks})", array_merge( array( Install::CHATBOT_ID, $source_id ), $keys ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		foreach ( $chunks as $index => $chunk ) {
			$vector = is_array( $embeddings ) && isset( $embeddings['vectors'][ $index ] ) ? $embeddings['vectors'][ $index ] : null;

			$ok = $wpdb->insert(
				$table,
				array(
					'source_id'            => $source_id,
					'chatbot_id'           => Install::CHATBOT_ID,
					'chunk_key'            => sha1( $doc['source_type'] . ':' . $doc['source_id'] . ':' . $index . ':' . $chunk['content'] ),
					'chunk_index'          => $index,
					'heading'              => $chunk['heading'],
					'content'              => $chunk['content'],
					'token_count'          => (int) ceil( $chunk['words'] * 1.3 ),
					'metadata_json'        => wp_json_encode(
						array(
							'post_type' => $doc['post_type'],
							'modified'  => isset( $doc['metadata']['modified'] ) ? $doc['metadata']['modified'] : '',
							'language'  => $doc['language'],
						)
					),
					'embedding_provider'   => $vector ? $embeddings['provider'] : '',
					'embedding_model'      => $vector ? $model : '',
					'embedding_dimensions' => $vector ? count( $vector ) : 0,
					'embedding'            => $vector ? Mysql_Vector_Store::pack( $vector ) : null,
					'created_at'           => $now,
					'updated_at'           => $now,
				)
			);

			if ( false === $ok ) {
				$message = $wpdb->last_error;
				$wpdb->query( 'ROLLBACK' );

				return new \WP_Error( 'kai_chatbot_store_failed', '' !== $message ? $message : __( 'The chunks could not be stored.', 'kriti-ai-chatbot' ) );
			}
		}

		$wpdb->query( 'COMMIT' );

		return $source_id;
	}

	/**
	 * Remove a source and its chunks. The source row goes first, so it is gone from retrieval at once.
	 *
	 * @param string $source_type       Source type.
	 * @param string $source_identifier Source identifier.
	 * @return bool Whether anything was removed.
	 */
	public static function remove( $source_type, $source_identifier ) {
		global $wpdb;

		$source = Sources::get( $source_type, $source_identifier );

		if ( ! $source ) {
			return false;
		}

		$wpdb->delete( Install::table( 'sources' ), array( 'id' => (int) $source['id'] ) );
		$wpdb->delete( Install::table( 'chunks' ), array( 'source_id' => (int) $source['id'] ) );

		return true;
	}

	/**
	 * Delete chunks whose source no longer exists. They are invisible to search already (search joins on
	 * the source), so this only frees space; it runs with the daily reconciliation.
	 *
	 * @return int Chunks deleted.
	 */
	public static function delete_orphans() {
		global $wpdb;

		$chunks  = Install::table( 'chunks' );
		$sources = Install::table( 'sources' );

		return (int) $wpdb->query( "DELETE c FROM {$chunks} c LEFT JOIN {$sources} s ON s.id = c.source_id WHERE s.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Remove everything: sources, chunks and jobs.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;

		foreach ( array( 'chunks', 'sources' ) as $name ) {
			$table = Install::table( $name );
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		Jobs::clear();
	}

	/**
	 * How many chunks are stored and how many carry an embedding.
	 *
	 * @return array{chunks: int, embedded: int}
	 */
	public static function counts() {
		global $wpdb;

		$table = Install::table( 'chunks' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(embedding IS NOT NULL) AS e FROM {$table} WHERE chatbot_id = %d", Install::CHATBOT_ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'chunks'   => $row ? (int) $row['n'] : 0,
			'embedded' => $row ? (int) $row['e'] : 0,
		);
	}
}
