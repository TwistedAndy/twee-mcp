<?php
/**
 * Twee MCP — WP Activity Log
 *
 * Implements MCP tools that summarize staff activity recorded by WP Activity Log: time spent, the products,
 * materials and other items people opened, edited or published, and the brands or categories they belong to.
 * The log describes how staff work, so the tools are private and limited to administrators.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Whether WP Activity Log is running
 *
 * @return bool
 */
function twcp_activity_active(): bool
{
	return (bool) apply_filters('twcp_activity_active', class_exists('\WSAL\Entities\Occurrences_Entity'));
}


/**
 * Connection and tables of the logged events. WP Activity Log can keep them in an external database.
 *
 * @return array [ wpdb, events table, metadata table ]
 */
function twcp_activity_table(): array
{
	global $wpdb;

	if (class_exists('\WSAL\Entities\Occurrences_Entity')) {
		$db = \WSAL\Entities\Occurrences_Entity::get_connection();

		return [$db, \WSAL\Entities\Occurrences_Entity::get_table_name($db), \WSAL\Entities\Metadata_Entity::get_table_name($db)];
	}

	return [$wpdb, $wpdb->base_prefix . 'wsal_occurrences', $wpdb->base_prefix . 'wsal_metadata'];
}


/**
 * Event IDs grouped by what they mean for activity reports. Other events of an item count as "edited".
 *
 * @return array Action => event IDs
 */
function twcp_activity_groups(): array
{
	return [
		'opened'        => [9072, 2100, 2102, 2104],
		'viewed'        => [9073, 2101, 2103, 2105],
		'created'       => [9000, 9138, 2000, 2004, 2029],
		'published'     => [9001, 2001, 2005, 2030],
		'trashed'       => [9012, 2012, 2013, 2034],
		'deleted'       => [9013, 2008, 2009, 2033],
		'restored'      => [9014, 2014, 2015, 2035],
		// WooCommerce and custom prices, and the brands, collections and other terms of items
		'price_changed' => [9016, TWCP_PRICES_EVENT],
		'terms_changed' => [TWCP_TERMS_EVENT],
		'term_created'  => [9002, 9057, 2023, 2121],
		'term_edited'   => [9053, 9054, 9055, 9056, 9059, 9060, 9061, 9062, 2052, 2124, 2125, 2127, 2128],
		'term_deleted'  => [9052, 9058, 2024, 2122],
		'uploaded'      => [2010],
		'file_deleted'  => [2011],
		'login'         => [1000, 1005],
		'logout'        => [1001, 1006, 1007, 1009],
		'failed_login'  => [1002, 1003, 1004],
		// Editing time measured in wp-admin, with the seconds in the details
		'time'          => [TWCP_TIME_EVENT],
		// User meta such as WooCommerce's daily wc_last_active stamp, and stock changes made by the system
		'ignored'       => [4015, 4016, 9105, 9106],
	];
}


/**
 * What a logged event means for activity reports
 *
 * @param int $code Event ID
 * @param int $post Post ID of the event
 *
 * @return string A key of twcp_activity_groups(), edited, or other
 */
function twcp_activity_action(int $code, int $post): string
{
	static $actions;

	if (!isset($actions)) {
		$actions = [];
		foreach (twcp_activity_groups() as $action => $codes) {
			$actions += array_fill_keys($codes, $action);
		}
	}

	return $actions[$code] ?? ($post > 0 ? 'edited' : 'other');
}


/**
 * Whether an action is work that counts towards active time
 *
 * @param string $action
 *
 * @return bool
 */
function twcp_activity_is_work(string $action): bool
{
	return !in_array($action, ['viewed', 'time', 'ignored', 'login', 'logout', 'failed_login'], true);
}


/**
 * Find a user by ID, login, email, or display name
 *
 * @param string $value
 *
 * @return WP_User|null
 */
function twcp_activity_user(string $value): ?WP_User
{
	$value = trim($value);
	$field = ctype_digit($value) ? 'id' : (is_email($value) ? 'email' : 'login');
	$user = get_user_by($field, $value);

	if (!$user and $field === 'login') {
		$users = get_users(['search' => $value, 'search_columns' => ['display_name'], 'number' => 2]);
		$user = count($users) === 1 ? $users[0] : null;
	}

	return $user ? : null;
}


/**
 * SQL conditions for the date range, user, and role of a request
 *
 * @param array  $args Tool arguments with date_from, date_to, role and user
 * @param string $role Role when the arguments name none; "any" includes every user
 * @param string $from Start date when the arguments name none
 *
 * @return array [ date_from, date_to, where, values ] or [ error ]
 */
function twcp_activity_scope(array $args, string $role = 'shop_manager', string $from = ''): array
{
	$to = isset($args['date_to']) ? twcp_tools_date($args['date_to']) : wp_date('Y-m-d');
	$from = isset($args['date_from']) ? twcp_tools_date($args['date_from']) : ($from ? : wp_date('Y-m-d', strtotime('-30 days')));
	if (!$from or !$to or $from > $to) {
		return ['error' => 'Provide a valid YYYY-MM-DD date range.'];
	}

	[$db] = twcp_activity_table();
	$zone = wp_timezone();
	$where = ['created_on >= %f', 'created_on < %f'];
	$values = [(new DateTimeImmutable($from, $zone))->getTimestamp(), (new DateTimeImmutable($to . ' +1 day', $zone))->getTimestamp()];

	if (!empty($args['user'])) {
		$user = twcp_activity_user((string) $args['user']);
		if (!$user) {
			return ['error' => 'User not found.'];
		}
		$where[] = 'user_id = %d';
		$values[] = $user->ID;
	} elseif (($args['role'] ?? $role) !== 'any') {
		$where[] = 'user_roles LIKE %s';
		$values[] = '%' . $db->esc_like(sanitize_key($args['role'] ?? $role)) . '%';
	}

	return ['date_from' => $from, 'date_to' => $to, 'where' => $where, 'values' => $values];
}


/**
 * Read the events of a date range from the log, ordered by user and time
 *
 * @param array $args Tool arguments with date_from, date_to, role and user
 *
 * @return array [ date_from, date_to, events, truncated ] or [ error ]
 */
function twcp_activity_query(array $args): array
{
	$scope = twcp_activity_scope($args);
	if (isset($scope['error'])) {
		return $scope;
	}

	[$db, $table] = twcp_activity_table();
	$limit = 200000;

	// System events have no user
	$events = $db->get_results($db->prepare(
		"SELECT id, alert_id, created_on, user_id, username, session_id, object, post_type, post_id FROM {$table} WHERE user_id > 0 AND " . implode(' AND ', $scope['where']) . ' ORDER BY user_id, created_on LIMIT %d',
		[...$scope['values'], $limit + 1]
	), ARRAY_A);

	if ($db->last_error) {
		return ['error' => 'The activity log could not be read: ' . $db->last_error];
	}

	$truncated = count($events) > $limit;
	if ($truncated) {
		array_pop($events);
	}

	return ['date_from' => $scope['date_from'], 'date_to' => $scope['date_to'], 'events' => twcp_activity_prepare($events), 'truncated' => $truncated];
}


/**
 * Cast logged events and add the action and item type of each
 *
 * @param array $events Rows of the events table
 *
 * @return array
 */
function twcp_activity_prepare(array $events): array
{
	// Some product events, such as copies and attribute changes, keep their item in the details only,
	// and tracked time keeps its seconds there
	$missing = $timed = [];
	foreach ($events as $event) {
		if (!(int) $event['post_id'] and in_array($event['object'], ['woocommerce-product', 'post', 'page'], true)) {
			$missing[] = (int) $event['id'];
		}
		if ((int) $event['alert_id'] === TWCP_TIME_EVENT) {
			$timed[] = (int) $event['id'];
		}
	}

	$details = twcp_activity_detail($missing, ['ProductID', 'PostID']);
	$seconds = twcp_activity_detail($timed, ['Seconds']);

	foreach ($events as &$event) {
		$event['id'] = (int) $event['id'];
		$event['post_id'] = (int) $event['post_id'] ? : ($details[$event['id']] ?? 0);
		$event['seconds'] = $seconds[$event['id']] ?? 0;
	}
	unset($event);

	// Type each item once, falling back to the logged type of deleted items
	$ids = array_unique(array_filter(array_column($events, 'post_id')));
	_prime_post_caches($ids, false, false);

	foreach ($events as &$event) {
		$event['alert_id'] = (int) $event['alert_id'];
		$event['created_on'] = (float) $event['created_on'];
		$event['user_id'] = (int) $event['user_id'];
		$event['action'] = twcp_activity_action($event['alert_id'], $event['post_id']);

		if ($event['post_id'] > 0) {
			$event['post_type'] = get_post_type($event['post_id']) ? : ($event['post_type'] ? : ($event['object'] === 'woocommerce-product' ? 'product' : 'post'));
		}
	}

	return $events;
}


/**
 * Whole-number details of events, such as the item or the seconds an event keeps in its details
 *
 * @param int[]    $ids   Event IDs
 * @param string[] $names Detail names
 *
 * @return array Event ID => value
 */
function twcp_activity_detail(array $ids, array $names): array
{
	[$db, , $table] = twcp_activity_table();
	$values = [];

	foreach (array_chunk($ids, 1000) as $chunk) {
		$rows = $db->get_results("SELECT occurrence_id, value FROM {$table} WHERE name IN ('" . implode("', '", $names) . "') AND occurrence_id IN (" . implode(',', $chunk) . ')', ARRAY_A);
		foreach ((array) $rows as $row) {
			$values[(int) $row['occurrence_id']] = (int) $row['value'];
		}
	}

	return $values;
}


/**
 * SQL condition for the events of one item, including those that keep the item in their details only
 *
 * @param int $id
 *
 * @return string
 */
function twcp_activity_item_where(int $id): string
{
	[, , $table] = twcp_activity_table();

	return "(post_id = {$id} OR (post_id = 0 AND id IN (SELECT occurrence_id FROM {$table} WHERE name IN ('ProductID', 'PostID') AND value = '{$id}')))";
}


/**
 * Name, login and roles of a logged user
 *
 * @param int    $id
 * @param string $username Logged name, used when the account is gone
 *
 * @return array
 */
function twcp_activity_user_info(int $id, string $username): array
{
	$user = get_userdata($id);

	return [
		'user_id' => $id,
		'name'    => $user ? $user->display_name : $username,
		'login'   => $user ? $user->user_login : $username,
		'roles'   => $user ? array_values($user->roles) : [],
	];
}


/**
 * Action/Tool: Summarize activity per user
 *
 * @param array $args
 *
 * @return array
 */
function twcp_activity_summary(array $args): array
{
	$query = twcp_activity_query($args);
	if (isset($query['error'])) {
		return $query;
	}

	$idle = 60 * (int) ($args['idle_minutes'] ?? 15);

	return [
		'date_from' => $query['date_from'],
		'date_to'   => $query['date_to'],
		'users'     => twcp_activity_rows($query['events'], $idle),
		'truncated' => $query['truncated'],
		'notes'     => sprintf('Users are ordered by published items. published counts items put online, published_new those put online for the first time; the rest were republished. price_updates counts the saves that changed prices of a product, WooCommerce or custom ones. tracked_minutes is the editing time measured in wp-admin, the most accurate time figure: the minutes a tab was in front and used, including reading and typing the log does not see. It stays 0 until Activity Tracking is turned on for the role on the Tools screen, and two devices used at once both count. Active time adds the gaps between consecutive logged actions up to %d minutes and one minute for each isolated action, so work outside WordPress, such as writing copy or preparing images, is not counted; treat it as a lower bound and use it to compare people. span_hours adds the time from the first to the last action of each day, an upper bound that includes breaks. Items count distinct posts per type; saves count each item once per minute. parallel_sessions above 0 means logins of the same account worked at the same time, which suggests a shared account. Views of the site and profile meta updates are left out.', $idle / 60),
	];
}


/**
 * Whether an action changes an item
 *
 * @param string $action
 *
 * @return bool
 */
function twcp_activity_is_change(string $action): bool
{
	return in_array($action, ['edited', 'created', 'published', 'trashed', 'deleted', 'restored', 'price_changed', 'terms_changed'], true);
}


/**
 * Whether a publish event put an item online for the first time. Publishing sets the date of a post that was
 * never published, so the date of a republished item stays older.
 *
 * @param int   $id
 * @param float $time Time of the publish event
 *
 * @return bool
 */
function twcp_activity_is_first_publish(int $id, float $time): bool
{
	$post = get_post($id);

	return $post and $post->post_date_gmt !== '0000-00-00 00:00:00' and abs(strtotime($post->post_date_gmt . ' UTC') - $time) <= DAY_IN_SECONDS;
}


/**
 * Active seconds of actions in time order. Time between actions counts up to the idle gap; an isolated action
 * counts as one minute.
 *
 * @param float[] $times
 * @param int     $idle Seconds
 *
 * @return float
 */
function twcp_activity_seconds(array $times, int $idle): float
{
	$active = 0.0;
	$previous = null;

	foreach ($times as $time) {
		$gap = $previous === null ? INF : $time - $previous;
		$active += $gap <= $idle ? $gap : 60;
		$previous = $time;
	}

	return $active;
}


/**
 * Activity totals per user, those who published most first
 *
 * @param array $events Prepared events ordered by user and time
 * @param int   $idle   Seconds
 *
 * @return array
 */
function twcp_activity_rows(array $events, int $idle): array
{
	$work = [];
	foreach ($events as $event) {
		$work[$event['user_id']][] = $event;
	}

	$users = [];
	foreach ($work as $id => $events) {
		$row = twcp_activity_user_info($id, (string) $events[0]['username']) + [
			'published'         => 0,
			'published_new'     => 0,
			'price_updates'     => 0,
			'active_days'       => 0,
			'tracked_minutes'   => 0,
			'active_minutes'    => 0,
			'active_hours'      => 0,
			'span_hours'        => 0,
			'sessions'          => 0,
			'parallel_sessions' => 0,
			'actions'           => 0,
			'first_activity'    => '',
			'last_activity'     => '',
			'items'             => [],
			'terms'             => ['created' => 0, 'edited' => 0, 'deleted' => 0],
			'media'             => ['uploaded' => 0, 'deleted' => 0],
			'logins'            => 0,
			'failed_logins'     => 0,
		];

		$items = $saves = $days = $sessions = $parallel = $seen = $times = $published = $prices = [];
		$tracked = 0;

		foreach ($events as $event) {
			$action = $event['action'];
			$time = $event['created_on'];
			$session = (string) $event['session_id'];

			if ($session !== '') {
				$sessions[$session] = true;
			}

			if ($action === 'time') {
				$tracked += $event['seconds'];
				continue;
			}
			if ($action === 'login') {
				$row['logins']++;
				continue;
			}
			if ($action === 'failed_login') {
				$row['failed_logins']++;
				continue;
			}
			if (!twcp_activity_is_work($action)) {
				continue;
			}

			$times[] = $time;
			$day = wp_date('Y-m-d', (int) $time);
			$days[$day] ??= [$time, $time];
			$days[$day][1] = $time;
			$row['actions']++;

			// Two logins of one account working at the same time suggest a shared account
			if ($session !== '') {
				foreach ($seen as $other => $last) {
					if ($other !== $session and $time - $last <= $idle) {
						$parallel[$session] = $parallel[$other] = true;
					}
				}
				$seen[$session] = $time;
			}

			if ($event['post_id'] > 0) {
				$type = $event['post_type'];
				if ($action === 'opened' or twcp_activity_is_change($action)) {
					$items[$type][$action][$event['post_id']] = true;
				}
				if (twcp_activity_is_change($action)) {
					$items[$type]['changed'][$event['post_id']] = true;
					// One click on Update logs an event for each changed field
					$saves[$type][$event['post_id'] . '@' . floor($time / 60)] = true;
				}
				if ($action === 'published') {
					$published[$type][$event['post_id']] ??= $time;
				}
				if ($action === 'price_changed') {
					$prices[$event['post_id'] . '@' . floor($time / 60)] = true;
				}
			} elseif (str_starts_with($action, 'term_')) {
				$row['terms'][substr($action, 5)]++;
			} elseif ($action === 'uploaded') {
				$row['media']['uploaded']++;
			} elseif ($action === 'file_deleted') {
				$row['media']['deleted']++;
			}
		}

		if (!$row['actions'] and !$row['logins'] and !$tracked) {
			continue;
		}

		// Edited counts every changed item, including those created, published or trashed
		foreach ($items as $type => $counts) {
			$row['items'][$type] = ['opened' => 0, 'edited' => 0];
			foreach (['opened' => 'opened', 'changed' => 'edited', 'created' => 'created', 'published' => 'published', 'trashed' => 'trashed', 'deleted' => 'deleted', 'restored' => 'restored', 'price_changed' => 'price_changed', 'terms_changed' => 'terms_changed'] as $action => $key) {
				if (!empty($counts[$action])) {
					$row['items'][$type][$key] = count($counts[$action]);
				}
			}
			if (isset($published[$type])) {
				$new = 0;
				foreach ($published[$type] as $id => $time) {
					if (twcp_activity_is_first_publish($id, $time)) {
						$new++;
					}
				}
				$row['items'][$type]['published_new'] = $new;
				$row['published'] += count($published[$type]);
				$row['published_new'] += $new;
			}
			$row['items'][$type]['saves'] = count($saves[$type] ?? []);
		}

		$active = twcp_activity_seconds($times, $idle);
		$row['price_updates'] = count($prices);
		$row['active_days'] = count($days);
		$row['tracked_minutes'] = (int) round($tracked / 60);
		$row['active_minutes'] = (int) round($active / 60);
		$row['active_hours'] = round($active / 3600, 1);
		$span = 0;
		foreach ($days as [$start, $end]) {
			$span += $end - $start;
		}
		$row['span_hours'] = round($span / 3600, 1);
		$row['sessions'] = count($sessions);
		$row['parallel_sessions'] = count($parallel);
		$row['first_activity'] = wp_date('Y-m-d H:i', (int) $events[0]['created_on']);
		$row['last_activity'] = wp_date('Y-m-d H:i', (int) end($events)['created_on']);

		$users[] = $row;
	}

	usort($users, function(array $a, array $b): int {
		return [$b['published'], $b['published_new'], $b['active_minutes']] <=> [$a['published'], $a['published_new'], $a['active_minutes']];
	});

	return $users;
}


/**
 * Current terms of logged items in a taxonomy. Items of a type without the taxonomy are matched by the name
 * of the top-level term in their own hierarchical taxonomies, such as a material collection named after a brand.
 *
 * @param int[]  $ids
 * @param string $taxonomy
 *
 * @return array Post ID => [ term ID => name ]
 */
function twcp_activity_item_terms(array $ids, string $taxonomy): array
{
	$types = [];
	foreach ($ids as $id) {
		$type = get_post_type($id);
		if ($type) {
			$types[$type][] = $id;
		}
	}

	$result = [];
	$names = null;
	$tops = [];

	foreach ($types as $type => $type_ids) {
		if (is_object_in_taxonomy($type, $taxonomy)) {
			foreach (wp_get_object_terms($type_ids, $taxonomy, ['fields' => 'all_with_object_id']) as $term) {
				$result[$term->object_id][$term->term_id] = html_entity_decode($term->name);
			}
			continue;
		}

		if ($names === null) {
			$names = [];
			foreach (get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]) as $term) {
				$names[mb_strtolower(html_entity_decode($term->name))] = $term;
			}
		}

		foreach (get_object_taxonomies($type, 'objects') as $object_taxonomy) {
			if (!$object_taxonomy->hierarchical or $object_taxonomy->name === $taxonomy) {
				continue;
			}

			foreach (wp_get_object_terms($type_ids, $object_taxonomy->name, ['fields' => 'all_with_object_id']) as $term) {
				if (!isset($tops[$term->term_id])) {
					$ancestors = get_ancestors($term->term_id, $object_taxonomy->name, 'taxonomy');
					$top = $ancestors ? get_term(end($ancestors), $object_taxonomy->name) : $term;
					$tops[$term->term_id] = $top instanceof WP_Term ? ($names[mb_strtolower(html_entity_decode($top->name))] ?? false) : false;
				}

				$match = $tops[$term->term_id];
				if ($match) {
					$result[$term->object_id][$match->term_id] = html_entity_decode($match->name);
				}
			}
		}
	}

	return $result;
}


/**
 * Action/Tool: Group the items staff worked on by taxonomy terms
 *
 * @param array $args
 *
 * @return array
 */
function twcp_activity_brands(array $args): array
{
	$taxonomy = sanitize_key($args['taxonomy'] ?? 'product_brand');
	if (!taxonomy_exists($taxonomy)) {
		return ['error' => 'Unknown taxonomy: ' . $taxonomy];
	}

	$query = twcp_activity_query($args);
	if (isset($query['error'])) {
		return $query;
	}

	// Item => type and, per user, what they did with it
	$items = [];
	$names = [];
	foreach ($query['events'] as $event) {
		$id = $event['post_id'];
		if ($id <= 0 or !($event['action'] === 'time' or twcp_activity_is_work($event['action']))) {
			continue;
		}

		$user = $event['user_id'];
		$names[$user] ??= (string) $event['username'];
		$items[$id]['type'] = $event['post_type'];
		$items[$id]['users'][$user] ??= ['saves' => [], 'seconds' => 0, 'created' => false, 'published' => false, 'price_changed' => false, 'changed' => false];
		$entry = &$items[$id]['users'][$user];

		if ($event['action'] === 'time') {
			$entry['seconds'] += $event['seconds'];
		} elseif ($event['action'] !== 'opened') {
			$entry['changed'] = true;
			$entry['saves'][floor($event['created_on'] / 60)] = true;
		}
		if (in_array($event['action'], ['created', 'published', 'price_changed'], true)) {
			$entry[$event['action']] = true;
		}
		unset($entry);
	}

	$terms = twcp_activity_item_terms(array_keys($items), $taxonomy);
	$groups = [];

	foreach ($items as $id => $item) {
		$assigned = $terms[$id] ?? (get_post_type($id) ? [0 => '(none)'] : [-1 => '(deleted items)']);

		foreach ($assigned as $term_id => $name) {
			$group = &$groups[$term_id];
			$group ??= ['term_id' => max(0, $term_id), 'name' => $name, 'items' => 0, 'types' => [], 'edited' => 0, 'created' => 0, 'published' => 0, 'price_changed' => 0, 'saves' => 0, 'tracked_minutes' => 0, 'users' => []];
			$group['items']++;
			$group['types'][$item['type']] = ($group['types'][$item['type']] ?? 0) + 1;

			foreach (['changed' => 'edited', 'created' => 'created', 'published' => 'published', 'price_changed' => 'price_changed'] as $flag => $key) {
				$group[$key] += (int) in_array(true, array_column($item['users'], $flag), true);
			}

			foreach ($item['users'] as $user => $entry) {
				$saves = count($entry['saves']);
				$time = $entry['seconds'];
				$group['saves'] += $saves;
				$group['tracked_minutes'] += $time;
				$group['users'][$user] ??= ['user_id' => $user, 'name' => '', 'items' => 0, 'edited' => 0, 'published' => 0, 'price_changed' => 0, 'saves' => 0, 'tracked_minutes' => 0];
				$group['users'][$user]['items']++;
				$group['users'][$user]['edited'] += (int) $entry['changed'];
				$group['users'][$user]['published'] += (int) $entry['published'];
				$group['users'][$user]['price_changed'] += (int) $entry['price_changed'];
				$group['users'][$user]['saves'] += $saves;
				$group['users'][$user]['tracked_minutes'] += $time;
			}
			unset($group);
		}
	}

	// Tracked seconds are added up before rounding
	foreach ($groups as &$group) {
		foreach ($group['users'] as $user => &$row) {
			$row['name'] = twcp_activity_user_info($user, $names[$user])['name'];
			$row['tracked_minutes'] = (int) round($row['tracked_minutes'] / 60);
		}
		unset($row);
		$group['tracked_minutes'] = (int) round($group['tracked_minutes'] / 60);
		usort($group['users'], 'twcp_activity_compare_work');
		$group['users'] = array_slice($group['users'], 0, 10);
	}
	unset($group);

	// Groups people changed rank above groups they only opened
	usort($groups, 'twcp_activity_compare_work');
	$limit = max(1, min(200, (int) ($args['limit'] ?? 50)));

	return [
		'date_from'    => $query['date_from'],
		'date_to'      => $query['date_to'],
		'taxonomy'     => $taxonomy,
		'items'        => count($items),
		'total_groups' => count($groups),
		'groups'       => array_slice($groups, 0, $limit),
		'truncated'    => $query['truncated'],
		'notes'        => 'Items count distinct posts the users opened or changed; edited, created, published and price_changed count items with at least one such action. tracked_minutes is the editing time measured in wp-admin on those items, 0 while it is not tracked. Items are grouped by their current terms, so an item that moved to another group counts there. An item in several terms counts in each.',
	];
}


/**
 * Order users and groups by the items they changed, then by saves and items
 *
 * @param array $a
 * @param array $b
 *
 * @return int
 */
function twcp_activity_compare_work(array $a, array $b): int
{
	return [$b['edited'], $b['saves'], $b['items']] <=> [$a['edited'], $a['saves'], $a['items']];
}


/**
 * Event types WP Activity Log records
 *
 * @return array Event ID => [ description, message, metadata, category, subcategory, object, event_type, severity ]
 */
function twcp_activity_catalogue(): array
{
	$alerts = class_exists('\WSAL\Controllers\Alert_Manager') ? \WSAL\Controllers\Alert_Manager::get_alerts() : [];
	$catalogue = [];

	foreach ($alerts as $code => $alert) {
		$alert = (array) $alert;
		$catalogue[(int) $code] = [
			'description' => html_entity_decode((string) ($alert['desc'] ?? '')),
			'message'     => (string) ($alert['message'] ?? ''),
			'metadata'    => (array) ($alert['metadata'] ?? []),
			'category'    => html_entity_decode((string) ($alert['category'] ?? '')),
			'subcategory' => html_entity_decode((string) ($alert['subcategory'] ?? '')),
			'object'      => (string) ($alert['object'] ?? ''),
			'event_type'  => (string) ($alert['event_type'] ?? ''),
			'severity'    => strtolower(str_replace('WSAL_', '', (string) ($alert['severity'] ?? ''))),
		];
	}

	return (array) apply_filters('twcp_activity_catalogue', $catalogue);
}


/**
 * Logged details of events
 *
 * @param int[] $ids Event IDs
 *
 * @return array Event ID => [ name => value ]
 */
function twcp_activity_meta(array $ids): array
{
	$ids = array_filter(array_map('intval', $ids));
	if (!$ids) {
		return [];
	}

	[$db, , $table] = twcp_activity_table();
	$meta = [];

	foreach ((array) $db->get_results("SELECT occurrence_id, name, value FROM {$table} WHERE occurrence_id IN (" . implode(',', $ids) . ')', ARRAY_A) as $row) {
		$value = $row['value'];
		// Some details, such as the data of a plugin, are plain objects
		if (is_serialized($value)) {
			$value = @unserialize($value, ['allowed_classes' => ['stdClass']]);
		}
		$meta[(int) $row['occurrence_id']][$row['name']] = $value;
	}

	return $meta;
}


/**
 * Plain, shortened text of a logged value
 *
 * @param mixed $value
 * @param int   $length
 *
 * @return string
 */
function twcp_activity_text($value, int $length = 300): string
{
	if ($value === null or $value === false) {
		return '';
	}
	if (!is_scalar($value)) {
		$value = wp_json_encode($value);
	}

	$text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES)));

	return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}


/**
 * Format a logged event with its message, item, details, and changed values
 *
 * @param array $event     Prepared event
 * @param array $meta      Logged details of the event
 * @param array $catalogue Event types
 *
 * @return array
 */
function twcp_activity_format(array $event, array $meta, array $catalogue): array
{
	$alert = $catalogue[$event['alert_id']] ?? [];
	// Placeholders name a detail, or a property of one as in %PluginData->Name%
	$value = function(array $match) use ($meta): string {
		$value = $meta[$match[1]] ?? '';
		if (!empty($match[2])) {
			$value = ((array) $value)[$match[2]] ?? '';
		}

		return twcp_activity_text($value, 150);
	};
	$fill = function(string $template) use ($value): string {
		return twcp_activity_text(preg_replace_callback('/%(\w+)(?:->(\w+))?%/', $value, $template), 500);
	};

	$details = [];
	foreach ($alert['metadata'] ?? [] as $label => $template) {
		$value = $fill((string) $template);
		if ($value !== '') {
			$details[wp_strip_all_tags((string) $label)] = $value;
		}
	}

	// Changes are logged as OldX with NewX, X, or, for products, ProductX
	$changes = [];
	foreach ($meta as $name => $value) {
		if (preg_match('/^(?:Old|Previous)(\w+)$/', $name, $match)) {
			$new = null;
			foreach (['New', 'Current', '', 'Product'] as $prefix) {
				if (isset($meta[$prefix . $match[1]]) and $prefix . $match[1] !== $name) {
					$new = $meta[$prefix . $match[1]];
					break;
				}
			}
			$changes[$match[1]] = ['old' => twcp_activity_text($value), 'new' => twcp_activity_text($new)];
		}
	}

	$item = null;
	if ($event['post_id'] > 0) {
		$title = get_post($event['post_id']) ? html_entity_decode(get_the_title($event['post_id'])) : '';
		$item = ['id' => $event['post_id'], 'type' => $event['post_type'], 'title' => $title ? : twcp_activity_text($meta['ProductTitle'] ?? $meta['PostTitle'] ?? '', 150)];
	}

	$result = [
		'id'          => $event['id'],
		'time'        => wp_date('Y-m-d H:i:s', (int) $event['created_on']),
		'user'        => twcp_activity_name($event),
		'code'        => $event['alert_id'],
		'action'      => $event['action'],
		'description' => $alert['description'] ?? '',
		'message'     => isset($alert['message']) ? $fill($alert['message']) : '',
		'item'        => $item,
		'details'     => $details,
		'changes'     => $changes,
	];

	// Empty fields are left out
	foreach ($result as $key => $field) {
		if ($field === '' or $field === [] or $field === null) {
			unset($result[$key]);
		}
	}

	return $result;
}


/**
 * Format prepared events with their details
 *
 * @param array $events
 *
 * @return array
 */
function twcp_activity_format_all(array $events): array
{
	$meta = twcp_activity_meta(array_column($events, 'id'));
	$catalogue = twcp_activity_catalogue();
	$formatted = [];

	foreach ($events as $event) {
		$formatted[] = twcp_activity_format($event, $meta[$event['id']] ?? [], $catalogue);
	}

	return $formatted;
}


/**
 * Display name of the user behind an event. Events without a user come from the system.
 *
 * @param array $event Prepared event
 *
 * @return string
 */
function twcp_activity_name(array $event): string
{
	static $names = [];

	$id = $event['user_id'];
	if ($id <= 0) {
		return (string) $event['username'] ? : 'System';
	}

	return $names[$id] ??= twcp_activity_user_info($id, (string) $event['username'])['name'];
}


/**
 * Action/Tool: Show how one user worked
 *
 * @param array $args
 *
 * @return array
 */
function twcp_activity_user_report(array $args): array
{
	$query = twcp_activity_query($args);
	if (isset($query['error'])) {
		return $query;
	}

	$idle = 60 * (int) ($args['idle_minutes'] ?? 15);
	$user = twcp_activity_user((string) $args['user']);
	$rows = twcp_activity_rows($query['events'], $idle);
	$result = [
		'date_from' => $query['date_from'],
		'date_to'   => $query['date_to'],
		'summary'   => $rows[0] ?? twcp_activity_user_info($user->ID, $user->user_login),
	];

	if (!$rows) {
		return $result + ['notes' => 'No activity was logged for this user in the date range.'];
	}

	$days = $sessions = $items = $days_tracked = $items_tracked = [];
	foreach ($query['events'] as $event) {
		if ($event['action'] === 'time') {
			$date = wp_date('Y-m-d', (int) $event['created_on']);
			$days_tracked[$date] = ($days_tracked[$date] ?? 0) + $event['seconds'];
			$items_tracked[$event['post_id']] = ($items_tracked[$event['post_id']] ?? 0) + $event['seconds'];
		}
		if (!twcp_activity_is_work($event['action'])) {
			continue;
		}

		$time = $event['created_on'];
		$days[wp_date('Y-m-d', (int) $time)][] = $event;
		$sessions[(string) $event['session_id']][] = $event;

		$id = $event['post_id'];
		if ($id > 0) {
			$items[$id] ??= ['id' => $id, 'type' => $event['post_type'], 'title' => '', 'terms' => [], 'actions' => [], 'saves' => [], 'first' => $time, 'last' => $time];
			$items[$id]['actions'][$event['action']] = ($items[$id]['actions'][$event['action']] ?? 0) + 1;
			$items[$id]['last'] = $time;
			if (twcp_activity_is_change($event['action'])) {
				$items[$id]['saves'][floor($time / 60)] = true;
			}
		}
	}

	// Days and sessions report the same totals of their actions
	$totals = function(array $events) use ($idle): array {
		$saves = [];
		foreach ($events as $event) {
			if ($event['post_id'] > 0 and twcp_activity_is_change($event['action'])) {
				$saves[$event['post_id'] . '@' . floor($event['created_on'] / 60)] = true;
			}
		}

		return [
			'start'          => wp_date('H:i', (int) $events[0]['created_on']),
			'end'            => wp_date('H:i', (int) end($events)['created_on']),
			'active_minutes' => (int) round(twcp_activity_seconds(array_column($events, 'created_on'), $idle) / 60),
			'actions'        => count($events),
			'items'          => count(array_unique(array_filter(array_column($events, 'post_id')))),
			'saves'          => count($saves),
		];
	};

	$result['days'] = [];
	foreach ($days as $date => $events) {
		$result['days'][] = ['date' => $date] + $totals($events) + ['tracked_minutes' => (int) round(($days_tracked[$date] ?? 0) / 60)];
	}

	$result['sessions'] = [];
	foreach ($sessions as $session => $events) {
		$result['sessions'][] = ['session' => $session === '' ? 'unknown' : substr($session, 0, 8), 'date' => wp_date('Y-m-d', (int) $events[0]['created_on'])] + $totals($events);
	}
	usort($result['sessions'], function(array $a, array $b): int {
		return [$a['date'], $a['start']] <=> [$b['date'], $b['start']];
	});
	$result['sessions'] = array_slice($result['sessions'], -100);

	// Items with the most saves first
	$taxonomy = sanitize_key($args['taxonomy'] ?? 'product_brand');
	$terms = taxonomy_exists($taxonomy) ? twcp_activity_item_terms(array_keys($items), $taxonomy) : [];
	uasort($items, function(array $a, array $b): int {
		return [count($b['saves']), $b['last']] <=> [count($a['saves']), $a['last']];
	});
	$items = array_slice($items, 0, max(1, min(200, (int) ($args['limit'] ?? 50))), true);

	foreach ($items as $id => &$item) {
		$item['title'] = get_post($id) ? html_entity_decode(get_the_title($id)) : '(deleted)';
		$item['terms'] = array_values($terms[$id] ?? []);
		$item['saves'] = count($item['saves']);
		$item['tracked_minutes'] = (int) round(($items_tracked[$id] ?? 0) / 60);
		$item['first'] = wp_date('Y-m-d H:i', (int) $item['first']);
		$item['last'] = wp_date('Y-m-d H:i', (int) $item['last']);
	}
	unset($item);

	$result['taxonomy'] = $taxonomy;
	$result['items'] = array_values($items);
	$result['truncated'] = $query['truncated'];

	return $result;
}


/**
 * Action/Tool: Show the logged history of one item
 *
 * @param array $args
 *
 * @return array
 */
function twcp_activity_history(array $args): array
{
	$id = absint($args['id']);
	$scope = twcp_activity_scope(array_intersect_key($args, array_flip(['date_from', 'date_to'])), 'any', '2000-01-01');
	if (isset($scope['error'])) {
		return $scope;
	}

	[$db, $table] = twcp_activity_table();
	$groups = twcp_activity_groups();
	$noise = implode(',', [...$groups['viewed'], ...$groups['ignored']]);

	$events = $db->get_results($db->prepare(
		"SELECT id, alert_id, created_on, user_id, username, session_id, object, post_type, post_id FROM {$table} WHERE " . twcp_activity_item_where($id) . " AND alert_id NOT IN ({$noise}) AND " . implode(' AND ', $scope['where']) . ' ORDER BY created_on DESC, id DESC LIMIT 5000',
		$scope['values']
	), ARRAY_A);

	if ($db->last_error) {
		return ['error' => 'The activity log could not be read: ' . $db->last_error];
	}

	$events = twcp_activity_prepare($events);
	$post = get_post($id);
	if (!$post and !$events) {
		return ['error' => 'No item or logged history was found for this ID.'];
	}

	$item = ['id' => $id, 'type' => $post ? $post->post_type : $events[0]['post_type'], 'title' => '', 'status' => $post ? $post->post_status : 'deleted', 'terms' => []];
	if ($post) {
		$item['title'] = html_entity_decode(get_the_title($post));
		foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
			$names = $taxonomy->show_ui ? wp_get_object_terms($id, $taxonomy->name, ['fields' => 'names', 'number' => 10]) : [];
			if ($names and !is_wp_error($names)) {
				$item['terms'][$taxonomy->name] = array_values(array_unique(array_map('html_entity_decode', $names)));
			}
		}
	}

	// Everyone who worked on the item; events are newest first
	$people = [];
	foreach ($events as $event) {
		$user = $event['user_id'];
		$time = wp_date('Y-m-d H:i', (int) $event['created_on']);
		$people[$user] ??= ['user_id' => $user, 'name' => twcp_activity_name($event), 'events' => 0, 'saves' => [], 'actions' => [], 'tracked_minutes' => 0, 'first' => $time, 'last' => $time];
		$people[$user]['tracked_minutes'] += $event['seconds'];
		$people[$user]['events']++;
		$people[$user]['actions'][$event['action']] = ($people[$user]['actions'][$event['action']] ?? 0) + 1;
		$people[$user]['first'] = $time;
		if (twcp_activity_is_change($event['action'])) {
			$people[$user]['saves'][floor($event['created_on'] / 60)] = true;
		}
	}
	// Tracked seconds are added up before rounding
	foreach ($people as &$person) {
		$person['saves'] = count($person['saves']);
		$person['tracked_minutes'] = (int) round($person['tracked_minutes'] / 60);
	}
	unset($person);
	usort($people, function(array $a, array $b): int {
		return $b['events'] <=> $a['events'];
	});

	$page = array_slice($events, max(0, (int) ($args['offset'] ?? 0)), max(1, min(200, (int) ($args['limit'] ?? 100))));

	return [
		'item'         => $item,
		'total'        => count($events),
		'contributors' => $people,
		'events'       => twcp_activity_format_all($page),
	];
}


/**
 * Action/Tool: List logged events
 *
 * @param array $args
 *
 * @return array
 */
function twcp_activity_events(array $args): array
{
	$scope = twcp_activity_scope($args, 'any');
	if (isset($scope['error'])) {
		return $scope;
	}

	[$db, $table] = twcp_activity_table();
	$groups = twcp_activity_groups();
	$where = $scope['where'];
	$values = $scope['values'];

	if (!empty($args['item_id'])) {
		$where[] = twcp_activity_item_where(absint($args['item_id']));
	}

	$codes = array_map('intval', (array) ($args['event_ids'] ?? []));
	$action = $args['action'] ?? '';

	if (isset($groups[$action])) {
		$codes = $codes ? (array_values(array_intersect($codes, $groups[$action])) ? : [0]) : $groups[$action];
	} elseif ($action) {
		// Edited and other events are the ones no group lists
		$where[] = 'alert_id NOT IN (' . implode(',', array_merge(...array_values($groups))) . ')';
		$where[] = $action === 'edited' ? 'post_id > 0' : 'post_id = 0';
	}

	if ($codes) {
		$where[] = 'alert_id IN (' . implode(',', $codes) . ')';
	} elseif (empty($args['include_noise'])) {
		$where[] = 'alert_id NOT IN (' . implode(',', [...$groups['viewed'], ...$groups['time'], ...$groups['ignored']]) . ')';
	}

	$sql = implode(' AND ', $where);
	$limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
	$offset = max(0, (int) ($args['offset'] ?? 0));

	$total = (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$sql}", $values));
	$events = $db->get_results($db->prepare(
		"SELECT id, alert_id, created_on, user_id, username, session_id, object, post_type, post_id FROM {$table} WHERE {$sql} ORDER BY created_on DESC, id DESC LIMIT %d OFFSET %d",
		[...$values, $limit, $offset]
	), ARRAY_A);

	if ($db->last_error) {
		return ['error' => 'The activity log could not be read: ' . $db->last_error];
	}

	return [
		'date_from' => $scope['date_from'],
		'date_to'   => $scope['date_to'],
		'total'     => $total,
		'events'    => twcp_activity_format_all(twcp_activity_prepare($events)),
	];
}


/**
 * Short browser and system name of a user agent
 *
 * @param string $agent
 *
 * @return string
 */
function twcp_activity_device(string $agent): string
{
	$browser = $system = '';

	foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $name) {
		if (str_contains($agent, $needle)) {
			$browser = $name;
			break;
		}
	}
	foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $needle => $name) {
		if (str_contains($agent, $needle)) {
			$system = $name;
			break;
		}
	}

	return ($browser and $system) ? $browser . ' on ' . $system : (twcp_activity_text($agent, 60) ? : 'Unknown');
}


/**
 * Action/Tool: Report logins per user
 *
 * @param array $args
 *
 * @return array
 */
function twcp_activity_logins(array $args): array
{
	$scope = twcp_activity_scope($args);
	if (isset($scope['error'])) {
		return $scope;
	}

	global $wpdb;
	[$db, $table] = twcp_activity_table();
	$groups = twcp_activity_groups();
	$codes = implode(',', [...$groups['login'], ...$groups['logout'], ...$groups['failed_login']]);

	$events = $db->get_results($db->prepare(
		"SELECT alert_id, created_on, user_id, username, client_ip, user_agent FROM {$table} WHERE user_id > 0 AND alert_id IN ({$codes}) AND " . implode(' AND ', $scope['where']) . ' ORDER BY created_on',
		$scope['values']
	), ARRAY_A);

	if ($db->last_error) {
		return ['error' => 'The activity log could not be read: ' . $db->last_error];
	}

	$users = [];
	foreach ($events as $event) {
		$id = (int) $event['user_id'];
		$action = twcp_activity_action((int) $event['alert_id'], 0);
		$time = wp_date('Y-m-d H:i', (int) $event['created_on']);

		$users[$id] ??= twcp_activity_user_info($id, (string) $event['username']) + ['logins' => 0, 'logouts' => 0, 'failed_logins' => 0, 'last_login' => null, 'last_failed_login' => null, 'ips' => [], 'devices' => []];
		$row = &$users[$id];

		if ($action === 'login') {
			$device = twcp_activity_device((string) $event['user_agent']);
			$row['logins']++;
			$row['last_login'] = $time;
			$row['ips'][$event['client_ip']] = ($row['ips'][$event['client_ip']] ?? 0) + 1;
			$row['devices'][$device] = ($row['devices'][$device] ?? 0) + 1;
		} elseif ($action === 'logout') {
			$row['logouts']++;
		} else {
			$row['failed_logins']++;
			$row['last_failed_login'] = $time;
		}
		unset($row);
	}

	// Sessions signed in now, which the Users Sessions feature keeps in the site database
	$now = [];
	$sessions = $wpdb->base_prefix . 'wsal_sessions';
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sessions)) === $sessions) {
		$now = array_map('intval', array_column((array) $wpdb->get_results($wpdb->prepare("SELECT user_id, COUNT(*) sessions FROM {$sessions} WHERE expiry_time > %d GROUP BY user_id", time()), ARRAY_A), 'sessions', 'user_id'));
	}

	foreach ($users as $id => &$row) {
		arsort($row['ips']);
		arsort($row['devices']);
		$row['ips'] = array_slice($row['ips'], 0, 5, true);
		$row['devices'] = array_slice($row['devices'], 0, 3, true);
		$row['signed_in_now'] = $now[$id] ?? 0;
	}
	unset($row);

	usort($users, function(array $a, array $b): int {
		return [$b['logins'], $b['failed_logins']] <=> [$a['logins'], $a['failed_logins']];
	});
	$result = ['date_from' => $scope['date_from'], 'date_to' => $scope['date_to'], 'users' => $users];

	// Attempts with unknown usernames belong to no user
	if (empty($args['user'])) {
		$failed = (array) $db->get_results($db->prepare(
			"SELECT client_ip ip, COUNT(*) attempts FROM {$table} WHERE (user_id = 0 OR user_id IS NULL) AND alert_id IN (" . implode(',', $groups['failed_login']) . ') AND created_on >= %f AND created_on < %f GROUP BY client_ip ORDER BY attempts DESC',
			array_slice($scope['values'], 0, 2)
		), ARRAY_A);
		$ips = [];
		foreach (array_slice($failed, 0, 5) as $row) {
			$ips[] = ['ip' => $row['ip'], 'attempts' => (int) $row['attempts']];
		}

		$result['unknown_users'] = [
			'failed_logins' => (int) array_sum(array_column($failed, 'attempts')),
			'top_ips'       => $ips,
		];
	}

	return $result;
}


/**
 * Action/Tool: List the event types WP Activity Log records
 *
 * @param array $args
 *
 * @return array
 */
function twcp_activity_types(array $args): array
{
	$scope = twcp_activity_scope(array_intersect_key($args, array_flip(['date_from', 'date_to'])), 'any');
	if (isset($scope['error'])) {
		return $scope;
	}

	[$db, $table] = twcp_activity_table();
	$counts = (array) $db->get_results($db->prepare("SELECT alert_id, COUNT(*) logged FROM {$table} WHERE " . implode(' AND ', $scope['where']) . ' GROUP BY alert_id', $scope['values']), ARRAY_A);
	$counts = array_map('intval', array_column($counts, 'logged', 'alert_id'));

	$catalogue = twcp_activity_catalogue();
	$disabled = class_exists('\WSAL\Helpers\Settings_Helper') ? array_map('intval', (array) \WSAL\Helpers\Settings_Helper::get_disabled_alerts()) : [];
	$search = mb_strtolower(trim((string) ($args['search'] ?? '')));
	$category = mb_strtolower(trim((string) ($args['category'] ?? '')));
	$all = !empty($args['all']) || $search !== '' || $category !== '';

	$types = [];
	foreach (array_unique([...array_keys($catalogue), ...array_keys($counts)]) as $code) {
		$alert = $catalogue[$code] ?? ['description' => '', 'category' => '', 'subcategory' => '', 'object' => '', 'event_type' => '', 'severity' => ''];
		$logged = $counts[$code] ?? 0;

		if (!$all and !$logged) {
			continue;
		}
		if ($search !== '' and !str_contains(mb_strtolower($code . ' ' . $alert['description'] . ' ' . $alert['category'] . ' ' . $alert['subcategory']), $search)) {
			continue;
		}
		if ($category !== '' and !str_contains(mb_strtolower($alert['category'] . ' / ' . $alert['subcategory']), $category)) {
			continue;
		}

		$types[] = [
			'code'        => $code,
			'description' => $alert['description'],
			'category'    => $alert['category'],
			'subcategory' => $alert['subcategory'],
			'object'      => $alert['object'],
			'event_type'  => $alert['event_type'],
			'severity'    => $alert['severity'],
			'enabled'     => !in_array($code, $disabled, true),
			'counted_as'  => twcp_activity_action($code, (int) in_array($alert['object'], ['post', 'page', 'woocommerce-product'], true)),
			'logged'      => $logged,
		];
	}

	usort($types, function(array $a, array $b): int {
		return [$b['logged'], $a['code']] <=> [$a['logged'], $b['code']];
	});

	return ['date_from' => $scope['date_from'], 'date_to' => $scope['date_to'], 'total' => count($types), 'event_types' => $types];
}
