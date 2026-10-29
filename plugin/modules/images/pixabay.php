<?php
/**
 * Pixabay Stock Image Driver
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Search Pixabay photos
 *
 * @param string $query
 * @param array  $args
 *
 * @return array
 */
function twcp_pixabay_search(string $query, array $args = []): array
{
	$keys = twcp_image_keys();
	$api_key = $keys['pixabay'];

	$per_page = isset($args['per_page']) ? min(50, max(3, (int) $args['per_page'])) : 10;
	$page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
	$orientation = !empty($args['orientation']) ? sanitize_key($args['orientation']) : '';

	if (!empty($api_key)) {
		$params = [
			'key'        => $api_key,
			'q'          => $query,
			'image_type' => 'photo',
			'per_page'   => $per_page,
			'page'       => $page,
			'safesearch' => 'true',
		];
		if ($orientation === 'landscape') {
			$params['orientation'] = 'horizontal';
		} elseif ($orientation === 'portrait') {
			$params['orientation'] = 'vertical';
		}

		$url = add_query_arg($params, 'https://pixabay.com/api/');
		$response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
	} else {
		$proxy_params = [
			'type'       => 'search',
			'term'       => $query,
			'page'       => $page,
			'safesearch' => 'true',
			'version'    => '7.2.0',
		];
		$url = add_query_arg($proxy_params, 'https://proxy.getinstantimages.com/api/pixabay');
		$response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
	}

	if (is_wp_error($response) or wp_remote_retrieve_response_code($response) !== 200) {
		return [];
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	$raw_items = $data['hits'] ?? ($data['results'] ?? []);
	$results = [];

	foreach ($raw_items as $item) {
		$tags = !empty($item['tags']) ? $item['tags'] : $query;
		$user = $item['user'] ?? 'Pixabay Contributor';
		$user_id = $item['user_id'] ?? 0;
		$user_url = $user_id ? "https://pixabay.com/users/{$user}-{$user_id}/" : 'https://pixabay.com';

		$results[] = [
			'id'           => 'pixabay_' . ($item['id'] ?? uniqid()),
			'provider'     => 'pixabay',
			'title'        => ucfirst($tags),
			'alt'          => $tags,
			'caption'      => sprintf('Image by %s from Pixabay', $user),
			'url'          => $item['largeImageURL'] ?? ($item['webformatURL'] ?? ''),
			'download_url' => $item['largeImageURL'] ?? ($item['webformatURL'] ?? ''),
			'thumb_url'    => $item['previewURL'] ?? ($item['webformatURL'] ?? ''),
			'width'        => $item['imageWidth'] ?? null,
			'height'       => $item['imageHeight'] ?? null,
			'author'       => $user,
			'author_url'   => $user_url,
			'source_url'   => $item['pageURL'] ?? '',
			'license'      => 'Pixabay License',
		];
	}

	return $results;
}
