<?php

/**
 * Post query, create, edit, trash, and schema tool tests
 */
class PostTest extends TWCP_TestCase {

	/**
	 * Queries filter by type, search, and term, and honor the sort order.
	 */
	public function test_get_posts(): void
	{
		$cat = self::factory()->category->create(['name' => 'News']);
		$old = self::factory()->post->create(['post_title' => 'Old apple', 'post_date' => '2024-01-01 10:00:00', 'post_category' => [$cat]]);
		$new = self::factory()->post->create(['post_title' => 'New apple', 'post_date' => '2025-01-01 10:00:00']);
		self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Apple page']);

		$result = $this->call('get_posts', ['post_type' => 'post', 'search' => 'apple', 'order' => 'asc']);
		$this->assertSame(2, $result['total']);
		$this->assertSame([$old, $new], array_column($result['posts'], 'id'));
		$this->assertArrayNotHasKey('content', $result['posts'][0], 'Lists omit full content');

		$desc = $this->call('get_posts', ['post_type' => 'post', 'search' => 'apple']);
		$this->assertSame([$new, $old], array_column($desc['posts'], 'id'));

		$by_term = $this->call('get_posts', ['post_type' => 'post', 'taxonomy' => 'category', 'term_id' => $cat]);
		$this->assertSame([$old], array_column($by_term['posts'], 'id'));

		$single = $this->call('get_posts', ['id' => $old]);
		$this->assertSame('News', $single['terms']['category'][0]['name']);
		$this->assertArrayHasKey('content', $single);

		$this->assertStringContainsString('not found', $this->call('get_posts', ['id' => 999999])['error']);
	}

	/**
	 * Creating a post saves taxonomies, thumbnail, SEO, and meta.
	 */
	public function test_create_post(): void
	{
		$tag = self::factory()->tag->create();
		$image = $this->create_image(400, 300);

		$result = $this->call('create_post', [
			'title'           => 'Created',
			'content'         => '<p>Body</p><script>bad()</script>',
			'post_status'     => 'draft',
			'thumbnail_id'    => $image,
			'taxonomies'      => ['post_tag' => [$tag]],
			'seo_title'       => 'SEO title',
			'seo_description' => 'SEO description',
			'meta'            => ['custom_key' => 'value'],
		]);

		$this->assertTrue($result['success']);
		$post = get_post($result['id']);
		$this->assertSame('draft', $post->post_status);
		$this->assertStringNotContainsString('<script>', $post->post_content);
		$this->assertSame($image, (int) get_post_thumbnail_id($post));
		$this->assertSame([$tag], wp_get_post_terms($post->ID, 'post_tag', ['fields' => 'ids']));
		$this->assertSame('SEO title', get_post_meta($post->ID, 'rank_math_title', true));
		$this->assertSame('SEO description', get_post_meta($post->ID, '_yoast_wpseo_metadesc', true));
		$this->assertSame('value', get_post_meta($post->ID, 'custom_key', true));
	}

	/**
	 * Invalid taxonomies, terms, dates, and protected meta are rejected.
	 */
	public function test_create_post_validation(): void
	{
		$this->assertStringContainsString('not registered for post', $this->call('create_post', ['title' => 'X', 'taxonomies' => ['product_cat' => [1]]])['error']);
		$this->assertStringContainsString('does not exist', $this->call('create_post', ['title' => 'X', 'taxonomies' => ['category' => [999999]]])['error']);
		$this->assertStringContainsString('future date', $this->call('create_post', ['title' => 'X', 'post_status' => 'future'])['error']);
		$this->assertStringContainsString('YYYY-MM-DD', $this->call('create_post', ['title' => 'X', 'date' => 'tomorrow'])['error']);
		$this->assertStringContainsString('Protected meta', $this->call('create_post', ['title' => 'X', 'meta' => ['_edit_lock' => '1']])['error']);
		$this->assertArrayHasKey('error', $this->call('create_post', ['title' => 'X', 'post_type' => 'nope']));
	}

	/**
	 * Editing changes only the supplied fields.
	 */
	public function test_edit_post(): void
	{
		$post_id = self::factory()->post->create(['post_title' => 'Before', 'post_content' => 'Keep me']);

		$result = $this->call('edit_post', ['id' => $post_id, 'title' => 'After']);
		$this->assertTrue($result['success']);
		$this->assertSame('After', get_the_title($post_id));
		$this->assertSame('Keep me', get_post_field('post_content', $post_id));

		$this->call('edit_post', ['id' => $post_id, 'thumbnail_id' => 0]);
		$this->assertFalse(has_post_thumbnail($post_id));
	}

	/**
	 * Trash is recoverable and refused for attachments.
	 */
	public function test_trash_post(): void
	{
		$post_id = self::factory()->post->create();

		$this->assertSame('trash', $this->call('trash_post', ['id' => $post_id])['status']);
		$this->assertSame('trash', get_post_status($post_id));

		$image = $this->create_image(100, 100);
		$this->assertArrayHasKey('error', $this->call('trash_post', ['id' => $image]));
	}

	/**
	 * Focus keywords come from Rank Math, or else Yoast SEO, for posts and terms; generators get them in their
	 * prompts, and get_terms returns them.
	 */
	public function test_focus_keywords(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model($this->model_reply('A fresh excerpt.'));

		$post = self::factory()->post->create(['post_excerpt' => '']);
		$this->assertSame('', twcp_focus_note($post));
		update_post_meta($post, '_yoast_wpseo_focuskw', 'nato straps');
		$this->assertSame('nato straps', twcp_focus_keywords($post));
		update_post_meta($post, 'rank_math_focus_keyword', 'leather straps,watch bands');

		$this->assertTrue(twcp_post_generate_excerpt($post));
		$this->assertStringContainsString('Focus Keywords: leather straps,watch bands (the first is the main one', $this->model_prompt($requests[0]['body']));

		$term = self::factory()->category->create(['name' => 'Strap Guides']);
		update_option('wpseo_taxonomy_meta', ['category' => [$term => ['wpseo_focuskw' => 'strap guide']]]);
		$this->assertSame('strap guide', twcp_focus_keywords($term, 'term'));
		update_term_meta($term, 'rank_math_focus_keyword', 'leather strap guide');
		$found = $this->call('get_terms', ['taxonomy' => 'category', 'search' => 'Strap Guides']);
		$this->assertSame('leather strap guide', $found['terms'][0]['seo']['focus_keyword']);

		remove_all_filters('pre_http_request');
	}

	/**
	 * The schema lists post types and taxonomies with access flags, and the site context with its target keywords.
	 */
	public function test_get_schema(): void
	{
		update_option('twcp_target_keywords', "leather watch straps\r\n\r\nnato straps\n");
		$schema = $this->call('get_schema');
		$this->assertSame(['leather watch straps', 'nato straps'], $schema['target_keywords']);

		$this->assertTrue($schema['post_types']['post']['can_create']);
		$this->assertContains('category', $schema['post_types']['post']['taxonomies']);
		$this->assertTrue($schema['taxonomies']['category']['can_assign']);

		$this->assertArrayNotHasKey('taxonomies', $this->call('get_schema', ['type' => 'post_types']));
	}

	/**
	 * Generated content gets the post's terms and related posts as approved links, and the settings choose the sources.
	 */
	public function test_post_links_note(): void
	{
		$category = self::factory()->category->create(['name' => 'Garden Tools']);
		$related = self::factory()->post->create(['post_title' => 'Pruning Basics', 'post_category' => [$category]]);
		$other = self::factory()->post->create(['post_title' => 'Unrelated Recipe']);
		$post = self::factory()->post->create(['post_category' => [$category]]);

		$links = twcp_post_links($post);
		$this->assertStringContainsString('Internal links: add 1 to 4 links', $links['note']);
		$this->assertStringContainsString(get_term_link($category), $links['note']);
		$this->assertStringContainsString(get_permalink($related), $links['note']);
		$this->assertStringNotContainsString(get_permalink($other), $links['note']);
		$this->assertStringNotContainsString(get_permalink($post), $links['note']);
		$this->assertSame(['get_products', 'get_terms', 'get_posts'], $links['read_tools'], 'Lookups are offered for facts even without lookup links');

		update_option('twcp_post_links', ['max' => 2, 'sources' => ['posts', 'lookup']]);
		$links = twcp_post_links($post);
		$this->assertStringContainsString('add 1 to 2 links', $links['note']);
		$this->assertStringNotContainsString(get_term_link($category), $links['note']);

		update_option('twcp_post_links', ['max' => 0, 'sources' => ['terms', 'posts']]);
		$this->assertSame("\nDo not add links.\n", twcp_post_links($post)['note']);
	}

	/**
	 * Links the model invents are unwrapped, while approved, existing, in-page, and looked-up site links stay.
	 */
	public function test_post_links_guard(): void
	{
		$category = self::factory()->category->create();
		$found = self::factory()->post->create();
		$post = self::factory()->post->create(['post_category' => [$category], 'post_content' => '<a href="https://partner.example/">Partner</a>']);

		$html = '<p><a href="' . get_term_link($category) . '">Approved</a> <a href="https://partner.example/">Existing</a> '
			. '<a href="#faq">Anchor</a> <a href="' . home_url('/made-up-page/') . '">Invented</a> <a href="https://elsewhere.example/x">External</a> '
			. '<a href="' . get_permalink($found) . '">Found</a></p>';

		$checked = twcp_post_links($post, $html);
		$this->assertStringContainsString('">Approved</a>', $checked);
		$this->assertStringContainsString('">Existing</a>', $checked);
		$this->assertStringContainsString('">Anchor</a>', $checked);
		$this->assertStringContainsString(' Invented ', $checked);
		$this->assertStringNotContainsString('elsewhere.example', $checked);
		$this->assertStringNotContainsString('">Found</a>', $checked);

		// With lookups on, links to real posts on the site are kept
		update_option('twcp_post_links', ['max' => 4, 'sources' => ['terms', 'lookup']]);
		$this->assertStringContainsString('">Found</a>', twcp_post_links($post, $html));
	}

	/**
	 * The content generator sends the approved links and saves the checked HTML.
	 */
	public function test_generate_content_links(): void
	{
		update_option('twcp_model_provider', 'openai');
		update_option('twcp_model_api_key', 'test-key');
		$category = self::factory()->category->create();
		$post = self::factory()->post->create(['post_content' => '', 'post_category' => [$category]]);

		$requests = $this->mock_model($this->model_reply('<h2>Care</h2><p>See <a href="' . get_term_link($category) . '">the guide</a> and <a href="https://fake.example/">this</a>.</p>'));
		$this->assertTrue(twcp_post_generate_content($post, true));

		$this->assertStringContainsString(get_term_link($category), $this->model_prompt($requests[0]['body']));
		$content = get_post_field('post_content', $post);
		$this->assertStringContainsString('">the guide</a>', $content);
		$this->assertStringNotContainsString('fake.example', $content);

		remove_all_filters('pre_http_request');
	}

	/**
	 * Editors can save the link settings, which are cleaned on save.
	 */
	public function test_post_links_setting(): void
	{
		twcp_admin_init();
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->assertSame('edit_others_posts', apply_filters('option_page_capability_twcp_content_group', 'manage_options'));

		update_option('twcp_post_links', ['max' => 99, 'sources' => ['posts', 'bogus']]);
		$this->assertSame(['max' => 15, 'sources' => ['posts']], get_option('twcp_post_links'));

		$html = twcp_template('automation', twcp_admin_data('automation'));
		$this->assertStringContainsString('Internal Links', $html);
		$this->assertStringContainsString('Up to 15 links', $html);
		$this->assertMatchesRegularExpression('/value="posts"\s+checked/', $html);
	}
}
