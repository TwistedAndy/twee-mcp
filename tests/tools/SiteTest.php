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
