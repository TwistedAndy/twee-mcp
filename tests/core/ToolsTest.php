<?php

/**
 * Tool registry, availability, access, and MCP dispatch tests
 */
class ToolsTest extends TWCP_TestCase {

	public function tear_down(): void
	{
		delete_option('twcp_disabled_tools');
		remove_all_filters('twcp_product_active');
		parent::tear_down();
	}


	/* -------------------------------------------------------------------------
	 * Registry & Groups
	 * ------------------------------------------------------------------------- */

	/**
	 * Every registry tool belongs to a group and has a callable and schema.
	 */
	public function test_registry_is_assembled_from_groups(): void
	{
		$registry = twcp_tools_registry();
		$groups = twcp_tools_groups();

		$this->assertArrayHasKey('get_posts', $registry);
		$this->assertArrayHasKey('get_orders', $registry);
		$this->assertArrayHasKey('preview_images', $registry);

		foreach ($registry as $name => $tool) {
			$this->assertArrayHasKey($tool['group'], $groups, $name);
			$this->assertIsCallable($tool['callable'], $name);
			$this->assertNotEmpty($tool['description'], $name);
			foreach ($tool['required'] ?? [] as $required) {
				$this->assertArrayHasKey($required, $tool['parameters'], $name . ' requires an undefined parameter');
			}
		}
	}

	/**
	 * WooCommerce groups are inactive without WooCommerce and their tools are hidden.
	 */
	public function test_woocommerce_tools_disabled_without_woocommerce(): void
	{
		add_filter('twcp_product_active', '__return_false');

		$enabled = twcp_tools_enabled();
		$this->assertArrayNotHasKey('get_products', $enabled);
		$this->assertArrayNotHasKey('get_orders', $enabled);
		$this->assertArrayHasKey('get_posts', $enabled);

		$this->assertSame('WooCommerce', twcp_tools_missing('save_coupon'));
		$this->assertNull(twcp_tools_missing('get_posts'));

		$names = array_column(twcp_tools_list(), 'name');
		$this->assertNotContains('create_product', $names);

		$result = $this->call('get_products');
		$this->assertStringContainsString('requires WooCommerce', $result['error']);
	}

	/**
	 * WooCommerce tools are listed when the plugin is active.
	 */
	public function test_woocommerce_tools_enabled_with_woocommerce(): void
	{
		add_filter('twcp_product_active', '__return_true');

		$this->assertArrayHasKey('get_products', twcp_tools_enabled());
		$this->assertArrayHasKey('get_sales_summary', twcp_tools_enabled());
		$this->assertNull(twcp_tools_missing('get_products'));
	}


	/* -------------------------------------------------------------------------
	 * Disabled Tools Setting
	 * ------------------------------------------------------------------------- */

	/**
	 * The setting accepts the Tools screen map and a plain list, and drops unknown names.
	 */
	public function test_sanitize_disabled(): void
	{
		$this->assertSame(['trash_post'], twcp_tools_sanitize_disabled(['get_posts' => '1', 'trash_post' => '0', 'unknown' => '0']));
		$this->assertSame(['trash_post', 'get_terms'], twcp_tools_sanitize_disabled(['trash_post', 'get_terms', 'trash_post', 'missing']));
		$this->assertSame([], twcp_tools_sanitize_disabled('garbage'));
	}

	/**
	 * Disabled tools are not listed and cannot be called.
	 */
	public function test_disabled_tool_is_hidden_and_blocked(): void
	{
		update_option('twcp_disabled_tools', ['trash_post']);

		$this->assertNotContains('trash_post', array_column(twcp_tools_list(), 'name'));

		$post_id = self::factory()->post->create();
		$result = $this->call('trash_post', ['id' => $post_id]);

		$this->assertStringContainsString('disabled', $result['error']);
		$this->assertSame('publish', get_post_status($post_id));
	}


	/* -------------------------------------------------------------------------
	 * Calls & Access
	 * ------------------------------------------------------------------------- */

	/**
	 * Unknown tools and invalid arguments are rejected before the callable runs.
	 */
	public function test_call_validation(): void
	{
		$this->assertStringContainsString('Unsupported tool', $this->call('nope')['error']);
		$this->assertArrayHasKey('error', $this->call('trash_post', []));
		$this->assertArrayHasKey('error', $this->call('trash_post', ['id' => 'abc']));
	}

	/**
	 * Read-only lookups refuse write tools and private tools.
	 */
	public function test_read_only_lookups(): void
	{
		$post_id = self::factory()->post->create();

		$this->assertStringContainsString('read-only', $this->call('trash_post', ['id' => $post_id], true)['error']);
		$this->assertStringContainsString('read-only', $this->call('preview_images', ['attachment_ids' => [1]], true)['error']);
		$this->assertArrayHasKey('id', $this->call('get_posts', ['id' => $post_id], true));
	}

	/**
	 * Users without the base capability or a tool capability are refused.
	 */
	public function test_capabilities(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
		$this->assertStringContainsString('cannot use content management tools', $this->call('get_posts')['error']);

		wp_set_current_user(self::factory()->user->create(['role' => 'author']));
		$this->assertStringContainsString('cannot use the get_comments tool', $this->call('get_comments')['error']);
		$this->assertStringContainsString('cannot use the add_menu_item tool', $this->call('add_menu_item', ['menu_id' => 1, 'type' => 'custom'])['error']);

		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->assertArrayHasKey('comments', $this->call('get_comments'));
		$this->assertStringContainsString('cannot use the generate_block_descriptions tool', $this->call('generate_block_descriptions')['error']);
	}

	/**
	 * Editing someone else's post requires edit_others_posts.
	 */
	public function test_post_level_access(): void
	{
		$post_id = self::factory()->post->create(['post_author' => self::$admin_id]);
		wp_set_current_user(self::factory()->user->create(['role' => 'author']));

		$this->assertSame('You cannot modify this content.', $this->call('edit_post', ['id' => $post_id, 'title' => 'Hijacked'])['error']);
	}


	/* -------------------------------------------------------------------------
	 * Instructions & Dispatch
	 * ------------------------------------------------------------------------- */

	/**
	 * Instruction hints follow the enabled tools.
	 */
	public function test_instructions_follow_enabled_tools(): void
	{
		$this->assertStringContainsString('get_comments', twcp_tools_instructions());

		update_option('twcp_disabled_tools', ['get_comments', 'moderate_comment', 'reply_comment']);
		$this->assertStringNotContainsString('get_comments', twcp_tools_instructions());
	}

	/**
	 * initialize returns server info and instructions; unknown methods fail.
	 */
	public function test_dispatch_protocol(): void
	{
		$init = $this->dispatch('initialize');
		$this->assertSame(TWCP_PROTOCOL, $init['result']['protocolVersion']);
		$this->assertNotEmpty($init['result']['instructions']);

		$this->assertNull($this->dispatch('notifications/initialized'));
		$this->assertSame(-32601, $this->dispatch('nope')['error']['code']);

		$list = $this->dispatch('tools/list');
		$this->assertSame(array_keys(twcp_tools_enabled()), array_column($list['result']['tools'], 'name'));
	}

	/**
	 * Tool results are compact JSON text, errors set isError.
	 */
	public function test_dispatch_tool_call(): void
	{
		$post_id = self::factory()->post->create(['post_title' => 'Compact']);

		$reply = $this->dispatch('tools/call', ['name' => 'get_posts', 'arguments' => ['id' => $post_id]]);
		$text = $reply['result']['content'][0]['text'];
		$this->assertStringNotContainsString("\n    ", $text);
		$this->assertSame('Compact', json_decode($text, true)['title']);
		$this->assertArrayNotHasKey('isError', $reply['result']);

		$error = $this->dispatch('tools/call', ['name' => 'nope']);
		$this->assertTrue($error['result']['isError']);
	}

	/**
	 * Images returned by a tool become MCP image content blocks.
	 */
	public function test_dispatch_image_content(): void
	{
		$image_id = $this->create_image(800, 600);

		$reply = $this->dispatch('tools/call', ['name' => 'preview_images', 'arguments' => ['attachment_ids' => [$image_id]]]);
		$content = $reply['result']['content'];

		$this->assertSame(['text', 'image'], array_column($content, 'type'));
		$this->assertSame('image/jpeg', $content[1]['mimeType']);
		$this->assertNotFalse(base64_decode($content[1]['data'], true));
		$this->assertStringNotContainsString('_mcp_images', $content[0]['text']);
	}

	/**
	 * Only discovery methods and public search/fetch skip authentication.
	 */
	public function test_public_methods(): void
	{
		$this->assertTrue(twcp_tools_public(['method' => 'tools/list']));
		$this->assertTrue(twcp_tools_public(['method' => 'tools/call', 'params' => ['name' => 'search']]));
		$this->assertFalse(twcp_tools_public(['method' => 'tools/call', 'params' => ['name' => 'get_posts']]));
		$this->assertFalse(twcp_tools_public([['method' => 'ping'], ['method' => 'tools/call', 'params' => ['name' => 'edit_post']]]));
	}
}
