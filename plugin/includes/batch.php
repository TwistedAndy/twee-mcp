<?php
/**
 * Twee MCP — Bulk & Batch Actions
 *
 * Single home for every bulk operation:
 * - Background batch engine (slice-by-slice with Pause, Resume, Reset) used by the AI Tools > Automation tab
 * - Real-time activity log showing processed items, titles, and generated results
 * - WordPress list-table bulk actions for posts, products, terms, and media
 *
 * The core runs the jobs; modules add them with the twcp_batch_types filter, such as the post, term, and image jobs
 * of the wp module, product copy and cross-sells, or ACF block descriptions, and their sections of the Automation
 * screen with the twcp_batch_sections filter.
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
 * Batch type registry, added by modules with the twcp_batch_types filter. Every job names its card on the Automation
 * screen (title, description, button, section) and "run", the function that fills one item: function(int $id,
 * bool $override) returning whether it worked, the new text, or [ success, message ]. Jobs with "terms" run over
 * taxonomy terms instead of posts, and their scope is a taxonomy instead of a post type. Jobs with "text" write that
 * text, so they can refine it or rewrite only poor values; the others can fill empty items or redo all of them, with
 * "redo" naming that mode, or set to false for a job that only fills.
 *
 * A job names the field it fills: "post:field" or "meta:key" for posts, and "term:description" or "term_meta:key" for
 * terms. A term job with "targets", a function returning the ACF term fields it can fill (field key => [ key, name,
 * label, taxonomies ]), fills the one chosen on its card. A job of posts can name its post type; without one, its card offers the post types,
 * or those listed in "scopes". The plugin takes the rest from them, see twcp_batch_defaults(). A job can set any of
 * those itself: "slice", "cost" [ model requests, tokens ] of one item, "capability", "unit", "fields" it may change for
 * Undo, or a function of the item returning them, "args", function(bool $override, array $types) returning the
 * WP_Query or get_terms arguments of its items in the post types or taxonomies of its scope, or "query" and "total" for
 * items that are not posts, "current" with the text it would replace, and "restored" run after Undo. A job lists the
 * system "prompts" it runs with, whose editors its card shows. A job with "combine" sends the model requests of a slice
 * as one request, see twcp_batch_combine().
 *
 * @return array Type => job
 */
function twcp_batch_types(): array
{
	$types = [];
	foreach ((array) apply_filters('twcp_batch_types', []) as $type => $job) {
		$types[$type] = $job + twcp_batch_defaults($job, $type);
	}

	return $types;
}


/**
 * What a job leaves out, taken from its post type and the field it fills. Without "override", it fills the items
 * whose field is empty; with it, all of them. Post jobs fill published posts.
 *
 * @param array  $job
 * @param string $type
 *
 * @return array [ section, slice, capability, cost, unit, fields, args, current ]
 */
function twcp_batch_defaults(array $job, string $type): array
{
	$defaults = ['section' => 'content', 'slice' => 5, 'capability' => 'edit_others_posts', 'cost' => [1, 2000]];

	$post_type = get_post_type_object($job['post_type'] ?? '');
	if ($post_type) {
		$defaults['capability'] = $post_type->cap->edit_others_posts;
		$defaults['unit'] = $post_type->labels->name;
	}
	if (empty($job['field']) and empty($job['targets'])) {
		return $defaults;
	}

	// The field of a job with targets is the ACF field chosen on its card: [ kind, key, meta key ]
	$field = function() use ($job, $type): array {
		if (isset($job['targets'])) {
			$target = twcp_batch_field($type);

			return ['acf', $target['key'] ?? '', $target['name'] ?? ''];
		}
		[$kind, $key] = explode(':', $job['field'], 2);

		return [$kind, $key, $key];
	};

	$defaults['fields'] = isset($job['targets']) ? function() use ($type): array {
		return ['acf:' . (twcp_batch_field($type)['key'] ?? '')];
	} : [$job['field']];

	$defaults['args'] = function(bool $override, array $types) use ($job, $field): array {
		global $wpdb;

		$args = !empty($job['terms']) ? ['taxonomy' => $types, 'hide_empty' => false] : ['post_type' => $types, 'post_status' => 'publish'];
		[$kind, , $name] = $field();
		if ($override) {
			return $args;
		}

		if ($kind === 'post') {
			$args['twcp_empty'] = $name;
		} elseif ($kind === 'term') {
			// Descriptions are kept in term_taxonomy, which get_terms cannot filter by
			$in = implode(',', array_fill(0, count($types), '%s'));
			$args['include'] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE description = '' AND taxonomy IN ({$in})", ...$types))) ? : [0];
		} else {
			// Missing, empty, an empty list, or the row count of an empty ACF repeater
			$args['meta_query'] = [
				'relation' => 'OR',
				['key' => $name, 'compare' => 'NOT EXISTS'],
				['key' => $name, 'value' => $kind === 'acf' ? ['', 'a:0:{}', '0'] : ['', 'a:0:{}'], 'compare' => 'IN'],
			];
		}

		return $args;
	};

	$defaults['current'] = function(int $id) use ($field): string {
		[$kind, $key] = $field();
		$value = twcp_batch_value($kind . ':' . $key, $id);

		// Rows of an ACF repeater are lists of subfields
		if (is_array($value)) {
			$value = implode("\n", array_map(function($row): string {
				return implode(' ', (array) $row);
			}, $value));
		}

		return is_scalar($value) ? mb_substr(trim(wp_strip_all_tags((string) $value)), 0, 1200) : '';
	};

	return $defaults;
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

	$redo = twcp_batch_types()[$type]['redo'] ?? __('Redo all', 'twee-mcp');

	return $redo === false ? ['fill' => __('Fill empty', 'twee-mcp')] : ['fill' => __('Fill empty', 'twee-mcp'), 'all' => $redo];
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
	$job = twcp_batch_types()[$type] ?? [];

	return isset($job['current']) ? (string) call_user_func($job['current'], $id) : '';
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

	$prompt = "Review the current {$label} of each item below and decide whether it is poor and should be rewritten.\n";
	$prompt .= "Poor: generic or placeholder text, a filename or code, keyword stuffing, broken grammar, a different language than the site, unrelated to the item, or far from the expected length.\n";
	$prompt .= "Acceptable: accurate, readable, and specific to the item, even if it could be polished.\n\n";

	foreach ($items as $id => $text) {
		$prompt .= 'ID: ' . $id . "\nItem: " . twcp_batch_name($type, $id) . "\nCurrent:\n{$text}\n\n";
	}

	$prompt .= 'Return strictly a JSON object that maps every ID to true when it needs a rewrite and false when it is acceptable, e.g. {"12": true, "15": false}. No markdown.';

	$verdicts = twcp_model_response(twcp_model_request($prompt, 'You are a strict content quality reviewer for ' . twcp_site_name() . '.', [], ['temperature' => 0.1]));
	if (!is_array($verdicts)) {
		return null;
	}

	return array_values(array_filter(array_map('intval', array_keys(array_filter($verdicts, fn($poor) => $poor === true or $poor === 'true')))));
}


/**
 * Generate the items of a slice with one model request
 *
 * Each item runs once with its model request collected instead of sent. The collected prompts go to the model
 * together, and each item's part of the reply is kept for its own run, which then saves it without a request. Items
 * the reply misses, and those that send images or more than one request, send their own request as usual.
 *
 * @param array $job
 * @param int[] $ids
 * @param bool  $override
 */
function twcp_batch_combine(array $job, array $ids, bool $override): void
{
	$items = [];
	foreach ($ids as $id) {
		twcp_model_combine(['requests' => []]);
		call_user_func($job['run'], $id, $override);
		$requests = twcp_model_combine()['requests'];
		if (count($requests) === 1 and !$requests[0]['images']) {
			$items[$id] = $requests[0];
		}
	}
	twcp_model_combine([]);

	// Items share the system prompt of the request
	$first = reset($items);
	$items = array_filter($items, function(array $request) use ($first): bool {
		return $request['system'] === $first['system'];
	});
	if (count($items) < 2) {
		return;
	}

	$prompt = 'Below are ' . count($items) . " separate requests, each with its own ID. Answer each one on its own, using only the facts given with it, and follow its output rules.\n\n";
	foreach ($items as $id => $request) {
		$prompt .= "=== ID {$id} ===\n{$request['prompt']}\n\n";
	}
	$prompt .= 'Return strictly a JSON object that maps every ID to its answer: a string, or an object when the request asks for JSON, e.g. {"12": "...", "15": "..."}. No markdown.';

	$answers = twcp_model_response(twcp_model_request($prompt, $first['system'], [], array_intersect_key($first['options'], ['temperature' => 0])));

	$replies = [];
	foreach ($items as $id => $request) {
		$answer = $answers[$id] ?? '';
		$answer = is_array($answer) ? wp_json_encode($answer) : trim((string) $answer);
		if ($answer !== '') {
			$replies[md5($request['system'] . $request['prompt'])] = $answer;
		}
	}
	twcp_model_combine(['replies' => $replies]);
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
	$fields = twcp_batch_types()[$type]['fields'] ?? [];

	return $fields instanceof Closure ? $fields($id) : (array) $fields;
}


/**
 * Value of a field of an item, as twcp_batch_fields() names it
 *
 * @param string $field
 * @param int    $id
 *
 * @return mixed Null when a meta key does not exist
 */
function twcp_batch_value(string $field, int $id)
{
	[$kind, $key] = explode(':', $field, 2);

	switch ($kind) {
		case 'post':
			return (string) get_post_field($key, $id, 'raw');
		case 'term':
			return (string) get_term_field($key, $id, '', 'raw');
		case 'acf':
			return metadata_exists('term', $id, '_' . (acf_get_field($key)['name'] ?? '')) ? get_field($key, 'term_' . $id, false) : null;
		case 'meta':
			return metadata_exists('post', $id, $key) ? get_post_meta($id, $key, true) : null;
		case 'term_meta':
			return metadata_exists('term', $id, $key) ? get_term_meta($id, $key, true) : null;
		case 'terms':
			$terms = wp_get_object_terms($id, $key, ['fields' => 'ids']);

			return is_array($terms) ? array_map('intval', $terms) : [];
		case 'yoast_term':
			return get_option('wpseo_taxonomy_meta', [])[$key][$id] ?? null;
	}

	return null;
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
		$snapshot[$field] = twcp_batch_value($field, $id);
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

	$job = twcp_batch_types()[$type] ?? [];
	if (isset($job['restored'])) {
		call_user_func($job['restored'], $id);
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
 * Log entry for an item whose job function returned the new text, or only whether it worked
 *
 * @param string      $type
 * @param int         $id
 * @param bool|string $result
 *
 * @return array [ success, message ]
 */
function twcp_batch_result(string $type, int $id, $result): array
{
	$title = twcp_batch_name($type, $id);
	if (is_string($result) ? $result === '' : !$result) {
		return ['success' => false, 'message' => sprintf(__('Failed to update "%s" (ID: %d).', 'twee-mcp'), $title, $id)];
	}

	$text = is_string($result) ? $result : twcp_batch_current($type, $id);

	return ['success' => true, 'message' => sprintf(__('Updated "%s"', 'twee-mcp'), $title) . ($text !== '' ? sprintf(': "%s"', mb_strimwidth($text, 0, 80, '...')) : '')];
}


/**
 * Name of an item of a batch job for its log
 *
 * @param string $type
 * @param int    $id
 *
 * @return string
 */
function twcp_batch_name(string $type, int $id): string
{
	$name = !empty(twcp_batch_types()[$type]['terms']) ? (get_term($id)->name ?? '') : get_the_title($id);

	return $name !== '' ? $name : '#' . $id;
}


/**
 * Query posts whose excerpt, content, or title is empty, with the twcp_empty query var naming the field
 */
add_filter('posts_where', function(string $where, WP_Query $query): string {
	global $wpdb;

	$field = $query->get('twcp_empty');
	if (in_array($field, ['post_excerpt', 'post_content', 'post_title'], true)) {
		$where .= " AND (TRIM({$wpdb->posts}.{$field}) = '' OR {$wpdb->posts}.{$field} IS NULL)";
	}

	return $where;
}, 10, 2);


/**
 * Post types or taxonomies a batch job runs over in the scope chosen on its card: its own post type, the one chosen,
 * or all those the card offers. Term jobs with targets keep to the taxonomies of the chosen field.
 *
 * @param string $type
 * @param string $scope Post type or taxonomy, or "all"
 *
 * @return string[]
 */
function twcp_batch_scope(string $type, string $scope = 'all'): array
{
	$job = twcp_batch_types()[$type] ?? [];

	if (!empty($job['terms'])) {
		$types = array_keys(twcp_batch_taxonomies());
		if (isset($job['targets'])) {
			$types = array_intersect($types, twcp_batch_field($type)['taxonomies'] ?? []);
		}
	} elseif (!empty($job['post_type'])) {
		return [$job['post_type']];
	} else {
		$types = array_keys(twcp_batch_post_types());
		if (isset($job['scopes'])) {
			$types = array_intersect($types, $job['scopes']);
		}
	}

	return array_values($scope === 'all' ? $types : array_intersect($types, [$scope]));
}


/**
 * WP_Query or get_terms arguments describing the items of a batch job
 *
 * @param string $type
 * @param bool   $override
 * @param string $scope Post type or taxonomy, or "all"
 *
 * @return array Empty when the job has nothing to fill
 */
function twcp_batch_args(string $type, bool $override = false, string $scope = 'all'): array
{
	$job = twcp_batch_types()[$type] ?? [];
	$types = twcp_batch_scope($type, $scope);

	return ($types and isset($job['args'])) ? (array) call_user_func($job['args'], $override, $types) : [];
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
	$job = twcp_batch_types()[$type] ?? [];
	if (isset($job['query'])) {
		return (array) call_user_func($job['query'], $offset, max(0, $limit), $override);
	}
	if (!empty($job['terms'])) {
		$args = twcp_batch_args($type, $override, $post_type);

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
	$job = twcp_batch_types()[$type] ?? [];
	if (isset($job['total'])) {
		return (int) call_user_func($job['total'], $override);
	}
	if (!empty($job['terms'])) {
		$args = twcp_batch_args($type, $override, $post_type);
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
 * Target field a batch job writes to: the one chosen on its card, or the first that fits
 *
 * @param string $type
 *
 * @return array Field from the targets of the job, empty for jobs without targets
 */
function twcp_batch_field(string $type): array
{
	$targets = twcp_batch_types()[$type]['targets'] ?? null;
	$fields = $targets ? (array) call_user_func($targets) : [];
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
		'field'      => '', // Target field of jobs with targets
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

	// Keep the last 40 logs, so the saved state does not keep growing
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

	$runs = array_filter($ids, function(int $id) use ($current, $poor): bool {
		return !isset($current[$id]) or ($poor !== null and in_array($id, $poor, true));
	});
	if (!empty($job['combine'])) {
		twcp_batch_combine($job, $runs, $override);
	}

	foreach ($ids as $id) {
		$state['processed']++;

		if (!in_array($id, $runs, true)) {
			$name = twcp_batch_name($type, $id);
			$res = $poor === null
				? ['success' => false, 'message' => sprintf(__('Could not check the quality of "%s" (ID: %d).', 'twee-mcp'), $name, $id)]
				: ['success' => true, 'message' => sprintf(__('Kept "%s": the current text looks good.', 'twee-mcp'), $name)];
		} else {
			// Keep the values from before the first change, so Undo returns to them
			$backup[$id] = $backup[$id] ?? twcp_batch_snapshot($type, $id);
			$res = call_user_func($job['run'], $id, $override);
			if (!is_array($res)) {
				$res = twcp_batch_result($type, $id, $res);
			}
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
	twcp_model_combine([]);
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
 * Up to 30 items a batch job can be previewed on, in the scope and mode of its card and matching a title search. In
 * "Fill empty", the items left come first, and all items when none are left.
 *
 * @param string $type
 * @param string $scope  Post type or taxonomy, or "all"
 * @param string $mode
 * @param string $search
 *
 * @return array[] [ id, label ]
 */
function twcp_batch_items(string $type, string $scope, string $mode, string $search = ''): array
{
	$job = twcp_batch_types()[$type];
	$items = [];

	foreach ($mode === 'fill' ? [false, true] : [true] as $all) {
		if (!empty($job['terms'])) {
			$args = twcp_batch_args($type, $all, $scope);
			$terms = $args ? get_terms($args + ['number' => 30, 'search' => $search, 'orderby' => 'name', 'update_term_meta_cache' => false]) : [];
			foreach (is_wp_error($terms) ? [] : $terms as $term) {
				$items[] = ['id' => $term->term_id, 'label' => $term->name . ' (' . (get_taxonomy($term->taxonomy)->labels->singular_name ?? $term->taxonomy) . ')'];
			}
		} else {
			if (isset($job['query'])) {
				$ids = twcp_batch_query($type, 0, 30, $all, $scope);
				_prime_post_caches($ids, false, false);
				$posts = array_filter(array_map('get_post', $ids));
			} else {
				// Titles only and the newest first by ID, without meta or terms, so the list stays quick on thousands of items
				$args = twcp_batch_args($type, $all, $scope);
				$posts = $args ? (new WP_Query($args + [
						'posts_per_page'         => 30,
						's'                      => $search,
						'search_columns'         => ['post_title'],
						'orderby'                => 'ID',
						'order'                  => 'DESC',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					]))->posts : [];
			}
			foreach ($posts as $post) {
				$items[] = ['id' => $post->ID, 'label' => ($post->post_title !== '' ? $post->post_title : '#' . $post->ID) . ' (' . (get_post_type_object($post->post_type)->labels->singular_name ?? $post->post_type) . ')'];
			}
		}
		if ($items) {
			break;
		}
	}

	return $items;
}


/**
 * AJAX: Items the batch jobs of the screen can be previewed on
 */
add_action('wp_ajax_twcp_batch_items', 'twcp_batch_ajax_items');

/**
 * List the preview items of several cards in one request, each in its own scope, mode, and search.
 *
 * @return void Sends a JSON response.
 */
function twcp_batch_ajax_items(): void
{
	twcp_batch_request();

	$allowed = twcp_batch_allowed();
	$items = [];
	foreach ((array) wp_unslash($_POST['cards'] ?? []) as $card) {
		$type = sanitize_key($card['type'] ?? '');
		if (isset($allowed[$type])) {
			$items[$type] = twcp_batch_items($type, sanitize_key($card['post_type'] ?? '') ? : 'all', twcp_batch_mode($type, $card['mode'] ?? 'fill'), sanitize_text_field($card['search'] ?? ''));
		}
	}

	wp_send_json_success(['items' => $items]);
}


/**
 * AJAX: Preview a batch job on one item
 */
add_action('wp_ajax_twcp_batch_preview', 'twcp_batch_ajax_preview');

/**
 * Run a batch job on one item with its model requests collected instead of sent, send them, and return the replies
 * next to the current value. Nothing is saved. Administrators can try prompt and shared context text they have not
 * saved yet.
 *
 * @return void Sends a JSON response.
 */
function twcp_batch_ajax_preview(): void
{
	$type = twcp_batch_request();
	$id = absint($_POST['id'] ?? 0);
	if (!$type or !$id) {
		wp_send_json_error(['message' => __('Choose an item to preview.', 'twee-mcp')]);
	}

	$missing = twcp_model_missing();
	if ($missing) {
		wp_send_json_error(['message' => $missing . ' ' . __('Set up a model on the AI Provider screen first.', 'twee-mcp')]);
	}

	$job = twcp_batch_types()[$type];
	if (current_user_can('manage_options')) {
		$editable = array_flip(array_merge($job['prompts'] ?? [], ['twcp_general_context', 'twcp_target_keywords']));
		foreach (array_intersect_key((array) wp_unslash($_POST['prompts'] ?? []), $editable) as $option => $text) {
			$text = twcp_prompt_sanitize($text, $option);
			add_filter('pre_option_' . $option, function() use ($text): string {
				return $text;
			});
		}
	}

	twcp_rewrite_mode(twcp_batch_mode($type, wp_unslash($_POST['mode'] ?? 'fill')));
	twcp_model_combine(['requests' => []]);
	call_user_func($job['run'], $id, true);
	$requests = twcp_model_combine()['requests'];
	twcp_model_combine([]);

	$results = [];
	foreach ($requests as $request) {
		$reply = twcp_model_request($request['prompt'], $request['system'], $request['images'], $request['options']);
		$results[] = ['prompt' => $request['prompt'], 'reply' => $reply, 'error' => $reply === '' ? twcp_model_last_error() : ''];
	}
	twcp_rewrite_mode('');

	if (!$results) {
		wp_send_json_error(['message' => __('This item sends no model request, so there is nothing to preview.', 'twee-mcp')]);
	}

	wp_send_json_success(['current' => twcp_batch_current($type, $id), 'results' => $results]);
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
 * Sections of the Automation screen batch cards, in the order of their position. Modules add theirs with the
 * twcp_batch_sections filter, and settings below a section's description with the twcp_batch_section action.
 *
 * @return array Section => [ label, description, position ]
 */
function twcp_batch_sections(): array
{
	return twcp_position_sort((array) apply_filters('twcp_batch_sections', [
		'content' => [
			'label'       => __('Content Automation', 'twee-mcp'),
			'description' => __('Fill in what posts and pages are missing. By default, each job only fills items that lack the value; choose another mode to refine or rewrite existing ones.', 'twee-mcp'),
			'position'    => 10,
		],
		'media'   => [
			'label'       => __('Media Automation', 'twee-mcp'),
			'description' => __('Images are scaled down before they are sent to the vision model to keep token usage low.', 'twee-mcp'),
			'position'    => 20,
		],
	]));
}


/**
 * Cards of the batch jobs the current user can run, by section
 *
 * @return array Section => cards
 */
function twcp_batch_cards(): array
{
	$post_types = ['all' => __('All Post Types', 'twee-mcp')] + array_map(fn(WP_Post_Type $type) => $type->labels->name, twcp_batch_post_types());
	$taxonomies = ['all' => __('All Taxonomies', 'twee-mcp')] + array_map(fn(WP_Taxonomy $taxonomy) => $taxonomy->labels->name, twcp_batch_taxonomies());

	$sections = array_fill_keys(array_keys(twcp_batch_sections()), []);
	foreach (twcp_batch_allowed() as $type => $job) {
		if (!isset($job['title'], $sections[$job['section']])) {
			continue;
		}

		$state = twcp_batch_state($type);
		$unit = $job['unit'] ?? '';
		$fields = [];
		foreach (isset($job['targets']) ? (array) call_user_func($job['targets']) : [] as $key => $field) {
			$where = count($field['taxonomies']) > 2
				? sprintf(__('%d taxonomies', 'twee-mcp'), count($field['taxonomies']))
				: implode(', ', array_map(fn(string $taxonomy) => get_taxonomy($taxonomy)->labels->name, $field['taxonomies']));
			$fields[$key] = $field['label'] . ' (' . $where . ')';
		}
		$badge = $state['mode'] !== 'fill'
			? ($unit ? sprintf(__('%d Total %s', 'twee-mcp'), $state['total'], $unit) : sprintf(__('%d Total', 'twee-mcp'), $state['total']))
			: sprintf(__('%d Missing', 'twee-mcp'), max(0, $state['total'] - $state['success']));

		// Jobs of terms choose a taxonomy, and jobs of posts without a post type of their own choose one
		$scopes = [];
		if (!empty($job['terms'])) {
			$scopes = $taxonomies;
		} elseif (empty($job['post_type']) and empty($job['query'])) {
			$scopes = array_intersect_key($post_types, array_flip($job['scopes'] ?? array_keys($post_types)));
		}

		$sections[$job['section']][] = [
			'type'        => $type,
			'title'       => $job['title'],
			'description' => $job['description'] ?? '',
			'button'      => $job['button'] ?? $job['title'],
			'scopes'      => $scopes,
			'data'        => $state,
			'badge'       => $badge,
			'modes'       => count(twcp_batch_modes($type)) > 1 ? twcp_batch_modes($type) : [],
			'cost'        => $job['cost'],
			'combine'     => !empty($job['combine']),
			'slice'       => $job['slice'],
			'fields'      => $fields,
			'undo'        => (bool) twcp_batch_fields($type, 0),
			'prompts'     => $job['prompts'] ?? [],
		];
	}

	return array_filter($sections);
}


/**
 * Items that need attention, for the overview on the Automation screen, added by modules with the twcp_batch_overview
 * filter
 *
 * @return array Item => [ label, count, url ], url empty when there is no screen to open
 */
function twcp_batch_overview(): array
{
	return (array) apply_filters('twcp_batch_overview', []);
}


/**
 * WordPress list-table bulk actions per screen, added by modules with the twcp_batch_actions filter. An action runs
 * "run" on each selected item, which returns whether it worked; with "ids", run gets all of them at once and returns
 * [ processed, skipped ]. A generate action names the batch "type" whose fields it fills, so items that already have
 * them count as skipped.
 *
 * @return array Screen ID => [ action => [ label, run, type, ids ] ]
 */
function twcp_batch_actions(): array
{
	return (array) apply_filters('twcp_batch_actions', []);
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
				$current[$action] = $info['label'];
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

				$ids = array_map('intval', $item_ids);
				if (!empty($info['ids'])) {
					$result = (array) call_user_func($info['run'], $ids);
					$processed = (int) ($result['processed'] ?? 0);
					$skipped = (int) ($result['skipped'] ?? 0);
				} else {
					$processed = 0;
					$skipped = 0;
					foreach ($ids as $id) {
						// Generate actions succeed without a change on items that already have the value
						$before = isset($info['type']) ? twcp_batch_snapshot($info['type'], $id) : null;
						if (!call_user_func($info['run'], $id)) {
							continue;
						}
						if ($before !== null and $before === twcp_batch_snapshot($info['type'], $id)) {
							$skipped++;
						} else {
							$processed++;
						}
					}
				}

				return add_query_arg(['twcp_processed' => $processed, 'twcp_skipped' => $skipped, 'twcp_selected' => count($ids)], $redirect_to);
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
	} elseif (isset($_GET['twcp_processed'])) {
		add_action('admin_notices', function() {
			$count = absint($_GET['twcp_processed']);
			$skipped = absint($_GET['twcp_skipped'] ?? 0);
			$failed = max(0, absint($_GET['twcp_selected'] ?? 0) - $count - $skipped);

			$message = sprintf(_n('Processed %d item with AI.', 'Processed %d items with AI.', $count, 'twee-mcp'), $count);
			if ($skipped) {
				$message .= ' ' . sprintf(_n('%d item already had a value and was left as it is.', '%d items already had a value and were left as they are.', $skipped, 'twee-mcp'), $skipped);
			}
			if ($failed) {
				$message .= ' ' . sprintf(_n('%d item failed: check the AI Provider connection and try it again.', '%d items failed: check the AI Provider connection and try them again.', $failed, 'twee-mcp'), $failed);
			}
			?>
			<div class="notice <?php echo $failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
				<p><?php echo esc_html($message); ?></p>
			</div>
			<?php
		});
	}
}
