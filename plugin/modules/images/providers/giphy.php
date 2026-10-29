<?php
/**
 * Giphy Stock Image Provider
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


add_filter('twcp_image_providers', function(array $providers): array {
	$providers['giphy'] = [
		'name'        => 'Giphy',
		'description' => __('Short-form animated GIF search and stickers library.', 'twee-mcp'),
		'position'    => 60,
		'search'      => 'twcp_giphy_search',
		'all'         => false,
		'key'         => [
			'label' => __('Giphy API Key', 'twee-mcp'),
			'url'   => 'https://developers.giphy.com/',
			'link'  => __('Get a GIPHY API Key', 'twee-mcp'),
			'help'  => __('Sign in to GIPHY Developers, create an app with the API option, and copy its API key into this field.', 'twee-mcp'),
		],
	];

	return $providers;
});


/**
 * Search Giphy GIFs, which needs a key
 *
 * @param string $query
 * @param array  $args per_page, page, orientation
 * @param string $key
 *
 * @return array
 */
function twcp_giphy_search(string $query, array $args, string $key): array
{
	if (!$key) {
		return [];
	}

	$per_page = min(30, $args['per_page']);

	$data = twcp_image_get(add_query_arg([
		'api_key' => $key,
		'q'       => $query,
		'limit'   => $per_page,
		'offset'  => ($args['page'] - 1) * $per_page,
		'rating'  => 'g',
	], 'https://api.giphy.com/v1/gifs/search'));

	$results = [];

	foreach ($data['data'] ?? [] as $item) {
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
