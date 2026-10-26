<?php
/**
 * Plugin updates from the GitHub releases of the repository in the Update URI header
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

add_filter('update_plugins_github.com', 'twcp_update_check', 10, 3);
add_filter('plugins_api', 'twcp_update_details', 10, 3);


/**
 * Latest release with a plugin ZIP attached, kept for 12 hours
 *
 * "Check again" on the Updates screen asks GitHub again.
 *
 * @return array Version, package, url and notes, or empty when there is no release to install
 */
function twcp_update_release(): array
{
	$release = empty($_GET['force-check']) ? get_transient('twcp_update_release') : false;
	if (is_array($release)) {
		return $release;
	}

	$response = wp_remote_get('https://api.github.com/repos/TwistedAndy/twee-mcp/releases/latest', [
		'timeout' => 10,
		'headers' => ['Accept' => 'application/vnd.github.html+json'],
	]);
	$data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

	// The source archives GitHub adds hold the whole repository, so only an attached ZIP can be installed
	$zip = current(array_filter($data['assets'] ?? [], fn($asset) => str_ends_with((string) ($asset['name'] ?? ''), '.zip')));

	$release = (!empty($data['tag_name']) and $zip) ? [
		'version' => ltrim($data['tag_name'], 'vV'),
		'package' => $zip['browser_download_url'],
		'url'     => $data['html_url'] ?? '',
		'notes'   => $data['body_html'] ?? '',
	] : [];

	// A failed request is kept too, so a GitHub outage or rate limit does not slow every admin page
	set_transient('twcp_update_release', $release, $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);

	return $release;
}


/**
 * Offer the latest GitHub release as an update of this plugin
 *
 * WordPress compares the versions and only lists the update when the release is newer.
 *
 * @param array|false $update
 * @param array       $plugin_data
 * @param string      $plugin_file
 *
 * @return array|false
 */
function twcp_update_check($update, array $plugin_data, string $plugin_file)
{
	// An update replaces the plugin folder, so a folder linked to a working copy is never updated
	if ($plugin_file !== plugin_basename(TWCP_PATH . 'index.php') or is_link(WP_PLUGIN_DIR . '/' . dirname($plugin_file))) {
		return $update;
	}

	$release = twcp_update_release();
	if (!$release) {
		return $update;
	}

	return [
		'slug'    => dirname($plugin_file),
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}


/**
 * Show the release notes in the "View version details" window
 *
 * @param false|object|array $result
 * @param string             $action
 * @param object             $args
 *
 * @return false|object|array
 */
function twcp_update_details($result, string $action, $args)
{
	if ($action !== 'plugin_information' or ($args->slug ?? '') !== dirname(plugin_basename(TWCP_PATH . 'index.php'))) {
		return $result;
	}

	$release = twcp_update_release();
	if (!$release) {
		return $result;
	}

	return (object) [
		'name'          => 'Twee MCP',
		'slug'          => $args->slug,
		'version'       => $release['version'],
		'homepage'      => $release['url'],
		'download_link' => $release['package'],
		'sections'      => ['changelog' => $release['notes']],
	];
}
