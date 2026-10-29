<?php
/**
 * Media & Image Tools
 *
 * MCP tools for the media library, and Alt text written by the model.
 * Images are described by the vision model.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Update editable metadata on a Media Library image.
 *
 * @param array $args Attachment ID and changed fields.
 *
 * @return array Updated image or error.
 */
function twcp_media_edit(array $args): array
{
	$id = absint($args['attachment_id']);
	if (!wp_attachment_is_image($id)) {
		return ['error' => 'Image attachment not found.'];
	}

	$data = ['ID' => $id];
	foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $field => $column) {
		if (isset($args[$field])) {
			$data[$column] = $field === 'title' ? sanitize_text_field($args[$field]) : wp_kses_post($args[$field]);
		}
	}

	$result = wp_update_post($data, true);
	if (is_wp_error($result)) {
		return ['error' => $result->get_error_message()];
	}
	if (isset($args['alt'])) {
		update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($args['alt']));
	}

	return ['success' => true, 'image' => twcp_media_entry(get_post($id))];
}

/**
 * Build a native WordPress gallery shortcode from attachment IDs.
 * This function returns shortcode text without modifying post content.
 *
 * @param array $args Gallery attachment IDs and display options.
 *
 * @return array Gallery shortcode or error.
 */
function twcp_media_gallery(array $args): array
{
	$ids = array_values(array_unique(array_map('intval', $args['attachment_ids'])));
	if (!$ids) {
		return ['error' => 'At least one image attachment is required.'];
	}

	foreach ($ids as $id) {
		if (!wp_attachment_is_image($id)) {
			return ['error' => 'Invalid image attachment: ' . $id];
		}
	}

	$size = $args['size'] ?? 'large';
	if (!in_array($size, array_merge(['full'], get_intermediate_image_sizes()), true)) {
		return ['error' => 'Unknown image size.'];
	}

	$link = $args['link'] ?? 'file';
	if (!in_array($link, ['file', 'none', 'attachment'], true)) {
		return ['error' => 'Unknown gallery link mode.'];
	}

	$columns = min(9, max(1, (int) ($args['columns'] ?? 3)));
	$shortcode = '[gallery ids="' . implode(',', $ids) . '" columns="' . $columns . '" size="' . $size . '" link="' . $link . '"]';

	return [
		'shortcode'      => $shortcode,
		'attachment_ids' => $ids,
		'instructions'   => 'Insert the shortcode in post content or an ACF rich-text field. ' . 'Use edit_product.gallery_image_ids for a product gallery.',
	];
}


/**
 * Find the associated parent post/product that uses or handles this attachment
 *
 * Checks, in order:
 * - the post the attachment was uploaded to
 * - a published post or product with it as the featured image (_thumbnail_id)
 * - a WooCommerce product with it in the gallery (_product_image_gallery)
 *
 * @param int|WP_Post $attachment Attachment ID or WP_Post object.
 *
 * @return WP_Post|null
 */
function twcp_media_associated_post($attachment): ?WP_Post
{
	$attachment = get_post($attachment);
	if (!$attachment or $attachment->post_type !== 'attachment') {
		return null;
	}

	// The post it was uploaded to
	if (!empty($attachment->post_parent)) {
		$parent = get_post($attachment->post_parent);
		if ($parent and $parent->post_status !== 'trash') {
			return $parent;
		}
	}

	global $wpdb;
	$attachment_id = $attachment->ID;

	// A post with it as the featured image
	$featured_post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d
		 AND p.post_status != 'trash'
		 ORDER BY p.post_date DESC LIMIT 1", $attachment_id));
	if ($featured_post_id) {
		$post = get_post($featured_post_id);
		if ($post) {
			return $post;
		}
	}

	// A product with it in the gallery
	$gallery_post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_product_image_gallery'
		 AND FIND_IN_SET(%d, pm.meta_value) > 0
		 AND p.post_status != 'trash'
		 ORDER BY p.post_date DESC LIMIT 1", $attachment_id));
	if ($gallery_post_id) {
		$post = get_post($gallery_post_id);
		if ($post) {
			return $post;
		}
	}

	return null;
}


/**
 * Format a media attachment into a comprehensive descriptor
 *
 * @param WP_Post $post
 * @param string  $size
 *
 * @return array
 */
function twcp_media_entry(WP_Post $post, string $size = 'full'): array
{
	$id = $post->ID;
	$alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
	$url = (string) wp_get_attachment_url($id);
	$meta = wp_get_attachment_metadata($id);
	$file = get_attached_file($id);
	$ext = strtolower($file ? pathinfo($file, PATHINFO_EXTENSION) : '');
	$filesize = ($file && file_exists($file)) ? filesize($file) : (!empty($meta['filesize']) ? $meta['filesize'] : null);
	$mime_type = $post->post_mime_type;
	$is_vector = ($mime_type === 'image/svg+xml' or $ext === 'svg');

	$width = !empty($meta['width']) ? (int) $meta['width'] : null;
	$height = !empty($meta['height']) ? (int) $meta['height'] : null;

	$orientation = 'unknown';
	if ($width and $height) {
		if ($width > $height * 1.1) {
			$orientation = 'landscape';
		} elseif ($height > $width * 1.1) {
			$orientation = 'portrait';
		} else {
			$orientation = 'square';
		}
	}

	$available_sizes = [];
	if (!empty($meta['sizes']) and is_array($meta['sizes'])) {
		foreach ($meta['sizes'] as $s_name => $s_data) {
			$available_sizes[$s_name] = [
				'width'  => $s_data['width'] ?? null,
				'height' => $s_data['height'] ?? null,
				'url'    => wp_get_attachment_image_url($id, $s_name) ? : null,
			];
		}
	}

	$associated = twcp_media_associated_post($post);

	return [
		'id'              => $id,
		'title'           => $post->post_title,
		'filename'        => $file ? basename($file) : '',
		'extension'       => $ext,
		'mime_type'       => $mime_type,
		'is_vector'       => $is_vector,
		'alt'             => $alt,
		'caption'         => $post->post_excerpt,
		'description'     => $post->post_content,
		'parent_id'       => $post->post_parent,
		'associated_post' => $associated ? [
			'id'    => $associated->ID,
			'title' => $associated->post_title,
			'name'  => $associated->post_name,
			'type'  => $associated->post_type,
		] : null,
		'provider'        => get_post_meta($id, '_twcp_image_provider', true),
		'source_url'      => get_post_meta($id, '_twcp_image_source_url', true),
		'download_url'    => get_post_meta($id, '_twcp_image_original_url', true),
		'author'          => get_post_meta($id, '_twcp_image_author', true),
		'author_url'      => get_post_meta($id, '_twcp_image_author_url', true),
		'license'         => get_post_meta($id, '_twcp_image_license', true),
		'url'             => $url,
		'original_size'   => [
			'width'          => $width,
			'height'         => $height,
			'dimensions'     => ($width and $height) ? "{$width}x{$height}" : null,
			'orientation'    => $orientation,
			'filesize'       => $filesize,
			'filesize_human' => $filesize ? size_format($filesize, 1) : null,
		],
		'sizes'           => $available_sizes,
		'tag'             => wp_get_attachment_image($id, $size),
	];
}


/**
 * Action/Tool: Query and search images from the media library
 *
 * @param array $args
 *
 * @return array
 */
function twcp_media_images(array $args): array
{
	$size = !empty($args['size']) ? sanitize_text_field($args['size']) : 'full';
	$limit = isset($args['limit']) ? min(50, max(1, (int) $args['limit'])) : 20;
	$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

	if (!empty($args['id'])) {
		$image_id = absint($args['id']);
		$attachment = get_post($image_id);

		if (!$attachment or $attachment->post_type !== 'attachment' or !str_starts_with($attachment->post_mime_type, 'image/')) {
			return ['error' => 'Image not found: ' . $image_id];
		}

		return [
			'image' => twcp_media_entry($attachment, $size),
		];
	}

	$query_args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $limit,
		'offset'         => $offset,
		'orderby'        => 'date',
		'order'          => 'DESC',
	];
	$type_filter = !empty($args['type']) ? strtolower(trim($args['type'])) : '';
	if ($type_filter === 'svg' or $type_filter === 'vector') {
		$query_args['post_mime_type'] = 'image/svg+xml';
	} elseif ($type_filter === 'raster' or $type_filter === 'photo' or $type_filter === 'image') {
		$query_args['post_mime_type'] = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
	} elseif (!empty($args['mime_type'])) {
		$query_args['post_mime_type'] = sanitize_text_field($args['mime_type']);
	} else {
		$query_args['post_mime_type'] = 'image';
	}

	if (!empty($args['search'])) {
		$query_args['s'] = sanitize_text_field($args['search']);
	}

	// Search Alt text and file names too, without extra queries or duplicate rows
	$search_filter = function($search, $query) use ($query_args) {
		if (!$query->get('twcp_image_search')) {
			return $search;
		}

		global $wpdb;
		$like = '%' . $wpdb->esc_like($query_args['s']) . '%';
		$sql = " AND (
			{$wpdb->posts}.post_title LIKE %s
			OR {$wpdb->posts}.post_excerpt LIKE %s
			OR {$wpdb->posts}.post_content LIKE %s
			OR EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m
				WHERE m.post_id = {$wpdb->posts}.ID
				AND m.meta_key IN ('_wp_attachment_image_alt', '_wp_attached_file')
				AND m.meta_value LIKE %s
			)
		)";

		return $wpdb->prepare($sql, $like, $like, $like, $like);
	};

	if (!empty($query_args['s'])) {
		$query_args['twcp_image_search'] = true;
		add_filter('posts_search', $search_filter, 10, 2);
	}

	try {
		$query = new WP_Query($query_args);
	} finally {
		remove_filter('posts_search', $search_filter, 10);
	}

	// Lists skip every size URL and the HTML tag, which are most of each entry's tokens
	$details = !empty($args['details']);
	$images = array_map(static function(WP_Post $post) use ($size, $details): array {
		$entry = twcp_media_entry($post, $size);

		return $details ? $entry : array_diff_key($entry, array_flip(['sizes', 'tag', 'description']));
	}, $query->posts);

	return [
		'total'  => (int) $query->found_posts,
		'limit'  => $limit,
		'offset' => $offset,
		'images' => $images,
	];
}


/**
 * Action/Tool: Small image previews returned as MCP image content
 *
 * @param array $args
 *
 * @return array
 */
function twcp_media_preview(array $args): array
{
	$ids = array_map('absint', $args['attachment_ids'] ?? []);

	if (!empty($args['post_id'])) {
		$post = get_post(absint($args['post_id']));
		if (!$post or !current_user_can('read_post', $post->ID)) {
			return ['error' => 'Post not found.'];
		}

		$ids[] = (int) get_post_thumbnail_id($post);
		$ids = array_merge($ids, array_map('absint', explode(',', (string) get_post_meta($post->ID, '_product_image_gallery', true))));
		if (preg_match_all('/wp-image-(\d+)/', $post->post_content, $matches)) {
			$ids = array_merge($ids, array_map('absint', $matches[1]));
		}
	}

	$ids = array_slice(array_values(array_unique(array_filter($ids))), 0, 10);
	if (!$ids) {
		return ['error' => 'No images to preview.'];
	}

	$max_edge = ($args['size'] ?? 'small') === 'medium' ? 512 : 256;
	$images = [];
	$previews = [];

	foreach ($ids as $id) {
		$entry = ['id' => $id, 'title' => get_the_title($id), 'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true)];
		$payload = wp_attachment_is_image($id) ? twcp_model_image_payload($id, $max_edge) : null;

		if ($payload and $payload['data'] !== '') {
			$entry['preview'] = count($previews) + 1;
			$previews[] = ['data' => $payload['data'], 'mimeType' => $payload['mime_type']];
		} else {
			$entry['preview'] = null; // Missing file, unreadable format, or not an image
		}
		$images[] = $entry;
	}

	return [
		'note'        => 'Previews follow in the order of the "preview" numbers.',
		'images'      => $images,
		'_mcp_images' => $previews,
	];
}


/**
 * Default built-in system prompt for image ALT tag generation (>1024 tokens)
 *
 * @return string
 */
function twcp_prompt_alt_default(): string
{
	$store_name = twcp_site_name();

	return <<<PROMPT
You are an accessibility and image SEO specialist writing HTML image alt text for {$store_name}, following Google's image SEO guidance and WCAG.

CORE OBJECTIVE:
Describe what the image shows and what matters about it on the page, for screen reader users, image search, and AI agents choosing images from the media library.

GUIDELINES:
1. Length: One sentence, usually 8 to 20 words. Be specific rather than long: "Dalmatian puppy playing fetch" beats both "dog" and a paragraph.
2. Key Attributes, most important first:
   - The main subject and its specific type, with the product or model name when the context confirms it
   - Color and finish (e.g. navy blue, matte black, brushed steel)
   - Material, texture, and the visible details that set this image apart from similar ones
   - Setting and presentation (e.g. flat lay, close-up, in use, on a white background)
   - Visible text, logos, or labels when they carry meaning
3. Accuracy: Describe only what is visible or confirmed by the context. Do not guess brands, sizes, or features.
4. Keywords: No keyword lists, repeated phrases, or promotional words.
5. Formatting:
   - Output strictly plain text in the language of the site.
   - Do NOT include labels like "Alt text:", "Image of", or "Photo of".
   - Do NOT wrap in quotes, brackets, or markdown code blocks.
PROMPT;
}


/**
 * Write the Alt text of an image with the vision model
 *
 * @param int  $attachment_id Attachment ID.
 * @param bool $force         Whether to overwrite an existing ALT text.
 *
 * @return string|null Generated alt tag or null on failure.
 */
function twcp_media_generate_alt(int $attachment_id, bool $force = false): ?string
{
	$post = get_post($attachment_id);
	if (!$post or $post->post_type !== 'attachment' or !str_starts_with($post->post_mime_type, 'image/')) {
		return null;
	}

	$current_alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
	if (!empty($current_alt) and !$force) {
		return $current_alt;
	}

	$prompt = "Analyze this image from " . twcp_site_name() . ".\n";
	$prompt .= "Write its Alt text.\n";

	if (!empty($current_alt)) {
		$prompt .= twcp_rewrite_note('Alt text', "\"{$current_alt}\"", 'Improve, refine, or rewrite this Alt text to describe the photo more precisely.');
	}

	$associated = twcp_media_associated_post($post);
	if ($associated) {
		$assoc_title = html_entity_decode(get_the_title($associated), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$assoc_slug = $associated->post_name;
		$type_obj = get_post_type_object($associated->post_type);
		$type_label = $type_obj ? $type_obj->labels->singular_name : $associated->post_type;

		$prompt .= "\nCONTEXT - ASSOCIATED {$type_label}:\n";
		$prompt .= "- Item / Post Name: \"{$assoc_title}\"\n";
		if (!empty($assoc_slug)) {
			$prompt .= "- Post Slug / Name: \"{$assoc_slug}\"\n";
		}
		$prompt .= "- Item Type: {$type_label}\n";
		$prompt .= "This image is attached to and handles this {$type_label}. Describe the image in the accurate context of this item (incorporating relevant product model, style, or material names if visible in the photo).\n";
	} elseif (!empty($post->post_title) and !preg_match('/^[0-9]+$/', $post->post_title)) {
		$attachment_title = html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$prompt .= "\nAttachment Name: \"{$attachment_title}\"\n";
	}

	$prompt .= "\nOutput strictly the alt text in plain text with no quotes, prefixes, or markdown.";

	// The vision model looks at the image itself
	$alt = twcp_model_vision($prompt, twcp_prompt('twcp_system_prompt_media_alt', 'twcp_prompt_alt_default'), [$attachment_id], ['temperature' => 0.4]);

	if (empty($alt)) {
		return null;
	}

	$clean_alt = sanitize_text_field(trim($alt, "\"' \t\n\r"));
	update_post_meta($attachment_id, '_wp_attachment_image_alt', $clean_alt);

	return $clean_alt;
}


/**
 * Count media library images missing an ALT tag
 *
 * @return int
 */
function twcp_media_alt_count(): int
{
	global $wpdb;

	$sql = "SELECT COUNT(p.ID)
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt')
		WHERE p.post_type = 'attachment'
		AND p.post_mime_type LIKE 'image/%'
		AND p.post_status = 'inherit'
		AND (pm.meta_value IS NULL OR pm.meta_value = '')";

	return (int) $wpdb->get_var($sql);
}


/**
 * Register ALT Text column in Media Library list view
 *
 * @param array $columns
 *
 * @return array
 */
function twcp_media_columns(array $columns): array
{
	$new_columns = [];
	foreach ($columns as $key => $title) {
		$new_columns[$key] = $title;
		// Add the Alt text column after the Uploaded to column
		if ($key === 'parent') {
			$new_columns['twcp_alt'] = __('Alt Text', 'twee-mcp');
		}
	}

	if (!isset($new_columns['twcp_alt'])) {
		// Fallback: place before date
		$new_columns = [];
		foreach ($columns as $key => $title) {
			if ($key === 'date') {
				$new_columns['twcp_alt'] = __('Alt Text', 'twee-mcp');
			}
			$new_columns[$key] = $title;
		}
	}

	if (!isset($new_columns['twcp_alt'])) {
		$new_columns['twcp_alt'] = __('Alt Text', 'twee-mcp');
	}

	return $new_columns;
}

add_filter('manage_media_columns', 'twcp_media_columns');


/**
 * Render ALT Text column value in Media Library list view
 *
 * @param string $column_name
 * @param int    $post_id
 */
function twcp_media_custom_column(string $column_name, int $post_id): void
{
	if ($column_name !== 'twcp_alt') {
		return;
	}

	if (!wp_attachment_is_image($post_id)) {
		echo '<span style="color:#a7aaad;">&mdash;</span>';

		return;
	}

	$alt = (string) get_post_meta($post_id, '_wp_attachment_image_alt', true);

	if (!empty($alt)) {
		echo '<div class="twcp-media-alt-val" style="font-size:12px; line-height:1.45; color:#2c3338; word-break:break-word;">' . esc_html($alt) . '</div>';
	} else {
		echo '<span class="twcp-media-alt-empty" style="display:inline-block; font-size:11px; padding:2px 7px; border-radius:3px; background:#fcf0f1; color:#d63638; border:1px solid #f8c8cb; font-weight:500;">' . esc_html__('No Alt text', 'twee-mcp') . '</span>';
	}
}

add_action('manage_media_custom_column', 'twcp_media_custom_column', 10, 2);


/**
 * Make ALT Text column sortable in Media Library
 *
 * @param array $sortable
 *
 * @return array
 */
function twcp_media_sortable_columns(array $sortable): array
{
	$sortable['twcp_alt'] = 'twcp_alt';

	return $sortable;
}

add_filter('manage_upload_sortable_columns', 'twcp_media_sortable_columns');


/**
 * Handle sorting by ALT Text in Media Library
 *
 * @param WP_Query $query
 */
function twcp_media_sorting(WP_Query $query): void
{
	if (!is_admin() or !$query->is_main_query()) {
		return;
	}

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if ($screen and $screen->id !== 'upload') {
		return;
	}

	if ($query->get('orderby') === 'twcp_alt') {
		$query->set('meta_key', '_wp_attachment_image_alt');
		$query->set('orderby', 'meta_value');
	}
}

add_action('pre_get_posts', 'twcp_media_sorting');


/**
 * Add CSS for the ALT Text column in Media Library
 */
add_action('admin_head-upload.php', function(): void {
	?>
	<style>
		.fixed .column-twcp_alt {
			width: 25%;
			min-width: 180px;
		}

		@media screen and (max-width: 782px) {
			.fixed .column-twcp_alt {
				display: none;
			}
		}
	</style>
	<?php
});


/**
 * Audit Alt tags for a list of image attachments and regenerate poor-quality ones
 *
 * @param array $attachment_ids List of attachment IDs.
 *
 * @return array ['checked' => int, 'updated' => int, 'details' => array]
 */
function twcp_media_check_batch(array $attachment_ids): array
{
	$image_ids = [];
	foreach ($attachment_ids as $id) {
		$id = absint($id);
		if (wp_attachment_is_image($id)) {
			$image_ids[] = $id;
		}
	}

	if (empty($image_ids)) {
		return ['checked' => 0, 'updated' => 0, 'details' => []];
	}

	$to_regenerate = [];
	$details = [];
	$items_to_audit = [];

	foreach ($image_ids as $id) {
		$current_alt = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
		$post = get_post($id);
		$file = get_attached_file($id);
		$filename = $file ? basename($file) : '';

		// If Alt is empty, it needs generation
		if ($current_alt === '') {
			$to_regenerate[$id] = __('Missing Alt text', 'twee-mcp');
			$details[$id] = [
				'needs_update' => true,
				'reason'       => __('Missing Alt text', 'twee-mcp'),
				'old_alt'      => '',
			];
			continue;
		}

		$associated = twcp_media_associated_post($post);
		$assoc_title = $associated ? html_entity_decode(get_the_title($associated), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';

		$items_to_audit[$id] = [
			'alt'        => $current_alt,
			'title'      => $post ? $post->post_title : '',
			'filename'   => $filename,
			'associated' => $assoc_title,
		];
	}

	// Evaluate existing Alt tags with the model
	if (!empty($items_to_audit)) {
		$chunks = array_chunk($items_to_audit, 25, true);

		foreach ($chunks as $chunk) {
			$audit_prompt = "You are a professional image accessibility and SEO auditor for " . twcp_site_name() . ".\n";
			$audit_prompt .= "Evaluate whether the existing Alt text for each image is POOR QUALITY and should be REGENERATED, or ACCEPTABLE.\n\n";
			$audit_prompt .= "CRITERIA FOR POOR QUALITY (needs_update: true):\n";
			$audit_prompt .= "- Merely a raw filename, camera code, or extension (e.g. 'IMG_0921', 'DSC1234.jpg', 'banner-final-v2')\n";
			$audit_prompt .= "- Keyword stuffing without grammatical structure (e.g. 'shoes running cheap best buy sale 2024')\n";
			$audit_prompt .= "- Generic placeholders (e.g. 'image', 'photo', 'product', 'banner')\n";
			$audit_prompt .= "- Under-descriptive: 1 or 2 generic words with zero description of material, color, texture, or style\n";
			$audit_prompt .= "- Spammy, garbled, or repetitive phrases\n\n";
			$audit_prompt .= "CRITERIA FOR ACCEPTABLE (needs_update: false):\n";
			$audit_prompt .= "- Descriptive and meaningful: describes the subject, color, material, or setting in a coherent phrase or sentence\n";
			$audit_prompt .= "- Informative for visually impaired users and image search engines\n\n";
			$audit_prompt .= "IMAGES TO EVALUATE:\n";

			foreach ($chunk as $img_id => $info) {
				$audit_prompt .= "Image ID: {$img_id}\n";
				$audit_prompt .= "Current Alt: \"{$info['alt']}\"\n";
				if (!empty($info['title']) and $info['title'] !== $info['alt']) {
					$audit_prompt .= "Image Title: \"{$info['title']}\"\n";
				}
				if (!empty($info['associated'])) {
					$audit_prompt .= "Associated Product: \"{$info['associated']}\"\n";
				}
				$audit_prompt .= "Filename: \"{$info['filename']}\"\n\n";
			}

			$audit_prompt .= "Respond strictly with a JSON object in this exact schema, with no markdown code blocks:\n";
			$audit_prompt .= '{"results":[{"id":123,"needs_update":true,"reason":"Just a filename"},{"id":456,"needs_update":false,"reason":"Descriptive and accurate"}]}';

			$system = "You are an automated JSON API that audits image Alt tags for accessibility and SEO. Always return valid JSON.";
			$response = twcp_model_request($audit_prompt, $system, [], ['temperature' => 0.1]);

			$parsed = twcp_model_response($response);

			if (is_array($parsed) and !empty($parsed['results']) and is_array($parsed['results'])) {
				foreach ($parsed['results'] as $item) {
					$item_id = isset($item['id']) ? absint($item['id']) : 0;
					if ($item_id and isset($chunk[$item_id])) {
						$needs_update = !empty($item['needs_update']);
						$reason = isset($item['reason']) ? sanitize_text_field($item['reason']) : '';

						$details[$item_id] = [
							'needs_update' => $needs_update,
							'reason'       => $reason,
							'old_alt'      => $chunk[$item_id]['alt'],
						];

						if ($needs_update) {
							$to_regenerate[$item_id] = $reason;
						}
					}
				}
			}
		}
	}

	// Regenerate poor-quality or missing Alt tags using vision model
	$updated_count = 0;
	foreach ($to_regenerate as $reg_id => $reason) {
		$new_alt = twcp_media_generate_alt($reg_id, true);
		if (!empty($new_alt)) {
			$updated_count++;
			if (isset($details[$reg_id])) {
				$details[$reg_id]['new_alt'] = $new_alt;
			}
		}
	}

	return [
		'checked' => count($image_ids),
		'updated' => $updated_count,
		'details' => $details,
	];
}


/**
 * Tool/Action: Audit Alt text quality and regenerate poor tags
 *
 * @param array $args
 *
 * @return array
 */
function twcp_media_check_tool(array $args): array
{
	$ids = !empty($args['attachment_ids']) && is_array($args['attachment_ids']) ? $args['attachment_ids'] : [];
	if (empty($ids)) {
		return ['error' => 'attachment_ids array is required'];
	}
	$result = twcp_media_check_batch($ids);

	return [
		'success' => true,
		'checked' => $result['checked'],
		'updated' => $result['updated'],
		'details' => $result['details'],
	];
}


/**
 * Whether the current user can upload the image the import_media_image tool names, and attach and assign it
 *
 * @param array $input
 *
 * @return string|null Error message, or null when allowed
 */
function twcp_media_import_access(array $input): ?string
{
	if (!current_user_can('upload_files')) {
		return 'You cannot upload media.';
	}
	if (!empty($input['post_id']) and !current_user_can('edit_post', absint($input['post_id']))) {
		return 'You cannot edit the parent post.';
	}

	return empty($input['assign_to']) ? null : twcp_media_assign_access((array) $input['assign_to']);
}


/**
 * Download remote image from URL and import into WordPress Media Library
 *
 * @param string|array $url_or_args
 * @param array        $meta
 *
 * @return array
 */
function twcp_media_import($url_or_args, array $meta = []): array
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
		$assign_result = twcp_media_assign($attachment_id, $meta['assign_to']);
		$result['assigned_to'] = $assign_result;
	}

	return $result;
}


/**
 * Whether the current user can assign an image to the target the assign_media_image tool names
 *
 * @param array $input
 *
 * @return string|null Error message, or null when allowed
 */
function twcp_media_assign_access(array $input): ?string
{
	return current_user_can('upload_files') ? twcp_tools_access_target($input) : 'You cannot assign media.';
}


/**
 * Assign a media attachment to a WordPress entity
 *
 * @param int|array $attachment_id_or_args
 * @param array     $target
 *
 * @return array
 */
function twcp_media_assign($attachment_id_or_args, array $target = []): array
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
		case 'post':
		case 'page':
			if (!$target_id) {
				return ['error' => 'Missing post ID.'];
			}

			$post = get_post($target_id);
			if (!$post) {
				return ['error' => "Post #{$target_id} not found."];
			}
			if (!in_array($assign_as, ['featured', 'content'], true)) {
				return ['error' => 'Unknown post image role.'];
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

			$term = get_term($target_id, sanitize_key($target['taxonomy'] ?? ''));
			if (!$term or is_wp_error($term)) {
				return ['error' => "Taxonomy term #{$target_id} not found."];
			}

			update_term_meta($target_id, 'thumbnail_id', $attachment_id);
			update_term_meta($target_id, 'image', $attachment_id);

			return [
				'success'       => true,
				'type'          => 'term',
				'term_id'       => $target_id,
				'taxonomy'      => $term->taxonomy,
				'term_name'     => $term->name,
				'attachment_id' => $attachment_id,
				'message'       => sprintf("Set thumbnail image #%d for term '%s' (#%d).", $attachment_id, $term->name, $target_id),
			];

		default:
			// Modules assign to their own targets, such as products and blocks
			return apply_filters('twcp_media_assign', null, $type, $attachment_id, $target_id, $assign_as, $target)
				?? ['error' => "Unsupported target type '{$type}'."];
	}
}
