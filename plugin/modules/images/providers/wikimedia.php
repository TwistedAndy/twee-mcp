<?php
/**
 * Wikimedia Commons Stock Image Provider
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


add_filter('twcp_image_providers', function(array $providers): array {
	$providers['wikimedia'] = [
		'name'        => 'Wikimedia Commons',
		'description' => __('Millions of freely usable educational & historical media files (No API key required).', 'twee-mcp'),
		'position'    => 50,
		'search'      => 'twcp_wikimedia_search',
		'all'         => 'fallback',
	];

	return $providers;
});


/**
 * Search Wikimedia Commons
 *
 * @param string $query
 * @param array  $args per_page, page, orientation
 *
 * @return array
 */
function twcp_wikimedia_search(string $query, array $args): array
{
	$per_page = min(30, $args['per_page']);

	$data = twcp_image_get(add_query_arg([
		'action'       => 'query',
		'format'       => 'json',
		'generator'    => 'search',
		'gsrnamespace' => 6,
		'gsrsearch'    => $query . ' filetype:bitmap',
		'gsrlimit'     => $per_page,
		'gsroffset'    => ($args['page'] - 1) * $per_page,
		'prop'         => 'imageinfo',
		'iiprop'       => 'url|size|mime|extmetadata',
	], 'https://commons.wikimedia.org/w/api.php'));

	$results = [];

	foreach ($data['query']['pages'] ?? [] as $page) {
		if (empty($page['imageinfo'][0])) {
			continue;
		}
		$info = $page['imageinfo'][0];
		$title = preg_replace('/^File:/i', '', $page['title'] ?? $query);
		$meta = $info['extmetadata'] ?? [];

		$artist = !empty($meta['Artist']['value']) ? strip_tags($meta['Artist']['value']) : 'Wikimedia Contributor';
		$license = !empty($meta['LicenseShortName']['value']) ? $meta['LicenseShortName']['value'] : 'Creative Commons / Public Domain';

		$results[] = [
			'id'           => 'wikimedia_' . ($page['pageid'] ?? uniqid()),
			'provider'     => 'wikimedia',
			'title'        => $title,
			'alt'          => $title,
			'caption'      => sprintf('Image by %s via Wikimedia Commons (%s)', $artist, $license),
			'url'          => $info['url'] ?? '',
			'download_url' => $info['url'] ?? '',
			'thumb_url'    => $info['thumburl'] ?? ($info['url'] ?? ''),
			'width'        => $info['width'] ?? null,
			'height'       => $info['height'] ?? null,
			'author'       => $artist,
			'author_url'   => $info['descriptionurl'] ?? '',
			'source_url'   => $info['descriptionurl'] ?? '',
			'license'      => $license,
		];
	}

	return $results;
}
