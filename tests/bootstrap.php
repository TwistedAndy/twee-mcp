<?php
/**
 * PHPUnit Bootstrap for WordPress Integration Tests
 *
 * WooCommerce and ACF tests run when those plugins are found next to this one,
 * or in the folders set by the WOOCOMMERCE_DIR and ACF_DIR environment variables.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$wp_tests_dir = dirname(__DIR__) . '/vendor/wp-phpunit/wp-phpunit';

if (!defined('WP_TESTS_CONFIG_FILE_PATH')) {
	define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/config.php');
}

if (!file_exists($wp_tests_dir . '/includes/functions.php')) {
	echo "Error: Could not find WP Test Library at: $wp_tests_dir" . PHP_EOL;
	exit(1);
}

require_once $wp_tests_dir . '/includes/functions.php';

define('TWCP_TESTS_WOOCOMMERCE', rtrim(getenv('WOOCOMMERCE_DIR') ? : dirname(__DIR__, 2) . '/woocommerce', '/\\') . '/woocommerce.php');
define('TWCP_TESTS_ACF', rtrim(getenv('ACF_DIR') ? : dirname(__DIR__, 2) . '/advanced-custom-fields-pro', '/\\') . '/acf.php');

/**
 * Manually load the plugin environment.
 */
function _manually_load_plugin()
{
	if (is_file(TWCP_TESTS_WOOCOMMERCE)) {
		require_once TWCP_TESTS_WOOCOMMERCE;
	}
	if (is_file(TWCP_TESTS_ACF)) {
		require_once TWCP_TESTS_ACF;
	}

	require_once dirname(__DIR__) . '/plugin/index.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

/**
 * Create the WooCommerce tables, pages, and roles in the test database.
 */
function _manually_install_woocommerce()
{
	if (!class_exists('WC_Install')) {
		return;
	}

	WC_Install::install();

	$GLOBALS['wp_roles'] = null;
	wp_roles();
}

tests_add_filter('setup_theme', '_manually_install_woocommerce');

require $wp_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
