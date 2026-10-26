<?php
/**
 * Openverse Stock Image Driver (Creative Commons, zero key required)
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Search Openverse photos
 *
 * @param string $query
 * @param array  $args
 *
 * @return array
 */
function twcp_openverse_search(string $query, array $args = []): array
{
	$per_page = isset($args['per_page']) ? min(30, max(1, (int) $args['per_page'])) : 10;
	$page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
	$orientation = !empty($args['orientation']) ? sanitize_key($args['orientation']) : '';

	$params = [
		'q'         => $query,
		'page_size' => $per_page,
		'page'      => $page,
	];
	if ($orientation === 'landscape') {
		$params['aspect_ratio'] = 'wide';
	} elseif ($orientation === 'portrait') {
		$params['aspect_ratio'] = 'tall';
	} elseif ($orientation === 'square') {
		$params['aspect_ratio'] = 'square';
	}

	$url = add_query_arg($params, 'https://api.openverse.org/v1/images/');
	$response = wp_remote_get($url, [
		'timeout'   => 15,
		'sslverify' => true,
		'headers'   => [
			'User-Agent' => 'WordPress-Twee-MCP/1.0 (' . home_url() . ')',
			'Accept'     => 'application/json',
		],
	]);

	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		$proxy_url = add_query_arg([
			'type'    => 'search',
			'term'    => $query,
			'page'    => $page,
			'version' => '7.2.0',
		], 'https://proxy.getinstantimages.com/api/openverse');
		$response = wp_remote_get($proxy_url, ['timeout' => 15, 'sslverify' => true]);
	}

	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		return [];
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	$raw_items = $data['results'] ?? [];
	$results = [];

	foreach ($raw_items as $item) {
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
