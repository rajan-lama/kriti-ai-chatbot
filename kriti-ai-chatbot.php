<?php

/**
 * Plugin Name:       Kriti AI Chatbot
 * Description:       A chatbot that answers visitors' questions from your own content. It indexes your posts, pages and Easy Digital Downloads products, answers with citations, and says so when it does not know.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Kriti AI
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kriti-ai-chatbot
 * Domain Path:       /languages
 *
 * @package KritiAIChatbot
 */

defined('ABSPATH') || exit;

define('KAICHAT_VERSION', '1.0.0');
define('KAICHAT_FILE', __FILE__);
define('KAICHAT_DIR', plugin_dir_path(__FILE__));
define('KAICHAT_URL', plugin_dir_url(__FILE__));

require_once KAICHAT_DIR . 'includes/autoload.php';

register_activation_hook(__FILE__, array('KritiAIChatbot\\Install', 'activate'));
register_deactivation_hook(__FILE__, array('KritiAIChatbot\\Install', 'deactivate'));

add_action('plugins_loaded', array('KritiAIChatbot\\Plugin', 'boot'));

if (! function_exists('kai_chatbot_freemius_connector')) {
  // Create a helper function for easy SDK access.
  function kai_chatbot_freemius_connector()
  {
    global $kai_chatbot_freemius_connector;

    if (! isset($kai_chatbot_freemius_connector)) {
      // Include Freemius SDK.
      require_once dirname(__FILE__) . '/vendor/freemius/start.php';

      $kai_chatbot_freemius_connector = fs_dynamic_init(array(
        'id'                  => '44274',
        'slug'                => 'kriti-ai-chatbot',
        'type'                => 'plugin',
        'public_key'          => 'pk_7c3866c8ca62f7eae5d48b7b7466e',
        'is_premium'          => true,
        'premium_suffix'      => 'Pro',
        // If your plugin is a serviceware, set this option to false.
        'has_premium_version' => true,
        'has_addons'          => false,
        'has_paid_plans'      => true,
        'is_org_compliant'    => false,
        'menu'                => array(
          'slug'           => 'kriti-ai-chatbot',
          'first-path'     => 'admin.php?page=kriti-ai-chatbot',
          'support'        => false,
        ),
      ));
    }

    return $kai_chatbot_freemius_connector;
  }

  // Init Freemius.
  kai_chatbot_freemius_connector();
  // Freemius runs this on deletion, so there is deliberately no uninstall.php.
  kai_chatbot_freemius_connector()->add_action('after_uninstall', array('KritiAIChatbot\\Install', 'uninstall'));
  // Signal that SDK was initiated.
  do_action('kai_chatbot_freemius_connector_loaded');
}
