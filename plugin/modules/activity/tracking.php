<?php
/**
 * Twee MCP — Activity tracking
 *
 * Logs what WP Activity Log does not record as WP Activity Log events, for the roles chosen on the Agent Tools screen:
 * - Editing time: each wp-admin screen counts the seconds its tab is in front and was used in the last two minutes,
 *   and logs them when the tab is hidden or closed, and every ten minutes on long visits.
 * - Term changes: changes of brands, tags, collections and other terms of an item that WP Activity Log does not
 *   log itself, such as product brands and material collections.
 * - Price changes: changes of custom price fields, such as price tables a theme keeps in meta, with the numbers
 *   before and after. WooCommerce prices are logged by WP Activity Log.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

define('TWCP_TERMS_EVENT', 99100);
define('TWCP_PRICES_EVENT', 99101);
define('TWCP_TIME_EVENT', 99102);


/**
 * Roles whose editing time, term and price changes are tracked
 *
 * @return string[]
 */
function twcp_tracking_roles(): array
{
	return array_values(array_intersect((array) get_option('twcp_tracking_roles', []), array_keys(wp_roles()->get_names())));
}


/**
 * Whether a user is tracked
 *
 * @param WP_User|null $user Default: the current user
 *
 * @return bool
 */
function twcp_tracking_user(?WP_User $user = null): bool
{
	$user ??= wp_get_current_user();

	return $user->ID > 0 and array_intersect($user->roles, twcp_tracking_roles());
}


/**
 * Register the tracked roles and price fields, set on the Agent Tools screen
 */
add_action('admin_init', 'twcp_tracking_admin_init');

/**
 * Register the settings of the tracked roles and price fields, set on the Agent Tools screen
 *
 * @return void
 */
function twcp_tracking_admin_init(): void
{
	register_setting('twcp_tracking_group', 'twcp_tracking_roles', [
		'type'              => 'array',
		'sanitize_callback' => function($input): array {
			$roles = array_map('sanitize_key', (array) $input);

			return array_values(array_intersect($roles, array_keys(wp_roles()->get_names())));
		},
		'default'           => [],
	]);

	register_setting('twcp_tracking_group', 'twcp_tracking_price_fields', [
		'type'              => 'string',
		'sanitize_callback' => function($input): string {
			$keys = [];
			foreach (explode(',', (string) $input) as $key) {
				$key = sanitize_key(trim($key));
				if ($key !== '') {
					$keys[] = $key;
				}
			}

			return implode(', ', $keys);
		},
		'default'           => '',
	]);
}


/**
 * Show the tracking settings on the Agent Tools screen
 */
add_filter('twcp_admin_tabs', function(array $tabs): array {
	$tabs['tools']['parts'][] = 'tracking';

	return $tabs;
});

add_filter('twcp_admin_data', function(array $data, string $part): array {
	if ($part !== 'tracking') {
		return $data;
	}

	return [
		'tracking_active' => twcp_activity_active(),
		'tracking_roles'  => twcp_tracking_roles(),
		'roles'           => array_map('translate_user_role', wp_roles()->get_names()),
		'price_fields'    => implode(', ', twcp_tracking_price_fields()),
	];
}, 10, 2);


/**
 * Track the chosen roles while WP Activity Log runs, checked once all plugins are loaded. Init runs on REST requests
 * too, so changes saved by the block editor or MCP tools are tracked.
 */
add_action('init', function() {
	if (class_exists('\WSAL\Controllers\Alert_Manager')) {
		twcp_tracking_hooks();
	}
});

/**
 * Add the hooks that track editing time, term changes, and price changes
 *
 * @return void
 */
function twcp_tracking_hooks(): void
{
	// The time tracker on every admin screen of a tracked user, and the seconds it sends
	add_action('admin_enqueue_scripts', 'twcp_tracking_assets');
	add_action('wp_ajax_twcp_tracking_ping', 'twcp_tracking_ping');

	// Term and price field changes, logged once at the end of the request
	add_action('set_object_terms', 'twcp_tracking_terms', 10, 6);
	add_action('add_post_meta', function($object_id, $meta_key) {
		twcp_tracking_prices($object_id, $meta_key);
	}, 10, 2);
	add_action('update_post_meta', function($meta_id, $object_id, $meta_key) {
		twcp_tracking_prices($object_id, $meta_key);
	}, 10, 3);
	add_action('delete_post_meta', function($meta_ids, $object_id, $meta_key) {
		twcp_tracking_prices($object_id, $meta_key);
	}, 10, 3);
}


/**
 * Enqueue the time tracker with the item and screen being worked on
 *
 * @return void
 */
function twcp_tracking_assets(): void
{
	if (!twcp_tracking_user()) {
		return;
	}

	$screen = get_current_screen();
	$post = ($screen and $screen->base === 'post' and isset($GLOBALS['post']) and $GLOBALS['post'] instanceof WP_Post) ? $GLOBALS['post']->ID : 0;
	wp_enqueue_script('twcp_tracking', twcp_module_url(__DIR__ . '/tracking.js'), [], (string) filemtime(__DIR__ . '/tracking.js'), true);
	wp_localize_script('twcp_tracking', 'twcpTracking', [
		'url'    => admin_url('admin-ajax.php'),
		'nonce'  => wp_create_nonce('twcp_tracking'),
		'post'   => $post,
		'screen' => $screen ? $screen->id : '',
	]);
}


/**
 * Log the active seconds of a screen visit
 *
 * @return void
 */
function twcp_tracking_ping(): void
{
	if (!check_ajax_referer('twcp_tracking', 'nonce', false) or !twcp_tracking_user()) {
		wp_send_json_error(null, 403);
	}

	wp_send_json_success(twcp_tracking_time((int) ($_POST['seconds'] ?? 0), (int) ($_POST['post'] ?? 0), (string) ($_POST['screen'] ?? '')));
}


/**
 * Log active seconds on an item or admin screen
 *
 * @param int    $seconds Active seconds, sent at least every ten minutes
 * @param int    $post    Item being edited, or 0
 * @param string $screen  Admin screen ID
 *
 * @return array Logged event data, empty when nothing was logged
 */
function twcp_tracking_time(int $seconds, int $post, string $screen): array
{
	// Ten minutes and the few seconds a busy tab's timers run late
	$seconds = min($seconds, 660);
	if ($seconds < 5) {
		return [];
	}

	$post = $post > 0 ? get_post($post) : null;
	$minutes = max(1, (int) round($seconds / 60));
	$data = [
		'Seconds'  => $seconds,
		'Duration' => sprintf(_n('%d minute', '%d minutes', $minutes, 'twee-mcp'), $minutes),
		'Screen'   => substr(sanitize_key($screen), 0, 64),
	];

	if ($post) {
		$data = twcp_tracking_post($post) + $data;
		$data['Target'] = sprintf(__('the %1$s %2$s', 'twee-mcp'), $data['PostTypeName'], $data['PostTitle']);
	} else {
		$data['Target'] = sprintf(__('the %s screen', 'twee-mcp'), $data['Screen']);
	}

	\WSAL\Controllers\Alert_Manager::trigger_event(TWCP_TIME_EVENT, $data);

	return $data;
}


/**
 * Event details of an item
 *
 * @param WP_Post $post
 *
 * @return array
 */
function twcp_tracking_post(WP_Post $post): array
{
	$type = get_post_type_object($post->post_type);

	return [
		'PostID'       => $post->ID,
		'PostType'     => $post->post_type,
		'PostTypeName' => $type ? mb_strtolower($type->labels->singular_name) : $post->post_type,
		'PostStatus'   => $post->post_status,
		'PostTitle'    => html_entity_decode(get_the_title($post)),
	];
}


/**
 * Register the time, term and price change events with WP Activity Log
 */
add_action('wsal_custom_alerts_register', 'twcp_tracking_register');

/**
 * Add the time, term and price change events to the WP Activity Log events
 *
 * @return void
 */
function twcp_tracking_register(): void
{
	if (!class_exists('\WSAL\Controllers\Alert_Manager')) {
		return;
	}

	$severity = defined('WSAL_MEDIUM') ? WSAL_MEDIUM : 300;

	\WSAL\Controllers\Alert_Manager::register_group([
		'Twee MCP' => [
			__('Content', 'twee-mcp') => [
				[
					TWCP_TERMS_EVENT,
					$severity,
					__('User changed the brands, tags, collections or other terms of an item', 'twee-mcp'),
					__('Changed the %TaxonomyName% of the %PostTypeName% %PostTitle% to %NewTerms%.', 'twee-mcp'),
					[
						__('ID', 'twee-mcp')             => '%PostID%',
						__('Status', 'twee-mcp')         => '%PostStatus%',
						__('Previous terms', 'twee-mcp') => '%OldTerms%',
					],
					[],
					'post',
					'modified',
				],
				[
					TWCP_PRICES_EVENT,
					$severity,
					__('User changed the custom prices of an item', 'twee-mcp'),
					__('Changed the %Field% prices of the %PostTypeName% %PostTitle%.', 'twee-mcp'),
					[
						__('ID', 'twee-mcp')              => '%PostID%',
						__('Status', 'twee-mcp')          => '%PostStatus%',
						__('Previous prices', 'twee-mcp') => '%OldPrices%',
						__('New prices', 'twee-mcp')      => '%NewPrices%',
					],
					[],
					'post',
					'modified',
				],
				[
					TWCP_TIME_EVENT,
					defined('WSAL_INFORMATIONAL') ? WSAL_INFORMATIONAL : 400,
					__('User spent time on an item or admin screen', 'twee-mcp'),
					__('Spent %Duration% on %Target%.', 'twee-mcp'),
					[
						__('ID', 'twee-mcp')      => '%PostID%',
						__('Seconds', 'twee-mcp') => '%Seconds%',
						__('Screen', 'twee-mcp')  => '%Screen%',
					],
					[],
					'post',
					'viewed',
				],
			],
		],
	]);
}


/**
 * Taxonomies whose changes are logged for a post type: those people assign in the editor, except the ones
 * WP Activity Log logs itself (post and product categories and tags, product attributes, types and visibility)
 *
 * @param string $post_type
 *
 * @return string[]
 */
function twcp_tracking_taxonomies(string $post_type): array
{
	$taxonomies = [];
	$logged = ['category', 'post_tag', 'product_cat', 'product_type', 'product_visibility'];

	foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
		if ($taxonomy->show_ui and !in_array($taxonomy->name, $logged, true) and !str_starts_with($taxonomy->name, 'pa_')) {
			$taxonomies[] = $taxonomy->name;
		}
	}

	return (array) apply_filters('twcp_tracking_taxonomies', $taxonomies, $post_type);
}


/**
 * Meta keys of custom price fields set on the Agent Tools screen, such as the price tables of a theme
 *
 * @return string[]
 */
function twcp_tracking_price_fields(): array
{
	return array_values(array_filter(array_map('trim', explode(',', (string) get_option('twcp_tracking_price_fields', '')))));
}


/**
 * Remember an item's value before the first change of a request, so the change is logged once at the end
 *
 * @param string $key   Item, kind and name of the change
 * @param array  $entry Item, kind, name, and the value before
 *
 * @return void
 */
function twcp_tracking_remember(string $key, array $entry): void
{
	global $twcp_tracking_changes;

	$twcp_tracking_changes[$key] ??= $entry;

	if (!has_action('shutdown', 'twcp_tracking_flush')) {
		add_action('shutdown', 'twcp_tracking_flush', 5);
	}
}


/**
 * Remember the terms an item had before the first change of a request and after the last one
 *
 * @param int    $object_id
 * @param array  $terms
 * @param array  $tt_ids     New term taxonomy IDs
 * @param string $taxonomy
 * @param bool   $append
 * @param array  $old_tt_ids Term taxonomy IDs before the change
 *
 * @return void
 */
function twcp_tracking_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids): void
{
	global $twcp_tracking_changes;

	$post_type = get_post_type($object_id);
	if (!$post_type or !twcp_tracking_user() or !in_array($taxonomy, twcp_tracking_taxonomies($post_type), true)) {
		return;
	}

	$key = $object_id . ':terms:' . $taxonomy;
	twcp_tracking_remember($key, ['post' => (int) $object_id, 'kind' => 'terms', 'name' => $taxonomy, 'old' => array_map('intval', $old_tt_ids)]);
	$twcp_tracking_changes[$key]['new'] = array_map('intval', $tt_ids);
}


/**
 * Remember a price field before its first change of a request
 *
 * @param int    $object_id
 * @param string $meta_key
 *
 * @return void
 */
function twcp_tracking_prices($object_id, $meta_key): void
{
	if (in_array($meta_key, twcp_tracking_price_fields(), true) and twcp_tracking_user()) {
		twcp_tracking_remember($object_id . ':prices:' . $meta_key, ['post' => (int) $object_id, 'kind' => 'prices', 'name' => $meta_key, 'old' => get_post_meta($object_id, $meta_key, true)]);
	}
}


/**
 * Numbers of a price field by their path, such as 0.prices.1.base. Prices are named values; list items, such as
 * the term IDs of a price row, are not.
 *
 * @param mixed      $value
 * @param string     $path Path of the value
 * @param int|string $key  Key of the value in its parent
 *
 * @return array Path => number
 */
function twcp_tracking_flatten($value, string $path = '', $key = ''): array
{
	if (!is_array($value)) {
		if (is_numeric($value) and !is_int($key)) {
			return [$path => (float) $value];
		}

		return [];
	}

	$numbers = [];
	foreach ($value as $index => $item) {
		$numbers += twcp_tracking_flatten($item, $path === '' ? (string) $index : $path . '.' . $index, $index);
	}

	return $numbers;
}


/**
 * Numbers that differ between two values of a price field, such as nested price tables
 *
 * @param mixed $old
 * @param mixed $new
 *
 * @return array [ old numbers, new numbers ] as "path: number" lists, empty when no number changed
 */
function twcp_tracking_numbers($old, $new): array
{
	$old = twcp_tracking_flatten(maybe_unserialize($old));
	$new = twcp_tracking_flatten(maybe_unserialize($new));
	$paths = array_keys(array_diff_assoc($old, $new) + array_diff_assoc($new, $old));

	if (!$paths) {
		return [];
	}

	$before = $after = [];
	foreach (array_slice($paths, 0, 20) as $path) {
		$before[] = $path . ': ' . (isset($old[$path]) ? $old[$path] + 0 : '-');
		$after[] = $path . ': ' . (isset($new[$path]) ? $new[$path] + 0 : '-');
	}

	return [implode(', ', $before), implode(', ', $after)];
}


/**
 * Log the term and price changes of the request, once per item and taxonomy or field
 *
 * @return array Logged event data
 */
function twcp_tracking_flush(): array
{
	global $twcp_tracking_changes;

	$changes = (array) $twcp_tracking_changes;
	$twcp_tracking_changes = [];
	$logged = [];

	$names = function(array $ids, string $taxonomy): string {
		$names = $ids ? get_terms(['taxonomy' => $taxonomy, 'term_taxonomy_id' => $ids, 'hide_empty' => false, 'fields' => 'names']) : [];

		return $names && !is_wp_error($names) ? implode(', ', array_map('html_entity_decode', $names)) : __('None', 'twee-mcp');
	};

	foreach ($changes as $change) {
		$post = get_post($change['post']);
		if (!$post or in_array($post->post_status, ['auto-draft', 'inherit'], true)) {
			continue;
		}

		if ($change['kind'] === 'terms') {
			sort($change['old']);
			sort($change['new']);
			if ($change['old'] === $change['new']) {
				continue;
			}

			$taxonomy = get_taxonomy($change['name']);
			$code = TWCP_TERMS_EVENT;
			$details = [
				'Taxonomy'     => $change['name'],
				'TaxonomyName' => $taxonomy ? mb_strtolower($taxonomy->labels->name) : $change['name'],
				'OldTerms'     => $names($change['old'], $change['name']),
				'NewTerms'     => $names($change['new'], $change['name']),
			];
		} else {
			$numbers = twcp_tracking_numbers($change['old'], get_post_meta($post->ID, $change['name'], true));
			if (!$numbers) {
				continue;
			}

			$code = TWCP_PRICES_EVENT;
			$details = ['Field' => $change['name'], 'OldPrices' => $numbers[0], 'NewPrices' => $numbers[1]];
		}

		$data = twcp_tracking_post($post) + $details;

		\WSAL\Controllers\Alert_Manager::trigger_event($code, $data);

		$logged[] = $data;
	}

	return $logged;
}
