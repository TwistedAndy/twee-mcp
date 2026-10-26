<?php
/**
 * Taxonomy Terms Tools & Content Generation
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * MCP tools for taxonomy terms
 *
 * @return array Tool name => [ description, callable, parameters, required, annotations ]
 */
function twcp_term_tools(): array
{
	return [
		'get_terms'    => [
			'description' => 'Get existing taxonomy terms (categories, tags, product categories) with search, post counts, and links.',
			'callable'    => 'twcp_term_query',
			'parameters'  => [
				'taxonomy'   => ['type' => 'string', 'description' => 'Taxonomy name (e.g. "category", "product_cat"). Defaults to all.'],
				'search'     => ['type' => 'string', 'description' => 'Keyword search in term names.'],
				'parent'     => ['type' => 'integer', 'description' => 'Parent term ID.'],
				'limit'      => ['type' => 'integer', 'description' => 'Number of terms (default 50).'],
				'offset'     => ['type' => 'integer', 'description' => 'Offset for pagination.'],
				'hide_empty' => ['type' => 'boolean', 'description' => 'Hide empty terms (default false).'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'create_term'  => [
			'description' => 'Create a new taxonomy term (e.g. category or product category), optionally with SEO metadata.',
			'callable'    => 'twcp_term_create',
			'required'    => ['taxonomy', 'name'],
			'parameters'  => [
				'taxonomy'        => ['type' => 'string', 'description' => 'Taxonomy name.'],
				'name'            => ['type' => 'string', 'description' => 'Term name.'],
				'slug'            => ['type' => 'string', 'description' => 'Optional slug.'],
				'description'     => ['type' => 'string', 'description' => 'Optional description.'],
				'parent'          => ['type' => 'integer', 'description' => 'Parent term ID.'],
				'seo_title'       => ['type' => 'string', 'description' => 'SEO title saved for both Rank Math and Yoast SEO.'],
				'seo_description' => ['type' => 'string', 'description' => 'SEO meta description saved for both Rank Math and Yoast SEO.'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'edit_term'    => [
			'description' => 'Edit an existing taxonomy term name, slug, description, parent, or SEO metadata.',
			'callable'    => 'twcp_term_edit',
			'required'    => ['term_id', 'taxonomy'],
			'parameters'  => [
				'term_id'         => ['type' => 'integer', 'description' => 'Term ID to edit.'],
				'taxonomy'        => ['type' => 'string', 'description' => 'Taxonomy of the term.'],
				'name'            => ['type' => 'string', 'description' => 'New term name.'],
				'slug'            => ['type' => 'string', 'description' => 'New slug.'],
				'description'     => ['type' => 'string', 'description' => 'New description.'],
				'parent'          => ['type' => 'integer', 'description' => 'New parent term ID.'],
				'seo_title'       => ['type' => 'string', 'description' => 'SEO title saved for both Rank Math and Yoast SEO.'],
				'seo_description' => ['type' => 'string', 'description' => 'SEO meta description saved for both Rank Math and Yoast SEO.'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'assign_terms' => [
			'description' => 'Assign existing categories, tags, brands, or other taxonomy terms to an existing post, page, product, or registered custom post type. Replace, append, or remove IDs; replace with [] clears that taxonomy.',
			'callable'    => 'twcp_term_assign',
			'required'    => ['post_id', 'taxonomy', 'term_ids'],
			'parameters'  => [
				'post_id'  => ['type' => 'integer', 'minimum' => 1],
				'taxonomy' => ['type' => 'string'],
				'term_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
				'mode'     => ['type' => 'string', 'enum' => ['replace', 'append', 'remove']],
			],
			'annotations' => ['readOnlyHint' => false],
		],
	];
}


/**
 * Read taxonomy terms
 *
 * @param array $args
 *
 * @return array
 */
function twcp_term_query(array $args): array
{
	$tax = sanitize_key($args['taxonomy'] ?? '');
	if ($tax !== '' and !taxonomy_exists($tax)) {
		return ['error' => 'Taxonomy does not exist: ' . $tax];
	}

	$term_args = [
		'taxonomy'   => $tax !== '' ? $tax : array_values(get_taxonomies(['public' => true])),
		'hide_empty' => !empty($args['hide_empty']),
		'number'     => isset($args['limit']) ? min(100, max(1, (int) $args['limit'])) : 50,
		'offset'     => isset($args['offset']) ? max(0, (int) $args['offset']) : 0,
	];

	if (!empty($args['search'])) {
		$term_args['search'] = sanitize_text_field($args['search']);
	}
	if (!empty($args['slug'])) {
		$term_args['slug'] = sanitize_title($args['slug']);
	}
	if (!empty($args['parent'])) {
		$term_args['parent'] = absint($args['parent']);
	}

	$terms = get_terms($term_args);
	if (is_wp_error($terms)) {
		return ['error' => $terms->get_error_message()];
	}

	$formatted = [];
	foreach ($terms as $t) {
		$formatted[] = twcp_term_format($t, $t->taxonomy);
	}

	return [
		'taxonomy' => $tax !== '' ? $tax : 'all',
		'count'    => count($formatted),
		'terms'    => $formatted,
	];
}


/**
 * Format a term object with full metadata and ACF blocks
 *
 * @param WP_Term $term
 * @param string  $taxonomy
 *
 * @return array
 */
function twcp_term_format(WP_Term $term, string $taxonomy): array
{
	$thumb_id = get_term_meta($term->term_id, 'thumbnail_id', true);
	$blocks_field = twcp_block_field_name();
	$blocks = get_term_meta($term->term_id, $blocks_field, true);

	return [
		'id'          => $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'taxonomy'    => $taxonomy,
		'description' => $term->description,
		'count'       => $term->count,
		'parent'      => $term->parent,
		'link'        => get_term_link($term),
		'image'       => $thumb_id ? [
			'id'  => (int) $thumb_id,
			'url' => wp_get_attachment_url($thumb_id),
			'alt' => get_post_meta($thumb_id, '_wp_attachment_image_alt', true),
		] : null,
		'seo'         => [
			'title'       => get_term_meta($term->term_id, 'rank_math_title', true) ? : '',
			'description' => get_term_meta($term->term_id, 'rank_math_description', true) ? : '',
		],
		'blocks'      => twcp_block_format_list($blocks),
	];
}


/**
 * Save explicitly supplied taxonomy SEO metadata for Rank Math and Yoast SEO.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy name.
 * @param array  $args     Tool arguments containing SEO fields.
 *
 * @return void
 */
function twcp_term_update_seo(int $term_id, string $taxonomy, array $args): void
{
	$fields = [
		'seo_title'       => ['rank_math_title', 'wpseo_title'],
		'seo_description' => ['rank_math_description', 'wpseo_desc'],
	];
	$yoast_meta = get_option('wpseo_taxonomy_meta', []);
	$yoast_meta = is_array($yoast_meta) ? $yoast_meta : [];
	$yoast_meta[$taxonomy] = isset($yoast_meta[$taxonomy]) && is_array($yoast_meta[$taxonomy]) ? $yoast_meta[$taxonomy] : [];
	$yoast_meta[$taxonomy][$term_id] = isset($yoast_meta[$taxonomy][$term_id]) && is_array($yoast_meta[$taxonomy][$term_id]) ? $yoast_meta[$taxonomy][$term_id] : [];
	$changed = false;

	foreach ($fields as $field => [$rank_math_key, $yoast_key]) {
		if (!array_key_exists($field, $args)) {
			continue;
		}

		$value = sanitize_text_field((string) $args[$field]);
		update_term_meta($term_id, $rank_math_key, $value);
		$yoast_meta[$taxonomy][$term_id][$yoast_key] = $value;
		$changed = true;
	}

	if ($changed) {
		update_option('wpseo_taxonomy_meta', $yoast_meta);
	}
}


/**
 * Validate a taxonomy => term IDs map before it is assigned to a post type
 *
 * @param string $post_type
 * @param mixed  $taxonomies
 *
 * @return true|WP_Error
 */
function twcp_term_validate_assignments(string $post_type, $taxonomies)
{
	if (!is_array($taxonomies)) {
		return new WP_Error('twcp_taxonomies', 'Taxonomies must be a map of taxonomy names to term ID arrays.');
	}

	foreach ($taxonomies as $taxonomy => $term_ids) {
		$object = get_taxonomy((string) $taxonomy);
		if (!$object or !is_object_in_taxonomy($post_type, $object->name)) {
			return new WP_Error('twcp_taxonomies', sprintf('Taxonomy %s is not registered for %s.', $taxonomy, $post_type));
		}
		if (!current_user_can($object->cap->assign_terms)) {
			return new WP_Error('twcp_taxonomies', 'You cannot assign terms in ' . $object->name . '.');
		}

		foreach ((array) $term_ids as $term_id) {
			$term = get_term(absint($term_id), $object->name);
			if (!$term or is_wp_error($term)) {
				return new WP_Error('twcp_taxonomies', sprintf('Term %d does not exist in %s.', absint($term_id), $object->name));
			}
		}
	}

	return true;
}


/**
 * Assign terms to a post
 *
 * @param array $args
 *
 * @return array
 */
function twcp_term_assign(array $args): array
{
	$post_id = absint($args['post_id'] ?? 0);
	if (!$post_id or !get_post($post_id)) {
		return ['error' => 'Post ID not found: ' . $post_id];
	}

	$taxonomy = sanitize_key($args['taxonomy'] ?? '');
	if (!taxonomy_exists($taxonomy)) {
		return ['error' => 'Taxonomy not found: ' . $taxonomy];
	}

	$term_ids = array_map('absint', (array) ($args['term_ids'] ?? []));
	$mode = sanitize_key($args['mode'] ?? 'replace');

	$valid = twcp_term_validate_assignments(get_post_type($post_id), [$taxonomy => $term_ids]);
	if (is_wp_error($valid)) {
		return ['error' => $valid->get_error_message()];
	}

	$current = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
	if (is_wp_error($current)) {
		return ['error' => $current->get_error_message()];
	}

	$final_ids = match ($mode) {
		'append' => array_unique(array_merge($current, $term_ids)),
		'remove' => array_diff($current, $term_ids),
		default => $term_ids,
	};

	$set_result = wp_set_object_terms($post_id, array_values($final_ids), $taxonomy, false);
	if (is_wp_error($set_result)) {
		return ['error' => $set_result->get_error_message()];
	}

	clean_post_cache($post_id);

	return [
		'success'   => true,
		'post_id'   => $post_id,
		'taxonomy'  => $taxonomy,
		'terms'     => array_values($final_ids),
		'mode_used' => $mode,
	];
}


/**
 * Create a taxonomy term
 *
 * @param array $args
 *
 * @return array
 */
function twcp_term_create(array $args): array
{
	$taxonomy = sanitize_key($args['taxonomy'] ?? 'category');
	if (!taxonomy_exists($taxonomy)) {
		return ['error' => 'Taxonomy does not exist: ' . $taxonomy];
	}

	if (empty($args['name'])) {
		return ['error' => 'Term name is required'];
	}

	$term_args = [];
	if (!empty($args['slug'])) {
		$term_args['slug'] = sanitize_title($args['slug']);
	}
	if (!empty($args['description'])) {
		$term_args['description'] = wp_kses_post($args['description']);
	}
	if (!empty($args['parent'])) {
		$term_args['parent'] = absint($args['parent']);
	}

	$result = wp_insert_term(sanitize_text_field($args['name']), $taxonomy, $term_args);

	if (is_wp_error($result)) {
		return ['error' => $result->get_error_message()];
	}

	twcp_term_update_seo((int) $result['term_id'], $taxonomy, $args);
	$term = get_term($result['term_id'], $taxonomy);

	return [
		'success' => true,
		'id'      => $result['term_id'],
		'name'    => $term->name,
		'slug'    => $term->slug,
		'link'    => get_term_link($term),
		'seo'     => [
			'title'       => get_term_meta($term->term_id, 'rank_math_title', true),
			'description' => get_term_meta($term->term_id, 'rank_math_description', true),
		],
	];
}


/**
 * Edit an existing taxonomy term
 *
 * @param array $args
 *
 * @return array
 */
function twcp_term_edit(array $args): array
{
	$taxonomy = sanitize_key($args['taxonomy'] ?? 'category');
	if (!taxonomy_exists($taxonomy)) {
		return ['error' => 'Taxonomy does not exist: ' . $taxonomy];
	}

	if (empty($args['term_id'])) {
		return ['error' => 'term_id parameter is required'];
	}

	$term_id = absint($args['term_id']);
	$term = get_term($term_id, $taxonomy);
	if (!$term or is_wp_error($term)) {
		return ['error' => 'Term not found: ' . $term_id];
	}

	$update_args = [];
	if (!empty($args['name'])) {
		$update_args['name'] = sanitize_text_field($args['name']);
	}
	if (!empty($args['slug'])) {
		$update_args['slug'] = sanitize_title($args['slug']);
	}
	if (isset($args['description'])) {
		$update_args['description'] = wp_kses_post($args['description']);
	}
	if (isset($args['parent'])) {
		$update_args['parent'] = absint($args['parent']);
	}

	$result = wp_update_term($term_id, $taxonomy, $update_args);

	if (is_wp_error($result)) {
		return ['error' => $result->get_error_message()];
	}

	twcp_term_update_seo($term_id, $taxonomy, $args);
	$term = get_term($term_id, $taxonomy);

	return [
		'success' => true,
		'id'      => $term_id,
		'name'    => $term->name,
		'slug'    => $term->slug,
		'link'    => get_term_link($term),
		'seo'     => [
			'title'       => get_term_meta($term_id, 'rank_math_title', true),
			'description' => get_term_meta($term_id, 'rank_math_description', true),
		],
	];
}


/**
 * Default built-in system prompt for taxonomy archive SEO generation (>1024 tokens)
 *
 * @return string
 */
function twcp_prompt_term_default(): string
{
	$site_name = twcp_site_name();

	return <<<PROMPT
You are a senior search engine optimization (SEO) strategist and conversion copywriter specializing in organic search rankings and collection archive discoverability for business, content, and e-commerce websites.

Your objective is to generate the highest-performing SEO Title tag and Meta Description for given taxonomy archive collections on {$site_name}.

CORE PRINCIPLES & GUIDELINES:

1. TITLE TAG SPECIFICATIONS:
   - Length: Strictly between 50 and 60 characters.
   - Structure: [Collection / Taxonomy Name] - [Key Value Proposition] | {$site_name}
   - Front-load the exact collection or category name in the first 30 characters.
   - Use title case and end with "| {$site_name}".

2. META DESCRIPTION SPECIFICATIONS:
   - Length: Strictly between 135 and 155 characters.
   - Entice visitors to browse the archive, stating what they will find there (products, guides, or services).
   - Conclude with a clear, active call-to-action (e.g. "Shop the collection", "Browse the guides").

3. OUTPUT FORMAT & JSON VALIDATION:
   - Output must be strictly valid JSON without any markdown formatting or code blocks.
   - Format: {"title": "...", "description": "..."}

4. FEW-SHOT EXAMPLES:
   Input: Taxonomy: Product Category, Term Name: Running Shoes
   Output: {"title": "Running Shoes for Road and Trail | {$site_name}", "description": "Browse cushioned, lightweight running shoes for road and trail in every size. Compare fit and support, then find your pair. Shop the collection."}

   Input: Taxonomy: Category, Term Name: Email Marketing
   Output: {"title": "Email Marketing Guides and Tips | {$site_name}", "description": "Learn to grow your list, write emails people open, and automate campaigns with practical email marketing guides and examples. Browse the guides."}
PROMPT;
}


/**
 * Generate and save SEO metadata for a taxonomy term.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy name.
 *
 * @return bool Whether metadata was generated and saved.
 */
function twcp_term_generate_seo(int $term_id, string $taxonomy, bool $force = false): bool
{
	$term = get_term($term_id, $taxonomy);
	if (!$term or is_wp_error($term)) {
		return false;
	}

	$existing_title = (string) get_term_meta($term_id, 'rank_math_title', true);
	$existing_desc = (string) get_term_meta($term_id, 'rank_math_description', true);

	if (!$force and !empty($existing_title) and !empty($existing_desc)) {
		return true;
	}

	$tax_object = get_taxonomy($taxonomy);
	$tax_label = $tax_object ? $tax_object->labels->singular_name : $taxonomy;

	$desc = wp_strip_all_tags($term->description);
	$blocks_field = twcp_block_field_name();
	$blocks = get_term_meta($term_id, $blocks_field, true);
	$blocks_text = twcp_post_blocks_text($blocks);

	$summary = '';
	if (!empty($blocks_text)) {
		$summary .= "Page Builder Content:\n" . $blocks_text . "\n\n";
	}
	if (!empty($desc)) {
		$summary .= "Current Description:\n" . substr($desc, 0, 800) . "\n";
	}

	$site_name = twcp_site_name();

	$prompt = "Generate an SEO Title and Meta Description for the following taxonomy archive on {$site_name} store.\n";
	$prompt .= "Taxonomy: {$tax_label}\n";
	$prompt .= "Term Name: {$term->name}\n";
	if ($summary) {
		$prompt .= "Context Summary:\n{$summary}\n";
	}

	if (!empty($existing_title) or !empty($existing_desc)) {
		$current = !empty($existing_title) ? "- Title: \"{$existing_title}\"\n" : '';
		$current .= !empty($existing_desc) ? "- Description: \"{$existing_desc}\"\n" : '';
		$prompt .= twcp_rewrite_note('SEO tags', rtrim($current), 'Improve these SEO tags to maximize CTR and search performance.');
	}

	$prompt .= "Requirements:\n";
	$prompt .= "1. SEO Title: 50 to 60 characters, captivating, contains primary keywords, ends with \"| {$site_name}\".\n";
	$prompt .= "2. SEO Meta Description: 140 to 160 characters, enticing shoppers to explore the collection.\n";
	$prompt .= "Output strictly valid JSON with this format and NO surrounding markdown:\n";
	$prompt .= '{"title": "...", "description": "..."}';

	$response = twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_term_seo', 'twcp_prompt_term_default'), [], [
		'temperature' => 0.4,
	]);
	$seo = twcp_post_seo_json($response);

	if (!$seo) {
		return false;
	}

	$seo_title = $seo['title'];
	$seo_desc = $seo['description'];

	twcp_term_update_seo($term_id, $taxonomy, [
		'seo_title'       => $seo_title,
		'seo_description' => $seo_desc,
	]);

	// Also update term description if empty
	if (empty($term->description)) {
		wp_update_term($term_id, $taxonomy, ['description' => $seo_desc]);
	}

	update_term_meta($term_id, '_twcp_seo_generated', time());

	return true;
}


/**
 * Default system prompt for the short descriptions shown on category, tag, and brand archives
 *
 * @return string
 */
function twcp_prompt_term_description_default(): string
{
	$site_name = twcp_site_name();

	return <<<PROMPT
You are a senior copywriter writing the short introductions shown at the top of category, tag, and brand archives on {$site_name}.

Guidelines:
* Length: 1 to 3 sentences, 150 to 300 characters.
* Say what the archive covers and who it helps, based on the term name, its parent, and the items in it.
* Use the archive name naturally once, in plain and specific language. No keyword stuffing.
* Never invent facts, numbers, brands, or claims that are not in the context.
* Plain text only: no HTML, markdown, quotes around the text, emojis, or links.

Examples:
Input: Taxonomy: Product Category, Term Name: Running Shoes
Output: Lightweight running shoes for road and trail, with options for every stride and distance. Compare cushioning, support, and fit to find the right pair for your next run.

Input: Taxonomy: Category, Term Name: Email Marketing
Output: Practical guides to growing an email list, writing campaigns people open, and automating follow-ups, from first newsletter to advanced segmentation.
PROMPT;
}


/**
 * Generate and save the description of a taxonomy term
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy name.
 * @param bool   $force    Whether to replace an existing description.
 *
 * @return bool Whether a description was generated and saved, or already existed.
 */
function twcp_term_generate_description(int $term_id, string $taxonomy, bool $force = false): bool
{
	$term = get_term($term_id, $taxonomy);
	if (!$term or is_wp_error($term)) {
		return false;
	}

	$current = trim(wp_strip_all_tags($term->description));
	if (!$force and $current !== '') {
		return true;
	}

	$tax_object = get_taxonomy($taxonomy);
	$parent = $term->parent ? get_term($term->parent, $taxonomy) : null;
	$items = get_posts([
		'post_type'   => $tax_object ? $tax_object->object_type : 'any',
		'post_status' => 'publish',
		'numberposts' => 8,
		'tax_query'   => [['taxonomy' => $taxonomy, 'terms' => $term_id]],
	]);

	$prompt = "Write the archive description for this term:\n";
	$prompt .= 'Taxonomy: ' . ($tax_object ? $tax_object->labels->singular_name : $taxonomy) . "\n";
	$prompt .= "Term Name: {$term->name}\n";
	if ($parent and !is_wp_error($parent)) {
		$prompt .= "Parent: {$parent->name}\n";
	}
	if ($items) {
		$prompt .= 'Items in this archive: ' . implode('; ', wp_list_pluck($items, 'post_title')) . "\n";
	}
	$seo = (string) get_term_meta($term_id, 'rank_math_description', true);
	if ($seo !== '') {
		$prompt .= "SEO Meta Description: {$seo}\n";
	}
	$blocks_text = twcp_post_blocks_text(get_term_meta($term_id, twcp_block_field_name(), true));
	if ($blocks_text) {
		$prompt .= "Page Builder Content:\n" . substr($blocks_text, 0, 800) . "\n";
	}
	if ($current !== '') {
		$prompt .= twcp_rewrite_note('description', '"' . substr($current, 0, 800) . '"');
	}
	$prompt .= "\nOutput only the description text.";

	$response = twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_term_description', 'twcp_prompt_term_description_default'), [], ['temperature' => 0.5]);
	$description = trim(sanitize_textarea_field($response), " \n\"'");
	if ($description === '') {
		return false;
	}

	$result = wp_update_term($term_id, $taxonomy, ['description' => $description]);

	return !is_wp_error($result);
}


/**
 * ACF fields on terms that the full description and FAQ jobs can fill
 *
 * Full descriptions go to WYSIWYG and textarea fields. FAQs go to repeaters with a text question
 * and a textarea, WYSIWYG, or text answer.
 *
 * @param string $kind "content" or "faq"
 *
 * @return array Field key => [ key, name, label, type, taxonomies, question, answer ]
 */
function twcp_term_acf_fields(string $kind): array
{
	$fields = [];

	if (!function_exists('acf_get_field_groups')) {
		return $fields;
	}

	$taxonomies = get_taxonomies(['public' => true, 'show_ui' => true]);
	unset($taxonomies['post_format']);

	foreach ($taxonomies as $taxonomy) {
		foreach (acf_get_field_groups(['taxonomy' => $taxonomy]) as $group) {
			foreach (acf_get_fields($group) ? : [] as $field) {
				$item = ['key' => $field['key'], 'name' => $field['name'], 'label' => $field['label'] ? : $field['name'], 'type' => $field['type'], 'question' => '', 'answer' => ''];

				if ($kind === 'faq' and $field['type'] === 'repeater') {
					foreach ($field['sub_fields'] ?? [] as $sub) {
						if (!$item['question'] and $sub['type'] === 'text') {
							$item['question'] = $sub['key'];
						} elseif (!$item['answer'] and in_array($sub['type'], ['textarea', 'wysiwyg', 'text'], true)) {
							$item['answer'] = $sub['key'];
							$item['type'] = $sub['type'];
						}
					}
					if (!$item['question'] or !$item['answer']) {
						continue;
					}
				} elseif ($kind !== 'content' or !in_array($field['type'], ['wysiwyg', 'textarea'], true)) {
					continue;
				}

				$fields[$field['key']] = ($fields[$field['key']] ?? $item) + ['taxonomies' => []];
				$fields[$field['key']]['taxonomies'][] = $taxonomy;
			}
		}
	}

	return $fields;
}


/**
 * Default system prompt for the full descriptions shown on category, tag, and brand archives
 *
 * @return string
 */
function twcp_prompt_term_content_default(): string
{
	$site_name = twcp_site_name();

	return <<<PROMPT
You are a senior content writer creating the full descriptions shown on category, tag, and brand archives of {$site_name}.

Requirements:
* Length: 250 to 500 words, in 2 to 4 sections.
* Start each section with an H2 heading; use H3 headings only where a section has distinct subtopics. Never use an H1.
* Explain what the archive covers, who it is for, how to choose between the items in it, and what to look for.
* Include one unordered list (UL/LI) with 3 to 6 practical points.
* Use the archive name and closely related search terms naturally, without keyword stuffing.
* Ground the text in the term name, its parent, its short description, and the items listed in the request. Never invent facts, numbers, brands, or claims.
* Do not add links unless the request lists them.
* Output strictly valid WordPress HTML without wrapper tags or markdown code fences.
PROMPT;
}


/**
 * Default system prompt for the FAQs shown on category, tag, and brand archives
 *
 * @return string
 */
function twcp_prompt_term_faq_default(): string
{
	$site_name = twcp_site_name();

	return <<<PROMPT
You are a senior content writer creating the FAQ sections shown on category, tag, and brand archives of {$site_name}.

Requirements:
* Write 4 to 6 questions people search for about the topic of this archive, the kind they ask before choosing, buying, or learning more.
* Questions: natural wording a person would type into a search engine, 5 to 12 words, each ending with a question mark. Ask about the topic itself, never about the website, the archive, or what to read.
* Answers: 2 to 4 sentences (40 to 90 words). Answer the question directly in the first sentence, then add a practical detail, example, or tip.
* Write each answer as standalone expert advice. Never mention the archive, category, page, articles, posts, guides, or this website, and never tell the reader to read something.
* Use the item titles and excerpts in the request to pick relevant questions and details, and use general expert knowledge for the answers.
* Never invent facts about the business itself: prices, numbers, policies, guarantees, locations, or services it offers.
* Cover different intents: choosing, comparing, using, caring for, or learning. Do not repeat a question.
* Output strictly valid JSON with NO surrounding markdown: [{"question": "...", "answer": "..."}]
PROMPT;
}


/**
 * Context about a term for the full description and FAQ prompts
 *
 * @param WP_Term $term
 *
 * @return string
 */
function twcp_term_context(WP_Term $term): string
{
	$tax_object = get_taxonomy($term->taxonomy);
	$parent = $term->parent ? get_term($term->parent, $term->taxonomy) : null;
	$children = get_terms(['taxonomy' => $term->taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'number' => 10, 'fields' => 'names']);
	$items = get_posts([
		'post_type'   => $tax_object ? $tax_object->object_type : 'any',
		'post_status' => 'publish',
		'numberposts' => 12,
		'tax_query'   => [['taxonomy' => $term->taxonomy, 'terms' => $term->term_id]],
	]);

	$context = 'Taxonomy: ' . ($tax_object ? $tax_object->labels->singular_name : $term->taxonomy) . "\n";
	$context .= "Term Name: {$term->name}\n";
	if ($parent and !is_wp_error($parent)) {
		$context .= "Parent: {$parent->name}\n";
	}
	if ($children and !is_wp_error($children)) {
		$context .= 'Subcategories: ' . implode(', ', $children) . "\n";
	}
	$description = trim(wp_strip_all_tags($term->description));
	if ($description !== '') {
		$context .= "Short Description: {$description}\n";
	}
	if ($items) {
		$context .= "Items in this archive:\n";
		foreach ($items as $item) {
			$excerpt = wp_trim_words(wp_strip_all_tags(strip_shortcodes($item->post_excerpt ? : $item->post_content)), 25, '...');
			$context .= '- ' . $item->post_title . ($excerpt !== '' ? ': ' . $excerpt : '') . "\n";
		}
	}

	return $context;
}


/**
 * Generate and save the full description of a term into an ACF field
 *
 * @param int    $term_id
 * @param string $field_key ACF field key from twcp_term_acf_fields('content')
 * @param bool   $force     Whether to replace an existing description
 *
 * @return bool Whether a description was generated and saved, or already existed
 */
function twcp_term_generate_content(int $term_id, string $field_key, bool $force = false): bool
{
	$term = get_term($term_id);
	$field = twcp_term_acf_fields('content')[$field_key] ?? null;
	if (!$term or is_wp_error($term) or !$field or !in_array($term->taxonomy, $field['taxonomies'], true)) {
		return false;
	}

	$current = trim(wp_strip_all_tags((string) get_field($field_key, 'term_' . $term_id, false)));
	if (!$force and $current !== '') {
		return true;
	}

	$prompt = "Write the full archive description for this term:\n" . twcp_term_context($term);
	if ($current !== '') {
		$prompt .= twcp_rewrite_note('full description', mb_substr($current, 0, 2500), 'Review, improve, and expand it.');
	}
	$prompt .= "\nOutput strictly HTML content.";

	$response = twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_term_content', 'twcp_prompt_term_content_default'), [], ['temperature' => 0.5, 'max_tokens' => 2500]);
	$html = trim(str_replace(['```html', '```'], '', $response));
	if ($html === '') {
		return false;
	}

	update_field($field_key, $field['type'] === 'wysiwyg' ? wp_kses_post($html) : sanitize_textarea_field(wp_strip_all_tags($html)), 'term_' . $term_id);

	return true;
}


/**
 * Generate and save the FAQ of a term into an ACF repeater
 *
 * @param int    $term_id
 * @param string $field_key ACF repeater key from twcp_term_acf_fields('faq')
 * @param bool   $force     Whether to replace existing questions
 *
 * @return bool Whether questions were generated and saved, or already existed
 */
function twcp_term_generate_faq(int $term_id, string $field_key, bool $force = false): bool
{
	$term = get_term($term_id);
	$field = twcp_term_acf_fields('faq')[$field_key] ?? null;
	if (!$term or is_wp_error($term) or !$field or !in_array($term->taxonomy, $field['taxonomies'], true)) {
		return false;
	}

	$rows = get_field($field_key, 'term_' . $term_id, false);
	$rows = is_array($rows) ? $rows : [];
	if (!$force and $rows) {
		return true;
	}

	$prompt = "Write the FAQ for this archive:\n" . twcp_term_context($term);
	if ($rows) {
		$current = '';
		foreach ($rows as $row) {
			$current .= 'Q: ' . wp_strip_all_tags((string) ($row[$field['question']] ?? '')) . "\nA: " . wp_strip_all_tags((string) ($row[$field['answer']] ?? '')) . "\n";
		}
		$prompt .= twcp_rewrite_note('FAQ', mb_substr($current, 0, 2500));
	}
	$prompt .= $field['type'] === 'wysiwyg' ? "\nAnswers may use <p>, <strong>, and <ul> tags." : "\nAnswers must be plain text.";

	$response = twcp_model_response(twcp_model_request($prompt, twcp_prompt('twcp_system_prompt_term_faq', 'twcp_prompt_term_faq_default'), [], ['temperature' => 0.5, 'max_tokens' => 2000]));

	$faq = [];
	foreach (is_array($response) ? $response : [] as $item) {
		$question = sanitize_text_field($item['question'] ?? '');
		$answer = (string) ($item['answer'] ?? '');
		$answer = $field['type'] === 'wysiwyg' ? wp_kses_post($answer) : sanitize_textarea_field(wp_strip_all_tags($answer));
		if ($question !== '' and trim($answer) !== '') {
			$faq[] = [$field['question'] => $question, $field['answer'] => $answer];
		}
	}

	if (!$faq) {
		return false;
	}

	update_field($field_key, $faq, 'term_' . $term_id);

	return true;
}
