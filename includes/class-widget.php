<?php
/**
 * The chat widget on the public site, and the shortcode and block that open it.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Widget. Loads one small script after the page, and nothing that blocks rendering.
 */
final class Widget {

	/**
	 * Set when a shortcode or block needs the widget on a page where it would otherwise not load.
	 *
	 * @var bool
	 */
	private static $forced = false;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'root' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_shortcode( 'kriti_ai_chatbot', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Whether the widget is shown on this request.
	 *
	 * @return bool
	 */
	public static function visible() {
		if ( is_admin() || is_feed() || is_embed() || ! Settings::get( 'enabled' ) ) {
			return false;
		}

		/**
		 * Show or hide the widget, for example on some pages only.
		 *
		 * @param bool $show Whether to show it.
		 */
		return self::$forced || (bool) apply_filters( 'kai_chatbot_show_widget', true );
	}

	/**
	 * Enqueue the assets.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::visible() || wp_script_is( 'kai-chatbot', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( 'kai-chatbot', KAICHAT_URL . 'assets/css/widget.css', array(), KAICHAT_VERSION );
		wp_enqueue_script(
			'kai-chatbot',
			KAICHAT_URL . 'assets/js/widget.js',
			array(),
			KAICHAT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$post   = get_queried_object();
		$public = Chat_Service::public_config();
		$cfg    = array(
			'endpoint'    => esc_url_raw( rest_url( Rest_Controller::NS . '/chat/message' ) ),
			'feedback'    => esc_url_raw( rest_url( Rest_Controller::NS . '/chat/feedback' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'title'       => $public['title'],
			'greeting'    => $public['greeting'],
			'placeholder' => $public['placeholder'],
			'color'       => $public['color'],
			'position'    => $public['position'],
			'maxChars'    => $public['max_chars'],
			'sources'     => $public['citations'],
			'prompts'     => $public['prompts'],
			'store'       => Conversation_Store::enabled(),
			'context'     => array(
				'post_id'   => $post instanceof \WP_Post ? (int) $post->ID : 0,
				'post_type' => $post instanceof \WP_Post ? $post->post_type : '',
			),
			'i18n'        => array(
				'open'       => __( 'Open chat', 'kriti-ai-chatbot' ),
				'close'      => __( 'Close chat', 'kriti-ai-chatbot' ),
				'send'       => __( 'Send', 'kriti-ai-chatbot' ),
				'reset'      => __( 'Start a new conversation', 'kriti-ai-chatbot' ),
				'thinking'   => __( 'Thinking…', 'kriti-ai-chatbot' ),
				'sources'    => __( 'Sources', 'kriti-ai-chatbot' ),
				'retry'      => __( 'Try again', 'kriti-ai-chatbot' ),
				'you'        => __( 'You', 'kriti-ai-chatbot' ),
				'bot'        => __( 'Assistant', 'kriti-ai-chatbot' ),
				'error'      => __( 'Something went wrong. Please try again.', 'kriti-ai-chatbot' ),
				'label'      => __( 'Your question', 'kriti-ai-chatbot' ),
				'helpful'    => __( 'This answer helped', 'kriti-ai-chatbot' ),
				'notHelpful' => __( 'This answer did not help', 'kriti-ai-chatbot' ),
				'thanks'     => __( 'Thanks for the feedback.', 'kriti-ai-chatbot' ),
				'suggested'  => __( 'Suggested questions', 'kriti-ai-chatbot' ),
			),
		);

		wp_add_inline_script( 'kai-chatbot', 'window.KritiAIChatbot = ' . wp_json_encode( $cfg ) . ';', 'before' );
	}

	/**
	 * Mount point. The script builds the widget inside it.
	 *
	 * @return void
	 */
	public static function root() {
		if ( self::visible() ) {
			echo '<div id="kai-chatbot-root"></div>';
		}
	}

	/**
	 * A button that opens the chat: [kriti_ai_chatbot label="Ask a question"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'label' => __( 'Chat with us', 'kriti-ai-chatbot' ) ),
			is_array( $atts ) ? $atts : array(),
			'kriti_ai_chatbot'
		);

		return self::open_button( $atts['label'] );
	}

	/**
	 * Register the block. The editor script is plain JavaScript, so there is no build step.
	 *
	 * @return void
	 */
	public static function register_block() {
		wp_register_script( 'kai-chatbot-block', KAICHAT_URL . 'assets/js/block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ), KAICHAT_VERSION, true );

		register_block_type(
			'kriti-ai-chatbot/open-button',
			array(
				'api_version'     => 3,
				'title'           => __( 'Chatbot button', 'kriti-ai-chatbot' ),
				'description'     => __( 'A button that opens the Kriti AI chatbot.', 'kriti-ai-chatbot' ),
				'category'        => 'widgets',
				'icon'            => 'format-chat',
				'editor_script'   => 'kai-chatbot-block',
				'attributes'      => array(
					'label' => array(
						'type'    => 'string',
						'default' => __( 'Chat with us', 'kriti-ai-chatbot' ),
					),
				),
				'render_callback' => static function ( $attributes ) {
					return self::open_button( isset( $attributes['label'] ) ? (string) $attributes['label'] : '' );
				},
			)
		);
	}

	/**
	 * Markup of the opening button. The widget script listens for clicks on `.kai-chat-open`.
	 *
	 * @param string $label Button text.
	 * @return string Empty when the chatbot is switched off.
	 */
	private static function open_button( $label ) {
		if ( ! Settings::get( 'enabled' ) ) {
			return '';
		}

		self::$forced = true;
		self::enqueue();

		return '<button type="button" class="kai-chat-open wp-element-button">' . esc_html( wp_strip_all_tags( $label ) ) . '</button>';
	}
}
