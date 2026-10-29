<?php
/**
 * Unsplash Stock Image Provider
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


add_filter('twcp_image_providers', function(array $providers): array {
	$providers['unsplash'] = [
		'name'        => 'Unsplash',
		'description' => __('High-resolution photography with generous API limits.', 'twee-mcp'),
		'position'    => 10,
		'search'      => 'twcp_unsplash_search',
		'key'         => [
			'label' => __('Unsplash Access Key', 'twee-mcp'),
			'url'   => 'https://unsplash.com/developers',
			'link'  => __('Get an Unsplash Access Key', 'twee-mcp'),
			'help'  => __('Sign in, register a new application, and copy its Access Key (not the Secret Key) into this field.', 'twee-mcp'),
		],
	];

	return $providers;
});


/**
 * Search Unsplash photos, through the Instant Images proxy without a key
 *
 * @param string $query
 * @param array  $args per_page, page, orientation
 * @param string $key
 *
 * @return array
 */
function twcp_unsplash_search(string $query, array $args, string $key): array
{
	$orientation = $args['orientation'] === 'square' ? 'squarish' : $args['orientation'];

	$params = [
		'query'    => $query,
		'page'     => $args['page'],
		'per_page' => min(30, $args['per_page']),
	];
	if (in_array($orientation, ['landscape', 'portrait', 'squarish'], true)) {
		$params['orientation'] = $orientation;
	}

	if ($key) {
		$data = twcp_image_get(add_query_arg($params, 'https://api.unsplash.com/search/photos'), ['Authorization' => 'Client-ID ' . $key]);
	} else {
		$data = twcp_image_proxy('unsplash', $query, $args['page']);
	}

	$results = [];

	foreach ($data['results'] ?? [] as $item) {
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
