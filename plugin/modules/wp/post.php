<?php
/**
 * Twee MCP — Posts, Pages & CPT Management
 *
 * Implements MCP tools for querying, creating, and editing posts,
 * schema introspection, automated AI SEO Title & Description generation,
 * excerpt generation, full article generation, stock featured image assignment,
 * and category classification.
 * Completely independent of theme helpers, using WordPress Core API.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Format rich post / product details for MCP responses
 *
 * @param WP_Post $post
 * @param bool    $full_content
 *
 * @return array
 */
function twcp_post_format_details(WP_Post $post, bool $full_content = true): array
{
	$post_id = $post->ID;

	// 1. Current SEO metadata (Rank Math and Yoast SEO support)
	$rm_title = (string) get_post_meta($post_id, 'rank_math_title', true);
	$rm_desc = (string) get_post_meta($post_id, 'rank_math_description', true);
	$rm_kw = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);

	$yoast_title = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
	$yoast_desc = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
	$yoast_kw = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);

	$seo_title = $rm_title ? : ($yoast_title ? : $post->post_title);
	$seo_description = $rm_desc ? : ($yoast_desc ? : $post->post_excerpt);

	$seo = [
		'title'           => $seo_title,
		'description'     => $seo_description,
		'focus_keyword'   => $rm_kw ? : ($yoast_kw ? : ''),
		'rank_math_title' => $rm_title ? : null,
		'rank_math_desc'  => $rm_desc ? : null,
		'yoast_title'     => $yoast_title ? : null,
		'yoast_desc'      => $yoast_desc ? : null,
	];

	// 2. Featured Image Details
	$thumb_id = (int) get_post_thumbnail_id($post_id);
	$featured_image = null;
	if ($thumb_id > 0) {
		$img_src = wp_get_attachment_image_src($thumb_id, 'full');
		$featured_image = [
			'id'     => $thumb_id,
			'url'    => $img_src ? $img_src[0] : (wp_get_attachment_url($thumb_id) ? : ''),
			'alt'    => (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true),
			'width'  => $img_src ? (int) $img_src[1] : null,
			'height' => $img_src ? (int) $img_src[2] : null,
		];
	}

	// 3. Gallery Images (WooCommerce product gallery or meta)
	$gallery_images = [];
	$raw_gallery = (string) get_post_meta($post_id, '_product_image_gallery', true);
	if (!empty($raw_gallery)) {
		$gallery_ids = array_filter(array_map('intval', explode(',', $raw_gallery)));
		foreach ($gallery_ids as $gid) {
			$g_src = wp_get_attachment_image_src($gid, 'full');
			$g_url = wp_get_attachment_url($gid);
			if ($g_src or $g_url) {
				$gallery_images[] = [
					'id'     => $gid,
					'url'    => $g_src ? $g_src[0] : ($g_url ? : ''),
					'alt'    => (string) get_post_meta($gid, '_wp_attachment_image_alt', true),
					'width'  => $g_src ? (int) $g_src[1] : null,
					'height' => $g_src ? (int) $g_src[2] : null,
				];
			}
		}
	}

	// 4. Object Taxonomies & Terms
	$taxonomies = get_object_taxonomies($post->post_type, 'names');
	$terms = [];
	foreach ($taxonomies as $tax) {
		$post_terms = get_the_terms($post, $tax);
		if (!is_wp_error($post_terms) and !empty($post_terms)) {
			$terms[$tax] = array_map(function($t) {
				return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'link' => get_term_link($t)];
			}, $post_terms);
		}
	}

	$data = [
		'id'             => $post->ID,
		'title'          => $post->post_title,
		'slug'           => $post->post_name,
		'status'         => $post->post_status,
		'post_type'      => $post->post_type,
		'date'           => $post->post_date,
		'modified'       => $post->post_modified,
		'link'           => get_permalink($post_id),
		'excerpt'        => $post->post_excerpt,
		'thumbnail_id'   => $thumb_id ? : null,
		'seo'            => $seo,
		'featured_image' => $featured_image,
		'gallery_images' => $gallery_images,
		'terms'          => $terms,
	];

	if ($full_content) {
		$data['content'] = $post->post_content;
	}

	// 5. WooCommerce Product details (if post_type is product or WooCommerce is active)
	if (in_array($post->post_type, ['product', 'product_variation'], true) and class_exists('WooCommerce')) {
		$product = wc_get_product($post_id);
		if ($product) {
			$attributes = array_map(static fn($attribute) => $attribute instanceof WC_Product_Attribute ? $attribute->get_data() : $attribute, $product->get_attributes());

			$data['product'] = [
				'sku'               => $product->get_sku(),
				'type'              => $product->get_type(),
				'status'            => $product->get_status(),
				'currency'          => get_woocommerce_currency(),
				'attributes'        => $attributes,
				'manage_stock'      => $product->get_manage_stock(),
				'gallery_image_ids' => $product->get_gallery_image_ids(),
				'upsell_ids'        => $product->get_upsell_ids(),
				'cross_sell_ids'    => $product->get_cross_sell_ids(),
				'price'             => $product->get_price(),
				'regular_price'     => $product->get_regular_price(),
				'sale_price'        => $product->get_sale_price(),
				'stock_status'      => $product->get_stock_status(),
				'stock_quantity'    => $product->get_stock_quantity(),
				'short_description' => $product->get_short_description(),
				'weight'            => $product->get_weight(),
				'dimensions'        => $product->get_dimensions(false),
			];

		}
	}

	return $data;
}


/**
 * Action/Tool: Query posts, pages, WooCommerce products, or custom post types
 *
 * @param array $args
 *
 * @return array
 */
function twcp_post_query(array $args): array
{
	if (!empty($args['id'])) {
		$post_id = absint($args['id']);
		$post = get_post($post_id);

		if (!$post) {
			return ['error' => 'Post not found: ' . $post_id];
		}

		return twcp_post_format_details($post, true);
	}

	$post_type = !empty($args['post_type']) ? sanitize_key($args['post_type']) : 'any';
	$limit = isset($args['limit']) ? min(50, max(1, (int) $args['limit'])) : 20;
	$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
	$status = !empty($args['post_status']) ? sanitize_key($args['post_status']) : 'publish';

	$query_args = [
		'post_type'      => $post_type,
		'post_status'    => $status,
		'posts_per_page' => $limit,
		'offset'         => $offset,
		'orderby'        => !empty($args['order_by']) ? sanitize_key($args['order_by']) : 'date',
		'order'          => (!empty($args['order']) && strtoupper($args['order']) === 'ASC') ? 'ASC' : 'DESC',
	];
	if ($post_type === 'product') {
		$meta_query = [];
		if (isset($args['sku'])) {
			$meta_query[] = ['key' => '_sku', 'value' => sanitize_text_field($args['sku'])];
		}
		if (!empty($args['stock_status'])) {
			$meta_query[] = ['key' => '_stock_status', 'value' => sanitize_key($args['stock_status'])];
		}
		if (isset($args['stock_below'])) {
			$meta_query[] = ['key' => '_manage_stock', 'value' => 'yes'];
			$meta_query[] = ['key' => '_stock', 'value' => (int) $args['stock_below'], 'compare' => '<', 'type' => 'NUMERIC'];
		}
		if ($meta_query) {
			$query_args['meta_query'] = $meta_query;
		}
	}
	if (!empty($args['search'])) {
		$query_args['s'] = sanitize_text_field($args['search']);
	}

	if (!empty($args['taxonomy']) and !empty($args['term_id'])) {
		$query_args['tax_query'] = [
			[
				'taxonomy' => sanitize_key($args['taxonomy']),
				'field'    => 'term_id',
				'terms'    => (int) $args['term_id'],
			],
		];
	}

	$query = new WP_Query($query_args);
	$posts = array_map(static fn(WP_Post $post) => twcp_post_format_details($post, false), $query->posts);

	return [
		'total'  => (int) $query->found_posts,
		'limit'  => $limit,
		'offset' => $offset,
		'posts'  => $posts,
	];
}


/**
 * Create a post, page, or registered custom post type.
 *
 * @param array $args Post fields.
 *
 * @return array Created post or error.
 */
function twcp_post_create(array $args): array
{
	if (empty($args['title'])) {
		return ['error' => 'Title is required to create a post.'];
	}

	return twcp_post_write($args);
}

/**
 * Update only the supplied fields of an existing post.
 *
 * @param array $args Post ID and changed fields.
 *
 * @return array Updated post or error.
 */
function twcp_post_edit(array $args): array
{
	$post = get_post(absint($args['id'] ?? 0));
	if (empty($args['id']) or !$post) {
		return ['error' => 'Post not found.'];
	}

	return twcp_post_write($args, $post);
}


/**
 * Save explicitly supplied SEO metadata for a post, page, product, or CPT.
 *
 * @param int   $post_id Post ID.
 * @param array $args    Tool arguments containing SEO fields.
 *
 * @return void
 */
function twcp_post_update_seo(int $post_id, array $args): void
{
	$fields = [
		'seo_title'       => ['rank_math_title', '_yoast_wpseo_title'],
		'seo_description' => ['rank_math_description', '_yoast_wpseo_metadesc'],
	];

	foreach ($fields as $field => $meta_keys) {
		if (!array_key_exists($field, $args)) {
			continue;
		}

		$value = sanitize_text_field((string) $args[$field]);
		foreach ($meta_keys as $meta_key) {
			update_post_meta($post_id, $meta_key, $value);
		}
	}
}


/**
 * Validate, map, and persist fields shared by post creation and editing.
 *
 * @param array        $args Post fields.
 * @param WP_Post|null $post Existing post when editing.
 *
 * @return array Saved post or error.
 */
function twcp_post_write(array $args, ?WP_Post $post = null): array
{
	$post_type = $post ? $post->post_type : ($args['post_type'] ?? 'post');
	$post_id = $post->ID ?? 0;

	if (!post_type_exists($post_type)) {
		return ['error' => 'Unknown post type.'];
	}
	if (isset($args['post_status']) and !in_array($args['post_status'], ['draft', 'pending', 'publish', 'private', 'future'], true)) {
		return ['error' => 'Unsupported post status.'];
	}
	if (!empty($args['thumbnail_id']) and !wp_attachment_is_image(absint($args['thumbnail_id']))) {
		return ['error' => 'Thumbnail must be an image attachment.'];
	}
	if (!empty($args['parent']) and get_post_type(absint($args['parent'])) !== $post_type) {
		return ['error' => 'Parent must have the same post type.'];
	}

	if (isset($args['taxonomies'])) {
		$valid = twcp_term_validate_assignments($post_type, $args['taxonomies']);
		if (is_wp_error($valid)) {
			return ['error' => $valid->get_error_message()];
		}
	}

	$registered = get_registered_meta_keys('post', $post_type);
	$seo_keys = [
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
	];
	foreach (($args['meta'] ?? []) as $key => $value) {
		$protected = is_protected_meta($key, 'post');
		$allowed = isset($registered[$key]) || in_array($key, $seo_keys, true);
		if ($protected and !$allowed) {
			return ['error' => 'Protected meta must be managed through its dedicated tool: ' . $key];
		}
		if ($post_id and !in_array($key, $seo_keys, true) and !current_user_can('edit_post_meta', $post_id, $key)) {
			return ['error' => 'You cannot edit meta: ' . $key];
		}
	}

	if (isset($args['date'])) {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $args['date'], wp_timezone());
		if (!$date or $date->format('Y-m-d H:i:s') !== $args['date']) {
			return ['error' => 'Date must use YYYY-MM-DD HH:MM:SS format.'];
		}
	}
	if (($args['post_status'] ?? '') === 'future' and (empty($date) or $date->getTimestamp() <= time())) {
		return ['error' => 'Scheduled posts require a future date.'];
	}

	$data = $post ? ['ID' => $post->ID] : ['post_type' => $post_type, 'post_status' => $args['post_status'] ?? 'publish'];
	$fields = [
		'title'       => 'post_title',
		'content'     => 'post_content',
		'excerpt'     => 'post_excerpt',
		'post_status' => 'post_status',
		'slug'        => 'post_name',
		'parent'      => 'post_parent',
		'date'        => 'post_date',
	];

	foreach ($fields as $field => $column) {
		if (!array_key_exists($field, $args)) {
			continue;
		}

		$value = $args[$field];
		if ($field === 'content') {
			$value = wp_kses_post($value);
		} elseif ($field === 'slug') {
			$value = sanitize_title($value);
		} elseif ($field === 'parent') {
			$value = absint($value);
		} elseif ($field === 'excerpt') {
			$value = sanitize_textarea_field($value);
		} else {
			$value = sanitize_text_field($value);
		}

		$data[$column] = $value;
	}

	if (isset($args['date'])) {
		$data['post_date_gmt'] = get_gmt_from_date($args['date']);
		$data['edit_date'] = true;
	}

	$id = $post ? wp_update_post($data, true) : wp_insert_post($data, true);
	if (is_wp_error($id)) {
		return ['error' => $id->get_error_message()];
	}

	if (isset($args['thumbnail_id'])) {
		if ($args['thumbnail_id']) {
			set_post_thumbnail($id, absint($args['thumbnail_id']));
		} else {
			delete_post_thumbnail($id);
		}
	}

	foreach (($args['taxonomies'] ?? []) as $taxonomy => $terms) {
		$result = wp_set_object_terms($id, array_map('intval', (array) $terms), $taxonomy, !empty($args['append_terms']));
		if (is_wp_error($result)) {
			return ['error' => $result->get_error_message(), 'id' => $id];
		}
	}

	foreach (($args['meta'] ?? []) as $key => $value) {
		update_post_meta($id, $key, $value);
	}
	twcp_post_update_seo($id, $args);

	return ['success' => true, 'id' => $id, 'title' => get_the_title($id), 'link' => get_permalink($id)];
}


/**
 * Action/Tool: Site schema introspection (post types and taxonomies)
 *
 * @param array $args
 *
 * @return array
 */
function twcp_post_schema(array $args): array
{
	$type = !empty($args['type']) ? sanitize_key($args['type']) : 'all';
	$result = [];

	if ($type === 'all' or $type === 'post_types') {
		$pts = get_post_types(['show_ui' => true], 'objects');
		$types = [];

		foreach ($pts as $k => $pt) {
			$types[$k] = [
				'label'        => $pt->label,
				'description'  => $pt->description,
				'hierarchical' => (bool) $pt->hierarchical,
				'taxonomies'   => get_object_taxonomies($k, 'names'),
				'supports'     => get_all_post_type_supports($k),
				'capabilities' => (array) $pt->cap,
				'can_create'   => current_user_can($pt->cap->create_posts),
			];
		}
		$result['post_types'] = $types;
	}

	if ($type === 'all' or $type === 'taxonomies') {
		$taxs = get_taxonomies(['public' => true], 'objects');
		$taxonomies = [];

		foreach ($taxs as $k => $tax) {
			$taxonomies[$k] = [
				'label'        => $tax->label,
				'description'  => $tax->description,
				'hierarchical' => (bool) $tax->hierarchical,
				'object_types' => $tax->object_type,
				'can_assign'   => current_user_can($tax->cap->assign_terms),
			];
		}
		$result['taxonomies'] = $taxonomies;
	}

	$result['site_context'] = twcp_general_context();
	$result['gallery'] = [
		'shortcode'    => '[gallery ids="12,34" columns="3" size="large" link="file"]',
		'instructions' => 'Use build_gallery_shortcode with Media Library IDs, then insert the result ' . 'in post content or an ACF rich-text field. Use edit_product.gallery_image_ids for product galleries.',
	];

	return $result;
}

/**
 * Move a post to the recoverable WordPress Trash.
 *
 * @param array $args Tool arguments containing the post ID.
 *
 * @return array Trash result or error.
 */
function twcp_post_trash(array $args): array
{
	$id = absint($args['id']);
	if (!EMPTY_TRASH_DAYS or get_post_type($id) === 'attachment') {
		return ['error' => 'Recoverable trash is unavailable for this content.'];
	}

	$result = wp_trash_post($id);

	return $result ? ['success' => true, 'id' => $id, 'status' => 'trash'] : ['error' => 'Could not move this post to Trash.'];
}


/**
 * Page builder blocks of a post or term, added by the blocks module
 *
 * @param int    $id
 * @param string $meta_type "post" or "term"
 *
 * @return array
 */
function twcp_post_blocks(int $id, string $meta_type = 'post'): array
{
	return (array) apply_filters('twcp_post_blocks', [], $id, $meta_type);
}


/**
 * Recursively extract text from ACF Page Builder blocks array
 *
 * @param mixed $blocks
 *
 * @return string
 */
function twcp_post_blocks_text($blocks): string
{
	if (empty($blocks) or !is_array($blocks)) {
		return '';
	}

	$lines = [];

	foreach ($blocks as $block) {
		if (!is_array($block)) {
			continue;
		}

		$layout = !empty($block['acf_fc_layout']) ? $block['acf_fc_layout'] : '';
		if ($layout) {
			$lines[] = "[Block: {$layout}]";
		}

		if (!empty($block['contents']) and is_array($block['contents'])) {
			if (!empty($block['contents']['title'])) {
				$lines[] = 'Title: ' . wp_strip_all_tags($block['contents']['title']);
			}
			if (!empty($block['contents']['caption'])) {
				$lines[] = 'Caption: ' . wp_strip_all_tags($block['contents']['caption']);
			}
			if (!empty($block['contents']['text'])) {
				$lines[] = wp_strip_all_tags($block['contents']['text']);
			}
		}

		if (!empty($block['title'])) {
			$lines[] = 'Title: ' . wp_strip_all_tags($block['title']);
		}
		if (!empty($block['text'])) {
			$lines[] = wp_strip_all_tags($block['text']);
		}

		if (!empty($block['items']) and is_array($block['items'])) {
			foreach ($block['items'] as $item) {
				if (is_array($item)) {
					if (!empty($item['title'])) {
						$lines[] = '- ' . wp_strip_all_tags($item['title']);
					}
					if (!empty($item['text'])) {
						$lines[] = '  ' . wp_strip_all_tags($item['text']);
					}
				}
			}
		}
	}

	return implode("\n", array_slice($lines, 0, 50));
}


/**
 * Extract clean title, stripped content, and builder blocks text for a post
 *
 * @param WP_Post|int $post
 *
 * @return array|null ['post' => WP_Post, 'title' => string, 'content' => string, 'blocks_text' => string]
 */
function twcp_post_context($post): ?array
{
	$post = get_post($post);
	if (!$post) {
		return null;
	}

	$blocks = twcp_post_blocks($post->ID);

	return [
		'post'        => $post,
		'title'       => $post->post_title,
		'content'     => wp_strip_all_tags($post->post_content),
		'blocks_text' => twcp_post_blocks_text($blocks),
	];
}


/**
 * Parse SEO JSON response with regex fallback
 *
 * @param string $response
 *
 * @return array|null
 */
function twcp_post_seo_json(string $response): ?array
{
	if (empty($response)) {
		return null;
	}

	$data = twcp_model_response($response);
	if (is_array($data) and !empty($data['title']) and !empty($data['description'])) {
		return [
			'title'       => sanitize_text_field($data['title']),
			'description' => sanitize_text_field($data['description']),
		];
	}

	if (preg_match('/"title"\s*:\s*"([^"]+)"/i', $response, $mt) and preg_match('/"description"\s*:\s*"([^"]+)"/i', $response, $md)) {
		return [
			'title'       => sanitize_text_field($mt[1]),
			'description' => sanitize_text_field($md[1]),
		];
	}

	return null;
}


/**
 * Default built-in system prompt for post/page SEO generation (>1024 tokens)
 *
 * @return string
 */
function twcp_prompt_seo_default(): string
{
	$site_name = twcp_site_name();

	return <<<PROMPT
You are a senior search engine optimization (SEO) strategist and conversion copywriter specializing in organic search rankings and click-through-rate (CTR) maximization for business, content, and e-commerce websites.

Your objective is to generate the highest-performing SEO Title tag and Meta Description for given website content on {$site_name}.

CORE PRINCIPLES & GUIDELINES:

1. TITLE TAG SPECIFICATIONS:
   - Length: Strictly between 50 and 60 characters (to avoid pixel truncation in Google desktop and mobile SERPs).
   - Structure: [Primary Target Keyword / Concept] - [Compelling Benefit or Feature] | {$site_name}
   - Front-load the most critical keyword terms in the first 30 characters.
   - Use title case, clean spacing, and avoid excessive punctuation (avoid ALL-CAPS or exclamation points).
   - Ensure the title accurately matches the user's search intent (commercial, transactional, or informational).

2. META DESCRIPTION SPECIFICATIONS:
   - Length: Strictly between 135 and 155 characters (optimal for Google's desktop snippet and mobile displays).
   - Structure: State the main value proposition, highlight unique attributes (e.g. features, expertise, pricing, delivery), and conclude with an enticing, active call-to-action (e.g. "Shop now", "Read the guide", "Get a quote").
   - Voice: Active, confident, clear, and direct. Avoid passive phrasing like "This post is about...".
   - Keyword Integration: Include secondary keyword variations naturally without keyword stuffing.

3. OUTPUT FORMAT & JSON VALIDATION:
   - Output must be strictly valid JSON without any markdown formatting, code ticks (```json), or introductory/concluding commentary.
   - The JSON object must strictly match this exact schema:
     {"title": "Compelling Title Here | {$site_name}", "description": "Engaging description here under 155 characters."}

4. FEW-SHOT EXAMPLES:
   Input: Title: How to Choose a Project Management Tool, Post Type: post
   Output: {"title": "How to Choose a Project Management Tool | {$site_name}", "description": "Compare features, pricing, and team fit to pick the right project management tool. Use our checklist to decide with confidence. Read the guide."}

   Input: Title: Web Design Services, Post Type: page
   Output: {"title": "Custom Web Design Services | {$site_name}", "description": "Get a fast, accessible website designed around your goals. See our process, recent projects, and pricing, then book a free consultation today."}
PROMPT;
}


/**
 * Generate SEO Title and Description for a post, page, product, or CPT
 *
 * @param int $post_id
 *
 * @return bool
 */
function twcp_post_generate_seo(int $post_id, bool $force = false): bool
{
	$post = get_post($post_id);
	if (!$post) {
		return false;
	}

	$rm_title = (string) get_post_meta($post_id, 'rank_math_title', true);
	$rm_desc = (string) get_post_meta($post_id, 'rank_math_description', true);
	$yoast_title = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
	$yoast_desc = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);

	$current_title = $rm_title ? : $yoast_title;
	$current_desc = $rm_desc ? : $yoast_desc;

	if (!$force and !empty($current_title) and !empty($current_desc)) {
		return true;
	}

	$ctx = twcp_post_context($post);
	$title = $ctx['title'];
	$content = $ctx['content'];
	$blocks_text = $ctx['blocks_text'];

	$summary = '';
	if (!empty($blocks_text)) {
		$summary .= "Page Builder Content:\n" . $blocks_text . "\n\n";
	}
	if (!empty($content)) {
		$summary .= "Post Body Content:\n" . substr($content, 0, 1000) . "\n";
	}

	$site_name = twcp_site_name();

	$prompt = "Generate an SEO Title and Meta Description for the following content on {$site_name} store.\n";
	$prompt .= "Title: {$title}\n";
	$prompt .= "Post Type: {$post->post_type}\n";
	if ($summary) {
		$prompt .= "Content Summary:\n{$summary}\n";
	}

	if (!empty($current_title) or !empty($current_desc)) {
		$current = !empty($current_title) ? "- Title: \"{$current_title}\"\n" : '';
		$current .= !empty($current_desc) ? "- Meta Description: \"{$current_desc}\"\n" : '';
		$prompt .= twcp_rewrite_note('SEO tags', rtrim($current), 'Improve these SEO tags to maximize organic search CTR and ranking.');
	}

	$prompt .= "Requirements:\n";
	$prompt .= "1. SEO Title: 50 to 60 characters, compelling, contains primary keywords, ends with \"| {$site_name}\".\n";
	$prompt .= "2. SEO Meta Description: 140 to 160 characters, persuasive with an action-driven benefit.\n";
	$prompt .= "Output strictly valid JSON with this format and NO surrounding markdown:\n";
	$prompt .= '{"title": "...", "description": "..."}';

	$response = twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_seo', 'twcp_prompt_seo_default'), [], ['temperature' => 0.4]);
	$seo = twcp_post_seo_json($response);

	if (!$seo) {
		return false;
	}

	$seo_title = $seo['title'];
	$seo_desc = $seo['description'];

	twcp_post_update_seo($post_id, [
		'seo_title'       => $seo_title,
		'seo_description' => $seo_desc,
	]);

	// Fallback to post excerpt if empty
	if (empty($post->post_excerpt)) {
		wp_update_post([
			'ID'           => $post_id,
			'post_excerpt' => $seo_desc,
		]);
	}

	update_post_meta($post_id, '_twcp_seo_generated', time());

	return true;
}


/**
 * Default system prompt for post short excerpt generation
 *
 * @return string
 */
function twcp_prompt_excerpt_default(): string
{
	$site_name = twcp_site_name();

	return <<<PROMPT
You are a senior editor and conversion copywriter for {$site_name}.
Your objective is to generate a concise, engaging short excerpt (summary) for a blog post or page.

GUIDELINES:
1. Length: Exactly 1 to 2 sentences (between 25 and 45 words, or 130 to 180 characters).
2. Voice & Style: Clear, compelling, active voice. Hook the reader and outline the primary topic or value of the article.
3. Content: Ground the excerpt in the post's content, builder blocks, or title. Do not invent unrelated topics.
4. Formatting: Output strictly plain text without quotes, prefixes like "Excerpt:", or markdown formatting.
PROMPT;
}


/**
 * Generate a short description/excerpt for a post (post_excerpt) if not set
 *
 * @param int  $post_id Post ID.
 * @param bool $force   Whether to overwrite an existing excerpt.
 *
 * @return bool
 */
function twcp_post_generate_excerpt(int $post_id, bool $force = false): bool
{
	$post = get_post($post_id);
	if (!$post) {
		return false;
	}

	if (!$force and !empty(trim($post->post_excerpt))) {
		return true;
	}

	$ctx = twcp_post_context($post);
	$title = $ctx['title'];
	$content = $ctx['content'];
	$blocks_text = $ctx['blocks_text'];

	$summary = '';
	if (!empty($blocks_text)) {
		$summary .= "Page Builder Content:\n" . substr($blocks_text, 0, 1500) . "\n\n";
	}
	if (!empty($content)) {
		$summary .= "Post Content:\n" . substr($content, 0, 1500) . "\n\n";
	}

	$prompt = "Write a concise, engaging short excerpt for this post:\n";
	$prompt .= "Title: \"{$title}\"\n";
	$prompt .= "Post Type: {$post->post_type}\n";
	if ($summary) {
		$prompt .= "Context Summary:\n{$summary}\n";
	}
	$current_excerpt = trim($post->post_excerpt);
	if (!empty($current_excerpt)) {
		$prompt .= twcp_rewrite_note('excerpt', "\"{$current_excerpt}\"");
	}
	$prompt .= "Output strictly 1 to 2 sentences in plain text without quotes or markdown.";

	$response = twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_post_excerpt', 'twcp_prompt_excerpt_default'), [], ['temperature' => 0.4]);

	if (empty($response)) {
		return false;
	}

	$clean_excerpt = sanitize_textarea_field(trim($response, "\"' \t\n\r"));
	if (empty($clean_excerpt)) {
		return false;
	}

	wp_update_post([
		'ID'           => $post_id,
		'post_excerpt' => $clean_excerpt,
	]);

	return true;
}


/**
 * Default system prompt for full article/page content generation based on "Create and Publish an Article" example
 *
 * @return string
 */
function twcp_prompt_content_default(): string
{
	$site_name = twcp_site_name();
	$site_url = home_url('/');

	return <<<PROMPT
You are a senior content creator and subject-matter writer for {$site_name} ({$site_url}).
Your objective is to write a complete, high-quality, long-form article for the blog.

Requirements for post content:
* Align with the topics, products, and services in the store profile.
* Be engaging and interesting, combining technical information with fascinating facts.
* Length & Structure: Comprehensive article divided into 4 to 8 sections using proper HTML headings (H2, H3).
* Use valid WordPress HTML without wrapper tags (no <html>, <body>, or duplicate H1).
* Start each section with an H2 subtitle, covering the section topic thoroughly.
* Use 2 to 4 H3 tags for subtopics where appropriate, with at least two paragraphs per H3 heading.
* Avoid generic words like "Introduction", "Conclusion", or "Summary" in H2 headings.
* Subtitles should relate to the main topic naturally.
* Include ordered or unordered lists (UL/OL and LI) with 3 to 8 items to present key points clearly.
* In addition to lists, consider using comparison tables (<table class=\"table\">) to present relevant specs or comparisons.
* Begin the first section with a natural, engaging summary—consider using an open question to invite further reading.
* Include natural references to relevant products, services, examples, and technical details to make the article more informative.
* Output strictly valid HTML content ready to be saved into post_content. Do not include markdown code block ticks (```html).
PROMPT;
}


/**
 * Approved internal links for generated post content, or generated HTML with unapproved links unwrapped
 *
 * The link sources and the maximum number of links are chosen in the Internal Links panel of the Automation screen.
 *
 * @param int         $post_id Post the content is written for, or the source of a similar post
 * @param string|null $html    Generated HTML to check
 *
 * @return array|string [ note => prompt text, read_tools => lookups to offer ], or the checked HTML
 */
function twcp_post_links(int $post_id, ?string $html = null)
{
	$settings = array_merge(['max' => 4, 'sources' => ['terms', 'posts', 'products']], (array) get_option('twcp_post_links', []));
	$sources = (array) $settings['sources'];
	$post = get_post($post_id);
	$links = [];

	if ($post and $settings['max'] > 0) {
		// The post's own categories and tags, which also pick the related posts
		$tax_query = ['relation' => 'OR'];
		$default_category = (int) get_option('default_category');
		foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
			$terms = $taxonomy->public ? wp_get_object_terms($post_id, $taxonomy->name) : [];
			foreach (is_wp_error($terms) ? [] : $terms as $term) {
				if ((int) $term->term_id === $default_category) {
					continue;
				}
				$tax_query[] = ['taxonomy' => $term->taxonomy, 'terms' => $term->term_id];
				$link = get_term_link($term);
				if (in_array('terms', $sources, true) and !is_wp_error($link)) {
					$links[$link] = $term->name;
				}
			}
		}

		if (in_array('posts', $sources, true)) {
			$related = get_posts([
				'post_type'    => $post->post_type,
				'post_status'  => 'publish',
				'numberposts'  => 5,
				'post__not_in' => [$post_id],
				'tax_query'    => count($tax_query) > 1 ? $tax_query : [],
			]);
			foreach ($related as $item) {
				$links[get_permalink($item)] = $item->post_title;
			}
		}

		if (in_array('pages', $sources, true)) {
			$exclude = [$post_id, (int) get_option('page_on_front'), (int) get_option('wp_page_for_privacy_policy')];
			foreach (get_pages(['parent' => 0, 'number' => 6, 'sort_column' => 'menu_order', 'exclude' => $exclude]) as $page) {
				$links[get_permalink($page)] = $page->post_title;
			}
		}

		if (in_array('products', $sources, true) and class_exists('WooCommerce')) {
			$categories = get_terms(['taxonomy' => 'product_cat', 'number' => 5, 'orderby' => 'count', 'order' => 'DESC', 'exclude' => [(int) get_option('default_product_cat')]]);
			foreach (is_wp_error($categories) ? [] : $categories as $term) {
				$link = get_term_link($term);
				if (!is_wp_error($link)) {
					$links[$link] = $term->name;
				}
			}
		}
	}

	$lookup = $settings['max'] > 0 && in_array('lookup', $sources, true);

	if ($html === null) {
		$note = '';
		if ($links or $lookup) {
			$note = "\nInternal links: add 1 to {$settings['max']} links where they fit the text naturally, spread across sections, with descriptive anchor text. ";
			$note .= $lookup ? "Use only the approved URLs below or URLs returned by the lookup tools." : "Use only the approved URLs below.";
			$note .= " Never invent URLs or output naked URLs.\n";
			foreach ($links as $url => $name) {
				$note .= '<a href="' . esc_url($url) . '">' . esc_html($name) . "</a>\n";
			}
		} else {
			$note = "\nDo not add links.\n";
		}

		return ['note' => $note, 'read_tools' => $lookup ? ['get_posts', 'get_terms'] : false];
	}

	// Keep approved links, links the post already had, in-page anchors, and site URLs found by lookups
	preg_match_all('/href=["\']([^"\']+)["\']/i', $post->post_content ?? '', $existing);
	$known = array_map('untrailingslashit', array_merge(array_keys($links), $existing[1]));

	return preg_replace_callback('#<a\s[^>]*?href=["\']([^"\']*)["\'][^>]*>(.*?)</a>#is', function(array $match) use ($known, $lookup): string {
		$url = html_entity_decode($match[1]);
		if (str_starts_with($url, '#') or in_array(untrailingslashit($url), $known, true)) {
			return $match[0];
		}
		if ($lookup and wp_parse_url($url, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) {
			if (url_to_postid($url)) {
				return $match[0];
			}
			$terms = get_terms(['slug' => basename((string) wp_parse_url($url, PHP_URL_PATH)), 'hide_empty' => false]);
			foreach (is_wp_error($terms) ? [] : $terms as $term) {
				if (untrailingslashit(get_term_link($term)) === untrailingslashit($url)) {
					return $match[0];
				}
			}
		}

		return $match[2];
	}, $html);
}


/**
 * Generate full page content (post_content) for a post if empty
 *
 * @param int  $post_id Post ID.
 * @param bool $force   Whether to overwrite existing content.
 *
 * @return bool
 */
function twcp_post_generate_content(int $post_id, bool $force = false): bool
{
	$post = get_post($post_id);
	if (!$post) {
		return false;
	}

	$stripped = trim(wp_strip_all_tags($post->post_content));
	if (!$force and !empty($stripped)) {
		return true;
	}

	$title = $post->post_title;
	$taxonomies = get_object_taxonomies($post->post_type, 'names');
	$terms_list = [];
	foreach ($taxonomies as $tax) {
		$t = wp_get_post_terms($post_id, $tax, ['fields' => 'names']);
		if (!is_wp_error($t) and !empty($t)) {
			$terms_list = array_merge($terms_list, $t);
		}
	}

	$prompt = "Write the complete, comprehensive article content for the following post:\n";
	$prompt .= "Title: \"{$title}\"\n";
	$prompt .= "Post Type: {$post->post_type}\n";
	if (!empty($stripped)) {
		$prompt .= twcp_rewrite_note('content', substr($stripped, 0, 2500), 'Review, improve, and expand it.');
	}
	if (!empty($post->post_excerpt)) {
		$prompt .= "Excerpt / Focus: \"{$post->post_excerpt}\"\n";
	}
	if (!empty($terms_list)) {
		$prompt .= "Associated Categories/Tags: " . implode(', ', array_unique($terms_list)) . "\n";
	}
	$links = twcp_post_links($post_id);
	$prompt .= $links['note'];
	$prompt .= "\nFollow all structural HTML requirements (H2, H3, paragraphs, lists, table). Output strictly HTML content.";

	$response = twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_post_content', 'twcp_prompt_content_default'), [], [
			'temperature' => 0.5,
			'max_tokens'  => 4000,
			'read_tools'  => $links['read_tools'],
		]);

	if (empty($response)) {
		return false;
	}

	$clean_html = trim(str_replace(['```html', '```'], '', $response));
	if (empty($clean_html)) {
		return false;
	}

	wp_update_post([
		'ID'           => $post_id,
		'post_content' => wp_kses_post(twcp_post_links($post_id, $clean_html)),
	]);

	return true;
}


/**
 * Assign a featured image (_thumbnail_id) to a post if not already set.
 * Searches media gallery first; if not found, searches connected stock providers and imports.
 *
 * @param int $post_id Post ID.
 *
 * @return bool
 */
function twcp_post_assign_image(int $post_id, ?string $search_query = null): bool
{
	$post = get_post($post_id);
	if (!$post) {
		return false;
	}

	// Only assign if not already set
	if (has_post_thumbnail($post_id)) {
		return true;
	}

	// 1. Search media library for direct attachment or matching title
	$attached = get_posts([
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_parent'    => $post_id,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	]);
	if (!empty($attached)) {
		set_post_thumbnail($post_id, (int) $attached[0]);
		twcp_media_generate_alt((int) $attached[0], false);

		return true;
	}

	$query_raw = !empty($search_query) ? $search_query : $post->post_title;
	$clean_title = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query_raw));
	$media_search = twcp_media_images([
		'search' => $clean_title,
		'limit'  => 1,
	]);
	if (!empty($media_search['images'][0]['id'])) {
		$img_id = (int) $media_search['images'][0]['id'];
		set_post_thumbnail($post_id, $img_id);
		twcp_media_generate_alt($img_id, false);

		return true;
	}

	// 2. An image found by a module, such as a stock photo of the images module
	$img_id = (int) apply_filters('twcp_post_featured_image', 0, $clean_title, $post);
	if ($img_id) {
		set_post_thumbnail($post_id, $img_id);
		twcp_media_generate_alt($img_id, false);

		return true;
	}

	return false;
}


/**
 * Assign appropriate categories to a post or product based on its title and content
 *
 * @param int $post_id Post ID.
 *
 * @return bool
 */
function twcp_post_assign_categories(int $post_id): bool
{
	$post = get_post($post_id);
	if (!$post) {
		return false;
	}

	// Identify primary hierarchical taxonomy
	if ($post->post_type === 'product') {
		$taxonomy = 'product_cat';
	} elseif ($post->post_type === 'post') {
		$taxonomy = 'category';
	} else {
		$taxonomies = get_object_taxonomies($post->post_type, 'objects');
		$taxonomy = '';
		foreach ($taxonomies as $t) {
			if ($t->hierarchical) {
				$taxonomy = $t->name;
				break;
			}
		}
		if (!$taxonomy) {
			return false;
		}
	}

	$terms = get_terms([
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
	]);

	if (empty($terms) or is_wp_error($terms)) {
		return false;
	}

	$valid_ids = [];
	$catalog_lines = [];
	foreach ($terms as $t) {
		$valid_ids[$t->term_id] = $t->name;
		$parent_info = $t->parent ? ' (Parent ID: ' . $t->parent . ')' : '';
		$catalog_lines[] = "- ID: {$t->term_id} | Name: \"{$t->name}\" | Slug: \"{$t->slug}\"{$parent_info}";
	}

	$content_sample = substr(wp_strip_all_tags($post->post_content), 0, 1500);
	$blocks = twcp_post_blocks($post_id);
	$blocks_text = substr(twcp_post_blocks_text($blocks), 0, 1000);

	$prompt = "Assign the most accurate categories to this {$post->post_type} from the available list.\n\n";
	$prompt .= "ITEM DETAILS:\n";
	$prompt .= "- Title: \"{$post->post_title}\"\n";
	if (!empty($post->post_excerpt)) {
		$prompt .= "- Excerpt: \"{$post->post_excerpt}\"\n";
	}
	if (!empty($content_sample)) {
		$prompt .= "- Content: {$content_sample}\n";
	}
	if (!empty($blocks_text)) {
		$prompt .= "- Builder Content: {$blocks_text}\n";
	}
	$prompt .= "\nAVAILABLE CATEGORIES ({$taxonomy}):\n" . implode("\n", $catalog_lines) . "\n\n";
	$prompt .= "RULES:\n";
	$prompt .= "1. Choose 1 to 3 category IDs that best match this item.\n";
	$prompt .= "2. Only pick IDs from the available list above.\n";
	$prompt .= "3. Output strictly a JSON array of integer IDs, for example: [12, 45]. No markdown or explanation.";

	$response = twcp_model_request($prompt, "You are an automated taxonomy classification assistant. Return strictly a JSON array of matching integer category IDs.", [], ['temperature' => 0.2]);

	$raw_ids = twcp_model_response($response);
	if (is_array($raw_ids)) {
		if (!empty($raw_ids['categories']) and is_array($raw_ids['categories'])) {
			$raw_ids = $raw_ids['categories'];
		}
		$selected_ids = [];
		foreach ($raw_ids as $cid) {
			$cid = (int) $cid;
			if (isset($valid_ids[$cid])) {
				$selected_ids[] = $cid;
			}
		}

		if (!empty($selected_ids)) {
			wp_set_object_terms($post_id, $selected_ids, $taxonomy, false);

			return true;
		}
	}

	return false;
}


/**
 * Default system prompt for creating a similar post on an adjacent topic
 *
 * @return string
 */
function twcp_prompt_similar_default(): string
{
	$site_name = twcp_site_name();
	$site_url = home_url('/');

	return <<<PROMPT
You are a senior content strategist, editor, and copywriting specialist for {$site_name} ({$site_url}).
Your objective is to create a complete, high-quality SIMILAR post on an adjacent, complementary, or related topic in the same niche and category.

Guidelines:
* Topic: The new post must be similar in nature, style, and domain, but cover an adjacent or complementary topic/angle rather than duplicating the original.
* Title: Catchy, natural, SEO-friendly, and specific.
* Short Excerpt: 1 to 2 engaging sentences (130 to 160 characters) summarizing the value of the post.
* Full Content: A comprehensive, long-form HTML article (or product description) divided into clear sections with H2 and H3 headings, engaging paragraphs, and bullet lists (UL/LI). Valid WordPress HTML only (no <html>, <body>, or duplicate H1 tags).
* SEO Title: 50 to 60 characters, compelling, ending with "| {$site_name}".
* SEO Meta Description: 140 to 160 characters, action-driven with strong click-through rate appeal.
* Image Keyword: 2 to 4 precise search terms to find a relevant stock photo or media library image.

Output strictly valid JSON with NO surrounding markdown or backticks:
{
  "title": "New Similar Title",
  "excerpt": "Short engaging excerpt...",
  "content": "<h2>First Subtitle</h2><p>...</p><h3>Specific Detail</h3><p>...</p><ul><li>...</li></ul>",
  "seo_title": "SEO Title | {$site_name}",
  "seo_description": "Meta description...",
  "image_query": "relevant keyword phrase"
}
PROMPT;
}


/**
 * Duplicate a post and generate a similar post on an adjacent topic
 * Rewrites title, excerpt, content, selects featured image, and generates SEO meta tags.
 *
 * @param int $source_id Source post ID to base the similar post on.
 *
 * @return int|bool Returns the new post ID on success, or false on failure.
 */
function twcp_post_create_similar(int $source_id)
{
	$source = get_post($source_id);
	if (!$source) {
		return false;
	}

	$post_type = $source->post_type;
	$site_name = twcp_site_name();

	// 1. Gather context from source post
	$source_title = $source->post_title;
	$source_excerpt = trim($source->post_excerpt);
	$source_content = trim(wp_strip_all_tags($source->post_content));

	$blocks = twcp_post_blocks($source_id);
	$blocks_text = twcp_post_blocks_text($blocks);

	// Collect taxonomies and assigned terms
	$taxonomies = get_object_taxonomies($post_type, 'names');
	$assigned_terms = [];
	$term_names = [];
	foreach ($taxonomies as $tax) {
		$term_ids = wp_get_object_terms($source_id, $tax, ['fields' => 'ids']);
		if (!is_wp_error($term_ids) and !empty($term_ids)) {
			$assigned_terms[$tax] = $term_ids;
			$names = wp_get_object_terms($source_id, $tax, ['fields' => 'names']);
			if (!is_wp_error($names) and !empty($names)) {
				$term_names = array_merge($term_names, $names);
			}
		}
	}
	$category_context = !empty($term_names) ? implode(', ', array_unique($term_names)) : '';

	// 2. Request similar post generation via AI model
	$prompt = "Create a new SIMILAR post based on the following existing content on {$site_name}:\n\n";
	$prompt .= "Original Title: \"{$source_title}\"\n";
	$prompt .= "Post Type: {$post_type}\n";
	if ($category_context) {
		$prompt .= "Category/Topic: {$category_context}\n";
	}
	if ($source_excerpt) {
		$prompt .= "Original Excerpt: \"{$source_excerpt}\"\n";
	}
	if ($blocks_text) {
		$prompt .= "Original Page Builder Content:\n" . substr($blocks_text, 0, 1000) . "\n\n";
	}
	if ($source_content) {
		$prompt .= "Original Body Content Summary:\n" . substr($source_content, 0, 1000) . "\n\n";
	}
	$links = twcp_post_links($source_id);
	$prompt .= str_replace('Internal links:', 'Internal links in the content:', $links['note']);
	$prompt .= "Generate a complete, high-quality similar post covering an adjacent/complementary topic in the same category. Output strictly JSON matching the required schema.";

	$response = twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_post_similar', 'twcp_prompt_similar_default'), [], [
			'temperature' => 0.6,
			'max_tokens'  => 4000,
			'read_tools'  => $links['read_tools'],
		]);

	$data = twcp_model_response($response);

	$new_title = (!empty($data) and !empty($data['title'])) ? sanitize_text_field($data['title']) : '';
	$new_excerpt = (!empty($data) and !empty($data['excerpt'])) ? sanitize_textarea_field($data['excerpt']) : '';
	$new_content = (!empty($data) and !empty($data['content'])) ? wp_kses_post(twcp_post_links($source_id, $data['content'])) : '';
	$new_seo_title = (!empty($data) and !empty($data['seo_title'])) ? sanitize_text_field($data['seo_title']) : '';
	$new_seo_desc = (!empty($data) and !empty($data['seo_description'])) ? sanitize_text_field($data['seo_description']) : '';
	$image_query = (!empty($data) and !empty($data['image_query'])) ? sanitize_text_field($data['image_query']) : '';

	// Fallback title if AI failed to return one
	if (empty($new_title)) {
		$new_title = $source_title . ' - ' . __('Similar', 'twee-mcp');
	}

	// 3. Insert the new duplicate post (as draft for review)
	$new_post_id = wp_insert_post([
		'post_title'   => $new_title,
		'post_name'    => sanitize_title($new_title),
		'post_content' => $new_content,
		'post_excerpt' => $new_excerpt,
		'post_status'  => 'draft',
		'post_type'    => $post_type,
		'post_author'  => get_current_user_id() ? : $source->post_author,
	]);

	if (!$new_post_id or is_wp_error($new_post_id)) {
		return false;
	}

	// 4. Assign categories and taxonomy terms from source post
	foreach ($assigned_terms as $tax => $term_ids) {
		wp_set_object_terms($new_post_id, $term_ids, $tax, false);
	}

	// 5. If content or excerpt was not in JSON, generate sequentially
	if (empty($new_content)) {
		twcp_post_generate_content($new_post_id, true);
	}
	if (empty($new_excerpt)) {
		twcp_post_generate_excerpt($new_post_id, true);
	}

	// 6. Select and assign featured image
	twcp_post_assign_image($new_post_id, $image_query ?: $new_title);

	// 7. Save SEO Meta Tags (Rank Math & Yoast SEO)
	twcp_post_update_seo($new_post_id, [
		'seo_title'       => $new_seo_title,
		'seo_description' => $new_seo_desc,
	]);
	if (empty($new_seo_title) or empty($new_seo_desc)) {
		twcp_post_generate_seo($new_post_id, true);
	}

	// 8. WooCommerce Product specifics
	if ($post_type === 'product' and class_exists('WooCommerce')) {
		$price = get_post_meta($source_id, '_price', true);
		$reg_price = get_post_meta($source_id, '_regular_price', true);
		if ($reg_price !== '') {
			update_post_meta($new_post_id, '_regular_price', $reg_price);
		}
		if ($price !== '') {
			update_post_meta($new_post_id, '_price', $price);
		}
		update_post_meta($new_post_id, '_stock_status', 'instock');
		// Copy product attributes if any
		$attrs = get_post_meta($source_id, '_product_attributes', true);
		if (!empty($attrs)) {
			update_post_meta($new_post_id, '_product_attributes', $attrs);
		}
	}

	return $new_post_id;
}


/**
 * MCP tool wrapper: create a similar post from an existing one
 *
 * @param array $args
 *
 * @return array
 */
function twcp_post_similar_tool(array $args): array
{
	if (empty($args['post_id'])) {
		return ['error' => 'post_id parameter is required'];
	}

	$source_id = absint($args['post_id']);
	$new_id = twcp_post_create_similar($source_id);
	if (!$new_id) {
		return ['error' => 'Failed to create similar post'];
	}

	$new_post = get_post($new_id);

	return [
		'success'     => true,
		'new_post_id' => $new_id,
		'post_title'  => $new_post ? $new_post->post_title : '',
		'post_type'   => $new_post ? $new_post->post_type : '',
		'post_status' => $new_post ? $new_post->post_status : '',
		'source_id'   => $source_id,
		'edit_link'   => get_edit_post_link($new_id, 'raw'),
	];
}
