<?php

/**
 * Admin tabs, legacy page slugs, and prompt settings tests
 */
class AdminTest extends TWCP_TestCase {

	public function tear_down(): void
	{
		unset($_GET['page']);
		delete_option('twcp_system_prompt_post_excerpt');
		parent::tear_down();
	}


	/* -------------------------------------------------------------------------
	 * Tabs & Slugs
	 * ------------------------------------------------------------------------- */

	/**
	 * Automation is open to content managers while settings and agent tabs stay with administrators.
	 */
	public function test_tab_capabilities(): void
	{
		$tabs = twcp_admin_tabs();

		$this->assertSame('automation', array_key_first($tabs));
		$this->assertSame('edit_others_posts', $tabs['automation']['capability']);
		foreach (['prompts', 'library', 'model', 'connection', 'tools'] as $tab) {
			$this->assertSame('manage_options', $tabs[$tab]['capability'], $tab);
		}

		$editor = self::factory()->user->create(['role' => 'editor']);
		wp_set_current_user($editor);
		$this->assertTrue(current_user_can($tabs['automation']['capability']));
		$this->assertFalse(current_user_can($tabs['prompts']['capability']));
		$this->assertFalse(current_user_can($tabs['tools']['capability']));
	}

	/**
	 * The first tab owns the parent slug and the page slug resolves back to its tab.
	 */
	public function test_slugs_resolve_to_tabs(): void
	{
		$this->assertSame('tw-mcp', twcp_admin_slug('automation'));
		$this->assertSame('tw-mcp-prompts', twcp_admin_slug('prompts'));
		$this->assertSame('tw-mcp-tools', twcp_admin_slug('tools'));

		foreach (array_keys(twcp_admin_tabs()) as $tab) {
			$_GET['page'] = twcp_admin_slug($tab);
			$this->assertSame($tab, twcp_admin_tab());
		}
	}

	/**
	 * Bookmarked slugs of removed tabs open the tab that took them over.
	 */
	public function test_legacy_slugs_open_new_tabs(): void
	{
		$tabs = twcp_admin_tabs();

		foreach (twcp_admin_legacy() as $slug => $tab) {
			$this->assertArrayHasKey($tab, $tabs, $slug);
			$_GET['page'] = $slug;
			$this->assertSame($tab, twcp_admin_tab(), $slug);
		}

		$_GET['page'] = 'unknown';
		$this->assertSame('automation', twcp_admin_tab());
	}


	/* -------------------------------------------------------------------------
	 * Prompts
	 * ------------------------------------------------------------------------- */

	/**
	 * Prompt data is grouped, and saved text is marked as customized.
	 */
	public function test_prompts_data_is_grouped(): void
	{
		update_option('twcp_system_prompt_post_excerpt', 'Write one sentence.');

		$data = twcp_admin_data('prompts');
		$this->assertArrayHasKey('product_settings', $data);

		foreach (twcp_prompt_registry() as $option => $prompt) {
			$this->assertArrayHasKey($prompt['group'], $data['groups'], $option);
			$this->assertArrayHasKey($option, $data['prompts'][$prompt['group']], $option);
		}

		$excerpt = $data['prompts']['content']['twcp_system_prompt_post_excerpt'];
		$this->assertSame('Write one sentence.', $excerpt['value']);
		$this->assertTrue($excerpt['customized']);
		$this->assertNotSame($excerpt['value'], $excerpt['default']);

		$context = $data['prompts']['context']['twcp_general_context'];
		$this->assertFalse($context['customized']);
		$this->assertSame($context['default'], $context['value']);
	}

	/**
	 * Saving the default text stores nothing, so later default updates still apply.
	 */
	public function test_prompt_sanitize_drops_default(): void
	{
		$option = 'twcp_system_prompt_post_excerpt';

		$this->assertSame('', twcp_prompt_sanitize(twcp_prompt_excerpt_default(), $option));
		$this->assertSame('', twcp_prompt_sanitize("  \n" . twcp_prompt_excerpt_default() . "\n", $option));
		$this->assertSame('Keep <b>HTML</b> examples.', twcp_prompt_sanitize(' Keep <b>HTML</b> examples. ', $option));
		$this->assertSame('', twcp_prompt_sanitize(['not', 'text'], $option));

		// Line endings differ between the browser (CRLF) and the source files, which may use either
		$lf = str_replace("\r\n", "\n", twcp_prompt_excerpt_default());
		$this->assertSame('', twcp_prompt_sanitize($lf, $option));
		$this->assertSame('', twcp_prompt_sanitize(str_replace("\n", "\r\n", $lf), $option));
	}

	/**
	 * Saving the Library form with the built-in block conventions stores nothing.
	 */
	public function test_block_conventions_drop_default(): void
	{
		twcp_admin_init();
		$lf = str_replace("\r\n", "\n", twcp_block_conventions_default());

		$this->assertSame('', sanitize_option('twcp_block_conventions', str_replace("\n", "\r\n", $lf)));
		$this->assertSame('', sanitize_option('twcp_block_conventions', $lf));
		$this->assertSame('Use short headings.', sanitize_option('twcp_block_conventions', ' Use short headings. '));
	}

	/**
	 * The generated site profile is based on what the site says about itself, since the model cannot open the home page.
	 */
	public function test_context_generate_sends_site_facts(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		update_option('blogname', 'Leather Works');
		self::factory()->post->create(['post_title' => 'Caring for calfskin boots', 'post_type' => twcp_product_active() ? 'product' : 'post']);
		$requests = $this->mock_model(['choices' => [['message' => ['content' => " Leather Works makes boots. \n"]]]]);

		$this->assertSame('Leather Works makes boots.', twcp_general_context_generate());

		$prompt = wp_json_encode($requests[0]['body']);
		foreach ([home_url('/'), 'Leather Works', 'Caring for calfskin boots'] as $fact) {
			$this->assertStringContainsString(trim(wp_json_encode($fact), '"'), $prompt);
		}

		remove_all_filters('pre_http_request');
		delete_option('twcp_model_api_key');
	}

	/**
	 * Saving the Prompts form with the built-in Store Guidelines stores nothing, and empty guidelines read as the default.
	 */
	public function test_product_rules_drop_default(): void
	{
		twcp_admin_init();
		$default = twcp_product_settings_default();

		update_option('twcp_product_settings', ['max_links' => '0', 'custom_rules' => ' ' . $default['custom_rules'] . "\n"]);
		$this->assertSame(['max_links' => 0, 'custom_rules' => ''], get_option('twcp_product_settings'));
		$this->assertSame(['max_links' => 0, 'custom_rules' => $default['custom_rules']], twcp_product_settings());

		update_option('twcp_product_settings', ['max_links' => 3, 'custom_rules' => 'Link to the size guide.']);
		$this->assertSame(['max_links' => 3, 'custom_rules' => 'Link to the size guide.'], twcp_product_settings());

		delete_option('twcp_product_settings');
	}

	/**
	 * Saving the Library form keeps the descriptions of layouts it does not list, such as those of another field.
	 */
	public function test_block_descriptions_keep_unlisted_layouts(): void
	{
		twcp_admin_init();
		update_option('twcp_block_descriptions', ['hero' => 'A large banner.', 'faq' => 'Questions and answers.']);

		update_option('twcp_block_descriptions', ['faq' => 'Common questions.', 'gallery' => '']);
		$this->assertSame(['hero' => 'A large banner.', 'faq' => 'Common questions.', 'gallery' => ''], get_option('twcp_block_descriptions'));

		// A screen without the layout table posts nothing, which keeps every description
		update_option('twcp_block_descriptions', null);
		$this->assertSame('A large banner.', get_option('twcp_block_descriptions')['hero']);
	}

	/**
	 * Key fields are text fields that password managers skip, so a saved login never replaces a key or blocks the form.
	 */
	public function test_key_fields_are_not_password_fields(): void
	{
		update_option('twcp_model_provider', 'chatgpt');
		update_option('twcp_model_api_key', 'sk-stored');

		$html = twcp_template('model', twcp_admin_data('model')) . twcp_template('images', twcp_admin_data('images'));
		$this->assertStringNotContainsString('type="password"', $html);
		$this->assertStringNotContainsString('type="url"', $html);

		// The stored key stays in the field for the ChatGPT plan, so saving the form does not clear it
		$this->assertMatchesRegularExpression('/id="twcp_model_api_key"[^>]+value="sk-stored"/', $html);

		delete_option('twcp_model_provider');
		delete_option('twcp_model_api_key');
	}
}
