<?php
/**
 * Stock Images, Sideloading, and Entity Assignment Library
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

// Load provider drivers
require_once TWCP_PATH . 'includes/images/unsplash.php';
require_once TWCP_PATH . 'includes/images/pixabay.php';
require_once TWCP_PATH . 'includes/images/pexels.php';
require_once TWCP_PATH . 'includes/images/openverse.php';
require_once TWCP_PATH . 'includes/images/wikimedia.php';
require_once TWCP_PATH . 'includes/images/giphy.php';


/**
 * MCP tools for stock images and Alt text audits
 *
 * @return array Tool name => [ description, callable, parameters, required, annotations ]
 */
function twcp_image_tools(): array
{
	return [
		'search_stock_images'  => [
			'description' => 'Search free high-quality stock photos and graphics across Unsplash, Pixabay, Pexels, Openverse, Wikimedia Commons, and Giphy. Returns image metadata, dimensions, author credits, and download URLs.',
			'callable'    => 'twcp_image_search',
			'required'    => ['query'],
			'parameters'  => [
				'query'       => [
					'type'        => 'string',
					'description' => 'Keyword search term (e.g. "leather watch strap", "vintage chronograph", "stainless steel buckle").',
				],
				'provider'    => [
					'type'        => 'string',
					'description' => 'Stock provider to search: "all" (default, merges results across providers), "unsplash", "pixabay", "pexels", "openverse", "wikimedia", "giphy".',
				],
				'orientation' => ['type' => 'string', 'description' => 'Image orientation filter: "landscape", "portrait", "square", "all".'],
				'per_page'    => ['type' => 'integer', 'description' => 'Number of images to return (default 10, max 50).'],
				'page'        => ['type' => 'integer', 'description' => 'Page offset for pagination (default 1).'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'check_media_alt_text' => [
			'description' => 'Audit the Alt text quality of media library images, detect poor or generic Alt tags (e.g. filenames, keyword stuffing, placeholders), and regenerate high-quality Alt text using AI vision analysis.',
			'callable'    => 'twcp_media_check_tool',
			'required'    => ['attachment_ids'],
			'parameters'  => [
				'attachment_ids' => [
					'type'        => 'array',
					'items'       => ['type' => 'integer'],
					'description' => 'List of image attachment IDs to audit and improve.',
				],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'import_media_image'   => [
			'description' => 'Download an image from a URL or stock photo and import/sideload it directly into the WordPress Media Library with automatic responsive sizes, alt text, title, and attribution. Can optionally assign it immediately to a product, post, term, or block.',
			'callable'    => 'twcp_image_import',
			'required'    => ['url'],
			'parameters'  => [
				'url'        => ['type' => 'string', 'description' => 'Direct download URL of the remote raster image. SVG imports are not supported.'],
				'source_url' => ['type' => 'string', 'description' => 'Original provider page URL from stock search.'],
				'author'     => ['type' => 'string'],
				'author_url' => ['type' => 'string'],
				'license'    => ['type' => 'string', 'description' => 'License name from stock search; preserve required attribution in caption.'],
				'title'      => ['type' => 'string', 'description' => 'Title for the imported media attachment.'],
				'alt'        => ['type' => 'string', 'description' => 'Descriptive Alt text for accessibility and SEO.'],
				'caption'    => ['type' => 'string', 'description' => 'Caption or attribution credit.'],
				'filename'   => ['type' => 'string', 'description' => 'SEO-friendly filename without extension, e.g. "italian-leather-watch-strap".'],
				'post_id'    => ['type' => 'integer', 'description' => 'Optional parent post ID to attach the image to.'],
				'assign_to'  => [
					'type'        => 'object',
					'description' => 'Optional entity target to assign immediately: { "type": "product"|"post"|"term"|"block", "id": 123, "assign_as": "featured"|"gallery"|"both"|"content", "field_name": "image", "block_index": 0 }',
				],
			],
			'annotations' => ['readOnlyHint' => false],
		],
	];
}


/**
 * Retrieve configured stock photo API keys
 *
 * @return array
 */
function twcp_image_keys(): array
{
	$instant_api = (array) get_option('instant_img_api_settings', []);
	$twcp_keys = (array) get_option('twcp_stock_api_keys', []);

	$keys = ['openverse' => '', 'wikimedia' => ''];
	foreach (['unsplash', 'pixabay', 'pexels', 'giphy'] as $provider) {
		$constant = 'INSTANT_IMAGES_' . strtoupper($provider) . '_KEY';
		$keys[$provider] = trim((string) ($twcp_keys[$provider] ?? ''));
		if ($keys[$provider] === '') {
			$keys[$provider] = defined($constant) ? trim((string) constant($constant)) : '';
		}
		if ($keys[$provider] === '') {
			$keys[$provider] = trim((string) ($instant_api[$provider . '_api'] ?? ''));
		}
	}

	return $keys;
}


/**
 * Unified Stock Photo Search across all providers
 *
 * @param string|array $query_or_args
 * @param array        $args
 *
 * @return array
 */
function twcp_image_search($query_or_args, array $args = []): array
{
	if (is_array($query_or_args)) {
		$args = $query_or_args;
		$query = !empty($args['query']) ? (string) $args['query'] : '';
	} else {
		$query = (string) $query_or_args;
	}

	$query = trim($query);
	if ($query === '') {
		return [
			'query'   => '',
			'total'   => 0,
			'results' => [],
		];
	}

	$provider = !empty($args['provider']) ? sanitize_key($args['provider']) : 'all';
	$per_page = isset($args['per_page']) ? min(50, max(1, (int) $args['per_page'])) : 10;
	$keys = twcp_image_keys();
	if ($provider !== 'all' and !array_key_exists($provider, $keys)) {
		return ['error' => 'Unknown stock image provider.'];
	}
	$args['per_page'] = $per_page;
	$args['page'] = max(1, (int) ($args['page'] ?? 1));
	$args['orientation'] = ($args['orientation'] ?? '') === 'squarish' ? 'square' : sanitize_key($args['orientation'] ?? '');
	$cache_key = 'twcp_images_' . hash('sha256', wp_json_encode([$query, $provider, $per_page, $args['page'], $args['orientation'], $keys]));
	$cached = get_transient($cache_key);
	if (is_array($cached)) {
		return $cached;
	}

	$results = [];

	if ($provider !== 'all') {
		$search = 'twcp_' . $provider . '_search';
		$results = $search($query, $args);
	} else {
		$sub_args = array_merge($args, ['per_page' => max(2, (int) ceil($per_page / 3))]);
		$p_results = [];
		foreach (['unsplash', 'pixabay', 'pexels'] as $name) {
			if (empty($keys[$name])) {
				continue;
			}

			$search = 'twcp_' . $name . '_search';
			$p_results[$name] = $search($query, $sub_args);
		}
		$p_results['openverse'] = twcp_openverse_search($query, $sub_args);

		$total_so_far = array_sum(array_map('count', $p_results));
		if ($total_so_far < $per_page) {
			$p_results['wikimedia'] = twcp_wikimedia_search($query, $sub_args);
		}

		$max_len = 0;
		foreach ($p_results as $items) {
			$max_len = max($max_len, count($items));
		}

		for ($i = 0; $i < $max_len; $i++) {
			foreach ($p_results as $items) {
				if (isset($items[$i])) {
					$results[] = $items[$i];
					if (count($results) >= $per_page) {
						break 2;
					}
				}
			}
		}
	}

	$result = [
		'query'           => $query,
		'provider'        => $provider,
		'page'            => $args['page'],
		'per_page'        => $per_page,
		'total'           => count($results),
		'count'           => count($results),
		'has_more'        => count($results) >= $per_page,
		'configured_keys' => [
			'unsplash' => !empty($keys['unsplash']),
			'pixabay'  => !empty($keys['pixabay']),
			'pexels'   => !empty($keys['pexels']),
			'giphy'    => !empty($keys['giphy']),
		],
		'results'         => $results,
	];
	if ($results) {
		set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
	}

	return $result;
}


/**
 * Download remote image from URL and import into WordPress Media Library
 *
 * @param string|array $url_or_args
 * @param array        $meta
 *
 * @return array
 */
function twcp_image_import($url_or_args, array $meta = []): array
{
	if (is_array($url_or_args)) {
		$meta = $url_or_args;
		$url = !empty($meta['url']) ? (string) $meta['url'] : '';
	} else {
		$url = (string) $url_or_args;
	}

	$url = trim($url);
	if (empty($url) or !wp_http_validate_url($url)) {
		return ['error' => 'A valid remote image URL is required.'];
	}
	if (!current_user_can('upload_files') or (!empty($meta['post_id']) and !current_user_can('edit_post', absint($meta['post_id'])))) {
		return ['error' => 'You cannot import media for this post.'];
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$tmp = download_url($url, 45);

	if (is_wp_error($tmp)) {
		return ['error' => 'Failed to download remote image: ' . $tmp->get_error_message()];
	}

	$clean_url = strtok($url, '?');
	$type = wp_get_image_mime($tmp);
	$extensions = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
		'image/avif' => 'avif',
	];
	$ext = $extensions[$type] ?? '';
	if (!$ext) {
		unlink($tmp);

		return ['error' => 'The downloaded file is not a supported raster image.'];
	}

	$filename_base = !empty($meta['filename']) ? sanitize_title($meta['filename']) : '';
	if (empty($filename_base) and !empty($meta['title'])) {
		$filename_base = sanitize_title($meta['title']);
	}
	if (empty($filename_base)) {
		$filename_base = sanitize_title(pathinfo($clean_url, PATHINFO_FILENAME));
	}
	if (empty($filename_base)) {
		$filename_base = 'image-' . wp_generate_password(6, false);
	}

	$filename = $filename_base . '.' . $ext;
	$file_array = [
		'name'     => $filename,
		'tmp_name' => $tmp,
	];

	$parent_id = !empty($meta['post_id']) ? absint($meta['post_id']) : 0;
	$title = !empty($meta['title']) ? sanitize_text_field($meta['title']) : ucwords(str_replace('-', ' ', $filename_base));
	$alt = !empty($meta['alt']) ? sanitize_text_field($meta['alt']) : $title;
	$caption = !empty($meta['caption']) ? wp_kses_post($meta['caption']) : '';
	$description = !empty($meta['description']) ? wp_kses_post($meta['description']) : '';

	$post_data = [
		'post_title'   => $title,
		'post_excerpt' => $caption,
		'post_content' => $description,
		'post_status'  => 'inherit',
	];

	$attachment_id = media_handle_sideload($file_array, $parent_id, $title, $post_data);

	if (file_exists($tmp)) {
		@unlink($tmp);
	}

	if (is_wp_error($attachment_id)) {
		return ['error' => 'Failed to insert attachment into WordPress Media Library: ' . $attachment_id->get_error_message()];
	}

	update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);

	foreach (['provider', 'source_url', 'author', 'author_url', 'license'] as $field) {
		if (empty($meta[$field])) {
			continue;
		}

		$value = str_ends_with($field, '_url') ? esc_url_raw($meta[$field]) : sanitize_text_field($meta[$field]);
		update_post_meta($attachment_id, '_twcp_image_' . $field, $value);
	}
	update_post_meta($attachment_id, '_twcp_image_original_url', esc_url_raw($url));

	$attached_file = get_attached_file($attachment_id);
	$attach_meta = wp_get_attachment_metadata($attachment_id);
	$attachment_post = get_post($attachment_id);

	$result = [
		'success'       => true,
		'attachment_id' => $attachment_id,
		'url'           => wp_get_attachment_url($attachment_id),
		'title'         => $title,
		'alt'           => $alt,
		'caption'       => $caption,
		'width'         => $attach_meta['width'] ?? null,
		'height'        => $attach_meta['height'] ?? null,
		'filesize'      => ($attached_file && file_exists($attached_file)) ? filesize($attached_file) : null,
		'mime_type'     => $attachment_post ? $attachment_post->post_mime_type : $type,
		'sizes'         => !empty($attach_meta['sizes']) ? array_keys($attach_meta['sizes']) : [],
		'edit_url'      => admin_url('post.php?post=' . $attachment_id . '&action=edit'),
	];

	if (!empty($meta['assign_to']) && is_array($meta['assign_to'])) {
		$assign_result = twcp_image_assign($attachment_id, $meta['assign_to']);
		$result['assigned_to'] = $assign_result;
	}

	return $result;
}


/**
 * Assign a media attachment to a WordPress entity
 *
 * @param int|array $attachment_id_or_args
 * @param array     $target
 *
 * @return array
 */
function twcp_image_assign($attachment_id_or_args, array $target = []): array
{
	if (is_array($attachment_id_or_args)) {
		$target = $attachment_id_or_args;
		$attachment_id = !empty($target['attachment_id']) ? absint($target['attachment_id']) : 0;
	} else {
		$attachment_id = absint($attachment_id_or_args);
	}

	if (!$attachment_id or !wp_attachment_is_image($attachment_id)) {
		return ['error' => "Attachment ID {$attachment_id} is invalid or not an image."];
	}

	$type = !empty($target['type']) ? sanitize_key($target['type']) : (!empty($target['target_type']) ? sanitize_key($target['target_type']) : '');
	$target_id = !empty($target['id']) ? absint($target['id']) : (!empty($target['target_id']) ? absint($target['target_id']) : 0);
	$assign_as = !empty($target['assign_as']) ? sanitize_key($target['assign_as']) : 'featured';

	if (!$type) {
		return ['error' => 'Missing target type (product, post, term, block).'];
	}

	$capability = in_array($type, ['term', 'category'], true) ? 'edit_term' : 'edit_post';
	if (!current_user_can('upload_files') or !current_user_can($capability, $target_id)) {
		return ['error' => 'You cannot assign media to this content.'];
	}

	switch ($type) {
		case 'product':
			if (!$target_id) {
				return ['error' => 'Missing product ID.'];
			}

			if (!twcp_product_active()) {
				return ['error' => 'WooCommerce is not active.'];
			}

			$product = wc_get_product($target_id);
			if (!$product) {
				return ['error' => 'Product not found.'];
			}
			if (!in_array($assign_as, ['featured', 'gallery', 'both'], true)) {
				return ['error' => 'Unknown product image role.'];
			}

			$args = ['product_id' => $target_id];
			if ($assign_as !== 'gallery') {
				$args['image_id'] = $attachment_id;
			}
			if ($assign_as !== 'featured') {
				$args['gallery_image_ids'] = array_values(array_unique([
					...$product->get_gallery_image_ids(),
					$attachment_id,
				]));
			}

			$result = twcp_product_edit($args);
			if (isset($result['error'])) {
				return $result;
			}

			return [
				'success'       => true,
				'type'          => 'product',
				'product_id'    => $target_id,
				'product_name'  => $product->get_name(),
				'assign_as'     => $assign_as,
				'attachment_id' => $attachment_id,
				'message'       => 'Product image assigned.',
			];

		case 'post':
		case 'page':
			if (!$target_id) {
				return ['error' => 'Missing post ID.'];
			}

			$post = get_post($target_id);
			if (!$post) {
				return ['error' => "Post #{$target_id} not found."];
			}

			if ($assign_as === 'content') {
				$img_html = wp_get_attachment_image($attachment_id, 'large');
				wp_update_post([
					'ID'           => $target_id,
					'post_content' => $post->post_content . "\n\n" . $img_html,
				]);

				return [
					'success'       => true,
					'type'          => 'post',
					'post_id'       => $target_id,
					'assign_as'     => 'content',
					'attachment_id' => $attachment_id,
					'message'       => sprintf("Appended image #%d into content of post '%s' (#%d).", $attachment_id, $post->post_title, $target_id),
				];
			} else {
				set_post_thumbnail($target_id, $attachment_id);

				return [
					'success'       => true,
					'type'          => 'post',
					'post_id'       => $target_id,
					'assign_as'     => 'featured',
					'attachment_id' => $attachment_id,
					'message'       => sprintf("Set featured image #%d for post '%s' (#%d).", $attachment_id, $post->post_title, $target_id),
				];
			}

		case 'term':
		case 'category':
			if (!$target_id) {
				return ['error' => 'Missing term ID.'];
			}

			$taxonomy = !empty($target['taxonomy']) ? sanitize_key($target['taxonomy']) : 'product_cat';
			$term = get_term($target_id, $taxonomy);
			if (!$term or is_wp_error($term)) {
				return ['error' => "Taxonomy term #{$target_id} in '{$taxonomy}' not found."];
			}

			update_term_meta($target_id, 'thumbnail_id', $attachment_id);
			update_term_meta($target_id, 'image', $attachment_id);

			return [
				'success'       => true,
				'type'          => 'term',
				'term_id'       => $target_id,
				'taxonomy'      => $taxonomy,
				'term_name'     => $term->name,
				'attachment_id' => $attachment_id,
				'message'       => sprintf("Set thumbnail image #%d for term '%s' (#%d).", $attachment_id, $term->name, $target_id),
			];

		case 'block':
			if (!$target_id) {
				return ['error' => 'Missing post/page ID for block assignment.'];
			}
			if (!isset($target['block_index'])) {
				return ['error' => 'Missing block_index for block assignment.'];
			}
			$field_name = !empty($target['field_name']) ? sanitize_key($target['field_name']) : 'image';
			$block_index = (int) $target['block_index'];

			$edit_res = twcp_block_edit([
				'object_id' => $target_id,
				'index'     => $block_index,
				'data'      => [
					$field_name => $attachment_id,
				],
			]);

			if (!empty($edit_res['error'])) {
				return ['error' => 'Failed to update ACF block: ' . $edit_res['error']];
			}

			if (!empty($edit_res['warnings'])) {
				return ['error' => 'Block field was rejected: ' . implode('; ', $edit_res['warnings'])];
			}

			return [
				'success'       => true,
				'type'          => 'block',
				'post_id'       => $target_id,
				'block_index'   => $block_index,
				'field_name'    => $field_name,
				'attachment_id' => $attachment_id,
				'message'       => sprintf("Assigned image #%d to field '%s' on block #%d of post #%d.", $attachment_id, $field_name, $block_index, $target_id),
			];

		default:
			return ['error' => "Unsupported target type '{$type}'. Allowed: product, post, term, block."];
	}
}
