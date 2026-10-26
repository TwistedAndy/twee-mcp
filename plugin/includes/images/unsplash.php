<?php
/**
 * Unsplash Stock Image Driver
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Search Unsplash photos
 *
 * @param string $query
 * @param array  $args
 *
 * @return array
 */
function twcp_unsplash_search(string $query, array $args = []): array
{
	$keys = twcp_image_keys();
	$api_key = $keys['unsplash'];

	$per_page = isset($args['per_page']) ? min(30, max(1, (int) $args['per_page'])) : 10;
	$page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
	$orientation = !empty($args['orientation']) ? sanitize_key($args['orientation']) : '';
	$orientation = $orientation === 'square' ? 'squarish' : $orientation;

	$params = [
		'query'    => $query,
		'page'     => $page,
		'per_page' => $per_page,
	];
	if (in_array($orientation, ['landscape', 'portrait', 'squarish'], true)) {
		$params['orientation'] = $orientation;
	}

	if (!empty($api_key)) {
		$url = add_query_arg($params, 'https://api.unsplash.com/search/photos');
		$response = wp_remote_get($url, [
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => [
				'Authorization' => 'Client-ID ' . $api_key,
				'Accept'        => 'application/json',
			],
		]);
	} else {
		$proxy_params = [
			'type'    => 'search',
			'term'    => $query,
			'page'    => $page,
			'version' => '7.2.0',
		];
		$url = add_query_arg($proxy_params, 'https://proxy.getinstantimages.com/api/unsplash');
		$response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
	}

	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		return [];
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	$raw_items = $data['results'] ?? [];
	$results = [];

	foreach ($raw_items as $item) {
		$desc = !empty($item['description']) ? $item['description'] : (!empty($item['alt_description']) ? $item['alt_description'] : $query);
		$author_name = $item['user']['name'] ?? 'Unsplash Creator';
		$author_link = $item['user']['links']['html'] ?? 'https://unsplash.com';

		$results[] = [
			'id'           => 'unsplash_' . ($item['id'] ?? uniqid()),
			'provider'     => 'unsplash',
			'title'        => ucfirst($desc),
			'alt'          => !empty($item['alt_description']) ? $item['alt_description'] : $desc,
			'caption'      => sprintf('Photo by %s on Unsplash', $author_name),
			'url'          => $item['urls']['regular'] ?? ($item['urls']['full'] ?? ''),
			'download_url' => $item['urls']['full'] ?? ($item['urls']['regular'] ?? ''),
			'thumb_url'    => $item['urls']['small'] ?? ($item['urls']['thumb'] ?? ''),
			'width'        => $item['width'] ?? null,
			'height'       => $item['height'] ?? null,
			'author'       => $author_name,
			'author_url'   => $author_link,
			'source_url'   => $item['links']['html'] ?? '',
			'license'      => 'Unsplash License',
		];
	}

	return $results;
}
