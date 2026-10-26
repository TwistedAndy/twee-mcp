<?php

/**
 * Batch job permissions, queries, cards, and overview tests
 */
class BatchTest extends TWCP_TestCase {

	/* -------------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------------- */

	/**
	 * Editors run content and media jobs but not site setup jobs.
	 */
	public function test_editor_allowed_types(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$allowed = twcp_batch_allowed();

		foreach (['post_excerpt', 'post_content', 'seo', 'featured_images', 'categories', 'term_seo', 'term_description', 'alts'] as $type) {
			$this->assertArrayHasKey($type, $allowed, $type);
		}
		$this->assertArrayNotHasKey('blocks', $allowed);
	}

	/**
	 * Authors cannot run batch jobs over other people's content.
	 */
	public function test_author_has_no_content_jobs(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'author']));
		$allowed = twcp_batch_allowed();

		$this->assertArrayNotHasKey('post_excerpt', $allowed);
		$this->assertArrayNotHasKey('blocks', $allowed);
		$this->assertArrayHasKey('alts', $allowed);
	}

	/**
	 * Store jobs exist only with WooCommerce and belong to shop managers.
	 */
	public function test_store_jobs_for_shop_managers(): void
	{
		$this->require_woocommerce();

		$this->assertArrayHasKey('cross_sells', twcp_batch_types());

		wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
		$allowed = twcp_batch_allowed();
		$this->assertArrayHasKey('products_short', $allowed);
		$this->assertArrayHasKey('cross_sells', $allowed);
		$this->assertArrayHasKey('post_excerpt', $allowed);
	}


	/* -------------------------------------------------------------------------
	 * Queries
	 * ------------------------------------------------------------------------- */

	/**
	 * Featured image jobs pick published items without a thumbnail.
	 */
	public function test_featured_images_query(): void
	{
		$before = twcp_batch_total('featured_images', false, 'post');
		$post = self::factory()->post->create();
		$this->assertSame($before + 1, twcp_batch_total('featured_images', false, 'post'));
		$this->assertContains($post, twcp_batch_query('featured_images', 0, 100, false, 'post'));

		set_post_thumbnail($post, $this->create_image(64, 64, $post));
		$this->assertSame($before, twcp_batch_total('featured_images', false, 'post'));

		// Attachments do not support thumbnails and are never a scope
		$this->assertSame([], twcp_batch_args('featured_images', false, 'attachment'));
	}

	/**
	 * Category jobs pick posts still in the default category unless reclassifying all.
	 */
	public function test_categories_query(): void
	{
		$before = twcp_batch_total('categories', false, 'post');
		$all = twcp_batch_total('categories', true, 'post');

		$default = self::factory()->post->create();
		$sorted = self::factory()->post->create(['post_category' => [self::factory()->category->create()]]);

		$this->assertSame($before + 1, twcp_batch_total('categories', false, 'post'));
		$this->assertSame($all + 2, twcp_batch_total('categories', true, 'post'));

		$ids = twcp_batch_query('categories', 0, 100, false, 'post');
		$this->assertContains($default, $ids);
		$this->assertNotContains($sorted, $ids);

		$this->assertSame([], twcp_batch_args('categories', false, 'page'));
	}

	/**
	 * Term jobs run over terms without SEO titles and scope by taxonomy.
	 */
	public function test_term_seo_query(): void
	{
		$before = twcp_batch_total('term_seo', false, 'category');
		$term = self::factory()->category->create();

		$this->assertSame($before + 1, twcp_batch_total('term_seo', false, 'category'));
		$this->assertContains($term, twcp_batch_query('term_seo', 0, 100, false, 'category'));
		$this->assertNotContains($term, twcp_batch_query('term_seo', 0, 100, false, 'post_tag'));

		update_term_meta($term, 'rank_math_title', 'Category title');
		$this->assertSame($before, twcp_batch_total('term_seo', false, 'category'));
		$this->assertSame([], twcp_batch_term_args(false, 'post_format'));
	}

	/**
	 * The description job runs over terms with an empty description.
	 */
	public function test_term_description_query(): void
	{
		$before = twcp_batch_total('term_description', false, 'post_tag');
		$tag = self::factory()->tag->create(['description' => '']);
		$described = self::factory()->tag->create(['description' => 'Already described.']);

		$this->assertSame($before + 1, twcp_batch_total('term_description', false, 'post_tag'));
		$this->assertContains($tag, twcp_batch_query('term_description', 0, 100, false, 'post_tag'));
		$this->assertNotContains($described, twcp_batch_query('term_description', 0, 100, false, 'post_tag'));
		$this->assertContains($described, twcp_batch_query('term_description', 0, 100, true, 'post_tag'));

		wp_update_term($tag, 'post_tag', ['description' => 'Now described.']);
		$this->assertSame($before, twcp_batch_total('term_description', false, 'post_tag'));
	}

	/**
	 * A description slice writes plain text from the term's items, and Undo restores the old one.
	 */
	public function test_term_description_slice_and_undo(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model(['choices' => [['message' => ['content' => '"Guides to pruning and planting <b>trees</b>."']]]]);

		$tag = self::factory()->tag->create(['name' => 'Orchard', 'description' => 'old']);
		self::factory()->post->create(['post_title' => 'Pruning Apple Trees', 'tags_input' => ['Orchard']]);

		$state = twcp_batch_restart('term_description', 'all', 'post_tag');
		$state['status'] = 'running';
		twcp_batch_save('term_description', $state);
		twcp_batch_slice('term_description');

		$prompt = end($requests[0]['body']['messages'])['content'];
		$this->assertStringContainsString('Term Name: Orchard', $prompt);
		$this->assertStringContainsString('Pruning Apple Trees', $prompt);
		$this->assertSame('Guides to pruning and planting trees.', get_term_field('description', $tag, '', 'raw'));

		twcp_batch_undo('term_description');
		$this->assertSame('old', get_term_field('description', $tag, '', 'raw'));

		remove_all_filters('pre_http_request');
	}


	/* -------------------------------------------------------------------------
	 * Tools Screen
	 * ------------------------------------------------------------------------- */

	/**
	 * Cards are grouped by section and limited to the jobs the user can run.
	 */
	public function test_cards_grouped_by_section(): void
	{
		$sections = twcp_batch_sections();
		$cards = twcp_batch_cards();

		foreach ($cards as $section => $items) {
			$this->assertArrayHasKey($section, $sections);
			foreach ($items as $card) {
				$this->assertArrayHasKey($card['type'], twcp_batch_types());
				$this->assertArrayHasKey('badge', $card);
				$this->assertArrayHasKey('btn_text', $card);
			}
		}
		$this->assertContains('blocks', array_column($cards['site'], 'type'));

		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->assertArrayNotHasKey('site', twcp_batch_cards());
	}

	/**
	 * The overview counts items per role and links to the matching screens.
	 */
	public function test_overview_counts(): void
	{
		self::factory()->post->create(['post_status' => 'draft']);
		self::factory()->comment->create(['comment_post_ID' => self::factory()->post->create(), 'comment_approved' => '0']);

		$items = array_column(twcp_batch_overview(), null, 0);
		$this->assertGreaterThanOrEqual(1, $items['Drafts and pending posts'][1]);
		$this->assertGreaterThanOrEqual(1, $items['Comments awaiting moderation'][1]);
		$this->assertStringContainsString('comment_status=moderated', $items['Comments awaiting moderation'][2]);

		wp_set_current_user(self::factory()->user->create(['role' => 'author']));
		$labels = array_column(twcp_batch_overview(), 0);
		$this->assertNotContains('Drafts and pending posts', $labels);
		$this->assertContains('Images without Alt text', $labels);
	}


	/* -------------------------------------------------------------------------
	 * Model setup
	 * ------------------------------------------------------------------------- */

	/**
	 * Generation waits for an API key, or for a ChatGPT account on the plan provider.
	 */
	public function test_model_missing(): void
	{
		update_option('twcp_model_provider', 'anthropic');
		delete_option('twcp_model_api_key');
		$this->assertStringContainsString('Anthropic (Claude) API key', twcp_model_missing());

		update_option('twcp_model_api_key', 'test-key');
		$this->assertSame('', twcp_model_missing());

		update_option('twcp_model_provider', 'chatgpt');
		$this->assertStringContainsString('no ChatGPT account', twcp_model_missing());
	}

	/**
	 * The Automation screen explains the missing model and locks the job buttons until it is set up.
	 */
	public function test_automation_screen_without_model(): void
	{
		update_option('twcp_model_provider', 'openai');
		delete_option('twcp_model_api_key');

		$html = twcp_template('automation', twcp_admin_data('automation'));
		$this->assertStringContainsString('Connect an AI Model', $html);
		$this->assertStringContainsString('Set Up AI Provider', $html);
		$this->assertStringContainsString('data-locked="1"', $html);
		$this->assertMatchesRegularExpression('/btn_start"\s+disabled/', $html);

		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$html = twcp_template('automation', twcp_admin_data('automation'));
		$this->assertStringContainsString('Ask an administrator', $html);
		$this->assertStringNotContainsString('Set Up AI Provider', $html);

		update_option('twcp_model_api_key', 'test-key');
		$html = twcp_template('automation', twcp_admin_data('automation'));
		$this->assertStringNotContainsString('Connect an AI Model', $html);
		$this->assertStringNotContainsString('data-locked', $html);
	}


	/* -------------------------------------------------------------------------
	 * Modes
	 * ------------------------------------------------------------------------- */

	/**
	 * Text jobs offer four modes, other jobs only fill or redo, and old override states map to "all".
	 */
	public function test_modes(): void
	{
		$this->assertSame(['fill', 'refine', 'poor', 'all'], array_keys(twcp_batch_modes('post_excerpt')));
		$this->assertSame(['fill', 'all'], array_keys(twcp_batch_modes('categories')));
		$this->assertSame('fill', twcp_batch_mode('categories', 'refine'));
		$this->assertSame('poor', twcp_batch_mode('alts', 'poor'));

		update_option('twcp_batch_seo', ['override' => true]);
		$state = twcp_batch_state('seo');
		$this->assertSame('all', $state['mode']);
		$this->assertTrue($state['override']);

		$state = twcp_batch_restart('seo', 'refine', 'post');
		$this->assertSame('refine', twcp_batch_state('seo')['mode']);
		$this->assertSame('post', $state['post_type']);
	}

	/**
	 * The note about the current value follows the mode of the running job.
	 */
	public function test_rewrite_note(): void
	{
		$this->assertStringContainsString('Review and improve it', twcp_rewrite_note('excerpt', '"Old"'));

		twcp_rewrite_mode('refine');
		$this->assertStringContainsString('Refine the current excerpt', twcp_rewrite_note('excerpt', '"Old"'));
		twcp_rewrite_mode('all');
		$this->assertStringContainsString('from scratch', twcp_rewrite_note('excerpt', '"Old"'));
		twcp_rewrite_mode('');
	}

	/**
	 * "Rewrite if poor" checks the existing values first and rewrites only the poor ones.
	 */
	public function test_poor_mode_rewrites_poor_values(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');

		$good = self::factory()->post->create(['post_excerpt' => 'A clear summary of how to pick a leather strap.']);
		$bad = self::factory()->post->create(['post_excerpt' => 'asdf']);

		$replies = [
			['choices' => [['message' => ['content' => wp_json_encode([(string) $good => false, (string) $bad => true])]]]],
			['choices' => [['message' => ['content' => 'A fresh excerpt.']]]],
		];
		$prompts = [];
		add_filter('pre_http_request', function($response, $args) use (&$replies, &$prompts) {
			$messages = json_decode($args['body'], true)['messages'];
			$prompts[] = end($messages)['content'];

			return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode(array_shift($replies)), 'cookies' => []];
		}, 10, 2);

		$state = twcp_batch_restart('post_excerpt', 'poor', 'post');
		$state['status'] = 'running';
		twcp_batch_save('post_excerpt', $state);
		$state = twcp_batch_slice('post_excerpt');

		$this->assertCount(2, $prompts);
		$this->assertStringContainsString('asdf', $prompts[0]);
		$this->assertStringContainsString('rated poor quality', $prompts[1]);
		$this->assertSame('A fresh excerpt.', get_post_field('post_excerpt', $bad));
		$this->assertSame('A clear summary of how to pick a leather strap.', get_post_field('post_excerpt', $good));
		$this->assertSame(2, $state['success']);
		$this->assertStringContainsString('looks good', implode("\n", array_column($state['logs'], 'message')));
		$this->assertSame('', twcp_rewrite_mode());

		remove_all_filters('pre_http_request');
	}

	/* -------------------------------------------------------------------------
	 * Undo
	 * ------------------------------------------------------------------------- */

	/**
	 * A run backs up the values it changes, and Undo puts them back.
	 */
	public function test_undo_restores_previous_values(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$this->mock_model(['choices' => [['message' => ['content' => 'A fresh excerpt.']]]]);

		$post = self::factory()->post->create(['post_excerpt' => 'The old excerpt.']);

		$state = twcp_batch_restart('post_excerpt', 'all', 'post');
		$state['status'] = 'running';
		twcp_batch_save('post_excerpt', $state);
		twcp_batch_slice('post_excerpt');

		$this->assertSame('A fresh excerpt.', get_post_field('post_excerpt', $post));
		$this->assertArrayHasKey($post, twcp_batch_backup('post_excerpt'));
		$this->assertGreaterThan(0, twcp_batch_state('post_excerpt')['undo']);

		// A second slice keeps the value from before the first change
		$state = twcp_batch_restart('post_excerpt', 'all', 'post');
		$state['status'] = 'running';
		twcp_batch_save('post_excerpt', $state);
		twcp_batch_slice('post_excerpt');
		$this->assertSame(['post:post_excerpt' => 'The old excerpt.'], twcp_batch_backup('post_excerpt')[$post]);

		$this->assertGreaterThan(0, twcp_batch_undo('post_excerpt'));
		$this->assertSame('The old excerpt.', get_post_field('post_excerpt', $post));
		$this->assertSame([], twcp_batch_backup('post_excerpt'));
		$this->assertSame(0, twcp_batch_state('post_excerpt')['undo']);

		remove_all_filters('pre_http_request');
	}

	/**
	 * A slice that is still running makes the next request wait, and the lock is freed when the slice ends.
	 */
	public function test_slice_lock(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model(['choices' => [['message' => ['content' => 'A fresh excerpt.']]]]);
		$post = self::factory()->post->create(['post_excerpt' => '']);

		$state = twcp_batch_restart('post_excerpt', 'fill', 'post');
		$state['status'] = 'running';
		twcp_batch_save('post_excerpt', $state);

		update_option('twcp_batch_lock_post_excerpt', time(), false);
		$state = twcp_batch_slice('post_excerpt');
		$this->assertTrue($state['busy']);
		$this->assertSame('running', $state['status']);
		$this->assertCount(0, $requests);

		// A lock older than a slice can take is left by a crash and is taken over
		update_option('twcp_batch_lock_post_excerpt', time() - HOUR_IN_SECONDS, false);
		$state = twcp_batch_slice('post_excerpt');
		$this->assertArrayNotHasKey('busy', $state);
		$this->assertSame('A fresh excerpt.', get_post_field('post_excerpt', $post));
		$this->assertFalse(get_option('twcp_batch_lock_post_excerpt'));

		remove_all_filters('pre_http_request');
	}

	/**
	 * Stop pressed during a slice ends it after the current item, and every item is saved as it finishes.
	 */
	public function test_stop_during_slice(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$this->mock_model(['choices' => [['message' => ['content' => 'A fresh excerpt.']]]]);
		self::factory()->post->create_many(3, ['post_excerpt' => '']);

		$state = twcp_batch_restart('post_excerpt', 'fill', 'post');
		$state['status'] = 'running';
		twcp_batch_save('post_excerpt', $state);

		// Another request saves the paused state while the first item is written
		add_filter('pre_http_request', function($response) {
			$saved = get_option('twcp_batch_post_excerpt');
			$saved['status'] = 'paused';
			update_option('twcp_batch_post_excerpt', $saved, false);

			return $response;
		}, 5);

		$state = twcp_batch_slice('post_excerpt');
		$this->assertSame('paused', $state['status']);
		$this->assertSame(1, $state['processed']);
		$this->assertSame('paused', twcp_batch_state('post_excerpt')['status']);
		$this->assertSame(1, twcp_batch_state('post_excerpt')['processed']);

		remove_all_filters('pre_http_request');
	}

	/**
	 * Stop pressed during the last item completes the job instead of leaving nothing to resume.
	 */
	public function test_stop_on_last_item_completes(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$this->mock_model(['choices' => [['message' => ['content' => 'A fresh excerpt.']]]]);
		self::factory()->post->create(['post_excerpt' => '']);

		$state = twcp_batch_restart('post_excerpt', 'fill', 'post');
		$state['status'] = 'running';
		twcp_batch_save('post_excerpt', $state);

		add_filter('pre_http_request', function($response) {
			$saved = get_option('twcp_batch_post_excerpt');
			$saved['status'] = 'paused';
			update_option('twcp_batch_post_excerpt', $saved, false);

			return $response;
		}, 5);

		$this->assertSame('completed', twcp_batch_slice('post_excerpt')['status']);

		remove_all_filters('pre_http_request');
	}

	/**
	 * Bulk generate actions count items that already have the value as skipped, not as processed.
	 */
	public function test_bulk_counts_skipped_items(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model(['choices' => [['message' => ['content' => 'A fresh excerpt.']]]]);
		$empty = self::factory()->post->create(['post_excerpt' => '']);
		$filled = self::factory()->post->create(['post_excerpt' => 'Already written.']);

		remove_all_filters('handle_bulk_actions-edit-post');
		twcp_batch_bulk();
		wp_parse_str((string) wp_parse_url(apply_filters('handle_bulk_actions-edit-post', 'https://example.com/', 'twcp_excerpt', [$empty, $filled]), PHP_URL_QUERY), $args);

		$this->assertSame(['twcp_processed' => '1', 'twcp_skipped' => '1', 'twcp_selected' => '2'], $args);
		$this->assertCount(1, $requests);
		$this->assertSame('Already written.', get_post_field('post_excerpt', $filled));

		remove_all_filters('pre_http_request');
		delete_option('twcp_model_api_key');
	}

	/**
	 * Terms with several meta rows are counted once, so the total matches the terms a run goes through.
	 */
	public function test_term_total_counts_each_term_once(): void
	{
		foreach (self::factory()->category->create_many(3) as $term_id) {
			add_term_meta($term_id, 'color', 'red');
			add_term_meta($term_id, 'icon', 'star');
			add_term_meta($term_id, 'order', '1');
		}

		$ids = twcp_batch_query('term_seo', 0, 100, false, 'category');
		$this->assertGreaterThanOrEqual(3, count($ids));
		$this->assertSame(count($ids), twcp_batch_total('term_seo', false, 'category'));
	}

	/**
	 * Changing the mode or scope keeps the Undo button for the last run.
	 */
	public function test_restart_keeps_undo_count(): void
	{
		$post = self::factory()->post->create();
		update_option('twcp_batch_undo_post_excerpt', [$post => ['post:post_excerpt' => '']], false);

		$this->assertSame(1, twcp_batch_restart('post_excerpt', 'refine', 'post')['undo']);
	}

	/**
	 * Block layouts are described a slice at a time, so a long list never runs in one request.
	 */
	public function test_blocks_run_in_slices(): void
	{
		if (!function_exists('acf_add_local_field_group')) {
			$this->markTestSkipped('ACF is not available. Set ACF_DIR to run block layout tests.');
		}

		acf_add_local_field_group([
			'key'      => 'group_twcp_blocks',
			'title'    => 'Blocks',
			'fields'   => [[
				'key'     => 'field_twcp_blocks',
				'name'    => 'blocks',
				'label'   => 'Blocks',
				'type'    => 'flexible_content',
				'layouts' => [
					'layout_a' => ['key' => 'layout_a', 'name' => 'alpha', 'label' => 'Alpha', 'sub_fields' => []],
					'layout_b' => ['key' => 'layout_b', 'name' => 'beta', 'label' => 'Beta', 'sub_fields' => []],
					'layout_c' => ['key' => 'layout_c', 'name' => 'gamma', 'label' => 'Gamma', 'sub_fields' => []],
				],
			]],
			'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
		]);
		update_option('twcp_blocks_field', 'blocks');
		update_option('twcp_block_descriptions', ['beta' => 'Already described.']);
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model(['choices' => [['message' => ['content' => 'A described layout.']]]]);

		$this->assertSame([0, 2], twcp_batch_query('blocks', 0, 5));
		$this->assertSame(2, twcp_batch_total('blocks'));

		$state = twcp_batch_restart('blocks', 'fill', 'all');
		$state['status'] = 'running';
		twcp_batch_save('blocks', $state);
		$state = twcp_batch_slice('blocks');

		$this->assertSame(2, $state['processed']);
		$this->assertCount(2, $requests);
		$saved = twcp_block_saved_descriptions();
		$this->assertSame('A described layout.', $saved['alpha']);
		$this->assertSame('Already described.', $saved['beta']);
		$this->assertSame('A described layout.', $saved['gamma']);

		remove_all_filters('pre_http_request');
	}

	/**
	 * Meta that did not exist before a run is removed again, and term SEO restores Rank Math and Yoast values.
	 */
	public function test_snapshot_restores_missing_meta_and_terms(): void
	{
		$post = self::factory()->post->create();
		$snapshot = twcp_batch_snapshot('seo', $post);
		$this->assertNull($snapshot['meta:rank_math_title']);

		update_post_meta($post, 'rank_math_title', 'New title');
		twcp_batch_restore_item('seo', $post, $snapshot);
		$this->assertFalse(metadata_exists('post', $post, 'rank_math_title'));

		$term = self::factory()->category->create();
		update_term_meta($term, 'rank_math_title', 'Old title');
		update_option('wpseo_taxonomy_meta', ['category' => [$term => ['wpseo_title' => 'Old Yoast']]]);
		$snapshot = twcp_batch_snapshot('term_seo', $term);

		update_term_meta($term, 'rank_math_title', 'New title');
		update_option('wpseo_taxonomy_meta', ['category' => [$term => ['wpseo_title' => 'New Yoast']]]);
		twcp_batch_restore_item('term_seo', $term, $snapshot);

		$this->assertSame('Old title', get_term_meta($term, 'rank_math_title', true));
		$this->assertSame('Old Yoast', get_option('wpseo_taxonomy_meta')['category'][$term]['wpseo_title']);
	}


	/* -------------------------------------------------------------------------
	 * Background runs
	 * ------------------------------------------------------------------------- */

	/**
	 * A background slice runs as the user who started the job and queues the next slice.
	 */
	public function test_background_slice_runs_and_requeues(): void
	{
		$this->require_woocommerce();

		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$this->mock_model(['choices' => [['message' => ['content' => 'A fresh excerpt.']]]]);
		self::factory()->post->create_many(6, ['post_excerpt' => '']);

		$queued = [];
		add_filter('pre_as_enqueue_async_action', function($pre, $hook, $args) use (&$queued) {
			$queued[] = [$hook, $args];

			return 1;
		}, 10, 3);

		$state = twcp_batch_restart('post_excerpt', 'fill', 'post');
		$state['status'] = 'running';
		$state['user'] = self::$admin_id;
		$state['background'] = true;
		twcp_batch_save('post_excerpt', $state);

		wp_set_current_user(0);
		twcp_batch_run_background('post_excerpt');

		$state = twcp_batch_state('post_excerpt');
		$this->assertSame(self::$admin_id, get_current_user_id());
		$this->assertSame(5, $state['success']);
		$this->assertSame('running', $state['status']);
		$this->assertSame([['twcp_batch_run', ['type' => 'post_excerpt']]], $queued);

		remove_all_filters('pre_as_enqueue_async_action');
		remove_all_filters('pre_http_request');
	}

	/**
	 * A background job pauses instead of failing every item when the model is gone.
	 */
	public function test_background_pauses_without_model(): void
	{
		update_option('twcp_model_provider', 'openai');
		delete_option('twcp_model_api_key');
		self::factory()->post->create(['post_excerpt' => '']);

		$state = twcp_batch_restart('post_excerpt', 'fill', 'post');
		$state['status'] = 'running';
		$state['user'] = self::$admin_id;
		twcp_batch_save('post_excerpt', $state);

		twcp_batch_run_background('post_excerpt');

		$state = twcp_batch_state('post_excerpt');
		$this->assertSame('paused', $state['status']);
		$this->assertSame(0, $state['processed']);
		$this->assertStringContainsString('The job was paused', end($state['logs'])['message']);
	}


	/* -------------------------------------------------------------------------
	 * Cards and defaults
	 * ------------------------------------------------------------------------- */

	/**
	 * Cards carry the request and token figures the estimate is built from.
	 */
	public function test_cards_have_estimates(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');

		$html = twcp_template('automation', twcp_admin_data('automation'));
		$this->assertMatchesRegularExpression('/data-type="post_content" data-calls="\d+" data-tokens="\d+" data-slice="3"/', $html);
		$this->assertStringContainsString('batch_estimate', $html);
		$this->assertStringContainsString('btn_undo', $html);
	}

	/**
	 * The default store context and prompts describe the current site rather than a sample store.
	 */
	public function test_generic_defaults(): void
	{
		update_option('blogname', 'Test Garden Shop');

		$context = twcp_general_context_default();
		$this->assertStringContainsString('Test Garden Shop', $context);

		$sources = implode("\n", array_map('file_get_contents', glob(dirname(__DIR__, 2) . '/plugin/{includes,includes/tools,parts}/*.php', GLOB_BRACE)));
		$this->assertStringNotContainsString('Ele Straps', $sources);
		$this->assertStringNotContainsString('StrapsCo', $sources);
	}


	/* -------------------------------------------------------------------------
	 * ACF term fields
	 * ------------------------------------------------------------------------- */

	/**
	 * Register term fields for the full description and FAQ jobs, or skip without ACF.
	 */
	private function add_term_fields(): void
	{
		if (!function_exists('acf_add_local_field_group')) {
			$this->markTestSkipped('ACF is not available. Set ACF_DIR to run term field tests.');
		}

		acf_add_local_field_group([
			'key'      => 'group_twcp_terms',
			'title'    => 'Term Fields',
			'fields'   => [
				['key' => 'field_twcp_long', 'name' => 'long_text', 'label' => 'Bottom Description', 'type' => 'wysiwyg'],
				['key' => 'field_twcp_color', 'name' => 'color', 'label' => 'Color', 'type' => 'color_picker'],
				['key' => 'field_twcp_faq', 'name' => 'faq', 'label' => 'FAQ', 'type' => 'repeater', 'sub_fields' => [
					['key' => 'field_twcp_faq_q', 'name' => 'title', 'label' => 'Question', 'type' => 'text'],
					['key' => 'field_twcp_faq_a', 'name' => 'text', 'label' => 'Answer', 'type' => 'wysiwyg'],
				]],
			],
			'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
		]);
	}

	/**
	 * Term fields are found per taxonomy, and the jobs only count terms of those taxonomies with an empty field.
	 */
	public function test_term_acf_fields_and_query(): void
	{
		$this->add_term_fields();

		$content = twcp_term_acf_fields('content');
		$this->assertSame(['category'], $content['field_twcp_long']['taxonomies']);
		$this->assertArrayNotHasKey('field_twcp_color', $content);
		$faq = twcp_term_acf_fields('faq')['field_twcp_faq'];
		$this->assertSame(['field_twcp_faq_q', 'field_twcp_faq_a', 'wysiwyg'], [$faq['question'], $faq['answer'], $faq['type']]);

		$this->assertArrayHasKey('term_content', twcp_batch_types());
		$this->assertSame('field_twcp_long', twcp_batch_state('term_content')['field']);
		$this->assertSame([], twcp_batch_term_args(false, 'post_tag', 'term_content'));

		$before = twcp_batch_total('term_content', false, 'category');
		$term = self::factory()->category->create();
		$this->assertSame($before + 1, twcp_batch_total('term_content', false, 'category'));
		update_field('field_twcp_long', '<p>Filled.</p>', 'term_' . $term);
		$this->assertSame($before, twcp_batch_total('term_content', false, 'category'));

		$html = twcp_template('automation', twcp_admin_data('automation'));
		$this->assertMatchesRegularExpression('/<option value="field_twcp_long"\s+selected=\'selected\'>Bottom Description \(Categories\)/', $html);
	}

	/**
	 * The full description job writes HTML into the chosen field, and Undo empties it again.
	 */
	public function test_term_content_slice_and_undo(): void
	{
		$this->add_term_fields();
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model(['choices' => [['message' => ['content' => '<h2>Choosing Tools</h2><p>Text.</p><script>x</script>']]]]);

		$term = self::factory()->category->create(['name' => 'Garden Tools', 'description' => 'Hand tools for the garden.']);
		self::factory()->post->create(['post_title' => 'Sharpening Pruners', 'post_category' => [$term]]);

		$state = twcp_batch_restart('term_content', 'fill', 'category');
		$state['status'] = 'running';
		twcp_batch_save('term_content', $state);
		twcp_batch_slice('term_content');

		$prompts = array_map(fn(array $request) => end($request['body']['messages'])['content'], (array) $requests);
		$prompt = implode("
", array_filter($prompts, fn(string $text) => str_contains($text, 'Term Name: Garden Tools')));
		$this->assertStringContainsString('Term Name: Garden Tools', $prompt);
		$this->assertStringContainsString('Short Description: Hand tools for the garden.', $prompt);
		$this->assertStringContainsString('Sharpening Pruners', $prompt);
		$this->assertSame('<h2>Choosing Tools</h2><p>Text.</p>x', get_field('field_twcp_long', 'term_' . $term, false));

		twcp_batch_undo('term_content');
		$this->assertEmpty(get_field('field_twcp_long', 'term_' . $term, false));

		remove_all_filters('pre_http_request');
	}

	/**
	 * The FAQ job fills the repeater rows from the model's JSON.
	 */
	public function test_term_faq_generation(): void
	{
		$this->add_term_fields();
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$faq = [['question' => 'Which pruner fits small hands?', 'answer' => '<p>A compact bypass pruner.</p>'], ['question' => 'How often to sharpen?', 'answer' => 'Every season.'], ['question' => '', 'answer' => 'Dropped.']];
		$this->mock_model(['choices' => [['message' => ['content' => wp_json_encode($faq)]]]]);

		$term = self::factory()->category->create();
		$this->assertTrue(twcp_term_generate_faq($term, 'field_twcp_faq'));

		$rows = get_field('field_twcp_faq', 'term_' . $term);
		$this->assertCount(2, $rows);
		$this->assertSame('Which pruner fits small hands?', $rows[0]['title']);
		$this->assertStringContainsString('compact bypass pruner', $rows[0]['text']);

		// Existing rows are kept unless the job rewrites them, and other taxonomies are refused
		$this->assertTrue(twcp_term_generate_faq($term, 'field_twcp_faq'));
		$this->assertFalse(twcp_term_generate_faq(self::factory()->tag->create(), 'field_twcp_faq'));

		remove_all_filters('pre_http_request');
	}
}
