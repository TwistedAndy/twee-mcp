<?php
/**
 * Plugin Name:       Twee MCP
 * Plugin URI:        https://www.linkedin.com/in/toniievych/
 * Description:       Model Context Protocol (MCP) server for WordPress with OAuth 2.1, AI model connectivity, and batch content helpers.
 * Version:           1.3.0
 * Author:            Twee
 * Author URI:        https://www.linkedin.com/in/toniievych/
 * License:           MIT
 * Text Domain:       twee-mcp
 * Domain Path:       /languages
 * Update URI:        https://github.com/TwistedAndy/twee-mcp
 */

defined('ABSPATH') or exit;

define('TWCP_VERSION', '1.3.0');
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
	foreach (twcp_module_files() as $module) {
		if (file_exists($file)) {
			break;
		}
		$file = dirname($module) . '/parts/' . $template . '.php';
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
 * Folders with modules outside the plugin, added with the twcp_module_dirs filter, so a project keeps its own modules
 * in a theme or plugin and updates leave them alone
 *
 * @return string[]
 */
function twcp_module_dirs(): array
{
	return (array) apply_filters('twcp_module_dirs', []);
}


/**
 * The module.php of every module: wp first, as the others build on it, then those in the modules folder of the
 * plugin, then those in the folders of twcp_module_dirs(). A module name keeps the first folder found with it.
 *
 * @return array Module => path of its module.php
 */
function twcp_module_files(): array
{
	static $files;
	if (isset($files)) {
		return $files;
	}

	$files = ['wp' => TWCP_PATH . 'modules/wp/module.php'];
	foreach (array_merge([TWCP_PATH . 'modules'], twcp_module_dirs()) as $dir) {
		foreach (glob(trailingslashit($dir) . '*/module.php') ? : [] as $file) {
			$files[basename(dirname($file))] ??= $file;
		}
	}

	return $files;
}


/**
 * Modules that can be switched off, all but wp, which the others build on
 *
 * @return array Module => [ name, description ] from the header of its module.php
 */
function twcp_modules_found(): array
{
	$found = [];
	foreach (array_diff_key(twcp_module_files(), ['wp' => true]) as $name => $file) {
		$found[$name] = get_file_data($file, ['name' => 'Module', 'description' => 'Description']);
	}

	return $found;
}


/**
 * URL of a file of a module, in this plugin, another plugin, a must-use plugin, or a theme
 *
 * @param string $file Absolute path
 *
 * @return string
 */
function twcp_module_url(string $file): string
{
	$file = wp_normalize_path($file);
	$content = wp_normalize_path(WP_CONTENT_DIR) . '/';

	return str_starts_with($file, $content) ? content_url(substr($file, strlen($content))) : plugins_url(basename($file), $file);
}


// Load modular libraries
require_once TWCP_PATH . 'includes/model.php';
require_once TWCP_PATH . 'includes/chatgpt.php';
require_once TWCP_PATH . 'includes/oauth.php';

// The module.php of every module registers it and its tools and loads its files, and the module adds its settings
// and screens with hooks, so a module folder can be added or removed on its own. Modules load once the theme is set
// up, so plugins and the theme can add their folders, and before WP Activity Log registers its events at priority 0.
// The wp module has the base tools the other modules build on, so it is always loaded; the others can be switched off
// on the Agent Tools screen.
add_action('after_setup_theme', function() {
	$disabled = array_diff((array) get_option('twcp_disabled_modules', []), ['wp']);
	foreach (array_diff_key(twcp_module_files(), array_flip($disabled)) as $file) {
		require_once $file;
	}
}, -1);

require_once TWCP_PATH . 'includes/tools.php';
require_once TWCP_PATH . 'includes/batch.php';
require_once TWCP_PATH . 'includes/admin.php';
require_once TWCP_PATH . 'includes/update.php';
