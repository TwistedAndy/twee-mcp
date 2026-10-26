<?php

/**
 * Taxonomy term query, create, edit, and assignment tool tests
 */
class TermTest extends TWCP_TestCase {

	/**
	 * Terms paginate and default to all public taxonomies.
	 */
	public function test_get_terms(): void
	{
		foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
			self::factory()->category->create(['name' => $name]);
		}
		self::factory()->tag->create(['name' => 'Alpha tag']);

		$page = $this->call('get_terms', ['taxonomy' => 'category', 'search' => 'a', 'limit' => 2, 'offset' => 1]);
		$this->assertSame(2, $page['count']);

		$all = $this->call('get_terms', ['search' => 'Alpha']);
		$this->assertSame('all', $all['taxonomy']);
		$this->assertEqualsCanonicalizing(['category', 'post_tag'], array_column($all['terms'], 'taxonomy'));

		$this->assertStringContainsString('does not exist', $this->call('get_terms', ['taxonomy' => 'nope'])['error']);
	}

	/**
	 * Creating and editing terms saves SEO to term meta, not post meta.
	 */
	public function test_create_and_edit_term(): void
	{
		$created = $this->call('create_term', ['taxonomy' => 'category', 'name' => 'Guides', 'seo_title' => 'Guide SEO']);
		$term_id = $created['id'];

		$this->assertTrue($created['success']);
		$this->assertSame('Guide SEO', get_term_meta($term_id, 'rank_math_title', true));
		$this->assertSame('', get_post_meta($term_id, 'rank_math_title', true));

		$edited = $this->call('edit_term', ['term_id' => $term_id, 'taxonomy' => 'category', 'name' => 'How-to Guides', 'seo_description' => 'Desc']);
		$this->assertTrue($edited['success']);
		$this->assertSame('How-to Guides', get_term($term_id)->name);
		$this->assertSame('Desc', get_option('wpseo_taxonomy_meta')['category'][$term_id]['wpseo_desc']);
	}

	/**
	 * Assignments replace, append, and remove terms.
	 */
	public function test_assign_terms(): void
	{
		$post_id = self::factory()->post->create();
		[$a, $b, $c] = self::factory()->tag->create_many(3);

		$this->call('assign_terms', ['post_id' => $post_id, 'taxonomy' => 'post_tag', 'term_ids' => [$a, $b]]);
		$this->call('assign_terms', ['post_id' => $post_id, 'taxonomy' => 'post_tag', 'term_ids' => [$c], 'mode' => 'append']);
		$result = $this->call('assign_terms', ['post_id' => $post_id, 'taxonomy' => 'post_tag', 'term_ids' => [$a], 'mode' => 'remove']);

		$this->assertTrue($result['success']);
		$this->assertEqualsCanonicalizing([$b, $c], wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']));

		$this->call('assign_terms', ['post_id' => $post_id, 'taxonomy' => 'post_tag', 'term_ids' => []]);
		$this->assertSame([], wp_get_post_terms($post_id, 'post_tag', ['fields' => 'ids']));
	}

	/**
	 * Assignment validation checks the taxonomy, the capability, and each term.
	 */
	public function test_validate_assignments(): void
	{
		$cat = self::factory()->category->create();

		$this->assertTrue(twcp_term_validate_assignments('post', ['category' => [$cat]]));
		$this->assertWPError(twcp_term_validate_assignments('page', ['category' => [$cat]]));
		$this->assertWPError(twcp_term_validate_assignments('post', ['category' => [999999]]));
		$this->assertWPError(twcp_term_validate_assignments('post', 'category'));

		wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
		$this->assertWPError(twcp_term_validate_assignments('post', ['category' => [$cat]]));
	}
}
