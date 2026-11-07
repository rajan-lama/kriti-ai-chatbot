<?php
/**
 * Activation, schema and scheduled events.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Database schema and scheduled events.
 *
 * Tables follow the Kriti AI convention `{prefix}kriti_ai_<plugin>_<table>`, where the prefix is the
 * one from wp-config.php.
 */
final class Install {

	const DB_VERSION = '2';

	/**
	 * The one chatbot of this version. Every table carries a chatbot_id so more can be added later
	 * without a migration.
	 */
	const CHATBOT_ID = 1;

	/**
	 * Table name with the WordPress prefix.
	 *
	 * @param string $name chatbots|sources|chunks|index_jobs|conversations|messages|learning_events|usage_logs.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'kriti_ai_chatbot_' . $name;
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::upgrade();
		Capabilities::grant_to_administrators();
		self::schedule_events();
	}

	/**
	 * Plugin deactivation. Knowledge and conversations stay in the database.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( array( Retention::HOOK, Sync_Manager::HOOK_RECON, Sync_Manager::HOOK_INDEX, Sync_Manager::HOOK_QUEUE ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Create or update the schema when its version changed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'kai_chatbot_db_version' ) !== self::DB_VERSION ) {
			self::upgrade();
			Capabilities::grant_to_administrators();
		}

		self::schedule_events();
	}

	/**
	 * Run the upgrade: keep old data, create the tables, make sure the chatbot exists.
	 *
	 * @return void
	 */
	private static function upgrade() {
		self::keep_version_one_tables();
		self::create_tables();
		self::ensure_chatbot();

		update_option( 'kai_chatbot_db_version', self::DB_VERSION, false );
	}

	/**
	 * Version 1 used `kai_chatbot_*` tables. They are renamed, never dropped, so no data is lost.
	 *
	 * @return void
	 */
	private static function keep_version_one_tables() {
		global $wpdb;

		foreach ( array( 'registry', 'chunks', 'conversations', 'messages' ) as $name ) {
			$old = $wpdb->prefix . 'kai_chatbot_' . $name;

			if ( $old === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) ) {
				$keep = $old . '_legacy_v1';

				// An earlier run may already have kept a table under this name; never overwrite or fail on it.
				if ( $keep === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $keep ) ) ) ) {
					$keep .= '_' . time();
				}

				$wpdb->query( "RENAME TABLE `{$old}` TO `{$keep}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	/**
	 * Daily jobs: conversation retention and reconciliation of the index.
	 *
	 * @return void
	 */
	private static function schedule_events() {
		if ( ! wp_next_scheduled( Retention::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Retention::HOOK );
		}

		if ( ! wp_next_scheduled( Sync_Manager::HOOK_RECON ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', Sync_Manager::HOOK_RECON );
		}
	}

	/**
	 * The chatbot row.
	 *
	 * @return void
	 */
	private static function ensure_chatbot() {
		global $wpdb;

		$table = self::table( 'chatbots' );

		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", self::CHATBOT_ID ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$now = current_time( 'mysql', true );

			$wpdb->insert(
				$table,
				array(
					'id'            => self::CHATBOT_ID,
					'name'          => __( 'Site assistant', 'kriti-ai-chatbot' ),
					'status'        => 'active',
					'settings_json' => '{}',
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}
	}

	/**
	 * Create the tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'chatbots' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'active',
			settings_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$c};"
		);

		// One row per piece of content: its identity, its hash, and how its last indexing went.
		dbDelta(
			'CREATE TABLE ' . self::table( 'sources' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chatbot_id bigint(20) unsigned NOT NULL DEFAULT 1,
			source_type varchar(32) NOT NULL,
			source_identifier varchar(100) NOT NULL,
			post_type varchar(40) NOT NULL DEFAULT '',
			title text NOT NULL,
			canonical_url text NOT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			source_version varchar(32) NOT NULL DEFAULT '',
			source_status varchar(12) NOT NULL DEFAULT 'indexed',
			language varchar(12) NOT NULL DEFAULT '',
			embedding_model varchar(100) NOT NULL DEFAULT '',
			last_seen_at datetime NULL,
			last_indexed_at datetime NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source (chatbot_id,source_type,source_identifier),
			KEY source_identifier (source_identifier),
			KEY source_status (source_status)
		) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'chunks' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			chatbot_id bigint(20) unsigned NOT NULL DEFAULT 1,
			chunk_key char(40) NOT NULL,
			chunk_index int(11) NOT NULL DEFAULT 0,
			heading text NOT NULL,
			content longtext NOT NULL,
			token_count int(11) NOT NULL DEFAULT 0,
			metadata_json text NULL,
			embedding_provider varchar(40) NOT NULL DEFAULT '',
			embedding_model varchar(100) NOT NULL DEFAULT '',
			embedding_dimensions int(11) NOT NULL DEFAULT 0,
			embedding longblob NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY chunk_key (chatbot_id,chunk_key),
			KEY source_id (source_id),
			FULLTEXT KEY search (heading,content)
		) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'index_jobs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chatbot_id bigint(20) unsigned NOT NULL DEFAULT 1,
			job_type varchar(12) NOT NULL,
			source_type varchar(32) NOT NULL DEFAULT '',
			source_identifier varchar(100) NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'pending',
			attempts int(11) NOT NULL DEFAULT 0,
			scheduled_at datetime NOT NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			error_code varchar(60) NOT NULL DEFAULT '',
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY due (status,scheduled_at),
			KEY job (job_type,source_identifier,status)
		) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'conversations' ) . " (
			id varchar(36) NOT NULL,
			chatbot_id bigint(20) unsigned NOT NULL DEFAULT 1,
			status varchar(12) NOT NULL DEFAULT 'open',
			locale varchar(12) NOT NULL DEFAULT '',
			page_url varchar(255) NOT NULL DEFAULT '',
			message_count int(11) NOT NULL DEFAULT 0,
			started_at datetime NOT NULL,
			last_message_at datetime NOT NULL,
			expires_at datetime NULL,
			PRIMARY KEY  (id),
			KEY last_message_at (last_message_at),
			KEY expires_at (expires_at)
		) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'messages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id varchar(36) NOT NULL,
			role varchar(12) NOT NULL,
			message_content longtext NOT NULL,
			question text NULL,
			citations_json longtext NULL,
			feedback varchar(12) NOT NULL DEFAULT '',
			confidence float NOT NULL DEFAULT 0,
			answered tinyint(1) NOT NULL DEFAULT 1,
			mode varchar(12) NOT NULL DEFAULT '',
			provider varchar(40) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			input_tokens int(11) NOT NULL DEFAULT 0,
			output_tokens int(11) NOT NULL DEFAULT 0,
			latency_ms int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at),
			KEY answered (role,answered)
		) {$c};"
		);

		// Unanswered questions and negative feedback: signals for the administrator, never trusted knowledge.
		dbDelta(
			'CREATE TABLE ' . self::table( 'learning_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chatbot_id bigint(20) unsigned NOT NULL DEFAULT 1,
			conversation_id varchar(36) NULL,
			event_type varchar(24) NOT NULL,
			event_payload_json longtext NULL,
			review_status varchar(12) NOT NULL DEFAULT 'new',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type,review_status),
			KEY created_at (created_at)
		) {$c};"
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'usage_logs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chatbot_id bigint(20) unsigned NOT NULL DEFAULT 1,
			conversation_id varchar(36) NULL,
			provider varchar(40) NOT NULL DEFAULT '',
			model varchar(100) NOT NULL DEFAULT '',
			operation varchar(24) NOT NULL,
			input_units int(11) NOT NULL DEFAULT 0,
			output_units int(11) NOT NULL DEFAULT 0,
			estimated_cost decimal(12,6) NOT NULL DEFAULT 0,
			status varchar(12) NOT NULL DEFAULT 'ok',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY operation (operation,created_at),
			KEY created_at (created_at)
		) {$c};"
		);
	}

	/**
	 * Plugin deletion, run by Freemius after uninstall (a plugin with an uninstall.php would
	 * bypass Freemius's own uninstall hook).
	 *
	 * By default nothing is removed, so deleting the plugin does not destroy the index or the
	 * conversations. Tick "Also delete the index, conversations and settings" in Settings first to
	 * remove everything.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$settings = get_option( 'kai_chatbot_settings', array() );

		if ( empty( $settings['delete_on_uninstall'] ) ) {
			return;
		}

		foreach ( self::table_names() as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( self::table( $name ) ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		foreach ( array( 'kai_chatbot_settings', 'kai_chatbot_db_version', 'kai_chatbot_sync_state' ) as $option ) {
			delete_option( $option );
		}

		delete_transient( 'kai_chatbot_sync_lock' );

		// Rate limit counters and cached query vectors.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_kai\\_chat\\_%' OR option_name LIKE '\\_transient\\_timeout\\_kai\\_chat\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		delete_post_meta_by_key( '_kai_chatbot_exclude' );

		self::deactivate();

		foreach ( array( Capabilities::MANAGE, Capabilities::KNOWLEDGE, Capabilities::ANALYTICS ) as $cap ) {
			foreach ( wp_roles()->role_objects as $role ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Names of all tables of the plugin.
	 *
	 * @return string[]
	 */
	public static function table_names() {
		return array( 'chatbots', 'sources', 'chunks', 'index_jobs', 'conversations', 'messages', 'learning_events', 'usage_logs' );
	}
}
