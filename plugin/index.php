<?php
/**
 * Plugin Name:       Twee MCP
 * Plugin URI:        https://www.linkedin.com/in/toniievych/
 * Description:       Model Context Protocol (MCP) server for WordPress with OAuth 2.1, AI model connectivity, and batch content helpers.
 * Version:           1.2.1
 * Author:            Twee
 * Author URI:        https://www.linkedin.com/in/toniievych/
 * License:           MIT
 * Text Domain:       twee-mcp
 * Domain Path:       /languages
 * Update URI:        https://github.com/TwistedAndy/twee-mcp
 */

defined('ABSPATH') or exit;

define('TWCP_VERSION', '1.2.1');
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
 * @param string $template Template name in the parts folder of the plugin or of a module
 * @param array  $data     Data to extract into template scope
 *
 * @return string Rendered HTML content
 */
function twcp_template(string $template, array $data = []): string
{
	$start_level = ob_get_level();
	ob_start();

	$file = TWCP_PATH . 'parts/' . $template . '.php';
	if (!file_exists($file)) {
		$file = (glob(TWCP_PATH . 'modules/*/parts/' . $template . '.php') ? : [''])[0];
	}

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


/**
 * Sort tabs, sections, or groups by their position, keeping the order of those with the same one
 *
 * @param array $items Key => [ position, ... ], 100 when it has none
 *
 * @return array
 */
function twcp_position_sort(array $items): array
{
	uasort($items, function(array $a, array $b): int {
		return ($a['position'] ?? 100) <=> ($b['position'] ?? 100);
	});

	return $items;
}


/**
 * Modules loaded from the modules folder, registered with the twcp_modules filter
 *
 * @return array Module => [ plugin, active ]: the plugin a module needs and whether it is running
 */
function twcp_modules(): array
{
	$modules = [];
	foreach ((array) apply_filters('twcp_modules', []) as $name => $module) {
		$modules[$name] = $module + ['plugin' => '', 'active' => true];
	}

	return $modules;
}


/**
 * Whether a module is there and the plugin it needs is running
 *
 * @param string $name
 *
 * @return bool
 */
function twcp_module_active(string $name): bool
{
	return !empty(twcp_modules()[$name]['active']);
}


/**
 * Module folders that can be switched off, all but wp, which the others build on
 *
 * @return array Folder => [ name, description ] from the header of its module.php
 */
function twcp_modules_found(): array
{
	$found = [];
	foreach (glob(TWCP_PATH . 'modules/*/module.php') as $file) {
		$folder = basename(dirname($file));
		if ($folder !== 'wp') {
			$found[$folder] = get_file_data($file, ['name' => 'Module', 'description' => 'Description']);
		}
	}

	return $found;
}


// Load modular libraries
require_once TWCP_PATH . 'includes/model.php';
require_once TWCP_PATH . 'includes/chatgpt.php';
require_once TWCP_PATH . 'includes/oauth.php';

// The module.php of every module in modules registers it and its tools and loads its files, and the module adds
// its settings and screens with hooks, so a module folder can be added or removed on its own. The wp module has the
// base tools the other modules build on. Modules switched off on the Agent Tools screen are not loaded.
foreach (glob(TWCP_PATH . 'modules/*/module.php') as $file) {
	if (!in_array(basename(dirname($file)), (array) get_option('twcp_disabled_modules', []), true)) {
		require_once $file;
	}
}

require_once TWCP_PATH . 'includes/tools.php';
require_once TWCP_PATH . 'includes/batch.php';
require_once TWCP_PATH . 'includes/admin.php';
require_once TWCP_PATH . 'includes/update.php';
