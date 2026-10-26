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
	 * The schema lists post types and taxonomies with access flags.
	 */
	public function test_get_schema(): void
	{
		$schema = $this->call('get_schema');

		$this->assertTrue($schema['post_types']['post']['can_create']);
		$this->assertContains('category', $schema['post_types']['post']['taxonomies']);
		$this->assertTrue($schema['taxonomies']['category']['can_assign']);

		$this->assertArrayNotHasKey('taxonomies', $this->call('get_schema', ['type' => 'post_types']));
	}
}
