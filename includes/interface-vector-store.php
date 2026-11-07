<?php
/**
 * Vector storage contract (spec section 12). The default is MySQL; a larger site can swap in
 * another store (Qdrant, for example) through the `kai_chatbot_vector_store` filter.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Vector store.
 */
interface Vector_Store {

	/**
	 * Store the vector of a chunk.
	 *
	 * @param int       $chunk_id Chunk id.
	 * @param float[]   $vector   Unit-length vector.
	 * @param string    $provider Provider slug.
	 * @param string    $model    Model name.
	 * @return bool
	 */
	public function upsert( $chunk_id, array $vector, $provider, $model );

	/**
	 * The chunks closest to a query vector.
	 *
	 * Only chunks of sources that are currently indexed are searched. Each result is a chunk id and a
	 * cosine similarity between -1 and 1.
	 *
	 * @param float[] $query_vector Unit-length query vector.
	 * @param string  $model        Only chunks embedded with this model are comparable.
	 * @param int     $limit        How many to return.
	 * @return array<array{chunk_id: int, score: float}> Best first.
	 */
	public function search( array $query_vector, $model, $limit );

	/**
	 * Remove the vectors of one source.
	 *
	 * @param int $source_id Source row id.
	 * @return void
	 */
	public function delete_by_source( $source_id );

	/**
	 * Whether the store works, and how much it holds.
	 *
	 * @return array{ok: bool, chunks: int, message: string}
	 */
	public function health_check();
}
