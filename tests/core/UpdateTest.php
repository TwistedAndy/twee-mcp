<?php

/**
 * Plugin updates from GitHub releases
 */
class UpdateTest extends TWCP_TestCase {

	/**
	 * GitHub API requests made by the test
	 *
	 * @var array
	 */
	private array $requests = [];

	public function tear_down(): void
	{
		delete_transient('twcp_update_release');
		remove_all_filters('pre_http_request');
		unset($_GET['force-check']);
		parent::tear_down();
	}

	/**
	 * Answer requests to the GitHub API with a release
	 *
	 * @param array $release
	 */
	private function mock_release(array $release): void
	{
		$this->requests = [];
		add_filter('pre_http_request', function($response, $args, $url) use ($release) {
			$this->requests[] = $url;

			return ['headers' => [], 'body' => wp_json_encode($release), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
		}, 10, 3);
	}


	/**
	 * The header sends update checks for this plugin to the update_plugins_github.com filter.
	 */
	public function test_header_routes_updates_to_github(): void
	{
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$uri = get_plugin_data(TWCP_PATH . 'index.php', false, false)['UpdateURI'];

		$this->assertSame('https://github.com/TwistedAndy/twee-mcp', $uri);
		$this->assertSame('github.com', wp_parse_url($uri, PHP_URL_HOST));
		$this->assertNotFalse(has_filter('update_plugins_github.com', 'twcp_update_check'));
	}

	/**
	 * The latest release is offered with its attached ZIP, and GitHub is asked once until the cache expires.
	 */
	public function test_release_offered_as_update(): void
	{
		$this->mock_release([
			'tag_name'  => 'v9.1.0',
			'html_url'  => 'https://github.com/TwistedAndy/twee-mcp/releases/tag/v9.1.0',
			'body_html' => '<p>Fixes</p>',
			'assets'    => [
				['name' => 'checksums.txt', 'browser_download_url' => 'https://example.com/checksums.txt'],
				['name' => 'twee-mcp-9.1.0.zip', 'browser_download_url' => 'https://example.com/twee-mcp-9.1.0.zip'],
			],
		]);
		$file = plugin_basename(TWCP_PATH . 'index.php');

		$update = twcp_update_check(false, [], $file);
		$this->assertSame('9.1.0', $update['version']);
		$this->assertSame('https://example.com/twee-mcp-9.1.0.zip', $update['package']);
		$this->assertSame(dirname($file), $update['slug']);

		twcp_update_check(false, [], $file);
		$this->assertCount(1, $this->requests);
		$this->assertStringContainsString('api.github.com/repos/TwistedAndy/twee-mcp/releases/latest', $this->requests[0]);

		// "Check again" skips the cache
		$_GET['force-check'] = '1';
		twcp_update_check(false, [], $file);
		$this->assertCount(2, $this->requests);

		$details = twcp_update_details(false, 'plugin_information', (object) ['slug' => dirname($file)]);
		$this->assertSame('9.1.0', $details->version);
		$this->assertSame('<p>Fixes</p>', $details->sections['changelog']);
	}

	/**
	 * A release without a plugin ZIP, or a failed request, offers nothing and is not asked for again at once.
	 */
	public function test_release_without_zip_is_ignored(): void
	{
		$this->mock_release(['tag_name' => 'v9.1.0', 'assets' => []]);
		$file = plugin_basename(TWCP_PATH . 'index.php');

		$this->assertFalse(twcp_update_check(false, [], $file));
		$this->assertFalse(twcp_update_check(false, [], $file));
		$this->assertCount(1, $this->requests);

		delete_transient('twcp_update_release');
		$this->mock_release(['message' => 'API rate limit exceeded']);
		$this->assertFalse(twcp_update_check(false, [], $file));
		$this->assertSame([], get_transient('twcp_update_release'));
	}

	/**
	 * Other plugins with a GitHub Update URI are left to their own filters.
	 */
	public function test_other_plugins_are_ignored(): void
	{
		$this->mock_release(['tag_name' => 'v9.1.0']);

		$this->assertFalse(twcp_update_check(false, [], 'other/other.php'));
		$this->assertSame('kept', twcp_update_details('kept', 'plugin_information', (object) ['slug' => 'other']));
		$this->assertSame([], $this->requests);
	}
}
