<?php
/**
 * Usage log: one line per AI request, without any visitor text.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Usage.
 */
final class Usage {

	/**
	 * Write a line.
	 *
	 * @param string      $operation       chat|embed_index|embed_query.
	 * @param string      $provider        Provider slug.
	 * @param string      $model           Model.
	 * @param int         $input           Input tokens.
	 * @param int         $output          Output tokens.
	 * @param string      $status          ok|error.
	 * @param string|null $conversation_id Conversation id.
	 * @return void
	 */
	public static function log( $operation, $provider, $model, $input, $output, $status = 'ok', $conversation_id = null ) {
		global $wpdb;

		$wpdb->insert(
			Install::table( 'usage_logs' ),
			array(
				'chatbot_id'      => Install::CHATBOT_ID,
				'conversation_id' => $conversation_id,
				'provider'        => substr( (string) $provider, 0, 40 ),
				'model'           => substr( (string) $model, 0, 100 ),
				'operation'       => $operation,
				'input_units'     => (int) $input,
				'output_units'    => (int) $output,
				'estimated_cost'  => 0, // Prices change; the plugin does not guess them.
				'status'          => $status,
				'created_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Delete lines older than a number of days.
	 *
	 * @param int $days Age.
	 * @return void
	 */
	public static function prune( $days = 90 ) {
		global $wpdb;

		$table = Install::table( 'usage_logs' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
