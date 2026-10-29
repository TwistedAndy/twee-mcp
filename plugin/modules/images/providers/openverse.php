<?php
/**
 * Openverse Stock Image Provider
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


add_filter('twcp_image_providers', function(array $providers): array {
	$providers['openverse'] = [
		'name'        => 'Openverse',
		'description' => __('WordPress Foundation search engine for 700M+ Creative Commons works (No API key required).', 'twee-mcp'),
		'position'    => 40,
		'search'      => 'twcp_openverse_search',
	];

	return $providers;
});


/**
 * Search Openverse photos, through the Instant Images proxy when the API fails
 *
 * @param string $query
 * @param array  $args per_page, page, orientation
 *
 * @return array
 */
function twcp_openverse_search(string $query, array $args): array
{
	$params = [
		'q'         => $query,
		'page_size' => min(30, $args['per_page']),
		'page'      => $args['page'],
	];
	$ratios = ['landscape' => 'wide', 'portrait' => 'tall', 'square' => 'square'];
	if (isset($ratios[$args['orientation']])) {
		$params['aspect_ratio'] = $ratios[$args['orientation']];
	}

	$data = twcp_image_get(add_query_arg($params, 'https://api.openverse.org/v1/images/'));
	if (!$data) {
		$data = twcp_image_proxy('openverse', $query, $args['page']);
	}

	$results = [];

	foreach ($data['results'] ?? [] as $item) {
		$title = !empty($item['title']) ? $item['title'] : $query;
		$author = $item['creator'] ?? ($item['user']['name'] ?? 'Creator');
		$license = strtoupper($item['license'] ?? 'CC');
		$img_url = $item['url'] ?? ($item['urls']['full'] ?? ($item['urls']['img'] ?? ''));

		$results[] = [
			'id'           => 'openverse_' . ($item['id'] ?? uniqid()),
			'provider'     => 'openverse',
			'title'        => ucfirst($title),
			'alt'          => $title,
			'caption'      => !empty($item['attribution']) ? strip_tags($item['attribution']) : sprintf('Photo by %s via Openverse (%s)', $author, $license),
			'url'          => $img_url,
			'download_url' => $img_url,
			'thumb_url'    => $item['thumbnail'] ?? ($item['urls']['thumb'] ?? $img_url),
			'width'        => $item['width'] ?? null,
			'height'       => $item['height'] ?? null,
			'author'       => $author,
			'author_url'   => $item['creator_url'] ?? ($item['user']['url'] ?? ''),
			'source_url'   => $item['foreign_landing_url'] ?? ($item['permalink'] ?? ''),
			'license'      => "Openverse ({$license})",
		];
	}

	return $results;
}
