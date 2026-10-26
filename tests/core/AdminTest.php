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
	}
}
