<?php
/**
 * Runs when the plugin is deleted.
 *
 * By default nothing is removed, so deleting the plugin does not destroy the index or the conversations.
 * Tick "Also delete the index, conversations and settings" in Settings first to remove everything.
 *
 * @package KritiAIChatbot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$kai_settings = get_option( 'kai_chatbot_settings', array() );

if ( empty( $kai_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'chatbots', 'sources', 'chunks', 'index_jobs', 'conversations', 'messages', 'learning_events', 'usage_logs' ) as $kai_name ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'kriti_ai_chatbot_' . $kai_name ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

foreach ( array( 'kai_chatbot_settings', 'kai_chatbot_db_version', 'kai_chatbot_sync_state' ) as $kai_option ) {
	delete_option( $kai_option );
}

delete_transient( 'kai_chatbot_sync_lock' );

// Rate limit counters and cached query vectors.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_kai\\_chat\\_%' OR option_name LIKE '\\_transient\\_timeout\\_kai\\_chat\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_post_meta_by_key( '_kai_chatbot_exclude' );

foreach ( array( 'kai_chatbot_retention', 'kai_chatbot_reconcile', 'kai_chatbot_index_post', 'kai_chatbot_process_queue' ) as $kai_hook ) {
	wp_clear_scheduled_hook( $kai_hook );
}

foreach ( array( 'kai_chatbot_manage', 'kai_chatbot_knowledge', 'kai_chatbot_analytics' ) as $kai_cap ) {
	foreach ( wp_roles()->role_objects as $kai_role ) {
		$kai_role->remove_cap( $kai_cap );
	}
}
