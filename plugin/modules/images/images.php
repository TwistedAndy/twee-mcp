<?php
/**
 * Twee MCP — Stock images
 *
 * Searches free stock photo providers, with the search_stock_images tool, the API keys and a search screen in the
 * Library. Found images are imported with import_media_image of the Media Library tools.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Stock image providers, which each file in the providers folder adds with the twcp_image_providers filter
 *
 * @return array Provider => [
 *     name        string
 *     description string   Optional
 *     position    int      Order on the screen and in searches of all providers
 *     search      callable Gets the query, [ per_page, page, orientation ], and the key, and returns the images
 *     key         array    [ label, url, link, help ] of its API key field, when it takes one; help is optional
 *     all         bool|string Whether a search of all providers includes it, true by default, or "fallback" to
 *                          include it only when the others found too few. A provider with a key joins only with one.
 * ]
 */
function twcp_image_providers(): array
{
	return twcp_position_sort((array) apply_filters('twcp_image_providers', []));
}


/**
 * API keys of the providers that take one: the saved key, then the constant or setting of the Instant Images plugin
 *
 * @return array Provider => key, empty when it has none
 */
function twcp_image_keys(): array
{
	$instant_api = (array) get_option('instant_img_api_settings', []);
	$twcp_keys = (array) get_option('twcp_stock_api_keys', []);

	$keys = [];
	foreach (twcp_image_providers() as $provider => $info) {
		if (empty($info['key'])) {
			continue;
		}
		$constant = 'INSTANT_IMAGES_' . strtoupper($provider) . '_KEY';
		$keys[$provider] = trim((string) ($twcp_keys[$provider] ?? ''));
		if ($keys[$provider] === '') {
			$keys[$provider] = defined($constant) ? trim((string) constant($constant)) : '';
		}
		if ($keys[$provider] === '') {
			$keys[$provider] = trim((string) ($instant_api[$provider . '_api'] ?? ''));
		}
	}

	return $keys;
}


/**
 * Get the JSON reply of a provider API
 *
 * @param string $url
 * @param array  $headers
 *
 * @return array Empty when the request fails
 */
function twcp_image_get(string $url, array $headers = []): array
{
	$response = wp_remote_get($url, [
		'timeout' => 15,
		'headers' => $headers + [
				'User-Agent' => 'WordPress-Twee-MCP/1.0 (' . home_url() . ')',
				'Accept'     => 'application/json',
			],
	]);

	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		return [];
	}

	return (array) json_decode(wp_remote_retrieve_body($response), true);
}


/**
 * Search a provider through the Instant Images proxy, which needs no API key
 *
 * @param string $provider
 * @param string $query
 * @param int    $page
 * @param array  $params Extra parameters
 *
 * @return array
 */
function twcp_image_proxy(string $provider, string $query, int $page, array $params = []): array
{
	return twcp_image_get(add_query_arg($params + [
			'type'    => 'search',
			'term'    => $query,
			'page'    => $page,
			'version' => '7.2.0',
		], 'https://proxy.getinstantimages.com/api/' . $provider));
}


/**
 * Search one stock photo provider, or all of them with their results taken in turns
 *
 * @param string|array $query_or_args
 * @param array        $args
 *
 * @return array
 */
function twcp_image_search($query_or_args, array $args = []): array
{
	if (is_array($query_or_args)) {
		$args = $query_or_args;
		$query = !empty($args['query']) ? (string) $args['query'] : '';
	} else {
		$query = (string) $query_or_args;
	}

	$query = trim($query);
	if ($query === '') {
		return [
			'query'   => '',
			'total'   => 0,
			'results' => [],
		];
	}

	$provider = !empty($args['provider']) ? sanitize_key($args['provider']) : 'all';
	$per_page = isset($args['per_page']) ? min(50, max(1, (int) $args['per_page'])) : 10;
	$providers = twcp_image_providers();
	$keys = twcp_image_keys();
	if ($provider !== 'all' and !isset($providers[$provider])) {
		return ['error' => 'Unknown stock image provider.'];
	}
	$args = [
		'per_page'    => $per_page,
		'page'        => max(1, (int) ($args['page'] ?? 1)),
		'orientation' => ($args['orientation'] ?? '') === 'squarish' ? 'square' : sanitize_key($args['orientation'] ?? ''),
	];
	$cache_key = 'twcp_images_' . hash('sha256', wp_json_encode([$query, $provider, $args, $keys]));
	$cached = get_transient($cache_key);
	if (is_array($cached)) {
		return $cached;
	}

	$results = [];

	if ($provider !== 'all') {
		$results = call_user_func($providers[$provider]['search'], $query, $args, $keys[$provider] ?? '');
	} else {
		$sub_args = array_merge($args, ['per_page' => max(2, (int) ceil($per_page / 3))]);
		$p_results = [];
		foreach ($providers as $name => $info) {
			$all = $info['all'] ?? true;
			if (!$all or (!empty($info['key']) and empty($keys[$name])) or ($all === 'fallback' and array_sum(array_map('count', $p_results)) >= $per_page)) {
				continue;
			}

			$p_results[$name] = call_user_func($info['search'], $query, $sub_args, $keys[$name] ?? '');
		}

		$max_len = 0;
		foreach ($p_results as $items) {
			$max_len = max($max_len, count($items));
		}

		for ($i = 0; $i < $max_len; $i++) {
			foreach ($p_results as $items) {
				if (isset($items[$i])) {
					$results[] = $items[$i];
					if (count($results) >= $per_page) {
						break 2;
					}
				}
			}
		}
	}

	$result = [
		'query'           => $query,
		'provider'        => $provider,
		'page'            => $args['page'],
		'per_page'        => $per_page,
		'total'           => count($results),
		'count'           => count($results),
		'has_more'        => count($results) >= $per_page,
		'configured_keys' => array_map('boolval', $keys),
		'results'         => $results,
	];
	if ($results) {
		set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
	}

	return $result;
}


/**
 * Register the stock photo API keys
 */
add_action('admin_init', 'twcp_image_admin_init');

/**
 * Register the settings of the stock photo API keys
 *
 * @return void
 */
function twcp_image_admin_init(): void
{
	register_setting('twcp_images_group', 'twcp_stock_api_keys', [
		'type'              => 'array',
		'sanitize_callback' => function($input) {
			$input = is_array($input) ? $input : [];
			$clean = [];
			foreach (twcp_image_providers() as $provider => $info) {
				if (!empty($info['key'])) {
					$clean[$provider] = !empty($input[$provider]) ? sanitize_text_field($input[$provider]) : '';
				}
			}

			return $clean;
		},
		'default'           => [],
	]);
}


/**
 * Show the stock image providers and search on the Images tab
 */
add_filter('twcp_admin_tabs', function(array $tabs): array {
	$tabs['images'] = ['label' => __('Images', 'twee-mcp'), 'capability' => 'manage_options', 'position' => 35, 'parts' => ['images']];

	return $tabs;
});


add_filter('twcp_admin_data', function(array $data, string $part): array {
	if ($part !== 'images') {
		return $data;
	}

	return [
		'providers'      => twcp_image_providers(),
		'keys'           => twcp_image_keys(),
		'instant_active' => defined('INSTANT_IMAGES_VERSION') || class_exists('Instant_Images'),
	];
}, 10, 2);


/**
 * AJAX: Search stock images
 */
add_action('wp_ajax_twcp_search_stock_images', 'twcp_image_ajax_search');

/**
 * Search configured stock-image providers from WordPress admin.
 *
 * @return void Sends a JSON response.
 */
function twcp_image_ajax_search(): void
{
	twcp_admin_ajax();

	wp_send_json_success(twcp_image_search(isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '', [
		'provider'    => isset($_POST['provider']) ? sanitize_key($_POST['provider']) : 'all',
		'orientation' => isset($_POST['orientation']) ? sanitize_key($_POST['orientation']) : '',
		'per_page'    => isset($_POST['per_page']) ? min(60, max(1, (int) $_POST['per_page'])) : 12,
		'page'        => isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1,
	]));
}


/**
 * AJAX: Import a remote image into the media library
 */
add_action('wp_ajax_twcp_import_stock_image', 'twcp_image_ajax_import');

/**
 * Import a selected stock image into the Media Library.
 *
 * @return void Sends a JSON response.
 */
function twcp_image_ajax_import(): void
{
	twcp_admin_ajax('upload_files');

	$image = isset($_POST['image']) && is_array($_POST['image']) ? wp_unslash($_POST['image']) : [];

	$url = !empty($image['download_url']) ? esc_url_raw($image['download_url']) : (!empty($image['url']) ? esc_url_raw($image['url']) : (isset($_POST['url']) ? esc_url_raw($_POST['url']) : ''));

	$meta = [
		'title'      => !empty($image['title']) ? sanitize_text_field($image['title']) : (isset($_POST['title']) ? sanitize_text_field($_POST['title']) : ''),
		'provider'   => !empty($image['provider']) ? sanitize_key($image['provider']) : (isset($_POST['provider']) ? sanitize_key($_POST['provider']) : ''),
		'author'     => !empty($image['author']) ? sanitize_text_field($image['author']) : (!empty($image['author_name']) ? sanitize_text_field($image['author_name']) : ''),
		'author_url' => !empty($image['author_url']) ? esc_url_raw($image['author_url']) : '',
		'source_url' => !empty($image['source_url']) ? esc_url_raw($image['source_url']) : '',
		'license'    => !empty($image['license']) ? sanitize_text_field($image['license']) : '',
		'caption'    => !empty($image['caption']) ? sanitize_textarea_field($image['caption']) : '',
		'alt'        => !empty($image['alt']) ? sanitize_text_field($image['alt']) : '',
	];

	$result = twcp_media_import($url, $meta);

	if (!empty($result['error'])) {
		wp_send_json_error(['message' => $result['error']]);
	}

	wp_send_json_success($result);
}


/**
 * Import a stock photo as the featured image of a post when the Media Library has none
 */
add_filter('twcp_post_featured_image', function(int $image_id, string $query, WP_Post $post): int {
	if ($image_id) {
		return $image_id;
	}

	$stock_res = twcp_image_search($query, ['per_page' => 5]);
	if (empty($stock_res['results']) and str_word_count($query) > 3) {
		$stock_res = twcp_image_search(implode(' ', array_slice(explode(' ', $query), 0, 3)), ['per_page' => 5]);
	}

	$item = $stock_res['results'][0] ?? [];
	$url = !empty($item['download_url']) ? $item['download_url'] : ($item['url'] ?? '');
	if (!$url) {
		return 0;
	}

	$import = twcp_media_import($url, [
		'title'      => !empty($item['title']) ? $item['title'] : $post->post_title,
		'alt'        => !empty($item['alt']) ? $item['alt'] : $post->post_title,
		'provider'   => !empty($item['provider']) ? $item['provider'] : '',
		'author'     => !empty($item['author']) ? $item['author'] : ($item['author_name'] ?? ''),
		'author_url' => !empty($item['author_url']) ? $item['author_url'] : '',
		'source_url' => !empty($item['source_url']) ? $item['source_url'] : '',
		'license'    => !empty($item['license']) ? $item['license'] : '',
		'post_id'    => $post->ID,
	]);

	return (int) ($import['attachment_id'] ?? 0);
}, 10, 3);


/**
 * Load the stock image search on the Images tab
 */
add_action('admin_enqueue_scripts', function() {
	if (sanitize_key($_GET['page'] ?? '') !== twcp_admin_slug('images')) {
		return;
	}

	wp_enqueue_style('twcp_images', plugins_url('images.css', __FILE__), ['twcp_mcp'], (string) filemtime(__DIR__ . '/images.css'));
	wp_enqueue_script('twcp_images', plugins_url('images.js', __FILE__), ['twcp_mcp'], (string) filemtime(__DIR__ . '/images.js'), true);
});
