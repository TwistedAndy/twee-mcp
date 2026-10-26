<?php
/**
 * Twee MCP — Bulk & Batch Actions
 *
 * Single home for every bulk operation:
 * - Background batch engine (slice-by-slice with Pause, Resume, Reset) used by the AI Tools > Automation tab
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
 * and their scope is a taxonomy instead of a post type. Jobs with "text" write that text,
 * so they can refine it or rewrite only poor values. Jobs with "acf" write to an ACF term field chosen on the card.
 *
 * @return array Type => [ slice size, per-item callback, capability, terms, acf, text ]
 */
function twcp_batch_types(): array
{
	$types = [
		'post_excerpt'    => ['slice' => 5, 'run' => 'twcp_batch_run_excerpt', 'capability' => 'edit_others_posts', 'text' => 'excerpt'],
		'post_content'    => ['slice' => 3, 'run' => 'twcp_batch_run_content', 'capability' => 'edit_others_posts', 'text' => 'article content'],
		'seo'             => ['slice' => 5, 'run' => 'twcp_batch_run_seo', 'capability' => 'edit_others_posts', 'text' => 'SEO title (50 to 60 characters) and meta description (140 to 160 characters)'],
		'featured_images' => ['slice' => 3, 'run' => 'twcp_batch_run_featured', 'capability' => 'edit_others_posts'],
		'categories'      => ['slice' => 5, 'run' => 'twcp_batch_run_categories', 'capability' => 'edit_others_posts'],
		'term_seo'        => ['slice' => 5, 'run' => 'twcp_batch_run_term_seo', 'capability' => 'edit_others_posts', 'terms' => true, 'text' => 'archive SEO title and description'],
		'term_description' => ['slice' => 5, 'run' => 'twcp_batch_run_term_description', 'capability' => 'edit_others_posts', 'terms' => true, 'text' => 'archive description (1 to 3 sentences)'],
		'alts'            => ['slice' => 5, 'run' => 'twcp_batch_run_alt', 'capability' => 'upload_files', 'text' => 'image Alt text (10 to 30 words)'],
		'blocks'          => ['slice' => 2, 'run' => 'twcp_batch_run_block', 'capability' => 'manage_options'],
	];

	if (twcp_term_acf_fields('content')) {
		$types['term_content'] = ['slice' => 3, 'run' => 'twcp_batch_run_term_content', 'capability' => 'edit_others_posts', 'terms' => true, 'acf' => 'content', 'text' => 'archive full description'];
	}
	if (twcp_term_acf_fields('faq')) {
		$types['term_faq'] = ['slice' => 3, 'run' => 'twcp_batch_run_term_faq', 'capability' => 'edit_others_posts', 'terms' => true, 'acf' => 'faq', 'text' => 'archive FAQ'];
	}

	if (twcp_product_active()) {
		$types['products_short'] = ['slice' => 5, 'run' => 'twcp_batch_run_short', 'capability' => 'edit_others_products', 'text' => 'short product description (130 to 155 characters)'];
		$types['products'] = ['slice' => 3, 'run' => 'twcp_batch_run_product', 'capability' => 'edit_others_products', 'text' => 'full product description'];
		$types['cross_sells'] = ['slice' => 3, 'run' => 'twcp_batch_run_cross_sells', 'capability' => 'edit_others_products'];
	}

	return $types;
}


/**
 * Rough cost of one item per batch job, for the estimate on the Automation screen
 *
 * System prompts, page context, and replies make up most of the tokens; images and lookups add to them.
 *
 * @return array Type => [ model requests, tokens ]
 */
function twcp_batch_costs(): array
{
	return [
		'post_excerpt'    => [1, 2500],
		'post_content'    => [1, 7000],
		'seo'             => [1, 3000],
		'featured_images' => [1, 2000],
		'categories'      => [1, 2000],
		'term_seo'        => [1, 2500],
		'term_description' => [1, 2000],
		'term_content'    => [1, 5000],
		'term_faq'        => [1, 4000],
		'alts'            => [1, 2000],
		'products_short'  => [1, 2500],
		'products'        => [2, 9000],
		'cross_sells'     => [1, 3000],
		'blocks'          => [1, 4000],
	];
}


/**
 * Modes a batch job can run in. Every mode except "fill" also fills empty items.
 *
 * @param string $type
 *
 * @return array Mode => label
 */
function twcp_batch_modes(string $type): array
{
	if (!empty(twcp_batch_types()[$type]['text'])) {
		return [
			'fill'   => __('Fill empty', 'twee-mcp'),
			'refine' => __('Refine existing', 'twee-mcp'),
			'poor'   => __('Rewrite if poor', 'twee-mcp'),
			'all'    => __('Rewrite all', 'twee-mcp'),
		];
	}

	return [
		'fill' => __('Fill empty', 'twee-mcp'),
		'all'  => $type === 'categories' ? __('Reclassify all', 'twee-mcp') : __('Redo all', 'twee-mcp'),
	];
}


/**
 * Valid mode of a batch job
 *
 * @param string $type
 * @param mixed  $mode
 *
 * @return string
 */
function twcp_batch_mode(string $type, $mode): string
{
	$mode = sanitize_key((string) $mode);

	return isset(twcp_batch_modes($type)[$mode]) ? $mode : 'fill';
}


/**
 * Current text a batch job would replace
 *
 * @param string $type
 * @param int    $id
 *
 * @return string Empty when the item has none
 */
function twcp_batch_current(string $type, int $id): string
{
	switch ($type) {
		case 'post_excerpt':
			return trim((string) get_post_field('post_excerpt', $id));
		case 'post_content':
			return mb_substr(trim(wp_strip_all_tags((string) get_post_field('post_content', $id))), 0, 1200);
		case 'seo':
			$title = get_post_meta($id, 'rank_math_title', true) ? : get_post_meta($id, '_yoast_wpseo_title', true);
			$desc = get_post_meta($id, 'rank_math_description', true) ? : get_post_meta($id, '_yoast_wpseo_metadesc', true);

			return trim(($title ? 'Title: ' . $title : '') . ($desc ? "\n" . 'Description: ' . $desc : ''));
		case 'term_seo':
			$title = get_term_meta($id, 'rank_math_title', true);
			$desc = get_term_meta($id, 'rank_math_description', true);

			return trim(($title ? 'Title: ' . $title : '') . ($desc ? "\n" . 'Description: ' . $desc : ''));
		case 'term_description':
			return trim(wp_strip_all_tags((string) get_term_field('description', $id)));
		case 'term_content':
		case 'term_faq':
			$value = get_field(twcp_batch_field($type)['key'] ?? '', 'term_' . $id, false);

			return mb_substr(trim(wp_strip_all_tags(is_array($value) ? implode("\n", array_map(fn($row) => implode(' ', (array) $row), $value)) : (string) $value)), 0, 1200);
		case 'alts':
			return trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
		case 'products_short':
			return trim(wp_strip_all_tags((string) get_post_field('post_excerpt', $id)));
		case 'products':
			return mb_substr(trim(wp_strip_all_tags((string) get_post_field('post_content', $id))), 0, 1200);
	}

	return '';
}


/**
 * Ask the model which existing values of a slice are poor
 *
 * @param string $type
 * @param array  $items ID => current text
 *
 * @return int[]|null IDs that need a rewrite, or null when the check failed
 */
function twcp_batch_audit(string $type, array $items): ?array
{
	if (!$items) {
		return [];
	}

	$job = twcp_batch_types()[$type];
	$label = $job['text'];
	$terms = !empty($job['terms']);

	$prompt = "Review the current {$label} of each item below and decide whether it is poor and should be rewritten.\n";
	$prompt .= "Poor: generic or placeholder text, a filename or code, keyword stuffing, broken grammar, a different language than the site, unrelated to the item, or far from the expected length.\n";
	$prompt .= "Acceptable: accurate, readable, and specific to the item, even if it could be polished.\n\n";

	foreach ($items as $id => $text) {
		$name = $terms ? (get_term($id)->name ?? '') : get_the_title($id);
		$prompt .= "ID: {$id}\nItem: {$name}\nCurrent:\n{$text}\n\n";
	}

	$prompt .= 'Return strictly a JSON object that maps every ID to true when it needs a rewrite and false when it is acceptable, e.g. {"12": true, "15": false}. No markdown.';

	$verdicts = twcp_model_response(twcp_model_request($prompt, 'You are a strict content quality reviewer for ' . twcp_site_name() . '.', [], ['temperature' => 0.1]));
	if (!is_array($verdicts)) {
		return null;
	}

	return array_values(array_filter(array_map('intval', array_keys(array_filter($verdicts, fn($poor) => $poor === true or $poor === 'true')))));
}


/**
 * Fields a batch job may change on an item, so they can be backed up and restored
 *
 * @param string $type
 * @param int    $id
 *
 * @return string[] "post:field", "term:field", "meta:key", "term_meta:key", "terms:taxonomy", "yoast_term:taxonomy", or "acf:key" of a term field
 */
function twcp_batch_fields(string $type, int $id): array
{
	switch ($type) {
		case 'post_excerpt':
		case 'products_short':
			return ['post:post_excerpt'];
		case 'post_content':
		case 'products':
			return ['post:post_content'];
		case 'seo':
			return ['meta:rank_math_title', 'meta:rank_math_description', 'meta:_yoast_wpseo_title', 'meta:_yoast_wpseo_metadesc', 'post:post_excerpt'];
		case 'featured_images':
			return ['meta:_thumbnail_id'];
		case 'categories':
			return ['terms:' . (get_post_type($id) === 'product' ? 'product_cat' : 'category')];
		case 'cross_sells':
			return ['meta:_crosssell_ids'];
		case 'alts':
			return ['meta:_wp_attachment_image_alt'];
		case 'term_seo':
			$term = get_term($id);

			return ['term_meta:rank_math_title', 'term_meta:rank_math_description', 'yoast_term:' . ($term->taxonomy ?? ''), 'term:description'];
		case 'term_description':
			return ['term:description'];
		case 'term_content':
		case 'term_faq':
			return ['acf:' . (twcp_batch_field($type)['key'] ?? '')];
	}

	return [];
}


/**
 * Current values of the fields a batch job may change
 *
 * @param string $type
 * @param int    $id
 *
 * @return array Field => value, null when a meta key does not exist
 */
function twcp_batch_snapshot(string $type, int $id): array
{
	$snapshot = [];

	foreach (twcp_batch_fields($type, $id) as $field) {
		[$kind, $key] = explode(':', $field, 2);

		if ($kind === 'post') {
			$snapshot[$field] = (string) get_post_field($key, $id, 'raw');
		} elseif ($kind === 'term') {
			$snapshot[$field] = (string) get_term_field($key, $id, '', 'raw');
		} elseif ($kind === 'acf') {
			$snapshot[$field] = metadata_exists('term', $id, '_' . (acf_get_field($key)['name'] ?? '')) ? get_field($key, 'term_' . $id, false) : null;
		} elseif ($kind === 'meta') {
			$snapshot[$field] = metadata_exists('post', $id, $key) ? get_post_meta($id, $key, true) : null;
		} elseif ($kind === 'term_meta') {
			$snapshot[$field] = metadata_exists('term', $id, $key) ? get_term_meta($id, $key, true) : null;
		} elseif ($kind === 'terms') {
			$terms = wp_get_object_terms($id, $key, ['fields' => 'ids']);
			$snapshot[$field] = is_array($terms) ? array_map('intval', $terms) : [];
		} elseif ($kind === 'yoast_term') {
			$snapshot[$field] = get_option('wpseo_taxonomy_meta', [])[$key][$id] ?? null;
		}
	}

	return $snapshot;
}


/**
 * Write a snapshot back to an item
 *
 * @param string $type
 * @param int    $id
 * @param array  $snapshot
 */
function twcp_batch_restore_item(string $type, int $id, array $snapshot): void
{
	$post = [];

	foreach ($snapshot as $field => $value) {
		[$kind, $key] = explode(':', $field, 2);

		if ($kind === 'post') {
			$post[$key] = $value;
		} elseif ($kind === 'term') {
			$term = get_term($id);
			$term and !is_wp_error($term) and wp_update_term($id, $term->taxonomy, [$key => $value]);
		} elseif ($kind === 'acf') {
			$value === null ? delete_field($key, 'term_' . $id) : update_field($key, $value, 'term_' . $id);
		} elseif ($kind === 'meta') {
			$value === null ? delete_post_meta($id, $key) : update_post_meta($id, $key, wp_slash($value));
		} elseif ($kind === 'term_meta') {
			$value === null ? delete_term_meta($id, $key) : update_term_meta($id, $key, wp_slash($value));
		} elseif ($kind === 'terms') {
			wp_set_object_terms($id, (array) $value, $key);
		} elseif ($kind === 'yoast_term') {
			$yoast = get_option('wpseo_taxonomy_meta', []);
			if ($value === null) {
				unset($yoast[$key][$id]);
			} else {
				$yoast[$key][$id] = $value;
			}
			update_option('wpseo_taxonomy_meta', $yoast);
		}
	}

	if ($post) {
		wp_update_post(wp_slash(['ID' => $id] + $post));
	}
	if ($type === 'cross_sells' or str_starts_with($type, 'products')) {
		function_exists('wc_delete_product_transients') and wc_delete_product_transients($id);
	}
}


/**
 * Backed-up values of the items changed by the last run of a batch job
 *
 * @param string $type
 *
 * @return array ID => snapshot
 */
function twcp_batch_backup(string $type): array
{
	$backup = get_option('twcp_batch_undo_' . $type, []);

	return is_array($backup) ? $backup : [];
}


/**
 * Restore every item changed by the last run of a batch job
 *
 * @param string $type
 *
 * @return int Restored items
 */
function twcp_batch_undo(string $type): int
{
	$backup = twcp_batch_backup($type);
	$restored = 0;

	foreach ($backup as $id => $snapshot) {
		$id = (int) $id;
		$exists = !empty(twcp_batch_types()[$type]['terms']) ? get_term($id) : get_post($id);
		if ($exists and !is_wp_error($exists)) {
			twcp_batch_restore_item($type, $id, $snapshot);
			$restored++;
		}
	}

	delete_option('twcp_batch_undo_' . $type);

	return $restored;
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
 * Process a single taxonomy term for its archive description
 */
function twcp_batch_run_term_description(int $id, bool $override = false): array
{
	$term = get_term($id);
	if (!$term or is_wp_error($term) or !current_user_can('edit_term', $id)) {
		return ['success' => false, 'message' => sprintf(__('Term #%d is not available.', 'twee-mcp'), $id)];
	}

	if (twcp_term_generate_description($id, $term->taxonomy, $override)) {
		return ['success' => true, 'message' => sprintf(__('Wrote a description for "%s" (%s): "%s"', 'twee-mcp'), $term->name, $term->taxonomy, mb_strimwidth((string) get_term_field('description', $id, '', 'raw'), 0, 60, '...'))];
	}

	return ['success' => false, 'message' => sprintf(__('Failed to write a description for "%s" (ID: %d).', 'twee-mcp'), $term->name, $id)];
}


/**
 * Process a single taxonomy term for the full description in an ACF field
 */
function twcp_batch_run_term_content(int $id, bool $override = false): array
{
	$term = get_term($id);
	if (!$term or is_wp_error($term) or !current_user_can('edit_term', $id)) {
		return ['success' => false, 'message' => sprintf(__('Term #%d is not available.', 'twee-mcp'), $id)];
	}

	if (twcp_term_generate_content($id, twcp_batch_field('term_content')['key'] ?? '', $override)) {
		return ['success' => true, 'message' => sprintf(__('Wrote a full description for "%s" (%s).', 'twee-mcp'), $term->name, $term->taxonomy)];
	}

	return ['success' => false, 'message' => sprintf(__('Failed to write a full description for "%s" (ID: %d).', 'twee-mcp'), $term->name, $id)];
}


/**
 * Process a single taxonomy term for the FAQ in an ACF repeater
 */
function twcp_batch_run_term_faq(int $id, bool $override = false): array
{
	$term = get_term($id);
	if (!$term or is_wp_error($term) or !current_user_can('edit_term', $id)) {
		return ['success' => false, 'message' => sprintf(__('Term #%d is not available.', 'twee-mcp'), $id)];
	}

	$key = twcp_batch_field('term_faq')['key'] ?? '';
	if (twcp_term_generate_faq($id, $key, $override)) {
		return ['success' => true, 'message' => sprintf(__('Wrote %d questions for "%s" (%s).', 'twee-mcp'), count((array) get_field($key, 'term_' . $id, false)), $term->name, $term->taxonomy)];
	}

	return ['success' => false, 'message' => sprintf(__('Failed to write an FAQ for "%s" (ID: %d).', 'twee-mcp'), $term->name, $id)];
}


/**
 * Process a single page builder layout for its description
 *
 * @param int  $index    Position of the layout in twcp_block_get_layouts()
 * @param bool $override Unused, the query already picks the layouts to describe
 */
function twcp_batch_run_block(int $index, bool $override = false): array
{
	$layout = twcp_block_get_layouts()[$index] ?? null;
	if (!$layout) {
		return ['success' => false, 'message' => sprintf(__('Layout #%d is not available.', 'twee-mcp'), $index)];
	}

	$description = (string) twcp_block_describe_single($layout['name'], twcp_block_previews_folder());

	return ['success' => true, 'message' => sprintf(__('Described "%s": "%s"', 'twee-mcp'), $layout['label'] ?? $layout['name'], mb_strimwidth($description, 0, 60, '...'))];
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
function twcp_batch_term_args(bool $override, string $taxonomy = 'all', string $type = 'term_seo'): array
{
	global $wpdb;

	$taxonomies = array_keys(twcp_batch_taxonomies());
	if ($taxonomy !== 'all') {
		$taxonomies = array_intersect($taxonomies, [$taxonomy]);
	}
	$field = !empty(twcp_batch_types()[$type]['acf']) ? twcp_batch_field($type) : null;
	if ($field !== null) {
		$taxonomies = array_intersect($taxonomies, $field['taxonomies'] ?? []);
	}
	if (!$taxonomies) {
		return [];
	}

	$args = ['taxonomy' => array_values($taxonomies), 'hide_empty' => false];
	if (!$override and $type === 'term_description') {
		$in = implode(',', array_fill(0, count($taxonomies), '%s'));
		$args['include'] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE description = '' AND taxonomy IN ({$in})", ...array_values($taxonomies)))) ? : [0];
	} elseif (!$override) {
		$key = $field ? $field['name'] : 'rank_math_title';
		$args['meta_query'] = [
			'relation' => 'OR',
			['key' => $key, 'compare' => 'NOT EXISTS'],
			['key' => $key, 'value' => ''],
		];
		// An empty ACF repeater stores its row count
		if ($field) {
			$args['meta_query'][] = ['key' => $key, 'value' => '0'];
		}
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
	// Layouts have no IDs, so their position in the layout list stands in for one
	if ($type === 'blocks') {
		$saved = twcp_block_saved_descriptions();
		$layouts = array_filter(twcp_block_get_layouts(), fn(array $layout) => $override or empty($saved[$layout['name']]));

		return array_slice(array_keys($layouts), $offset, max(0, $limit));
	}
	if (!empty(twcp_batch_types()[$type]['terms'])) {
		$args = twcp_batch_term_args($override, $post_type, $type);

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
		$args = twcp_batch_term_args($override, $post_type, $type);
		if (!$args) {
			return 0;
		}

		// A "count" query counts a term once per joined meta row, so count the distinct IDs instead
		$ids = get_terms($args + ['fields' => 'ids']);

		return is_array($ids) ? count($ids) : 0;
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
 * ACF term field a batch job writes to: the one chosen on its card, or the first that fits
 *
 * @param string $type
 *
 * @return array Field from twcp_term_acf_fields(), empty for jobs without a field
 */
function twcp_batch_field(string $type): array
{
	$kind = twcp_batch_types()[$type]['acf'] ?? '';
	$fields = $kind ? twcp_term_acf_fields($kind) : [];
	$saved = get_option('twcp_batch_' . $type, [])['field'] ?? '';

	return $fields[$saved] ?? (reset($fields) ? : []);
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
		'type'       => $type,
		'status'     => 'idle', // idle, running, paused, completed
		'mode'       => 'fill', // fill, refine, poor, all
		'override'   => false,
		'post_type'  => 'all',
		'field'      => '', // ACF field key of jobs that write to a term field
		'offset'     => 0,
		'total'      => 0,
		'processed'  => 0,
		'success'    => 0,
		'errors'     => 0,
		'logs'       => [],
		'undo'       => 0, // Items the last run changed and Undo can restore
		'user'       => 0, // Who started the job; background slices run as this user
		'background' => false, // Whether Action Scheduler runs the slices instead of the browser
		'updated'    => time(),
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
	// Jobs saved before modes existed only had the override switch
	$state['mode'] = twcp_batch_mode($type, $saved['mode'] ?? (!empty($state['override']) ? 'all' : 'fill'));
	$state['override'] = $state['mode'] !== 'fill';
	$state['post_type'] = !empty($state['post_type']) ? sanitize_key($state['post_type']) : 'all';
	$state['field'] = twcp_batch_field($type)['key'] ?? '';
	$state['undo'] = count(twcp_batch_backup($type));

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
	$prev = twcp_batch_state($type);

	return twcp_batch_restart($type, $prev['mode'], $prev['post_type']);
}


/**
 * Start a batch job over with a mode and scope
 *
 * @param string $type
 * @param string $mode
 * @param string $post_type Post type or taxonomy, or "all"
 *
 * @return array
 */
function twcp_batch_restart(string $type, string $mode, string $post_type): array
{
	$state = twcp_batch_blank($type);
	$state['mode'] = twcp_batch_mode($type, $mode);
	$state['override'] = $state['mode'] !== 'fill';
	$state['post_type'] = sanitize_key($post_type) ? : 'all';
	$state['field'] = twcp_batch_field($type)['key'] ?? '';
	$state['total'] = twcp_batch_total($type, $state['override'], $state['post_type']);
	$state['undo'] = count(twcp_batch_backup($type));
	twcp_batch_save($type, $state);

	return $state;
}


/**
 * Execute one slice of a batch job, unless another request is still running one
 *
 * A refreshed page or a second tab asks for the next slice while the previous one may still run,
 * so the job is locked for the length of a slice. The state then comes back with "busy" set.
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

	// add_option() fails while another request holds the lock; a lock left by a crashed slice expires
	$lock = 'twcp_batch_lock_' . $type;
	if (!add_option($lock, time(), '', false)) {
		wp_cache_delete($lock, 'options');
		if ((int) get_option($lock) > time() - 10 * MINUTE_IN_SECONDS) {
			return $state + ['busy' => true];
		}
		update_option($lock, time(), false);
	}

	$job = $types[$type];
	$state['status'] = 'running';
	$override = !empty($state['override']);
	$logs = $state['logs'] ?? [];

	// When not overriding, handled items leave the missing set, so query offset is only failed items
	$post_type = !empty($state['post_type']) ? sanitize_key($state['post_type']) : 'all';
	$offset = $override ? $state['offset'] : $state['errors'];
	$ids = twcp_batch_query($type, $offset, $job['slice'], $override, $post_type);

	// In "poor" mode, existing values are checked together first and only the poor ones are rewritten
	$current = [];
	$poor = [];
	if ($state['mode'] === 'poor' and !empty($job['text'])) {
		$current = array_filter(array_combine($ids, array_map(fn(int $id) => twcp_batch_current($type, $id), $ids)) ? : []);
		$poor = twcp_batch_audit($type, $current);
	}

	twcp_rewrite_mode($state['mode']);
	$backup = twcp_batch_backup($type);

	foreach ($ids as $id) {
		$state['processed']++;

		if (isset($current[$id]) and ($poor === null or !in_array($id, $poor, true))) {
			$name = !empty($job['terms']) ? (get_term($id)->name ?? '#' . $id) : (get_the_title($id) ? : '#' . $id);
			$res = $poor === null
				? ['success' => false, 'message' => sprintf(__('Could not check the quality of "%s" (ID: %d).', 'twee-mcp'), $name, $id)]
				: ['success' => true, 'message' => sprintf(__('Kept "%s": the current text looks good.', 'twee-mcp'), $name)];
		} else {
			// Keep the values from before the first change, so Undo returns to them
			$backup[$id] = $backup[$id] ?? twcp_batch_snapshot($type, $id);
			$res = call_user_func($job['run'], $id, $override);
		}

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
		$state['offset']++;

		// Stop pressed during this item ends the slice here, instead of being overwritten by the save below
		wp_cache_delete('twcp_batch_' . $type, 'options');
		if ((get_option('twcp_batch_' . $type, [])['status'] ?? '') === 'paused') {
			$state['status'] = 'paused';
		}

		// Save after every item, so the screen can follow a slice while it runs
		$state['logs'] = $logs;
		twcp_batch_save($type, $state);

		if ($state['status'] === 'paused') {
			break;
		}
	}

	twcp_rewrite_mode('');
	update_option('twcp_batch_undo_' . $type, $backup, false);
	$state['undo'] = count($backup);

	// A job stopped on its last item is complete as well
	if (($state['status'] === 'running' and count($ids) < $job['slice']) or ($state['total'] > 0 and $state['processed'] >= $state['total'])) {
		$state['status'] = 'completed';
		$state['total'] = $state['processed'];
		$logs[] = [
			'time'    => current_time('H:i:s'),
			'success' => true,
			'message' => sprintf(__('Batch complete. Total %d processed (%d successful, %d errors).', 'twee-mcp'), $state['processed'], $state['success'], $state['errors']),
		];
	}

	$state['logs'] = array_slice($logs, -40);
	twcp_batch_save($type, $state);
	delete_option($lock);

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
 * AJAX: Start, pause, resume, reset, or change the mode or scope of a batch job
 */
add_action('wp_ajax_twcp_batch_control', 'twcp_batch_ajax_control');

/**
 * Start, pause, resume, reset, or change the mode or scope of a batch job.
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

	$mode = isset($_POST['mode']) ? twcp_batch_mode($type, wp_unslash($_POST['mode'])) : $state['mode'];
	$post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : $state['post_type'];

	if ($action === 'change_field') {
		$state['field'] = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
		twcp_batch_save($type, $state);
		$state = twcp_batch_restart($type, $mode, $post_type);
	} elseif ($action === 'change_mode' or $action === 'change_post_type') {
		$state = twcp_batch_restart($type, $mode, $post_type);
	} elseif ($action === 'undo') {
		if ($state['status'] === 'running') {
			wp_send_json_error(['message' => __('Stop the job before undoing its last run.', 'twee-mcp')]);
		}
		$restored = twcp_batch_undo($type);
		$state = twcp_batch_restart($type, $state['mode'], $state['post_type']);
		$state['logs'][] = [
			'time'    => current_time('H:i:s'),
			'success' => true,
			'message' => sprintf(_n('Restored the previous values of %d item.', 'Restored the previous values of %d items.', $restored, 'twee-mcp'), $restored),
		];
		twcp_batch_save($type, $state);
	} elseif ($action === 'reset') {
		$state = twcp_batch_reset($type);
	} elseif ($action === 'pause') {
		$state['status'] = 'paused';
		twcp_batch_save($type, $state);
	} elseif ($action === 'start' or $action === 'resume') {
		$missing = twcp_model_missing();
		if ($missing) {
			wp_send_json_error(['message' => $missing . ' ' . __('Set up a model on the AI Provider screen first.', 'twee-mcp')]);
		}
		// A fresh run replaces the backup of the previous one
		if ($action === 'start' and !$state['processed']) {
			delete_option('twcp_batch_undo_' . $type);
			$state['undo'] = 0;
		}
		$state['mode'] = $mode;
		$state['override'] = $mode !== 'fill';
		$state['post_type'] = $post_type;
		$state['status'] = 'running';
		$state['user'] = get_current_user_id();
		$state['background'] = twcp_batch_background();
		if ($state['background']) {
			$state['logs'][] = ['time' => current_time('H:i:s'), 'success' => true, 'message' => __('Running on the server. You can close this page; the job keeps going.', 'twee-mcp')];
		}
		twcp_batch_save($type, $state);
		if ($state['background']) {
			twcp_batch_schedule($type);
		}
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
 * Whether batch jobs can run on the server through Action Scheduler (bundled with WooCommerce)
 *
 * @return bool
 */
function twcp_batch_background(): bool
{
	return (bool) apply_filters('twcp_batch_background', function_exists('as_enqueue_async_action') and function_exists('as_has_scheduled_action'));
}


/**
 * Queue the next slice of a background job
 *
 * @param string $type
 * @param bool   $check Skip when a slice is already queued or running
 */
function twcp_batch_schedule(string $type, bool $check = true): void
{
	if (!$check or !as_has_scheduled_action('twcp_batch_run', ['type' => $type], 'twee-mcp')) {
		as_enqueue_async_action('twcp_batch_run', ['type' => $type], 'twee-mcp');
	}
}


/**
 * Action Scheduler: run one slice as the user who started the job, then queue the next one
 */
add_action('twcp_batch_run', 'twcp_batch_run_background');

/**
 * Run one slice of a background job.
 *
 * @param string $type
 *
 * @return void
 */
function twcp_batch_run_background(string $type): void
{
	$types = twcp_batch_types();
	$state = twcp_batch_state($type);
	if (!isset($types[$type]) or $state['status'] !== 'running') {
		return;
	}

	wp_set_current_user((int) $state['user']);

	$problem = current_user_can($types[$type]['capability']) ? twcp_model_missing() : __('The user who started the job can no longer run it.', 'twee-mcp');

	if ($problem) {
		$state['status'] = 'paused';
		$state['logs'][] = ['time' => current_time('H:i:s'), 'success' => false, 'message' => $problem . ' ' . __('The job was paused.', 'twee-mcp')];
		twcp_batch_save($type, $state);

		return;
	}

	// A busy job is queued again by the slice that holds the lock
	$state = twcp_batch_slice($type);
	if ($state['status'] === 'running' and empty($state['busy'])) {
		twcp_batch_schedule($type, false);
	}
}


/**
 * Sections of the Automation screen batch cards
 *
 * @return array Section => [ label, description ]
 */
function twcp_batch_sections(): array
{
	return [
		'content' => [
			'label'       => __('Content Automation', 'twee-mcp'),
			'description' => __('Fill in what posts and pages are missing. By default, each job only fills items that lack the value; choose another mode to refine or rewrite existing ones.', 'twee-mcp'),
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
		],
		'post_content'    => [
			'section'        => 'content',
			'title'          => __('Full Content', 'twee-mcp'),
			'description'    => __('Writes complete HTML articles for empty posts.', 'twee-mcp'),
			'scopes'         => $post_types,
			'btn_text'       => __('Generate Content', 'twee-mcp'),
		],
		'seo'             => [
			'section'        => 'content',
			'title'          => __('SEO Titles & Meta Descriptions', 'twee-mcp'),
			'description'    => __('Saves SEO tags for Rank Math and Yoast SEO.', 'twee-mcp'),
			'scopes'         => $post_types,
			'btn_text'       => __('Generate SEO Tags', 'twee-mcp'),
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
		],
		'term_seo'        => [
			'section'        => 'content',
			'title'          => __('Category & Tag SEO', 'twee-mcp'),
			'description'    => __('Saves SEO tags for category, tag, and brand archives.', 'twee-mcp'),
			'scopes'         => $taxonomies,
			'btn_text'       => __('Generate SEO Tags', 'twee-mcp'),
		],
		'term_description' => [
			'section'        => 'content',
			'title'          => __('Category & Tag Descriptions', 'twee-mcp'),
			'description'    => __('Writes the short introductions shown on category, tag, and brand archives.', 'twee-mcp'),
			'scopes'         => $taxonomies,
			'btn_text'       => __('Generate Descriptions', 'twee-mcp'),
		],
		'term_content'    => [
			'section'        => 'content',
			'title'          => __('Full Term Descriptions', 'twee-mcp'),
			'description'    => __('Writes longer archive descriptions with headings and a list into the chosen term field.', 'twee-mcp'),
			'scopes'         => $taxonomies,
			'btn_text'       => __('Generate Full Descriptions', 'twee-mcp'),
		],
		'term_faq'        => [
			'section'        => 'content',
			'title'          => __('Term FAQs', 'twee-mcp'),
			'description'    => __('Writes 4 to 6 questions and answers into the chosen term repeater.', 'twee-mcp'),
			'scopes'         => $taxonomies,
			'btn_text'       => __('Generate FAQs', 'twee-mcp'),
		],
		'alts'            => [
			'section'        => 'media',
			'title'          => __('Image Alt Text', 'twee-mcp'),
			'description'    => __('Describes images without Alt text using the vision model.', 'twee-mcp'),
			'btn_text'       => __('Generate Alt Text', 'twee-mcp'),
			'unit'           => __('Images', 'twee-mcp'),
		],
		'products_short'  => [
			'section'        => 'store',
			'title'          => __('Short Product Descriptions', 'twee-mcp'),
			'description'    => __('Writes 130 to 155 character summaries that double as meta descriptions.', 'twee-mcp'),
			'btn_text'       => __('Generate Short Descriptions', 'twee-mcp'),
			'unit'           => __('Products', 'twee-mcp'),
		],
		'products'        => [
			'section'        => 'store',
			'title'          => __('Full Product Descriptions', 'twee-mcp'),
			'description'    => __('Writes HTML descriptions with specifications and internal links.', 'twee-mcp'),
			'btn_text'       => __('Generate Full Descriptions', 'twee-mcp'),
			'unit'           => __('Products', 'twee-mcp'),
		],
		'cross_sells'     => [
			'section'        => 'store',
			'title'          => __('Cross-sells', 'twee-mcp'),
			'description'    => __('Links up to 4 complementary products from the same category or brand to products without cross-sells.', 'twee-mcp'),
			'btn_text'       => __('Link Cross-sells', 'twee-mcp'),
			'unit'           => __('Products', 'twee-mcp'),
		],
		'blocks'          => [
			'section'        => 'site',
			'title'          => __('ACF Block Descriptions', 'twee-mcp'),
			'description'    => __('Describes page builder layouts from their fields and preview screenshots.', 'twee-mcp'),
			'btn_text'       => __('Generate Block Descriptions', 'twee-mcp'),
			'unit'           => __('Layouts', 'twee-mcp'),
		],
	];

	$sections = array_fill_keys(array_keys(twcp_batch_sections()), []);
	$allowed = twcp_batch_allowed();
	$costs = twcp_batch_costs();
	foreach (array_intersect_key($configs, $allowed) as $type => $conf) {
		$job = $allowed[$type];
		$state = twcp_batch_state($type);
		$unit = $conf['unit'] ?? '';
		$fields = [];
		foreach (empty($job['acf']) ? [] : twcp_term_acf_fields($job['acf']) as $key => $field) {
			$taxonomies = count($field['taxonomies']) > 2
				? sprintf(__('%d taxonomies', 'twee-mcp'), count($field['taxonomies']))
				: implode(', ', array_map(fn(string $taxonomy) => get_taxonomy($taxonomy)->labels->name, $field['taxonomies']));
			$fields[$key] = $field['label'] . ' (' . $taxonomies . ')';
		}
		$badge = $state['mode'] !== 'fill'
			? ($unit ? sprintf(__('%d Total %s', 'twee-mcp'), $state['total'], $unit) : sprintf(__('%d Total', 'twee-mcp'), $state['total']))
			: sprintf(__('%d Missing', 'twee-mcp'), $state['total']);

		$sections[$conf['section']][] = $conf + [
				'type'      => $type,
				'data'      => $state,
				'badge'     => $badge,
				'has_pause' => true,
				'modes'     => $type === 'featured_images' ? [] : twcp_batch_modes($type),
				'cost'      => $costs[$type] ?? [1, 2000],
				'slice'     => $job['slice'],
				'fields'    => $fields,
			];
	}

	return array_filter($sections);
}


/**
 * Items that need attention, for the overview on the Automation screen
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
		$screens['edit-' . $taxonomy]['twcp_description'] = [__('Generate Descriptions', 'twee-mcp'), fn(int $id) => twcp_term_generate_description($id, $taxonomy, false)];
		$screens['edit-' . $taxonomy]['twcp_description_regen'] = [__('Regenerate Descriptions', 'twee-mcp'), fn(int $id) => twcp_term_generate_description($id, $taxonomy, true)];
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

				if (twcp_model_missing()) {
					return add_query_arg('twcp_model_missing', 1, $redirect_to);
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
	if (isset($_GET['twcp_model_missing'])) {
		add_action('admin_notices', function() {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html(twcp_model_missing() ? : __('The AI model was not set up.', 'twee-mcp')); ?> <?php echo esc_html__('Nothing was processed. Add an API key or sign in with ChatGPT on the AI Tools > AI Provider screen.', 'twee-mcp'); ?></p>
			</div>
			<?php
		});
	} elseif (isset($_GET['twcp_alt_checked'])) {
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
