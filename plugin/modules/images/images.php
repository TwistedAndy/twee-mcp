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
 * Retrieve configured stock photo API keys
 *
 * @return array
 */
function twcp_image_keys(): array
{
	$instant_api = (array) get_option('instant_img_api_settings', []);
	$twcp_keys = (array) get_option('twcp_stock_api_keys', []);

	$keys = ['openverse' => '', 'wikimedia' => ''];
	foreach (['unsplash', 'pixabay', 'pexels', 'giphy'] as $provider) {
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
 * Unified Stock Photo Search across all providers
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
	$keys = twcp_image_keys();
	if ($provider !== 'all' and !array_key_exists($provider, $keys)) {
		return ['error' => 'Unknown stock image provider.'];
	}
	$args['per_page'] = $per_page;
	$args['page'] = max(1, (int) ($args['page'] ?? 1));
	$args['orientation'] = ($args['orientation'] ?? '') === 'squarish' ? 'square' : sanitize_key($args['orientation'] ?? '');
	$cache_key = 'twcp_images_' . hash('sha256', wp_json_encode([$query, $provider, $per_page, $args['page'], $args['orientation'], $keys]));
	$cached = get_transient($cache_key);
	if (is_array($cached)) {
		return $cached;
	}

	$results = [];

	if ($provider !== 'all') {
		$search = 'twcp_' . $provider . '_search';
		$results = $search($query, $args);
	} else {
		$sub_args = array_merge($args, ['per_page' => max(2, (int) ceil($per_page / 3))]);
		$p_results = [];
		foreach (['unsplash', 'pixabay', 'pexels'] as $name) {
			if (empty($keys[$name])) {
				continue;
			}

			$search = 'twcp_' . $name . '_search';
			$p_results[$name] = $search($query, $sub_args);
		}
		$p_results['openverse'] = twcp_openverse_search($query, $sub_args);

		$total_so_far = array_sum(array_map('count', $p_results));
		if ($total_so_far < $per_page) {
			$p_results['wikimedia'] = twcp_wikimedia_search($query, $sub_args);
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
		'configured_keys' => [
			'unsplash' => !empty($keys['unsplash']),
			'pixabay'  => !empty($keys['pixabay']),
			'pexels'   => !empty($keys['pexels']),
			'giphy'    => !empty($keys['giphy']),
		],
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
			foreach (['unsplash', 'pexels', 'pixabay', 'giphy'] as $provider) {
				$clean[$provider] = !empty($input[$provider]) ? sanitize_text_field($input[$provider]) : '';
			}

			return $clean;
		},
		'default'           => [],
	]);
}


/**
 * Show the stock image providers in the Library
 */
add_filter('twcp_admin_tabs', function(array $tabs): array {
	$tabs['library']['parts'][] = 'images';

	return $tabs;
}, 20);

add_filter('twcp_admin_data', function(array $data, string $part): array {
	if ($part !== 'images') {
		return $data;
	}

	return [
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
