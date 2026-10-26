<?php
/**
 * Twee MCP — Bulk & Batch Actions
 *
 * Single home for every bulk operation:
 * - Background batch engine (slice-by-slice with Pause, Resume, Reset) used by the AI Tools > Tools tab
 * - Real-time activity log showing processed items, titles, and generated results
 * - WordPress list-table bulk actions for posts, products, terms, and media
 *
 * Supported batch types:
 * - alts           Image ALT tags (vision model)
 * - blocks         ACF block descriptions (vision model)
 * - post_excerpt   Post short excerpts
 * - post_content   Post full content
 * - seo            Post & page SEO titles / meta descriptions
 * - products       WooCommerce full product descriptions
 * - products_short WooCommerce short product descriptions
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Public post types available for post batches
 *
 * @return WP_Post_Type[]
 */
function twcp_batch_post_types(): array
{
	$types = get_post_types(['public' => true], 'objects');
	unset($types['attachment']);

	return $types;
}


/**
 * Taxonomies whose archives can get SEO tags from a batch, limited to those the user can edit
 *
 * @return WP_Taxonomy[]
 */
function twcp_batch_taxonomies(): array
{
	$taxonomies = get_taxonomies(['public' => true, 'show_ui' => true], 'objects');
	unset($taxonomies['post_format']);

	return array_filter($taxonomies, fn(WP_Taxonomy $taxonomy) => current_user_can($taxonomy->cap->edit_terms));
}


/**
 * Batch type registry. Jobs with "terms" run over taxonomy terms instead of posts,
 * and their scope is a taxonomy instead of a post type.
 *
 * @return array Type => [ slice size, per-item callback, capability, terms ]
 */
function twcp_batch_types(): array
{
	$types = [
		'post_excerpt'    => ['slice' => 5, 'run' => 'twcp_batch_run_excerpt', 'capability' => 'edit_others_posts'],
		'post_content'    => ['slice' => 3, 'run' => 'twcp_batch_run_content', 'capability' => 'edit_others_posts'],
		'seo'             => ['slice' => 5, 'run' => 'twcp_batch_run_seo', 'capability' => 'edit_others_posts'],
		'featured_images' => ['slice' => 3, 'run' => 'twcp_batch_run_featured', 'capability' => 'edit_others_posts'],
		'categories'      => ['slice' => 5, 'run' => 'twcp_batch_run_categories', 'capability' => 'edit_others_posts'],
		'term_seo'        => ['slice' => 5, 'run' => 'twcp_batch_run_term_seo', 'capability' => 'edit_others_posts', 'terms' => true],
		'alts'            => ['slice' => 5, 'run' => 'twcp_batch_run_alt', 'capability' => 'upload_files'],
		'blocks'          => ['slice' => 0, 'run' => null, 'capability' => 'manage_options'],
	];

	if (twcp_product_active()) {
		$types['products_short'] = ['slice' => 5, 'run' => 'twcp_batch_run_short', 'capability' => 'edit_others_products'];
		$types['products'] = ['slice' => 3, 'run' => 'twcp_batch_run_product', 'capability' => 'edit_others_products'];
		$types['cross_sells'] = ['slice' => 3, 'run' => 'twcp_batch_run_cross_sells', 'capability' => 'edit_others_products'];
	}

	return $types;
}


/**
 * Helper to run a batch item callback and format log messages consistently
 *
 * @param int           $id
 * @param string        $label
 * @param callable      $callback
 * @param bool          $override
 * @param callable|null $detail
 *
 * @return array
 */
function twcp_batch_run_item(int $id, string $label, callable $callback, bool $override = false, ?callable $detail = null): array
{
	$title = get_the_title($id) ?: ('#' . $id);
	$result = call_user_func($callback, $id, $override);
	$ok = is_string($result) ? ($result !== '') : (bool) $result;

	if ($ok) {
		$extra = '';
		if ($detail !== null) {
			$d = call_user_func($detail, $id, $result);
			if ($d) {
				$extra = sprintf(' ("%s")', mb_strimwidth($d, 0, 50, '...'));
			}
		} elseif (is_string($result) && $result !== '') {
			$extra = sprintf(': "%s"', mb_strimwidth($result, 0, 80, '...'));
		}

		return [
			'success' => true,
			'message' => sprintf(__('Generated %s for "%s"%s', 'twee-mcp'), $label, $title, $extra),
		];
	}

	return [
		'success' => false,
		'message' => sprintf(__('Failed to generate %s for "%s" (ID: %d).', 'twee-mcp'), $label, $title, $id),
	];
}


/**
 * Process a single image for ALT tag generation with detailed log output
 */
function twcp_batch_run_alt(int $id, bool $override = false): array
{
	return twcp_batch_run_item($id, 'Alt', 'twcp_media_generate_alt', $override);
}


/**
 * Process a single post/page for short excerpt generation with log output
 */
function twcp_batch_run_excerpt(int $id, bool $override = false): array
{
	return twcp_batch_run_item($id, 'excerpt', 'twcp_post_generate_excerpt', $override, fn($pid) => get_post_field('post_excerpt', $pid));
}


/**
 * Process a single post/page for full content generation with log output
 */
function twcp_batch_run_content(int $id, bool $override = false): array
{
	return twcp_batch_run_item($id, 'full content', 'twcp_post_generate_content', $override);
}


/**
 * Process a single post/page for SEO title and meta description with log output
 */
function twcp_batch_run_seo(int $id, bool $override = false): array
{
	return twcp_batch_run_item($id, 'SEO Tags', 'twcp_post_generate_seo', $override, fn($pid) => get_post_meta($pid, 'rank_math_title', true) ?: get_post_meta($pid, '_yoast_wpseo_title', true) ?: '');
}


/**
 * Process a single product for full description generation with log output
 */
function twcp_batch_run_product(int $id, bool $override = false): array
{
	return twcp_batch_run_item($id, 'full description', 'twcp_product_full', $override);
}


/**
 * Process a single product for short description generation with log output
 */
function twcp_batch_run_short(int $id, bool $override = false): array
{
	return twcp_batch_run_item($id, 'short description', 'twcp_product_short', $override);
}


/**
 * Process a single post for a featured image from the Media Library or stock providers
 */
function twcp_batch_run_featured(int $id, bool $override = false): array
{
	return twcp_batch_run_item($id, 'featured image', fn(int $pid) => twcp_post_assign_image($pid) and has_post_thumbnail($pid), $override, fn($pid) => get_the_title(get_post_thumbnail_id($pid)));
}


/**
 * Process a single post or product still in the default category
 */
function twcp_batch_run_categories(int $id, bool $override = false): array
{
	$taxonomy = get_post_type($id) === 'product' ? 'product_cat' : 'category';

	return twcp_batch_run_item($id, 'categories', 'twcp_post_assign_categories', $override, fn($pid) => implode(', ', wp_get_post_terms($pid, $taxonomy, ['fields' => 'names'])));
}


/**
 * Process a single taxonomy term for archive SEO tags
 */
function twcp_batch_run_term_seo(int $id, bool $override = false): array
{
	$term = get_term($id);
	if (!$term or is_wp_error($term) or !current_user_can('edit_term', $id)) {
		return ['success' => false, 'message' => sprintf(__('Term #%d is not available.', 'twee-mcp'), $id)];
	}

	if (twcp_term_generate_seo($id, $term->taxonomy, $override)) {
		return ['success' => true, 'message' => sprintf(__('Generated SEO Tags for "%s" (%s): "%s"', 'twee-mcp'), $term->name, $term->taxonomy, mb_strimwidth((string) get_term_meta($id, 'rank_math_title', true), 0, 60, '...'))];
	}

	return ['success' => false, 'message' => sprintf(__('Failed to generate SEO Tags for "%s" (ID: %d).', 'twee-mcp'), $term->name, $id)];
}


/**
 * Process a single product for AI-selected cross-sells
 */
function twcp_batch_run_cross_sells(int $id, bool $override = false): array
{
	$product = wc_get_product($id);
	if (!$product) {
		return ['success' => false, 'message' => sprintf(__('Product #%d not found.', 'twee-mcp'), $id)];
	}
	if (!$override and $product->get_cross_sell_ids()) {
		return ['success' => true, 'message' => sprintf(__('Kept the existing cross-sells of "%s".', 'twee-mcp'), $product->get_name())];
	}

	$related = twcp_product_select_related(['product_id' => $id, 'limit' => 4]);
	if (empty($related['selected_ids'])) {
		return ['success' => false, 'message' => sprintf(__('No cross-sell candidates for "%s" (ID: %d).', 'twee-mcp'), $product->get_name(), $id)];
	}

	$product->set_cross_sell_ids($related['selected_ids']);
	$product->save();

	return ['success' => true, 'message' => sprintf(__('Linked cross-sells for "%s": %s', 'twee-mcp'), $product->get_name(), mb_strimwidth(implode(', ', array_column($related['selected_products'], 'title')), 0, 80, '...'))];
}


/**
 * Dynamic query filter for empty excerpt or content
 */
add_filter('posts_where', function(string $where, WP_Query $query): string {
	global $wpdb;
	if ($query->get('twcp_empty_excerpt')) {
		$where .= " AND ({$wpdb->posts}.post_excerpt = '' OR {$wpdb->posts}.post_excerpt IS NULL)";
	}
	if ($query->get('twcp_empty_content')) {
		$where .= " AND (TRIM({$wpdb->posts}.post_content) = '' OR {$wpdb->posts}.post_content IS NULL)";
	}

	return $where;
}, 10, 2);


/**
 * Base WP_Query arguments describing the item set of a batch type
 *
 * @param string $type
 * @param bool   $override
 *
 * @return array Empty when the type is unavailable
 */
function twcp_batch_args(string $type, bool $override = false, string $post_type = 'all'): array
{
	$post_types = ($post_type && $post_type !== 'all') ? [$post_type] : array_keys(twcp_batch_post_types());

	switch ($type) {
		case 'alts':
			$args = [
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_status'    => 'inherit',
			];
			if (!$override) {
				$args['meta_query'] = [
					'relation' => 'OR',
					['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
					['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
				];
			}

			return $args;

		case 'post_excerpt':
		case 'post_content':
			$args = [
				'post_type'   => $post_types,
				'post_status' => 'publish',
			];
			if (!$override) {
				$args[$type === 'post_excerpt' ? 'twcp_empty_excerpt' : 'twcp_empty_content'] = true;
			}

			return $args;

		case 'seo':
			if (!$post_types) {
				return [];
			}
			$args = [
				'post_type'   => $post_types,
				'post_status' => 'publish',
			];
			if (!$override) {
				$args['meta_query'] = [
					'relation' => 'AND',
					[
						'relation' => 'OR',
						['key' => 'rank_math_title', 'value' => '', 'compare' => '='],
						['key' => 'rank_math_title', 'compare' => 'NOT EXISTS'],
					],
					[
						'relation' => 'OR',
						['key' => '_yoast_wpseo_title', 'value' => '', 'compare' => '='],
						['key' => '_yoast_wpseo_title', 'compare' => 'NOT EXISTS'],
					],
				];
			}

			return $args;

		case 'featured_images':
			$args = [
				'post_type'   => array_values(array_filter($post_types, fn($type) => post_type_supports($type, 'thumbnail'))),
				'post_status' => 'publish',
				'meta_query'  => [['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS']],
			];

			return $args['post_type'] ? $args : [];

		case 'categories':
			// Posts and products the assignment can classify, still in the default category unless overriding
			$types = array_values(array_intersect($post_types, twcp_product_active() ? ['post', 'product'] : ['post']));
			if (!$types) {
				return [];
			}
			$args = ['post_type' => $types, 'post_status' => 'publish'];
			if (!$override) {
				$args['tax_query'] = ['relation' => 'OR'];
				if (in_array('post', $types, true)) {
					$args['tax_query'][] = ['taxonomy' => 'category', 'terms' => [(int) get_option('default_category')]];
				}
				if (in_array('product', $types, true)) {
					$args['tax_query'][] = ['taxonomy' => 'product_cat', 'terms' => [(int) get_option('default_product_cat')]];
				}
			}

			return $args;

		case 'cross_sells':
			if (!twcp_product_active()) {
				return [];
			}
			$args = ['post_type' => 'product', 'post_status' => 'publish'];
			if (!$override) {
				$args['meta_query'] = [
					'relation' => 'OR',
					['key' => '_crosssell_ids', 'compare' => 'NOT EXISTS'],
					['key' => '_crosssell_ids', 'value' => ['', 'a:0:{}'], 'compare' => 'IN'],
				];
			}

			return $args;

		case 'products_short':
		case 'products':
			if (!twcp_product_active()) {
				return [];
			}
			$args = [
				'post_type'   => 'product',
				'post_status' => 'publish',
			];
			if (!$override) {
				$args[$type === 'products_short' ? 'twcp_empty_excerpt' : 'twcp_empty_content'] = true;
			}

			return $args;
	}

	return [];
}


/**
 * get_terms arguments for term batches
 *
 * @param bool   $override
 * @param string $taxonomy Taxonomy name or "all"
 *
 * @return array Empty when no taxonomy is available
 */
function twcp_batch_term_args(bool $override, string $taxonomy = 'all'): array
{
	$taxonomies = array_keys(twcp_batch_taxonomies());
	if ($taxonomy !== 'all') {
		$taxonomies = array_intersect($taxonomies, [$taxonomy]);
	}
	if (!$taxonomies) {
		return [];
	}

	$args = ['taxonomy' => array_values($taxonomies), 'hide_empty' => false];
	if (!$override) {
		$args['meta_query'] = [
			'relation' => 'OR',
			['key' => 'rank_math_title', 'compare' => 'NOT EXISTS'],
			['key' => 'rank_math_title', 'value' => ''],
		];
	}

	return $args;
}


/**
 * Fetch a slice of item IDs for a batch type
 *
 * @param string $type
 * @param int    $offset
 * @param int    $limit
 * @param bool   $override
 *
 * @return int[]
 */
function twcp_batch_query(string $type, int $offset, int $limit, bool $override = false, string $post_type = 'all'): array
{
	if (!empty(twcp_batch_types()[$type]['terms'])) {
		$args = twcp_batch_term_args($override, $post_type);

		return ($args and $limit > 0) ? array_map('intval', get_terms($args + ['fields' => 'ids', 'number' => $limit, 'offset' => $offset, 'orderby' => 'term_id'])) : [];
	}

	$args = twcp_batch_args($type, $override, $post_type);
	if (!$args or $limit < 1) {
		return [];
	}

	$query = new WP_Query($args + [
			'fields'                 => 'ids',
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

	return array_map('intval', $query->posts);
}


/**
 * Count the items available for a batch type
 *
 * @param string $type
 * @param bool   $override
 *
 * @return int
 */
function twcp_batch_total(string $type, bool $override = false, string $post_type = 'all'): int
{
	if ($type === 'blocks') {
		return $override ? count(twcp_block_get_layouts()) : twcp_block_count_missing();
	}
	if (!empty(twcp_batch_types()[$type]['terms'])) {
		$args = twcp_batch_term_args($override, $post_type);

		return $args ? (int) get_terms($args + ['fields' => 'count']) : 0;
	}

	$args = twcp_batch_args($type, $override, $post_type);
	if (!$args) {
		return 0;
	}

	$query = new WP_Query($args + [
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

	return (int) $query->found_posts;
}


/**
 * Default state of a batch job
 *
 * @param string $type
 *
 * @return array
 */
function twcp_batch_blank(string $type): array
{
	return [
		'type'      => $type,
		'status'    => 'idle', // idle, running, paused, completed
		'override'  => false,
		'post_type' => 'all',
		'offset'    => 0,
		'total'     => 0,
		'processed' => 0,
		'success'   => 0,
		'errors'    => 0,
		'logs'      => [],
		'updated'   => time(),
	];
}


/**
 * Current state of a batch job
 *
 * @param string $type
 *
 * @return array
 */
function twcp_batch_state(string $type): array
{
	$saved = get_option('twcp_batch_' . $type, []);
	$state = array_merge(twcp_batch_blank($type), is_array($saved) ? $saved : []);

	if (!isset($state['logs']) or !is_array($state['logs'])) {
		$state['logs'] = [];
	}
	$state['override'] = !empty($state['override']);
	$state['post_type'] = !empty($state['post_type']) ? sanitize_key($state['post_type']) : 'all';

	// Keep the counter fresh until the job starts
	if ($state['status'] === 'idle') {
		$state['total'] = twcp_batch_total($type, $state['override'], $state['post_type']);
	}

	return $state;
}


/**
 * Persist the state of a batch job
 *
 * @param string $type
 * @param array  $state
 */
function twcp_batch_save(string $type, array $state): void
{
	$state['updated'] = time();

	// Keep last 40 logs in database to prevent unbounded growth
	if (isset($state['logs']) and is_array($state['logs']) and count($state['logs']) > 40) {
		$state['logs'] = array_slice($state['logs'], -40);
	}

	update_option('twcp_batch_' . $type, $state, false);
}


/**
 * Reset a batch job back to the beginning
 *
 * @param string $type
 *
 * @return array
 */
function twcp_batch_reset(string $type): array
{
	$prev = get_option('twcp_batch_' . $type, []);
	$override = is_array($prev) && !empty($prev['override']);
	$post_type = is_array($prev) && !empty($prev['post_type']) ? sanitize_key($prev['post_type']) : 'all';

	$state = twcp_batch_blank($type);
	$state['override'] = $override;
	$state['post_type'] = $post_type;
	$state['total'] = twcp_batch_total($type, $override, $post_type);
	twcp_batch_save($type, $state);

	return $state;
}


/**
 * Execute one slice of a batch job
 *
 * @param string $type
 *
 * @return array
 */
function twcp_batch_slice(string $type): array
{
	$types = twcp_batch_types();
	$state = twcp_batch_state($type);

	if (!isset($types[$type]) or $state['status'] === 'paused') {
		return $state;
	}

	$job = $types[$type];
	$state['status'] = 'running';
	$override = !empty($state['override']);
	$logs = $state['logs'] ?? [];

	if ($type === 'blocks') {
		$folder = twcp_block_previews_folder();
		$done = twcp_block_generate_descriptions($folder, $override);
		$state['processed'] += $done;
		$state['success'] += $done;
		$state['offset'] = $state['total'];
		$state['status'] = 'completed';

		$logs[] = [
			'time'    => current_time('H:i:s'),
			'success' => true,
			'message' => sprintf(__('Generated descriptions for %d block layout(s).', 'twee-mcp'), $done),
		];
	} else {
		// When not overriding, handled items leave the missing set, so query offset is only failed items
		$post_type = !empty($state['post_type']) ? sanitize_key($state['post_type']) : 'all';
		$offset = $override ? $state['offset'] : $state['errors'];
		$ids = twcp_batch_query($type, $offset, $job['slice'], $override, $post_type);

		foreach ($ids as $id) {
			$state['processed']++;
			$res = call_user_func($job['run'], $id, $override);

			$is_ok = is_array($res) ? !empty($res['success']) : (bool) $res;
			$msg = is_array($res) && !empty($res['message']) ? $res['message'] : sprintf(__('Processed item ID #%d', 'twee-mcp'), $id);

			if ($is_ok) {
				$state['success']++;
			} else {
				$state['errors']++;
			}

			$logs[] = [
				'time'    => current_time('H:i:s'),
				'success' => $is_ok,
				'message' => $msg,
			];
		}

		$state['offset'] += count($ids);

		if (count($ids) < $job['slice'] or ($state['total'] > 0 and $state['processed'] >= $state['total'])) {
			$state['status'] = 'completed';
			$state['total'] = $state['processed'];
			$logs[] = [
				'time'    => current_time('H:i:s'),
				'success' => true,
				'message' => sprintf(__('Batch complete. Total %d processed (%d successful, %d errors).', 'twee-mcp'), $state['processed'], $state['success'], $state['errors']),
			];
		}
	}

	$state['logs'] = array_slice($logs, -40);
	twcp_batch_save($type, $state);

	return $state;
}


/**
 * Validate and return the batch type from an AJAX request
 *
 * @return string
 */
function twcp_batch_request(): string
{
	check_ajax_referer('twcp_admin_nonce', 'nonce');

	$type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
	$types = twcp_batch_types();

	if ($type and !isset($types[$type])) {
		wp_send_json_error(['message' => __('Invalid batch type.', 'twee-mcp')]);
	}
	if ($type ? !current_user_can($types[$type]['capability']) : !twcp_batch_allowed()) {
		wp_send_json_error(['message' => __('Unauthorized.', 'twee-mcp')]);
	}

	return $type;
}


/**
 * Batch types the current user can run
 *
 * @return array
 */
function twcp_batch_allowed(): array
{
	return array_filter(twcp_batch_types(), fn(array $type) => current_user_can($type['capability']));
}


/**
 * AJAX: Retrieve the state of every batch job
 */
add_action('wp_ajax_twcp_batch_status', 'twcp_batch_ajax_status');

/**
 * Return the current state of every batch job.
 *
 * @return void Sends a JSON response.
 */
function twcp_batch_ajax_status(): void
{
	twcp_batch_request();

	$states = [];
	foreach (array_keys(twcp_batch_allowed()) as $type) {
		$states[$type] = twcp_batch_state($type);
	}

	wp_send_json_success(['batches' => $states]);
}


/**
 * AJAX: Start, pause, resume, reset, or toggle override for a batch job
 */
add_action('wp_ajax_twcp_batch_control', 'twcp_batch_ajax_control');

/**
 * Start, pause, resume, reset, or toggle override for a batch job.
 *
 * @return void Sends a JSON response.
 */
function twcp_batch_ajax_control(): void
{
	$type = twcp_batch_request();
	$action = isset($_POST['batch_action']) ? sanitize_key($_POST['batch_action']) : '';

	if (!$type) {
		wp_send_json_error(['message' => __('Invalid batch type.', 'twee-mcp')]);
	}

	$state = twcp_batch_state($type);

	if ($action === 'toggle_override') {
		$override = !empty($_POST['override']);
		$post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : ($state['post_type'] ?? 'all');
		$state = twcp_batch_blank($type);
		$state['override'] = $override;
		$state['post_type'] = $post_type;
		$state['total'] = twcp_batch_total($type, $override, $post_type);
		twcp_batch_save($type, $state);
	} elseif ($action === 'change_post_type') {
		$post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'all';
		$override = isset($_POST['override']) ? !empty($_POST['override']) : !empty($state['override']);
		$state = twcp_batch_blank($type);
		$state['override'] = $override;
		$state['post_type'] = $post_type;
		$state['total'] = twcp_batch_total($type, $override, $post_type);
		twcp_batch_save($type, $state);
	} elseif ($action === 'reset') {
		$state = twcp_batch_reset($type);
	} elseif ($action === 'pause') {
		$state['status'] = 'paused';
		twcp_batch_save($type, $state);
	} elseif ($action === 'start' or $action === 'resume') {
		if (isset($_POST['override'])) {
			$state['override'] = !empty($_POST['override']);
		}
		if (isset($_POST['post_type'])) {
			$state['post_type'] = sanitize_key($_POST['post_type']);
		}
		$state['status'] = 'running';
		twcp_batch_save($type, $state);
	} else {
		wp_send_json_error(['message' => __('Invalid control action.', 'twee-mcp')]);
	}

	wp_send_json_success(['state' => $state]);
}


/**
 * AJAX: Execute one slice of a batch job
 */
add_action('wp_ajax_twcp_batch_step', 'twcp_batch_ajax_step');

/**
 * Execute one slice of a running batch job.
 *
 * @return void Sends a JSON response.
 */
function twcp_batch_ajax_step(): void
{
	$type = twcp_batch_request();

	if (!$type) {
		wp_send_json_error(['message' => __('Invalid batch type.', 'twee-mcp')]);
	}

	wp_send_json_success(['state' => twcp_batch_slice($type)]);
}


/**
 * Sections of the Tools screen batch cards
 *
 * @return array Section => [ label, description ]
 */
function twcp_batch_sections(): array
{
	return [
		'content' => [
			'label'       => __('Content Automation', 'twee-mcp'),
			'description' => __('Fill in what posts and pages are missing. Each job only picks items that lack the value unless you enable rewriting.', 'twee-mcp'),
		],
		'media'   => [
			'label'       => __('Media Automation', 'twee-mcp'),
			'description' => __('Images are scaled down before they are sent to the vision model to keep token usage low.', 'twee-mcp'),
		],
		'store'   => [
			'label'       => __('Store Automation', 'twee-mcp'),
			'description' => __('Product copy and merchandising for WooCommerce. Copy rules live on the Prompts tab.', 'twee-mcp'),
		],
		'site'    => [
			'label'       => __('Site Setup', 'twee-mcp'),
			'description' => __('One-time jobs that help AI agents understand the site.', 'twee-mcp'),
		],
	];
}


/**
 * Configuration and current states for the batch cards the current user can run
 *
 * @return array Section => cards
 */
function twcp_batch_cards(): array
{
	$post_types = ['all' => __('All Post Types', 'twee-mcp')] + array_map(fn(WP_Post_Type $type) => $type->labels->name, twcp_batch_post_types());
	$taxonomies = ['all' => __('All Taxonomies', 'twee-mcp')] + array_map(fn(WP_Taxonomy $taxonomy) => $taxonomy->labels->name, twcp_batch_taxonomies());

	$configs = [
		'post_excerpt'    => [
			'section'        => 'content',
			'title'          => __('Short Excerpts', 'twee-mcp'),
			'description'    => __('Writes 1 to 2 sentence excerpts for items without one.', 'twee-mcp'),
			'scopes'         => $post_types,
			'btn_text'       => __('Generate Excerpts', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
		],
		'post_content'    => [
			'section'        => 'content',
			'title'          => __('Full Content', 'twee-mcp'),
			'description'    => __('Writes complete HTML articles for empty posts.', 'twee-mcp'),
			'scopes'         => $post_types,
			'btn_text'       => __('Generate Content', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
		],
		'seo'             => [
			'section'        => 'content',
			'title'          => __('SEO Titles & Meta Descriptions', 'twee-mcp'),
			'description'    => __('Saves SEO tags for Rank Math and Yoast SEO.', 'twee-mcp'),
			'scopes'         => $post_types,
			'btn_text'       => __('Generate SEO Tags', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
		],
		'featured_images' => [
			'section'     => 'content',
			'title'       => __('Featured Images', 'twee-mcp'),
			'description' => __('Picks a matching Media Library image, or imports a stock photo, for items without a featured image.', 'twee-mcp'),
			'scopes'      => $post_types,
			'btn_text'    => __('Assign Images', 'twee-mcp'),
		],
		'categories'      => [
			'section'        => 'content',
			'title'          => __('Categories', 'twee-mcp'),
			'description'    => __('Moves posts and products out of the default category into 1 to 3 matching ones.', 'twee-mcp'),
			'scopes'         => array_intersect_key($post_types, array_flip(['all', 'post', 'product'])),
			'btn_text'       => __('Assign Categories', 'twee-mcp'),
			'override_label' => __('Reclassify all', 'twee-mcp'),
		],
		'term_seo'        => [
			'section'        => 'content',
			'title'          => __('Category & Tag SEO', 'twee-mcp'),
			'description'    => __('Saves SEO tags for category, tag, and brand archives.', 'twee-mcp'),
			'scopes'         => $taxonomies,
			'btn_text'       => __('Generate SEO Tags', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
		],
		'alts'            => [
			'section'        => 'media',
			'title'          => __('Image Alt Text', 'twee-mcp'),
			'description'    => __('Describes images without Alt text using the vision model.', 'twee-mcp'),
			'btn_text'       => __('Generate Alt Text', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
			'unit'           => __('Images', 'twee-mcp'),
		],
		'products_short'  => [
			'section'        => 'store',
			'title'          => __('Short Product Descriptions', 'twee-mcp'),
			'description'    => __('Writes 130 to 155 character summaries that double as meta descriptions.', 'twee-mcp'),
			'btn_text'       => __('Generate Short Descriptions', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
			'unit'           => __('Products', 'twee-mcp'),
		],
		'products'        => [
			'section'        => 'store',
			'title'          => __('Full Product Descriptions', 'twee-mcp'),
			'description'    => __('Writes HTML descriptions with specifications and internal links.', 'twee-mcp'),
			'btn_text'       => __('Generate Full Descriptions', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
			'unit'           => __('Products', 'twee-mcp'),
		],
		'cross_sells'     => [
			'section'        => 'store',
			'title'          => __('Cross-sells', 'twee-mcp'),
			'description'    => __('Links up to 4 complementary products from the same category or brand to products without cross-sells.', 'twee-mcp'),
			'btn_text'       => __('Link Cross-sells', 'twee-mcp'),
			'override_label' => __('Replace existing', 'twee-mcp'),
			'unit'           => __('Products', 'twee-mcp'),
		],
		'blocks'          => [
			'section'        => 'site',
			'title'          => __('ACF Block Descriptions', 'twee-mcp'),
			'description'    => __('Describes page builder layouts from their fields and preview screenshots.', 'twee-mcp'),
			'has_pause'      => false,
			'btn_text'       => __('Generate Block Descriptions', 'twee-mcp'),
			'override_label' => __('Rewrite existing', 'twee-mcp'),
			'unit'           => __('Layouts', 'twee-mcp'),
		],
	];

	$sections = array_fill_keys(array_keys(twcp_batch_sections()), []);
	foreach (array_intersect_key($configs, twcp_batch_allowed()) as $type => $conf) {
		$state = twcp_batch_state($type);
		$unit = $conf['unit'] ?? '';
		$badge = !empty($state['override'])
			? ($unit ? sprintf(__('%d Total %s', 'twee-mcp'), $state['total'], $unit) : sprintf(__('%d Total', 'twee-mcp'), $state['total']))
			: sprintf(__('%d Missing', 'twee-mcp'), $state['total']);

		$sections[$conf['section']][] = $conf + [
				'type'      => $type,
				'data'      => $state,
				'badge'     => $badge,
				'has_pause' => true,
			];
	}

	return array_filter($sections);
}


/**
 * Items that need attention, for the overview on the Tools screen
 *
 * @return array[] [ label, count, url ]
 */
function twcp_batch_overview(): array
{
	$items = [];

	if (current_user_can('moderate_comments')) {
		$items[] = [__('Comments awaiting moderation', 'twee-mcp'), (int) wp_count_comments()->moderated, admin_url('edit-comments.php?comment_status=moderated')];
	}
	if (current_user_can('edit_others_posts')) {
		$items[] = [__('Drafts and pending posts', 'twee-mcp'), (int) wp_count_posts()->draft + (int) wp_count_posts()->pending, admin_url('edit.php?post_status=draft')];
		$items[] = [__('Posts without SEO tags', 'twee-mcp'), twcp_batch_total('seo', false, 'post'), ''];
	}
	if (current_user_can('upload_files')) {
		$items[] = [__('Images without Alt text', 'twee-mcp'), twcp_media_alt_count(), admin_url('upload.php?mode=list&orderby=twcp_alt&order=asc')];
	}
	if (twcp_product_active() and current_user_can('edit_others_products')) {
		$stock = fn(array $meta) => (int) (new WP_Query(['post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => $meta]))->found_posts;
		$low = (int) get_option('woocommerce_notify_low_stock_amount', 2);

		$items[] = [__('Products out of stock', 'twee-mcp'), $stock([['key' => '_stock_status', 'value' => 'outofstock']]), admin_url('edit.php?post_type=product&stock_status=outofstock')];
		$items[] = [sprintf(__('Products with %d or fewer in stock', 'twee-mcp'), $low), $stock([['key' => '_manage_stock', 'value' => 'yes'], ['key' => '_stock', 'value' => $low, 'compare' => '<=', 'type' => 'NUMERIC'], ['key' => '_stock_status', 'value' => 'instock']]), admin_url('edit.php?post_type=product&stock_status=instock')];
		$items[] = [__('Products without short descriptions', 'twee-mcp'), twcp_batch_total('products_short'), ''];
	}
	if (twcp_product_active() and current_user_can('edit_shop_orders')) {
		$hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$items[] = [__('Orders processing or on hold', 'twee-mcp'), (int) wc_orders_count('processing') + (int) wc_orders_count('on-hold'), admin_url($hpos ? 'admin.php?page=wc-orders&status=wc-processing' : 'edit.php?post_type=shop_order&post_status=wc-processing')];
	}

	return $items;
}


/**
 * WordPress list-table bulk actions per screen
 *
 * @return array Screen ID => [ action => [ label, callback ] ]
 */
function twcp_batch_actions(): array
{
	$seo_gen = __('Generate SEO Tags', 'twee-mcp');
	$seo_regen = __('Regenerate SEO Tags', 'twee-mcp');
	$screens = [];

	$screens['upload']['twcp_alt_check'] = [
		__('Check Alt Text', 'twee-mcp'),
		fn(int $id) => (bool) twcp_media_check_batch([$id])
	];
	$screens['upload']['twcp_alt'] = [
		__('Generate Alt Text', 'twee-mcp'),
		fn(int $id) => (bool) twcp_media_generate_alt($id, false)
	];
	$screens['upload']['twcp_alt_regen'] = [
		__('Regenerate Alt Text', 'twee-mcp'),
		fn(int $id) => (bool) twcp_media_generate_alt($id, true)
	];

	$all_post_types = array_unique(array_merge(get_post_types(['public' => true], 'names'), get_post_types(['show_ui' => true], 'names')));
	$excluded = ['attachment', 'wp_block', 'wp_navigation', 'acf-field-group', 'acf-field', 'shop_order', 'shop_coupon', 'shop_order_placehold'];
	$post_types = array_diff($all_post_types, $excluded);

	foreach ($post_types as $post_type) {
		$screen_id = 'edit-' . $post_type;

		// Create Similar (duplicate & rewrite title, excerpt, content, select featured image, generate SEO)
		$screens[$screen_id]['twcp_create_similar'] = [
			__('Create Similar', 'twee-mcp'),
			fn(int $id) => (bool) twcp_post_create_similar($id),
		];

		// SEO: Generate (if empty) vs Regenerate (always override)
		$screens[$screen_id]['twcp_seo'] = [$seo_gen, fn(int $id) => twcp_post_generate_seo($id, false)];
		$screens[$screen_id]['twcp_seo_regen'] = [$seo_regen, fn(int $id) => twcp_post_generate_seo($id, true)];

		// Featured Image
		$screens[$screen_id]['twcp_featured_image'] = [
			__('Assign Featured Image', 'twee-mcp'),
			'twcp_post_assign_image',
		];

		// Excerpt: Generate (if empty) vs Regenerate (override)
		$screens[$screen_id]['twcp_excerpt'] = [
			__('Generate Short Excerpt', 'twee-mcp'),
			fn(int $id) => twcp_post_generate_excerpt($id, false),
		];
		$screens[$screen_id]['twcp_excerpt_regen'] = [
			__('Regenerate Short Excerpt', 'twee-mcp'),
			fn(int $id) => twcp_post_generate_excerpt($id, true),
		];

		// Full Content: Generate (if empty) vs Regenerate (override)
		$screens[$screen_id]['twcp_content'] = [
			__('Generate Full Content', 'twee-mcp'),
			fn(int $id) => twcp_post_generate_content($id, false),
		];
		$screens[$screen_id]['twcp_content_regen'] = [
			__('Regenerate Full Content', 'twee-mcp'),
			fn(int $id) => twcp_post_generate_content($id, true),
		];

		// Assign Categories (for Posts and Products)
		if ($post_type === 'post' or ($post_type === 'product' and twcp_product_active())) {
			$screens[$screen_id]['twcp_categories'] = [
				__('Assign Categories', 'twee-mcp'),
				'twcp_post_assign_categories',
			];
		}

		// WooCommerce Products
		if ($post_type === 'product' and twcp_product_active()) {
			$screens['edit-product']['twcp_short'] = [
				__('Generate Short Product Descriptions', 'twee-mcp'),
				fn(int $id) => twcp_product_short($id, false)
			];
			$screens['edit-product']['twcp_short_regen'] = [
				__('Regenerate Short Product Descriptions', 'twee-mcp'),
				fn(int $id) => twcp_product_short($id, true)
			];
			$screens['edit-product']['twcp_full'] = [
				__('Generate Full Product Descriptions', 'twee-mcp'),
				fn(int $id) => twcp_product_full($id, false)
			];
			$screens['edit-product']['twcp_full_regen'] = [
				__('Regenerate Full Product Descriptions', 'twee-mcp'),
				fn(int $id) => twcp_product_full($id, true)
			];
		}
	}

	foreach (get_taxonomies(['public' => true], 'names') as $taxonomy) {
		$screens['edit-' . $taxonomy]['twcp_seo'] = [$seo_gen, fn(int $id) => twcp_term_generate_seo($id, $taxonomy, false)];
		$screens['edit-' . $taxonomy]['twcp_seo_regen'] = [$seo_regen, fn(int $id) => twcp_term_generate_seo($id, $taxonomy, true)];
	}

	return $screens;
}


/**
 * Register list-table bulk actions
 */
add_action('admin_init', 'twcp_batch_bulk');

/**
 * Register and process bulk actions on supported admin list screens.
 *
 * @return void
 */
function twcp_batch_bulk(): void
{
	// Bulk action hooks
	foreach (twcp_batch_actions() as $screen => $actions) {
		add_filter('bulk_actions-' . $screen, function($current) use ($actions) {
			foreach ($actions as $action => $info) {
				$current[$action] = $info[0];
			}

			return $current;
		});

		foreach ($actions as $action => $info) {
			add_filter('handle_bulk_actions-' . $screen, function($redirect_to, $doaction, $item_ids) use ($action, $info) {
				if ($doaction !== $action) {
					return $redirect_to;
				}

				if ($action === 'twcp_alt_check') {
					$result = twcp_media_check_batch($item_ids);
					$redirect_to = add_query_arg([
						'twcp_alt_checked' => $result['checked'],
						'twcp_alt_updated' => $result['updated'],
					], $redirect_to);

					return $redirect_to;
				}

				$processed = 0;
				foreach ($item_ids as $id) {
					if (call_user_func($info[1], (int) $id)) {
						$processed++;
					}
				}

				$redirect_to = add_query_arg('twcp_processed', $processed, $redirect_to);
				if ($action === 'twcp_create_similar') {
					$redirect_to = add_query_arg('twcp_created_similar', $processed, $redirect_to);
				}

				return $redirect_to;
			}, 10, 3);
		}
	}

	// Bulk notice
	if (isset($_GET['twcp_alt_checked'])) {
		add_action('admin_notices', function() {
			$checked = (int) $_GET['twcp_alt_checked'];
			$updated = (int) ($_GET['twcp_alt_updated'] ?? 0);
			$kept = max(0, $checked - $updated);
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					if ($updated > 0) {
						echo sprintf(esc_html__('Alt Text audit complete: Checked %d image(s) &mdash; %d Alt text(s) regenerated due to poor quality, %d were already high quality.', 'twee-mcp'), $checked, $updated, $kept);
					} else {
						echo sprintf(esc_html__('Alt Text audit complete: Checked %d image(s) &mdash; all Alt texts were evaluated as high quality.', 'twee-mcp'), $checked);
					}
					?>
				</p>
			</div>
			<?php
		});
	} elseif (isset($_GET['twcp_created_similar'])) {
		add_action('admin_notices', function() {
			$count = absint($_GET['twcp_created_similar']);
			?>
			<div class="notice notice-success is-dismissible">
			<p><?php echo sprintf(esc_html(_n('Successfully created %d similar post as draft.', 'Successfully created %d similar posts as drafts.', $count, 'twee-mcp')), $count); ?></p>
			</div>
			<?php
		});
	} elseif (isset($_GET['twcp_processed'])) {
		add_action('admin_notices', function() {
			$count = absint($_GET['twcp_processed']);
			?>
			<div class="notice notice-success is-dismissible">
			<p><?php echo sprintf(esc_html(_n('Successfully processed %d item with AI.', 'Successfully processed %d items with AI.', $count, 'twee-mcp')), $count); ?></p>
			</div>
			<?php
		});
	}
}
