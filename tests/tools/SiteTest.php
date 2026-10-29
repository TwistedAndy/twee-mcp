<?php

/**
 * Site settings and navigation menu tool tests
 */
class SiteTest extends TWCP_TestCase {

	/**
	 * Site info reports general settings and the store when WooCommerce is active.
	 */
	public function test_get_site_info(): void
	{
		update_option('blogname', 'Strap Shop');

		$info = $this->call('get_site_info');
		$this->assertSame('Strap Shop', $info['name']);
		$this->assertSame(wp_timezone_string(), $info['timezone']);
		$this->assertIsArray($info['active_plugins']);
		$this->assertSame(twcp_product_active(), isset($info['store']));
	}

	/**
	 * Users are found by name, email, or login and filtered by role; the list is private and needs list_users.
	 */
	public function test_get_users(): void
	{
		$walter = self::factory()->user->create(['role' => 'editor', 'user_login' => 'wgomez', 'user_email' => 'walter@cassoni.test', 'first_name' => 'Walter', 'last_name' => 'Gómez']);
		update_user_meta($walter, 'wc_last_active', strtotime('2026-09-07 UTC'));
		$karina = self::factory()->user->create(['role' => 'author', 'first_name' => 'Karina']);
		$ids = function(array $args): array {
			return array_column($this->call('get_users', $args)['users'], 'id');
		};

		$found = $this->call('get_users', ['search' => 'Gómez']);
		$this->assertSame(1, $found['total']);
		$this->assertSame([$walter, 'walter@cassoni.test', ['editor'], '2026-09-07'], [$found['users'][0]['id'], $found['users'][0]['email'], $found['users'][0]['roles'], $found['users'][0]['last_active']]);

		$this->assertSame([$walter], $ids(['search' => 'cassoni.test']));
		$this->assertSame([$walter], $ids(['search' => 'wgom']));
		$this->assertSame([$karina], $ids(['search' => 'Karina']));
		$this->assertSame([], $ids(['search' => 'Karina', 'role' => 'editor']));
		$this->assertContains($walter, $ids(['role' => 'editor']));
		$this->assertNotContains($karina, $ids(['role' => 'editor']));
		$this->assertSame([$karina], $ids(['id' => $karina]));

		$this->assertStringContainsString('Available roles', $this->call('get_users', ['role' => 'chef'])['error']);
		$this->assertStringContainsString('unavailable', $this->call('get_users', [], true)['error']);

		wp_set_current_user($karina);
		$this->assertStringContainsString('cannot use', $this->call('get_users')['error']);
	}

	/**
	 * Menu items link posts, terms, and custom URLs, with submenus.
	 */
	public function test_menu_items(): void
	{
		$menu_id = wp_create_nav_menu('Main');
		$page = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About']);
		$cat = self::factory()->category->create(['name' => 'Blog']);

		$about = $this->call('add_menu_item', ['menu_id' => $menu_id, 'type' => 'post_type', 'object_id' => $page]);
		$this->assertTrue($about['success']);
		$this->call('add_menu_item', ['menu_id' => $menu_id, 'type' => 'taxonomy', 'object_id' => $cat, 'parent_id' => $about['item_id']]);
		$custom = $this->call('add_menu_item', ['menu_id' => $menu_id, 'type' => 'custom', 'title' => 'Shop', 'url' => 'https://example.org/shop']);

		$items = $custom['menu']['items'];
		$this->assertSame(['About', 'Blog', 'Shop'], array_column($items, 'title'));
		$this->assertSame($about['item_id'], $items[1]['parent_id']);
		$this->assertSame('https://example.org/shop', $items[2]['url']);

		$menus = $this->call('get_menus');
		$this->assertContains('Main', array_column($menus['menus'], 'name'));
		$this->assertCount(3, $this->call('get_menus', ['menu_id' => $menu_id])['items']);

		$removed = $this->call('remove_menu_item', ['item_id' => $custom['item_id']]);
		$this->assertCount(2, $removed['menu']['items']);
		$this->assertArrayHasKey('error', $this->call('remove_menu_item', ['item_id' => $page]), 'Pages are not menu items');
	}

	/**
	 * Invalid menu items are rejected.
	 */
	public function test_menu_item_validation(): void
	{
		$menu_id = wp_create_nav_menu('Footer');
		$other = wp_create_nav_menu('Other');
		$foreign = $this->call('add_menu_item', ['menu_id' => $other, 'type' => 'custom', 'title' => 'X', 'url' => 'https://example.org']);

		$this->assertSame('Menu not found.', $this->call('add_menu_item', ['menu_id' => 999999, 'type' => 'custom'])['error']);
		$this->assertStringContainsString('title and URL', $this->call('add_menu_item', ['menu_id' => $menu_id, 'type' => 'custom', 'url' => 'https://example.org'])['error']);
		$this->assertStringContainsString('not found', $this->call('add_menu_item', ['menu_id' => $menu_id, 'type' => 'post_type', 'object_id' => 999999])['error']);
		$this->assertStringContainsString('Parent item', $this->call('add_menu_item', ['menu_id' => $menu_id, 'type' => 'custom', 'title' => 'Y', 'url' => 'https://example.org', 'parent_id' => $foreign['item_id']])['error']);
	}
}
