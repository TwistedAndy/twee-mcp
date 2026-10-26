<?php

/**
 * ACF block storage and description tests
 */
class BlockTest extends TWCP_TestCase {

	/**
	 * Blocks round-trip through post and term meta and keep the ACF field reference.
	 */
	public function test_block_load_and_save(): void
	{
		$post_id = self::factory()->post->create();
		$field = twcp_block_field_name();
		$blocks = [['acf_fc_layout' => 'hero', 'title' => 'Hello']];

		twcp_block_save($post_id, 'post', $blocks);
		$this->assertSame($blocks, twcp_block_load($post_id, 'post'));
		$this->assertSame(twcp_block_field_key($field), get_post_meta($post_id, '_acf_map', true)[$field]);

		$term_id = self::factory()->category->create();
		twcp_block_save($term_id, 'term', $blocks);
		$this->assertSame($blocks, twcp_block_load($term_id, 'term'));

		$this->assertSame([], twcp_block_load(self::factory()->post->create(), 'post'));
	}

	/**
	 * Block lists are summarized by layout.
	 */
	public function test_format_list(): void
	{
		$list = twcp_block_format_list([['acf_fc_layout' => 'hero'], ['acf_fc_layout' => 'cta']]);

		$this->assertSame(['hero', 'cta'], array_column($list, 'layout'));
		$this->assertSame([0, 1], array_column($list, 'index'));
		$this->assertSame([], twcp_block_format_list('not blocks'));
	}
}
