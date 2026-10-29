<?php

/**
 * Admin tabs, legacy page slugs, module switches, and prompt settings tests
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
	 * Tabs follow their position, and automation is open to content managers while settings and agent tabs stay with
	 * administrators.
	 */
	public function test_tab_capabilities(): void
	{
		$tabs = twcp_admin_tabs();

		$this->assertSame(['automation', 'blocks', 'images', 'model', 'connection', 'tools'], array_keys($tabs), 'Module tabs follow their position');
		$this->assertSame('edit_others_posts', $tabs['automation']['capability']);
		foreach (['blocks', 'images', 'model', 'connection', 'tools'] as $tab) {
			$this->assertSame('manage_options', $tabs[$tab]['capability'], $tab);
		}

		$editor = self::factory()->user->create(['role' => 'editor']);
		wp_set_current_user($editor);
		$this->assertTrue(current_user_can($tabs['automation']['capability']));
		$this->assertFalse(current_user_can($tabs['connection']['capability']));
		$this->assertFalse(current_user_can($tabs['tools']['capability']));
	}

	/**
	 * The first tab owns the parent slug and the page slug resolves back to its tab.
	 */
	public function test_slugs_resolve_to_tabs(): void
	{
		$this->assertSame('tw-mcp', twcp_admin_slug('automation'));
		$this->assertSame('tw-mcp-connection', twcp_admin_slug('connection'));
		$this->assertSame('tw-mcp-tools', twcp_admin_slug('tools'));

		foreach (array_keys(twcp_admin_tabs()) as $tab) {
			$_GET['page'] = twcp_admin_slug($tab);
			$this->assertSame($tab, twcp_admin_tab());
		}
	}

	/**
	 * Bookmarked slugs of removed tabs lead to the tab that took them over, and are no slugs of tabs.
	 */
	public function test_legacy_slugs_open_new_tabs(): void
	{
		$tabs = twcp_admin_tabs();
		$slugs = array_map('twcp_admin_slug', array_keys($tabs));

		foreach (twcp_admin_legacy() as $slug => $tab) {
			$this->assertArrayHasKey($tab, $tabs, $slug);
			$this->assertNotContains($slug, $slugs, $slug);
		}

		$_GET['page'] = 'unknown';
		$this->assertSame('automation', twcp_admin_tab());
	}


	/* -------------------------------------------------------------------------
	 * Prompts
	 * ------------------------------------------------------------------------- */

	/**
	 * Every module but wp can be switched off; the setting keeps only module folders.
	 */
	public function test_modules_can_be_switched_off(): void
	{
		$this->assertSame(['activity', 'blocks', 'images', 'woo'], array_keys(twcp_modules_found()));
		$this->assertSame('WooCommerce', twcp_modules_found()['woo']['name']);
		$this->assertSame(['woo'], twcp_admin_sanitize_modules(['woo' => '0', 'blocks' => '1', 'wp' => '0', 'nope' => '0']));
		$this->assertSame(['images'], twcp_admin_sanitize_modules(['images', 'wp']));

		update_option('twcp_disabled_modules', ['woo']);
		$this->assertStringContainsString('3 of 4 Modules Enabled', twcp_template('modules', twcp_admin_data('modules')));
		delete_option('twcp_disabled_modules');
	}


	/**
	 * Administrators find each prompt in a form of its own on the card of the job that runs with it, prompts of no job
	 * in their section, and the shared context above the sections; saved text is marked as customized, and the full
	 * product description prompt brings the copy rules of the WooCommerce module. Editors run the jobs without the
	 * prompts.
	 */
	public function test_prompts_on_automation_screen(): void
	{
		update_option('twcp_system_prompt_post_excerpt', 'Write one sentence.');

		$data = twcp_admin_data('automation');
		$groups = $data['prompt_groups'];
		$this->assertSame(['context', 'content', 'store', 'terms', 'media'], array_keys($groups));
		foreach (twcp_prompt_registry() as $option => $prompt) {
			$this->assertArrayHasKey($option, $groups[$prompt['group']]['prompts'], $option);
		}

		$excerpt = $groups['content']['prompts']['twcp_system_prompt_post_excerpt'];
		$this->assertSame('Write one sentence.', $excerpt['value']);
		$this->assertTrue($excerpt['customized']);
		$this->assertNotSame($excerpt['value'], $excerpt['default']);

		$context = $groups['context']['prompts']['twcp_general_context'];
		$this->assertFalse($context['customized']);
		$this->assertSame($context['default'], $context['value']);

		$html = twcp_template('automation', $data);
		foreach (twcp_prompt_registry() as $option => $prompt) {
			$this->assertSame(1, substr_count($html, "name='option_page' value='{$option}'"), $option);
		}
		$this->assertLessThan(strpos($html, 'Needs Attention'), strpos($html, "value='twcp_target_keywords'"));
		$excerpt = strpos($html, "value='twcp_system_prompt_post_excerpt'");
		$this->assertLessThan($excerpt, strpos($html, 'data-type="post_excerpt"'));
		$this->assertGreaterThan($excerpt, strpos($html, 'data-type="post_content"'));
		$this->assertLessThan(strpos($html, 'data-type="post_excerpt"'), strpos($html, "value='twcp_system_prompt_post_similar'"));
		$full = strpos($html, "value='twcp_system_prompt_product'");
		$this->assertGreaterThan($full, strpos($html, 'twcp_product_settings[max_links]'));
		$this->assertGreaterThan(strpos($html, "value='twcp_system_prompt_product_short'"), $full);

		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$data = twcp_admin_data('automation');
		$this->assertSame([], $data['prompt_groups']);
		$this->assertStringNotContainsString('twcp_system_prompt_', twcp_template('automation', $data));
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
	 * Target keywords of the Shared Context follow the site context in every system prompt, and no section is added
	 * without them.
	 */
	public function test_target_keywords_join_every_prompt(): void
	{
		$this->assertStringNotContainsString('TARGET KEYWORDS', twcp_prompt('twcp_system_prompt_seo', 'twcp_prompt_seo_default'));

		update_option('twcp_target_keywords', "leather watch straps\r\nnato straps");
		$system = twcp_prompt('twcp_system_prompt_seo', 'twcp_prompt_seo_default');
		$this->assertStringStartsWith("STORE PROFILE & GENERAL CONTEXT:\n" . twcp_general_context(), $system);
		$this->assertStringContainsString("TARGET KEYWORDS (work them in where they fit the item naturally; never force or stuff them):\nleather watch straps\r\nnato straps\n\n", $system);
		$this->assertSame('', twcp_prompt_sanitize('  ', 'twcp_target_keywords'));
	}

	/**
	 * Saving the Blocks form with the built-in block conventions stores nothing.
	 */
	public function test_block_conventions_drop_default(): void
	{
		twcp_block_admin_init();
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
		$requests = $this->mock_model($this->model_reply(" Leather Works makes boots. \n"));

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
		twcp_product_admin_init();
		$default = twcp_product_settings_default();

		update_option('twcp_product_settings', ['max_links' => '0', 'custom_rules' => ' ' . $default['custom_rules'] . "\n"]);
		$this->assertSame(['max_links' => 0, 'custom_rules' => ''], get_option('twcp_product_settings'));
		$this->assertSame(['max_links' => 0, 'custom_rules' => $default['custom_rules']], twcp_product_settings());

		update_option('twcp_product_settings', ['max_links' => 3, 'custom_rules' => 'Link to the size guide.']);
		$this->assertSame(['max_links' => 3, 'custom_rules' => 'Link to the size guide.'], twcp_product_settings());

		delete_option('twcp_product_settings');
	}

	/**
	 * Saving the Blocks form keeps the descriptions of layouts it does not list, such as those of another field.
	 */
	public function test_block_descriptions_keep_unlisted_layouts(): void
	{
		twcp_block_admin_init();
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


	/**
	 * Each API key provider links its key page and guide, and only the selected one is shown.
	 */
	public function test_key_links_follow_provider(): void
	{
		update_option('twcp_model_provider', 'anthropic');

		$html = twcp_template('model', twcp_admin_data('model'));

		foreach (['openai', 'openrouter', 'anthropic'] as $provider) {
			$info = twcp_model_providers()[$provider];
			$this->assertStringContainsString('href="' . $info['keys_url'] . '"', $html, $provider);
			$this->assertStringContainsString('href="' . $info['guide_url'] . '"', $html, $provider);
		}

		$this->assertMatchesRegularExpression('/style="margin-top: 4px;" data-provider-panel="anthropic"/', $html);
		$this->assertMatchesRegularExpression('/style="margin-top: 4px; display: none;" data-provider-panel="openai"/', $html);

		delete_option('twcp_model_provider');
	}


	/* -------------------------------------------------------------------------
	 * Agent Access
	 * ------------------------------------------------------------------------- */

	/**
	 * The ChatGPT steps leave the OAuth settings to discovery, with no client ID to create.
	 */
	public function test_connection_chatgpt_steps(): void
	{
		$html = twcp_template('connection', twcp_admin_data('connection'));

		$this->assertStringContainsString('Add custom MCP server', $html);
		$this->assertStringContainsString(rest_url('straps/v1/.well-known/oauth-protected-resource'), $html);
		$this->assertStringNotContainsString('User-Defined OAuth Client', $html);
		$this->assertStringNotContainsString('twcp_client_create', $html);
	}
}
