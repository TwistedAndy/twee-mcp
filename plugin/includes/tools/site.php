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
 * MCP tools for site settings and menus
 *
 * @return array Tool name => [ description, callable, parameters, required, annotations ]
 */
function twcp_site_tools(): array
{
	return [
		'get_site_info'    => [
			'description' => 'Get general site settings: name, tagline, URLs, language, timezone, date format, permalink structure, front page and blog page, active theme, active plugins, and store currency.',
			'callable'    => 'twcp_site_info',
			'parameters'  => [],
			'annotations' => ['readOnlyHint' => true],
		],
		'get_menus'        => [
			'description' => 'List navigation menus with their theme locations and ordered items (title, URL, linked object, parent item).',
			'callable'    => 'twcp_site_menus',
			'parameters'  => [
				'menu_id' => ['type' => 'integer', 'description' => 'Optional single menu ID.'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'add_menu_item'    => [
			'description' => 'Add a link to a navigation menu: a post, page, or product (type "post_type"), a category or other term (type "taxonomy"), or a custom URL (type "custom").',
			'callable'    => 'twcp_site_menu_add',
			'capability'  => 'edit_theme_options',
			'required'    => ['menu_id', 'type'],
			'parameters'  => [
				'menu_id'   => ['type' => 'integer', 'minimum' => 1],
				'type'      => ['type' => 'string', 'enum' => ['post_type', 'taxonomy', 'custom']],
				'object_id' => ['type' => 'integer', 'description' => 'Post or term ID for post_type and taxonomy items.'],
				'title'     => ['type' => 'string', 'description' => 'Label; defaults to the linked object title. Required for custom links.'],
				'url'       => ['type' => 'string', 'description' => 'Custom link URL.'],
				'parent_id' => ['type' => 'integer', 'description' => 'Parent menu item ID for a submenu entry.'],
				'position'  => ['type' => 'integer', 'description' => 'Menu order position (default last).'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'remove_menu_item' => [
			'description' => 'Remove an item from a navigation menu. The linked post or term is not affected.',
			'callable'    => 'twcp_site_menu_remove',
			'capability'  => 'edit_theme_options',
			'required'    => ['item_id'],
			'parameters'  => [
				'item_id' => ['type' => 'integer', 'minimum' => 1],
			],
			'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
		],
	];
}


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

	if (twcp_product_active()) {
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
