<?php
/**
 * Pixabay Stock Image Provider
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


add_filter('twcp_image_providers', function(array $providers): array {
	$providers['pixabay'] = [
		'name'        => 'Pixabay',
		'description' => __('Over 4 million royalty-free photos, illustrations, and vectors.', 'twee-mcp'),
		'position'    => 20,
		'search'      => 'twcp_pixabay_search',
		'key'         => [
			'label' => __('Pixabay API Key', 'twee-mcp'),
			'url'   => 'https://pixabay.com/service/about/api/',
			'link'  => __('Get a Pixabay API Key', 'twee-mcp'),
			'help'  => __('Sign in to Pixabay, open the API documentation, and copy the key shown for your account into this field.', 'twee-mcp'),
		],
	];

	return $providers;
});


/**
 * Search Pixabay photos, through the Instant Images proxy without a key
 *
 * @param string $query
 * @param array  $args per_page, page, orientation
 * @param string $key
 *
 * @return array
 */
function twcp_pixabay_search(string $query, array $args, string $key): array
{
	if ($key) {
		$params = [
			'key'        => $key,
			'q'          => $query,
			'image_type' => 'photo',
			'per_page'   => min(50, max(3, $args['per_page'])),
			'page'       => $args['page'],
			'safesearch' => 'true',
		];
		if ($args['orientation'] === 'landscape') {
			$params['orientation'] = 'horizontal';
		} elseif ($args['orientation'] === 'portrait') {
			$params['orientation'] = 'vertical';
		}

		$data = twcp_image_get(add_query_arg($params, 'https://pixabay.com/api/'));
	} else {
		$data = twcp_image_proxy('pixabay', $query, $args['page'], ['safesearch' => 'true']);
	}

	$results = [];

	foreach ($data['hits'] ?? ($data['results'] ?? []) as $item) {
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
