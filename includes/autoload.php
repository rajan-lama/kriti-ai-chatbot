<?php
/**
 * Class autoloader.
 *
 * KritiAIChatbot\Sync_Manager is includes/class-sync-manager.php, and a sub-namespace
 * maps to a folder: KritiAIChatbot\Integrations\EDD is includes/integrations/class-edd.php.
 *
 * @package KritiAIChatbot
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'KritiAIChatbot\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = strtolower( str_replace( '_', '-', array_pop( $parts ) ) );
		$dir   = KAICHAT_DIR . 'includes/';

		if ( $parts ) {
			$dir .= strtolower( implode( '/', $parts ) ) . '/';
		}

		foreach ( array( 'class-', 'interface-' ) as $kind ) {
			if ( is_readable( $dir . $kind . $name . '.php' ) ) {
				require_once $dir . $kind . $name . '.php';
				return;
			}
		}
	}
);
