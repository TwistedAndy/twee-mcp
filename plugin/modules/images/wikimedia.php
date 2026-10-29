<?php
/**
 * Wikimedia Commons Stock Image Driver (Public Domain / CC, zero key required)
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Search Wikimedia Commons
 *
 * @param string $query
 * @param array  $args
 *
 * @return array
 */
function twcp_wikimedia_search(string $query, array $args = []): array
{
	$per_page = isset($args['per_page']) ? min(30, max(1, (int) $args['per_page'])) : 10;
	$page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
	$offset = ($page - 1) * $per_page;

	$url = add_query_arg([
		'action'       => 'query',
		'format'       => 'json',
		'generator'    => 'search',
		'gsrnamespace' => 6,
		'gsrsearch'    => $query . ' filetype:bitmap',
		'gsrlimit'     => $per_page,
		'gsroffset'    => $offset,
		'prop'         => 'imageinfo',
		'iiprop'       => 'url|size|mime|extmetadata',
	], 'https://commons.wikimedia.org/w/api.php');

	$response = wp_remote_get($url, [
		'timeout'   => 15,
		'sslverify' => true,
		'headers'   => [
			'User-Agent' => 'WordPress-Twee-MCP/1.0 (' . home_url() . ')',
			'Accept'     => 'application/json',
		],
	]);

	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		return [];
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	$pages = $data['query']['pages'] ?? [];
	$results = [];

	foreach ($pages as $page) {
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
