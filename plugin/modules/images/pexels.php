<?php
/**
 * Pexels Stock Image Driver
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Search Pexels photos
 *
 * @param string $query
 * @param array  $args
 *
 * @return array
 */
function twcp_pexels_search(string $query, array $args = []): array
{
	$keys = twcp_image_keys();
	$api_key = $keys['pexels'];

	$per_page = isset($args['per_page']) ? min(50, max(1, (int) $args['per_page'])) : 10;
	$page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
	$orientation = !empty($args['orientation']) ? sanitize_key($args['orientation']) : '';

	$params = [
		'query'    => $query,
		'per_page' => $per_page,
		'page'     => $page,
	];
	if (in_array($orientation, ['landscape', 'portrait', 'square'], true)) {
		$params['orientation'] = $orientation;
	}

	if (!empty($api_key)) {
		$url = add_query_arg($params, 'https://api.pexels.com/v1/search');
		$response = wp_remote_get($url, [
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => [
				'Authorization' => $api_key,
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
		$url = add_query_arg($proxy_params, 'https://proxy.getinstantimages.com/api/pexels');
		$response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
	}

	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		return [];
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	$raw_items = $data['photos'] ?? ($data['results'] ?? []);
	$results = [];

	foreach ($raw_items as $item) {
		$alt = !empty($item['alt']) ? $item['alt'] : $query;
		$author = $item['photographer'] ?? 'Pexels Creator';
		$author_url = $item['photographer_url'] ?? 'https://pexels.com';

		$results[] = [
			'id'           => 'pexels_' . ($item['id'] ?? uniqid()),
			'provider'     => 'pexels',
			'title'        => ucfirst($alt),
			'alt'          => $alt,
			'caption'      => sprintf('Photo by %s on Pexels', $author),
			'url'          => $item['src']['large2x'] ?? ($item['src']['large'] ?? ($item['src']['original'] ?? '')),
			'download_url' => $item['src']['original'] ?? ($item['src']['large2x'] ?? ''),
			'thumb_url'    => $item['src']['medium'] ?? ($item['src']['small'] ?? ''),
			'width'        => $item['width'] ?? null,
			'height'       => $item['height'] ?? null,
			'author'       => $author,
			'author_url'   => $author_url,
			'source_url'   => $item['url'] ?? '',
			'license'      => 'Pexels License',
		];
	}

	return $results;
}
