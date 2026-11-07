<?php
/**
 * Plugin bootstrap.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every part of the plugin together.
 */
final class Plugin {

	/**
	 * Start the plugin.
	 *
	 * @return void
	 */
	public static function boot() {
		load_plugin_textdomain( 'kriti-ai-chatbot', false, dirname( plugin_basename( KAICHAT_FILE ) ) . '/languages' );

		Install::maybe_upgrade();
		Capabilities::register();
		Sync_Manager::register();
		Retention::register();
		Rest_Controller::register();
		Widget::register();
		Integrations\EDD::register();

		if ( is_admin() ) {
			Admin::register();
		}
	}
}
