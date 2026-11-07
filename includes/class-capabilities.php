<?php
/**
 * Capabilities. Roles are never hard-coded in checks; administrators just receive all of them.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Capability helper.
 */
final class Capabilities {

	const MANAGE    = 'kai_chatbot_manage';
	const KNOWLEDGE = 'kai_chatbot_knowledge';
	const ANALYTICS = 'kai_chatbot_analytics';

	/**
	 * All capabilities of the plugin.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::MANAGE, self::KNOWLEDGE, self::ANALYTICS );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		// Network administrators on multisite do not hold role capabilities.
		add_filter(
			'user_has_cap',
			static function ( $allcaps, $caps, $args, $user ) {
				if ( is_multisite() && is_super_admin( $user->ID ) ) {
					foreach ( Capabilities::all() as $cap ) {
						$allcaps[ $cap ] = true;
					}
				}
				return $allcaps;
			},
			10,
			4
		);
	}

	/**
	 * Give the administrator role every capability.
	 *
	 * @return void
	 */
	public static function grant_to_administrators() {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::all() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Remove the capabilities from every role.
	 *
	 * @return void
	 */
	public static function remove_from_all_roles() {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Whether the current user holds at least one capability of the plugin.
	 *
	 * @return string The first capability the user has, or an empty string.
	 */
	public static function any_for_current_user() {
		foreach ( array( self::ANALYTICS, self::KNOWLEDGE, self::MANAGE ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return $cap;
			}
		}

		return '';
	}
}
