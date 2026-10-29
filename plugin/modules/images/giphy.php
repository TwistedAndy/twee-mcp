<?php
/**
 * Giphy Driver
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Search Giphy GIFs
 *
 * @param string $query
 * @param array  $args
 *
 * @return array
 */
function twcp_giphy_search(string $query, array $args = []): array
{
	$keys = twcp_image_keys();
	$api_key = $keys['giphy'];

	if (empty($api_key)) {
		return [];
	}

	$per_page = isset($args['per_page']) ? min(30, max(1, (int) $args['per_page'])) : 10;
	$page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
	$offset = ($page - 1) * $per_page;

	$url = add_query_arg([
		'api_key' => $api_key,
		'q'       => $query,
		'limit'   => $per_page,
		'offset'  => $offset,
		'rating'  => 'g',
	], 'https://api.giphy.com/v1/gifs/search');

	$response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		return [];
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	$raw_items = $data['data'] ?? [];
	$results = [];

	foreach ($raw_items as $item) {
		$title = !empty($item['title']) ? $item['title'] : $query;
		$author = !empty($item['username']) ? $item['username'] : 'Giphy Creator';

		$results[] = [
			'id'           => 'giphy_' . ($item['id'] ?? uniqid()),
			'provider'     => 'giphy',
			'title'        => $title,
			'alt'          => $title,
			'caption'      => sprintf('GIF by %s on Giphy', $author),
			'url'          => $item['images']['original']['url'] ?? '',
			'download_url' => $item['images']['original']['url'] ?? '',
			'thumb_url'    => $item['images']['fixed_height_small']['url'] ?? ($item['images']['preview_gif']['url'] ?? ''),
			'width'        => $item['images']['original']['width'] ?? null,
			'height'       => $item['images']['original']['height'] ?? null,
			'author'       => $author,
			'author_url'   => $item['url'] ?? '',
			'source_url'   => $item['url'] ?? '',
			'license'      => 'Giphy License',
		];
	}

	return $results;
}
