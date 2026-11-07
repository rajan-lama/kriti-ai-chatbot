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
