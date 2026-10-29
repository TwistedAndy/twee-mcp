<?php

/**
 * Stock image provider tests
 */
class ImageTest extends TWCP_TestCase {

	public function tear_down(): void
	{
		remove_all_filters('pre_http_request');
		delete_option('twcp_stock_api_keys');
		parent::tear_down();
	}

	/**
	 * Hosts asked for, each answered with one image in the format of its provider
	 *
	 * @return ArrayObject
	 */
	private function mock_providers(): ArrayObject
	{
		$hosts = new ArrayObject();
		$replies = [
			'api.openverse.org'          => ['results' => [['id' => 'o1', 'url' => 'https://example.com/o.jpg']]],
			'commons.wikimedia.org'      => ['query' => ['pages' => [['pageid' => 7, 'title' => 'File:W.jpg', 'imageinfo' => [['url' => 'https://example.com/w.jpg']]]]]],
			'api.pexels.com'             => ['photos' => [['id' => 'p1', 'src' => ['original' => 'https://example.com/p.jpg']]]],
			'proxy.getinstantimages.com' => ['results' => []],
			'api.giphy.com'              => ['data' => [['id' => 'g1', 'images' => ['original' => ['url' => 'https://example.com/g.gif']]]]],
		];

		add_filter('pre_http_request', function($response, $args, $url) use ($hosts, $replies) {
			$host = parse_url($url, PHP_URL_HOST);
			$hosts[] = $host;

			return ['response' => ['code' => 200], 'body' => wp_json_encode($replies[$host] ?? [])];
		}, 10, 3);

		return $hosts;
	}

	/**
	 * The providers register themselves in their order, and those with a key field have a key.
	 */
	public function test_providers(): void
	{
		$providers = twcp_image_providers();

		$this->assertSame(['unsplash', 'pixabay', 'pexels', 'openverse', 'wikimedia', 'giphy'], array_keys($providers));
		$this->assertSame(['unsplash', 'pixabay', 'pexels', 'giphy'], array_keys(twcp_image_keys()));
		foreach ($providers as $info) {
			$this->assertIsCallable($info['search']);
		}

		$tool = twcp_tools_registry()['search_stock_images'];
		$this->assertStringContainsString('Wikimedia Commons, Giphy', $tool['description']);
	}

	/**
	 * A search of all providers skips those without their key and Giphy, and takes Wikimedia only when the others
	 * found too few images.
	 */
	public function test_search_all(): void
	{
		$hosts = $this->mock_providers();

		$found = twcp_image_search('red strap', ['per_page' => 2]);
		$this->assertSame(['api.openverse.org', 'commons.wikimedia.org'], $hosts->getArrayCopy());
		$this->assertSame(['openverse', 'wikimedia'], array_column($found['results'], 'provider'));

		update_option('twcp_stock_api_keys', ['pexels' => 'pexels-key', 'giphy' => 'giphy-key']);
		$hosts->exchangeArray([]);

		$found = twcp_image_search('red strap', ['per_page' => 2]);
		$this->assertSame(['api.pexels.com', 'api.openverse.org'], $hosts->getArrayCopy());
		$this->assertSame(['pexels', 'openverse'], array_column($found['results'], 'provider'));
		$this->assertTrue($found['configured_keys']['pexels']);
		$this->assertFalse($found['configured_keys']['unsplash']);

		$found = twcp_image_search('red strap', ['provider' => 'giphy']);
		$this->assertSame('giphy', $found['results'][0]['provider']);
		$this->assertArrayHasKey('error', twcp_image_search('red strap', ['provider' => 'nope']));
	}

	/**
	 * A provider added with the filter is searched, listed on the screen, and gets a key field.
	 */
	public function test_custom_provider(): void
	{
		$filter = function(array $providers): array {
			$providers['custom'] = [
				'name'     => 'Custom Photos',
				'position' => 5,
				'search'   => function(string $query, array $args, string $key): array {
					return [['provider' => 'custom', 'title' => $query . ' ' . $args['per_page'] . ' ' . $key]];
				},
				'key'      => ['label' => 'Custom Key', 'url' => 'https://example.com', 'link' => 'Get one'],
			];

			return $providers;
		};
		add_filter('twcp_image_providers', $filter);
		update_option('twcp_stock_api_keys', ['custom' => 'secret']);
		$this->mock_providers();

		$this->assertSame('custom', array_key_first(twcp_image_providers()));
		$this->assertSame('red 4 secret', twcp_image_search('red', ['provider' => 'custom', 'per_page' => 4])['results'][0]['title']);

		$html = twcp_template('images', twcp_admin_data('images'));
		$this->assertStringContainsString('name="twcp_stock_api_keys[custom]"', $html);
		$this->assertStringContainsString('<option value="custom">Custom Photos</option>', $html);

		remove_filter('twcp_image_providers', $filter);
	}
}
