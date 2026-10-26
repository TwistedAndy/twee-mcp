<?php

/**
 * Media Library, preview, gallery, and image assignment tool tests
 */
class MediaTest extends TWCP_TestCase {

	/**
	 * Lists are compact; details and single images include sizes and the tag.
	 */
	public function test_get_images(): void
	{
		$id = $this->create_image(1200, 800, 0, 'searchable-photo');
		update_post_meta($id, '_wp_attachment_image_alt', 'Brown leather strap');

		$list = $this->call('get_images', ['search' => 'leather']);
		$this->assertSame(1, $list['total']);
		$entry = $list['images'][0];
		$this->assertSame($id, $entry['id']);
		$this->assertSame('landscape', $entry['original_size']['orientation']);
		$this->assertArrayNotHasKey('sizes', $entry);
		$this->assertArrayNotHasKey('tag', $entry);

		$details = $this->call('get_images', ['search' => 'searchable', 'details' => true]);
		$this->assertArrayHasKey('medium', $details['images'][0]['sizes']);

		$single = $this->call('get_images', ['id' => $id, 'size' => 'medium']);
		$this->assertStringContainsString('<img', $single['image']['tag']);

		$this->assertArrayHasKey('error', $this->call('get_images', ['id' => self::factory()->post->create()]));
	}

	/**
	 * Media metadata edits update only supplied fields.
	 */
	public function test_edit_media(): void
	{
		$id = $this->create_image(200, 200);

		$result = $this->call('edit_media', ['attachment_id' => $id, 'alt' => 'New alt', 'caption' => 'Caption']);
		$this->assertTrue($result['success']);
		$this->assertSame('New alt', get_post_meta($id, '_wp_attachment_image_alt', true));
		$this->assertSame('Caption', get_post_field('post_excerpt', $id));

		$this->assertArrayHasKey('error', $this->call('edit_media', ['attachment_id' => self::factory()->post->create(), 'alt' => 'x']));
	}

	/**
	 * Previews gather post images and stay small.
	 */
	public function test_preview_images(): void
	{
		$post_id = self::factory()->post->create();
		$featured = $this->create_image(1600, 900);
		$inline = $this->create_image(900, 1600);
		set_post_thumbnail($post_id, $featured);
		wp_update_post(['ID' => $post_id, 'post_content' => '<img class="wp-image-' . $inline . '" src="x.jpg">']);

		$result = $this->call('preview_images', ['post_id' => $post_id]);
		$this->assertSame([$featured, $inline], array_column($result['images'], 'id'));
		$this->assertSame([1, 2], array_column($result['images'], 'preview'));
		$this->assertCount(2, $result['_mcp_images']);

		$image = imagecreatefromstring(base64_decode($result['_mcp_images'][1]['data']));
		$this->assertSame(256, imagesy($image), 'Portrait previews fit the longest edge');

		$medium = $this->call('preview_images', ['attachment_ids' => [$featured], 'size' => 'medium']);
		$image = imagecreatefromstring(base64_decode($medium['_mcp_images'][0]['data']));
		$this->assertLessThanOrEqual(512, imagesx($image));

		$this->assertArrayHasKey('error', $this->call('preview_images', []));
		$this->assertNull($this->call('preview_images', ['attachment_ids' => [self::factory()->post->create()]])['images'][0]['preview']);
	}

	/**
	 * Gallery shortcodes validate their images, size, and link mode.
	 */
	public function test_build_gallery_shortcode(): void
	{
		$a = $this->create_image(100, 100);
		$b = $this->create_image(100, 100);

		$result = $this->call('build_gallery_shortcode', ['attachment_ids' => [$a, $b, $a], 'columns' => 2]);
		$this->assertSame('[gallery ids="' . $a . ',' . $b . '" columns="2" size="large" link="file"]', $result['shortcode']);

		$this->assertArrayHasKey('error', $this->call('build_gallery_shortcode', ['attachment_ids' => [$a], 'size' => 'giant']));
		$this->assertArrayHasKey('error', $this->call('build_gallery_shortcode', ['attachment_ids' => [self::factory()->post->create()]]));
	}

	/**
	 * Images can be assigned as a post thumbnail or a term thumbnail.
	 */
	public function test_assign_media_image(): void
	{
		$post_id = self::factory()->post->create();
		$term_id = self::factory()->category->create();
		$image = $this->create_image(300, 300);

		$this->call('assign_media_image', ['attachment_id' => $image, 'type' => 'post', 'id' => $post_id]);
		$this->assertSame($image, (int) get_post_thumbnail_id($post_id));

		$this->call('assign_media_image', ['attachment_id' => $image, 'type' => 'term', 'id' => $term_id, 'taxonomy' => 'category']);
		$this->assertSame($image, (int) get_term_meta($term_id, 'thumbnail_id', true));
	}

	/**
	 * The associated post is found through the parent or the featured image.
	 */
	public function test_associated_post(): void
	{
		$parent = self::factory()->post->create();
		$attached = $this->create_image(100, 100, $parent);
		$this->assertSame($parent, twcp_media_associated_post($attached)->ID);

		$featured_on = self::factory()->post->create();
		$loose = $this->create_image(100, 100);
		set_post_thumbnail($featured_on, $loose);
		$this->assertSame($featured_on, twcp_media_associated_post($loose)->ID);

		$this->assertNull(twcp_media_associated_post($this->create_image(100, 100)));
	}

	/**
	 * Alt generation sends a scaled image and saves the cleaned reply.
	 */
	public function test_generate_alt(): void
	{
		update_option('twcp_model_provider', 'anthropic');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model(['content' => [['type' => 'text', 'text' => '"Black rubber strap on a white table"']]]);
		$id = $this->create_image(1600, 1200);

		$this->assertSame('Black rubber strap on a white table', twcp_media_generate_alt($id));
		$this->assertSame('Black rubber strap on a white table', get_post_meta($id, '_wp_attachment_image_alt', true));

		$sent = imagecreatefromstring(base64_decode($requests[0]['body']['messages'][0]['content'][0]['source']['data']));
		$this->assertLessThanOrEqual(768, imagesx($sent));

		$this->assertSame('Black rubber strap on a white table', twcp_media_generate_alt($id), 'Existing alt is kept');
		$this->assertCount(1, $requests);

		remove_all_filters('pre_http_request');
		delete_option('twcp_model_provider');
		delete_option('twcp_model_api_key');
	}
}
