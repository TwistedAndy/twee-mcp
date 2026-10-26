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

		foreach (['post_excerpt', 'post_content', 'seo', 'featured_images', 'categories', 'term_seo', 'alts'] as $type) {
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
}
