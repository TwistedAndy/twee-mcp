<?php
/**
 * Twee MCP — Site Settings & Navigation Menus
 *
 * Implements MCP tools for reading general site settings and managing navigation menus.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Action/Tool: General site settings
 *
 * @return array
 */
function twcp_site_info(): array
{
	$theme = wp_get_theme();
	$plugins = [];

	if (!function_exists('get_plugins')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$installed = get_plugins();
	foreach ((array) get_option('active_plugins', []) as $file) {
		$plugins[] = $installed[$file]['Name'] ?? $file;
	}

	$info = [
		'name'                => get_bloginfo('name'),
		'description'         => get_bloginfo('description'),
		'home_url'            => home_url('/'),
		'admin_email'         => get_option('admin_email'),
		'language'            => get_locale(),
		'timezone'            => wp_timezone_string(),
		'date_format'         => get_option('date_format'),
		'time_format'         => get_option('time_format'),
		'permalink_structure' => get_option('permalink_structure'),
		'posts_per_page'      => (int) get_option('posts_per_page'),
		'show_on_front'       => get_option('show_on_front'),
		'page_on_front'       => (int) get_option('page_on_front'),
		'page_for_posts'      => (int) get_option('page_for_posts'),
		'theme'               => $theme->get('Name'),
		'active_plugins'      => $plugins,
	];

	if (class_exists('WooCommerce')) {
		$info['store'] = [
			'currency'      => get_woocommerce_currency(),
			'shop_page'     => (int) wc_get_page_id('shop'),
			'prices_tax'    => wc_prices_include_tax() ? 'inclusive' : 'exclusive',
			'weight_unit'   => get_option('woocommerce_weight_unit'),
			'base_location' => wc_get_base_location(),
		];
	}

	return $info;
}


/**
 * Action/Tool: Find users
 *
 * @param array $args
 *
 * @return array
 */
function twcp_site_users(array $args): array
{
	$role = sanitize_key($args['role'] ?? '');
	if ($role !== '' and !wp_roles()->is_role($role)) {
		return ['error' => 'Unknown role. Available roles: ' . implode(', ', array_keys(wp_roles()->get_names()))];
	}

	$query = [
		'number'  => max(1, min(100, (int) ($args['limit'] ?? 20))),
		'offset'  => max(0, (int) ($args['offset'] ?? 0)),
		'orderby' => 'display_name',
		'role'    => $role,
	];

	if (!empty($args['id'])) {
		$query['include'] = [absint($args['id'])];
	}

	// Names live in user meta, which the user search does not cover, so both sets of matches are combined
	$search = trim((string) ($args['search'] ?? ''));
	if ($search !== '') {
		$ids = get_users(['fields' => 'ID', 'role' => $role, 'search' => '*' . $search . '*', 'search_columns' => ['user_login', 'user_email', 'user_nicename', 'display_name']]);
		$ids = array_merge($ids, get_users(['fields' => 'ID', 'role' => $role, 'meta_query' => [
			'relation' => 'OR',
			['key' => 'first_name', 'value' => $search, 'compare' => 'LIKE'],
			['key' => 'last_name', 'value' => $search, 'compare' => 'LIKE'],
		]]));
		$query['include'] = array_values(array_intersect($query['include'] ?? $ids, $ids)) ? : [0];
	}

	$result = new WP_User_Query($query + ['count_total' => true]);
	$users = [];

	foreach ($result->get_results() as $user) {
		$active = (int) get_user_meta($user->ID, 'wc_last_active', true);
		$users[] = [
			'id'          => $user->ID,
			'login'       => $user->user_login,
			'name'        => $user->display_name,
			'first_name'  => $user->first_name,
			'last_name'   => $user->last_name,
			'email'       => $user->user_email,
			'roles'       => array_values($user->roles),
			'registered'  => wp_date('Y-m-d', strtotime($user->user_registered . ' UTC')),
			'last_active' => $active ? wp_date('Y-m-d', $active) : null,
		];
	}

	return ['total' => $result->get_total(), 'users' => $users];
}


/**
 * Format a navigation menu with its locations and items
 *
 * @param WP_Term $menu
 *
 * @return array
 */
function twcp_site_menu_format(WP_Term $menu): array
{
	$items = [];
	foreach ((array) wp_get_nav_menu_items($menu->term_id, ['post_status' => 'any']) as $item) {
		$items[] = [
			'id'        => (int) $item->ID,
			'title'     => $item->title,
			'url'       => $item->url,
			'type'      => $item->type,
			'object'    => $item->object,
			'object_id' => (int) $item->object_id,
			'parent_id' => (int) $item->menu_item_parent,
			'position'  => (int) $item->menu_order,
		];
	}

	return [
		'id'        => $menu->term_id,
		'name'      => $menu->name,
		'slug'      => $menu->slug,
		'locations' => array_keys(array_filter(get_nav_menu_locations(), fn($id) => (int) $id === $menu->term_id)),
		'items'     => $items,
	];
}


/**
 * Action/Tool: List navigation menus
 *
 * @param array $args
 *
 * @return array
 */
function twcp_site_menus(array $args = []): array
{
	if (!empty($args['menu_id'])) {
		$menu = wp_get_nav_menu_object(absint($args['menu_id']));

		return $menu ? twcp_site_menu_format($menu) : ['error' => 'Menu not found.'];
	}

	return [
		'locations' => get_registered_nav_menus(),
		'menus'     => array_map('twcp_site_menu_format', wp_get_nav_menus()),
	];
}


/**
 * Action/Tool: Add an item to a navigation menu
 *
 * @param array $args
 *
 * @return array
 */
function twcp_site_menu_add(array $args): array
{
	$menu = wp_get_nav_menu_object(absint($args['menu_id'] ?? 0));
	if (!$menu) {
		return ['error' => 'Menu not found.'];
	}

	$type = $args['type'] ?? '';
	$object_id = absint($args['object_id'] ?? 0);
	$item = [
		'menu-item-type'      => $type,
		'menu-item-status'    => 'publish',
		'menu-item-title'     => sanitize_text_field($args['title'] ?? ''),
		'menu-item-parent-id' => absint($args['parent_id'] ?? 0),
		'menu-item-position'  => absint($args['position'] ?? 0),
	];

	if ($type === 'post_type') {
		$post = get_post($object_id);
		if (!$post or $post->post_status === 'trash') {
			return ['error' => 'Linked post not found.'];
		}
		$item['menu-item-object'] = $post->post_type;
		$item['menu-item-object-id'] = $post->ID;
	} elseif ($type === 'taxonomy') {
		$term = get_term($object_id);
		if (!$term or is_wp_error($term)) {
			return ['error' => 'Linked term not found.'];
		}
		$item['menu-item-object'] = $term->taxonomy;
		$item['menu-item-object-id'] = $term->term_id;
	} elseif ($type === 'custom') {
		$url = esc_url_raw((string) ($args['url'] ?? ''));
		if ($url === '' or $item['menu-item-title'] === '') {
			return ['error' => 'Custom links require a title and URL.'];
		}
		$item['menu-item-url'] = $url;
	} else {
		return ['error' => 'Unknown menu item type.'];
	}

	if ($item['menu-item-parent-id'] and !in_array($item['menu-item-parent-id'], array_map('intval', wp_list_pluck((array) wp_get_nav_menu_items($menu->term_id), 'ID')), true)) {
		return ['error' => 'Parent item does not belong to this menu.'];
	}

	$item_id = wp_update_nav_menu_item($menu->term_id, 0, $item);
	if (is_wp_error($item_id)) {
		return ['error' => $item_id->get_error_message()];
	}

	return ['success' => true, 'item_id' => $item_id, 'menu' => twcp_site_menu_format($menu)];
}


/**
 * Action/Tool: Remove a navigation menu item
 *
 * @param array $args
 *
 * @return array
 */
function twcp_site_menu_remove(array $args): array
{
	$item_id = absint($args['item_id'] ?? 0);
	if (!is_nav_menu_item($item_id)) {
		return ['error' => 'Menu item not found.'];
	}

	$menus = wp_get_object_terms($item_id, 'nav_menu');
	wp_delete_post($item_id, true);

	return ['success' => true, 'item_id' => $item_id, 'menu' => !empty($menus[0]) ? twcp_site_menu_format($menus[0]) : null];
}
