<?php
/**
 * Pexels Stock Image Provider
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


add_filter('twcp_image_providers', function(array $providers): array {
	$providers['pexels'] = [
		'name'        => 'Pexels',
		'description' => __('Curated authentic photography licensed under Pexels license.', 'twee-mcp'),
		'position'    => 30,
		'search'      => 'twcp_pexels_search',
		'key'         => [
			'label' => __('Pexels API Key', 'twee-mcp'),
			'url'   => 'https://www.pexels.com/join-consumer/',
			'link'  => __('Get a Pexels API Key', 'twee-mcp'),
			'help'  => __('Create or sign in to a Pexels account, request API access, and copy your API key into this field.', 'twee-mcp'),
		],
	];

	return $providers;
});


/**
 * Search Pexels photos, through the Instant Images proxy without a key
 *
 * @param string $query
 * @param array  $args per_page, page, orientation
 * @param string $key
 *
 * @return array
 */
function twcp_pexels_search(string $query, array $args, string $key): array
{
	$params = [
		'query'    => $query,
		'per_page' => min(50, $args['per_page']),
		'page'     => $args['page'],
	];
	if (in_array($args['orientation'], ['landscape', 'portrait', 'square'], true)) {
		$params['orientation'] = $args['orientation'];
	}

	if ($key) {
		$data = twcp_image_get(add_query_arg($params, 'https://api.pexels.com/v1/search'), ['Authorization' => $key]);
	} else {
		$data = twcp_image_proxy('pexels', $query, $args['page']);
	}

	$results = [];

	foreach ($data['photos'] ?? ($data['results'] ?? []) as $item) {
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
