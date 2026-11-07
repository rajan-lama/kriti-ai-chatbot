<?php
/**
 * WordPress admin screens.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Admin: Dashboard, Knowledge Sources and Settings.
 */
final class Admin {

	const SLUG_DASHBOARD = 'kriti-ai-chatbot';
	const SLUG_SOURCES   = 'kriti-ai-chatbot-sources';
	const SLUG_SETTINGS  = 'kriti-ai-chatbot-settings';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_kai_chatbot_save_sources', array( __CLASS__, 'save_sources' ) );
		add_action( 'admin_post_kai_chatbot_clear_index', array( __CLASS__, 'clear_index' ) );
		add_action( 'admin_post_kai_chatbot_clear_conversations', array( __CLASS__, 'clear_conversations' ) );
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public static function menu() {
		$parent_cap = Capabilities::any_for_current_user();

		if ( '' === $parent_cap ) {
			return;
		}

		add_menu_page( __( 'Kriti AI Chatbot', 'kriti-ai-chatbot' ), __( 'Kriti Chatbot', 'kriti-ai-chatbot' ), $parent_cap, self::SLUG_DASHBOARD, array( __CLASS__, 'page_dashboard' ), 'dashicons-format-chat', 58 );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Dashboard', 'kriti-ai-chatbot' ), __( 'Dashboard', 'kriti-ai-chatbot' ), Capabilities::ANALYTICS, self::SLUG_DASHBOARD, array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Knowledge Sources', 'kriti-ai-chatbot' ), __( 'Knowledge Sources', 'kriti-ai-chatbot' ), Capabilities::KNOWLEDGE, self::SLUG_SOURCES, array( __CLASS__, 'page_sources' ) );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Settings', 'kriti-ai-chatbot' ), __( 'Settings', 'kriti-ai-chatbot' ), Capabilities::MANAGE, self::SLUG_SETTINGS, array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Register the settings. Sanitizing is in Settings::sanitize().
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'kai_chatbot',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'KritiAIChatbot\\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		// The settings capability, instead of manage_options.
		add_filter(
			'option_page_capability_kai_chatbot',
			static function () {
				return Capabilities::MANAGE;
			}
		);
	}

	/**
	 * Assets on the plugin's own screens.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'kriti-ai-chatbot' ) ) {
			return;
		}

		// The same fonts the Kriti AI plugin loads: Inter, and the Material Symbols icons.
		wp_enqueue_style(
			'kai-chatbot-fonts',
			add_query_arg( array( 'family' => 'Material+Symbols+Outlined:100,200,300,400,500,600,700,0,1|Inter:400,500,600,700' ), '//fonts.googleapis.com/css' ),
			array(),
			KAICHAT_VERSION
		);
		wp_enqueue_style( 'kai-chatbot-admin', KAICHAT_URL . 'assets/css/admin.css', array( 'kai-chatbot-fonts' ), KAICHAT_VERSION );

		if ( false !== strpos( (string) $hook, self::SLUG_SOURCES ) ) {
			wp_enqueue_script( 'kai-chatbot-admin', KAICHAT_URL . 'assets/js/admin.js', array(), KAICHAT_VERSION, true );
			wp_localize_script(
				'kai-chatbot-admin',
				'KAIAdmin',
				array(
					'root'  => esc_url_raw( rest_url( Rest_Controller::NS . '/admin/knowledge' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'i18n'  => array(
						'working'  => __( 'Working…', 'kriti-ai-chatbot' ),
						/* translators: 1: number done, 2: total. */
						'progress' => __( '%1$s of %2$s pages checked', 'kriti-ai-chatbot' ),
						/* translators: 1: new or changed pages, 2: unchanged pages, 3: errors. */
						'done'     => __( 'Done. %1$s new or changed, %2$s unchanged, %3$s errors.', 'kriti-ai-chatbot' ),
						/* translators: %s: number of jobs. */
						'retried'  => __( '%s failed jobs were put back in the queue.', 'kriti-ai-chatbot' ),
						'failed'   => __( 'That could not run.', 'kriti-ai-chatbot' ),
					),
				)
			);
		}
	}

	/**
	 * Notices: setup problems and the result of an action.
	 *
	 * @return void
	 */
	public static function notices() {
		$screen = get_current_screen();

		if ( ! $screen || false === strpos( (string) $screen->id, 'kriti-ai-chatbot' ) ) {
			return;
		}

		$messages = array(
			'sources_saved' => array( 'success', __( 'Knowledge sources saved. Press “Sync now” to update the index.', 'kriti-ai-chatbot' ) ),
			'index_cleared' => array( 'success', __( 'The index was deleted.', 'kriti-ai-chatbot' ) ),
			'conv_cleared'  => array( 'success', __( 'All conversations were deleted.', 'kriti-ai-chatbot' ) ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a fixed message.
		$key = isset( $_GET['kai_msg'] ) ? sanitize_key( wp_unslash( $_GET['kai_msg'] ) ) : '';

		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Shows an error text from the failed action.
		if ( isset( $_GET['kai_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['kai_error'] ) ) ) );
		}

		if ( ! Text_Provider::available() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The Kriti AI plugin is not active, so the chatbot cannot write answers or understand meaning. It searches by keywords and shows the best matching passage. Activate Kriti AI and add an AI provider.', 'kriti-ai-chatbot' )
			);
		} elseif ( in_array( Text_Provider::slug(), array( '', 'mock' ), true ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Kriti AI has no text provider set up yet, so the chatbot shows the best matching passage instead of a written answer. Add a provider under Kriti AI → Integrations.', 'kriti-ai-chatbot' )
			);
		}
	}

	/* ---------- pages ---------- */

	/**
	 * Dashboard.
	 *
	 * @return void
	 */
	public static function page_dashboard() {
		self::guard( Capabilities::ANALYTICS );

		$summary = Analytics::summary();

		require KAICHAT_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Knowledge sources.
	 *
	 * @return void
	 */
	public static function page_sources() {
		self::guard( Capabilities::KNOWLEDGE );

		$types      = self::source_types();
		$embedding  = Embedder::ready() ? Embedder::model() : '';
		$summary    = Sources::summary( $embedding );
		$chosen     = Sync_Manager::post_types();
		$state      = Sync_Manager::state();
		$jobs       = Jobs::counts();
		$failures   = Jobs::recent_failures( 5 );
		$errors     = Sources::recent_errors( 5 );
		$counts     = Knowledge_Store::counts();
		$store      = Embedder::store()->health_check();
		$provider   = Embedder::provider();
		$settings   = Settings::all();

		require KAICHAT_DIR . 'admin/views/sources.php';
	}

	/**
	 * Settings.
	 *
	 * @return void
	 */
	public static function page_settings() {
		self::guard( Capabilities::MANAGE );

		$s         = Settings::all();
		$providers = Text_Provider::choices();
		$embedders = Text_Provider::available() ? \KritiAI\Provider_Manager::enabled_slugs( 'embedding' ) : array();

		require KAICHAT_DIR . 'admin/views/settings.php';
	}

	/* ---------- actions ---------- */

	/**
	 * Save which content is used and which extra fields are read.
	 *
	 * @return void
	 */
	public static function save_sources() {
		self::guard( Capabilities::KNOWLEDGE, 'kai_chatbot_save_sources' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) ) : array();
		$fields     = array( 'post_types' => $post_types );

		foreach ( array( 'extra_meta_keys', 'exclude_ids', 'exclude_urls', 'exclude_terms' ) as $field ) {
			$fields[ $field ] = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		// phpcs:enable

		update_option( Settings::OPTION, Settings::sanitize( array_merge( Settings::all(), $fields ), false ) );
		Settings::flush();

		self::back( self::SLUG_SOURCES, 'sources_saved' );
	}

	/**
	 * Delete the whole index.
	 *
	 * @return void
	 */
	public static function clear_index() {
		self::guard( Capabilities::KNOWLEDGE, 'kai_chatbot_clear_index' );

		Sync_Manager::clear_index();

		self::back( self::SLUG_SOURCES, 'index_cleared' );
	}

	/**
	 * Delete every stored conversation.
	 *
	 * @return void
	 */
	public static function clear_conversations() {
		self::guard( Capabilities::MANAGE, 'kai_chatbot_clear_conversations' );

		Conversation_Store::delete_all();

		self::back( self::SLUG_SETTINGS, 'conv_cleared' );
	}

	/* ---------- helpers ---------- */

	/**
	 * Public post types that can feed the knowledge base.
	 *
	 * @return array<string,\WP_Post_Type>
	 */
	public static function source_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		return $types;
	}

	/**
	 * Stop unless the user may do this. For actions, the nonce is checked too.
	 *
	 * @param string      $cap    Capability.
	 * @param string|null $action Nonce action, for form posts.
	 * @return void
	 */
	private static function guard( $cap, $action = null ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'kriti-ai-chatbot' ), '', array( 'response' => 403 ) );
		}

		if ( $action ) {
			check_admin_referer( $action );
		}
	}

	/**
	 * Redirect back to a screen with a message.
	 *
	 * @param string $slug    Screen slug.
	 * @param string $message Message key.
	 * @param string $error   Error text.
	 * @return void
	 */
	private static function back( $slug, $message, $error = '' ) {
		$args = array( 'page' => $slug );

		if ( '' !== $message ) {
			$args['kai_msg'] = $message;
		}

		if ( '' !== $error ) {
			$args['kai_error'] = $error;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
