<?php
/**
 * Plugin Name:       Twee MCP
 * Plugin URI:        https://www.linkedin.com/in/toniievych/
 * Description:       Model Context Protocol (MCP) server for WordPress with OAuth 2.1, AI model connectivity, and batch content helpers.
 * Version:           1.0.0
 * Author:            Twee
 * Author URI:        https://www.linkedin.com/in/toniievych/
 * License:           MIT
 * Text Domain:       twee-mcp
 * Domain Path:       /languages
 */

defined('ABSPATH') or exit;

define('TWCP_VERSION', '1.0.0');
define('TWCP_PATH', plugin_dir_path(__FILE__));
define('TWCP_URL', plugin_dir_url(__FILE__));
define('TWCP_PROTOCOL', '2025-06-18');
define('TWCP_CAPABILITY', 'edit_posts');
define('TWCP_ACCESS_TTL', HOUR_IN_SECONDS);
define('TWCP_REFRESH_TTL', 90 * DAY_IN_SECONDS);
define('TWCP_CODE_TTL', 5 * MINUTE_IN_SECONDS);
define('TWCP_REDIRECT_HOSTS', "claude.ai\nclaude.com\nanthropic.com\nchatgpt.com\nopenai.com");


/**
 * Check if MCP server functionality is globally enabled
 *
 * @return bool
 */
function twcp_is_enabled(): bool
{
	return (bool) get_option('twcp_enabled', 1);
}


/**
 * Render a template part with specified data (tw_app_template pattern)
 *
 * @param string $template Template name in parts/ folder
 * @param array  $data     Data to extract into template scope
 *
 * @return string Rendered HTML content
 */
function twcp_template(string $template, array $data = []): string
{
	$start_level = ob_get_level();
	ob_start();

	$file = TWCP_PATH . 'parts/' . $template . '.php';

	if (!empty($data)) {
		extract($data, EXTR_SKIP);
	}

	if (file_exists($file)) {
		include $file;
	}

	$content = ob_get_clean();

	while (ob_get_level() > $start_level) {
		ob_end_clean();
	}

	return $content;
}


// Load modular libraries
require_once TWCP_PATH . 'includes/model.php';
require_once TWCP_PATH . 'includes/chatgpt.php';
require_once TWCP_PATH . 'includes/oauth.php';
require_once TWCP_PATH . 'includes/image.php';
require_once TWCP_PATH . 'includes/tools/media.php';
require_once TWCP_PATH . 'includes/tools/post.php';
require_once TWCP_PATH . 'includes/tools/term.php';
require_once TWCP_PATH . 'includes/tools/block.php';
require_once TWCP_PATH . 'includes/tools/product.php';
require_once TWCP_PATH . 'includes/tools/field.php';
require_once TWCP_PATH . 'includes/tools/comment.php';
require_once TWCP_PATH . 'includes/tools/site.php';
require_once TWCP_PATH . 'includes/tools/order.php';
require_once TWCP_PATH . 'includes/tools.php';
require_once TWCP_PATH . 'includes/batch.php';
require_once TWCP_PATH . 'includes/admin.php';
