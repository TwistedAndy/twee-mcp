<?php
/**
 * Module: Activity Log
 * Description: Reports of the events WP Activity Log records, and the activity tracking that adds to them.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/tracking.php';


/**
 * The activity tools and the activity tracking need WP Activity Log
 */
add_filter('twcp_modules', function(array $modules): array {
	$modules['activity'] = ['plugin' => 'WP Activity Log', 'active' => twcp_activity_active()];

	return $modules;
});


/**
 * Add the WP Activity Log tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$range = [
		'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (default 30 days ago).'],
		'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (default today).'],
		'role'      => ['type' => 'string', 'description' => 'Role slug of the users to include (default "shop_manager"); "any" includes every signed-in user.'],
		'user'      => ['type' => 'string', 'description' => 'Only this user: ID, login, email, or display name. Overrides role.'],
	];

	$groups['activity'] = [
		'label'    => __('Activity Log', 'twee-mcp'),
		'position' => 100,
		'module'   => 'activity',
		'hint'     => 'For staff activity from WP Activity Log, start with get_activity_summary for the items each user published, the price updates, time spent, and items edited, and get_brand_activity to group that work by brand, category, collection, or another taxonomy. Published items are the main measure of output. Once tracked_minutes is above 0, prefer it over the estimated active time. Drill into one person with get_user_activity, one product or material with get_item_history, and logins with get_login_activity. For anything else, list events with get_activity_events (action price_changed or terms_changed, for example), using get_activity_event_types to find event IDs.',
		'tools'    => [
			'get_activity_summary' => [
				'description' => 'Summarize staff activity from WP Activity Log for a date range, one row per user: active days, sessions, estimated active time, and for each post type (products, materials, pages...) how many items they opened, edited, created, published, trashed and saved, plus term changes, media uploads, and logins.',
				'callable'    => 'twcp_activity_summary',
				'capability'  => 'manage_options',
				'private'     => true,
				'parameters'  => $range + [
					'idle_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120, 'description' => 'A longer gap between two actions counts as idle time (default 15).'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'get_brand_activity'   => [
				'description' => 'Group the products, materials and other items staff worked on in a date range by brand, category, collection, designer, or any other taxonomy, with the users behind each group. Items are grouped by their current terms. Items of a type without that taxonomy, such as materials for brands, are matched by the name of their top-level term, such as a collection named after the brand.',
				'callable'    => 'twcp_activity_brands',
				'capability'  => 'manage_options',
				'private'     => true,
				'parameters'  => $range + [
					'taxonomy' => ['type' => 'string', 'description' => 'Taxonomy to group by (default "product_brand"), e.g. "product_cat", "pa_collection", "collection", "pa_designer".'],
					'limit'    => ['type' => 'integer', 'description' => 'Number of groups (default 50, max 200).'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'get_user_activity'    => [
				'description' => 'Show how one user worked in a date range: totals, each day with active time and actions, each login session with start, end and active time, and the products, materials and other items they worked on with their brand or other terms, saves, and actions.',
				'callable'    => 'twcp_activity_user_report',
				'capability'  => 'manage_options',
				'private'     => true,
				'required'    => ['user'],
				'parameters'  => array_diff_key($range, ['role' => true]) + [
					'idle_minutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120, 'description' => 'A longer gap between two actions counts as idle time (default 15).'],
					'taxonomy'     => ['type' => 'string', 'description' => 'Taxonomy that labels the items (default "product_brand").'],
					'limit'        => ['type' => 'integer', 'description' => 'Number of items (default 50, max 200).'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'get_item_history'     => [
				'description' => 'Show the logged history of one product, material, page, or other post: who opened, changed, published, or trashed it and when, with the old and new values of each change, and a summary of everyone who worked on it.',
				'callable'    => 'twcp_activity_history',
				'capability'  => 'manage_options',
				'private'     => true,
				'required'    => ['id'],
				'parameters'  => [
					'id'        => ['type' => 'integer', 'minimum' => 1, 'description' => 'Post, product, or material ID.'],
					'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (default: everything logged).'],
					'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (default today).'],
					'limit'     => ['type' => 'integer', 'description' => 'Number of events (default 100, max 200).'],
					'offset'    => ['type' => 'integer', 'description' => 'Pagination offset.'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'get_activity_events'  => [
				'description' => 'List logged events newest first with the user, event description, message, item, details, and old and new values. Filter by date range, user, role, item, event IDs, or action. Use it for questions the summaries do not answer.',
				'callable'    => 'twcp_activity_events',
				'capability'  => 'manage_options',
				'private'     => true,
				'parameters'  => array_merge($range, [
					'role'          => ['type' => 'string', 'description' => 'Role slug of the users to include (default "any").'],
					'item_id'       => ['type' => 'integer', 'description' => 'Only events of this post, product, or material.'],
					'event_ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these event IDs; see get_activity_event_types.'],
					'action'        => ['type' => 'string', 'enum' => [...array_keys(twcp_activity_groups()), 'edited', 'other'], 'description' => 'Only events of this kind.'],
					'include_noise' => ['type' => 'boolean', 'description' => 'Include site views, tracked time, user meta stamps, and system stock changes (default false).'],
					'limit'         => ['type' => 'integer', 'description' => 'Number of events (default 50, max 200).'],
					'offset'        => ['type' => 'integer', 'description' => 'Pagination offset.'],
				]),
				'annotations' => ['readOnlyHint' => true],
			],
			'get_login_activity'   => [
				'description' => 'Report logins per user in a date range: successful and failed logins, the last login, the IP addresses and devices used, and the sessions signed in now. Also counts failed logins with unknown usernames.',
				'callable'    => 'twcp_activity_logins',
				'capability'  => 'manage_options',
				'private'     => true,
				'parameters'  => $range,
				'annotations' => ['readOnlyHint' => true],
			],
			'get_activity_event_types' => [
				'description' => 'List the event types WP Activity Log records, with their category, whether each is enabled, the action these tools count it as, and how often it was logged in a date range. Without search, category, or all, only the event types logged in the range are listed.',
				'callable'    => 'twcp_activity_types',
				'capability'  => 'manage_options',
				'parameters'  => [
					'search'    => ['type' => 'string', 'description' => 'Text in the event description, category, or ID.'],
					'category'  => ['type' => 'string', 'description' => 'Category or subcategory, e.g. "WooCommerce", "Products", "Users Logins".'],
					'all'       => ['type' => 'boolean', 'description' => 'Include event types never logged in the range.'],
					'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD for the counts (default 30 days ago).'],
					'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD for the counts (default today).'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
		],
	];

	return $groups;
});
