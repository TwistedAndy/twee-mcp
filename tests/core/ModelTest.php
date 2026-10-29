<?php

/**
 * Model settings, image payloads, and model request tests
 */
class ModelTest extends TWCP_TestCase {

	public function set_up(): void
	{
		parent::set_up();
		update_option('twcp_model_provider', 'anthropic');
		update_option('twcp_model_api_key', 'test-key');
	}

	public function tear_down(): void
	{
		remove_all_filters('pre_http_request');
		foreach (['twcp_model_provider', 'twcp_model_api_key', 'twcp_vision_size', 'twcp_disabled_tools'] as $option) {
			delete_option($option);
		}
		parent::tear_down();
	}


	/* -------------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------------- */

	/**
	 * Defaults come from the provider map; the vision size falls back to 768.
	 */
	public function test_settings_defaults(): void
	{
		$settings = twcp_model_settings();

		$this->assertSame('anthropic', $settings['provider']);
		$this->assertSame(TWCP_MODEL_DEFAULT_ANTHROPIC_MODEL, $settings['model']);
		$this->assertSame(TWCP_MODEL_DEFAULT_ANTHROPIC_VISION, $settings['vision_model']);
		$this->assertSame(768, $settings['vision_size']);

		update_option('twcp_vision_size', 999);
		$this->assertSame(768, twcp_model_settings()['vision_size']);

		update_option('twcp_vision_size', 512);
		$this->assertSame(512, twcp_model_settings()['vision_size']);

		update_option('twcp_model_provider', 'unknown');
		$this->assertSame('openai', twcp_model_settings()['provider']);
	}

	/**
	 * A missing API key fails without an HTTP request.
	 */
	public function test_request_without_key(): void
	{
		delete_option('twcp_model_api_key');
		update_option('twcp_model_provider', 'openai');
		$requests = $this->mock_model([]);

		$this->assertSame('', twcp_model_request('Hi'));
		$this->assertStringContainsString('No API key', twcp_model_last_error());
		$this->assertCount(0, $requests);
	}


	/* -------------------------------------------------------------------------
	 * Image Payloads
	 * ------------------------------------------------------------------------- */

	/**
	 * Large images use a fitting intermediate size or a resized temporary copy.
	 */
	public function test_image_file_scales_down(): void
	{
		$id = $this->create_image(1600, 900);

		$balanced = twcp_model_image_file($id, 768);
		[$width] = wp_getimagesize($balanced['path']);
		$this->assertLessThanOrEqual(768, $width);
		$this->assertGreaterThanOrEqual(460, $width);
		$this->assertFalse($balanced['temp'], 'An existing size fits, so no resize is needed');

		$preview = twcp_model_image_file($id, 256);
		[$width, $height] = wp_getimagesize($preview['path']);
		$this->assertTrue($preview['temp']);
		$this->assertSame([256, 144], [$width, $height]);
		wp_delete_file($preview['path']);

		$small = $this->create_image(300, 200);
		$original = twcp_model_image_file($small, 768);
		$this->assertSame(get_attached_file($small), $original['path']);
	}

	/**
	 * Formats vision models cannot read are converted to JPEG when the image editor can, or skipped.
	 */
	public function test_image_file_unsupported_formats(): void
	{
		$svg = get_temp_dir() . 'twcp-icon.svg';
		file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');

		$file = twcp_model_image_file($svg, 512);
		if ($file) {
			$this->assertSame('image/jpeg', $file['mime_type'], 'Imagick rasterizes the SVG');
			wp_delete_file($file['path']);
		}

		$text = get_temp_dir() . 'twcp-text.jpg';
		file_put_contents($text, 'not an image');
		$this->assertNull(twcp_model_image_file($text, 512));
		$this->assertNull(twcp_model_image_file(get_temp_dir() . 'missing.jpg', 512));
		wp_delete_file($svg);
		wp_delete_file($text);
	}

	/**
	 * Payloads accept data URIs, attachment IDs, upload URLs, and remote URLs.
	 */
	public function test_image_payload_sources(): void
	{
		$data = twcp_model_image_payload('data:image/png;base64,AAAA');
		$this->assertSame(['mime_type' => 'image/png', 'data' => 'AAAA', 'url' => 'data:image/png;base64,AAAA'], $data);

		$id = $this->create_image(1200, 800);
		$from_id = twcp_model_image_payload($id, 256);
		$from_url = twcp_model_image_payload(wp_get_attachment_url($id), 256);
		$this->assertSame('image/jpeg', $from_id['mime_type']);
		$this->assertSame(strlen($from_id['data']), strlen($from_url['data']));
		$this->assertLessThan(filesize(get_attached_file($id)), strlen(base64_decode($from_id['data'])));

		$remote = twcp_model_image_payload('https://example.com/photo.jpg');
		$this->assertSame('', $remote['data']);
		$this->assertSame('https://example.com/photo.jpg', $remote['url']);

		$this->assertNull(twcp_model_image_payload('not an image'));
	}


	/* -------------------------------------------------------------------------
	 * Requests
	 * ------------------------------------------------------------------------- */

	/**
	 * Claude requests cache the system prompt and send remote images by URL.
	 */
	public function test_claude_request(): void
	{
		$requests = $this->mock_model(['content' => [['type' => 'text', 'text' => "```html\n<p>Done</p>\n```"]]]);

		$text = twcp_model_request('Write', 'System rules', ['https://example.com/a.jpg'], ['temperature' => 0.2]);
		$body = $requests[0]['body'];

		$this->assertSame('<p>Done</p>', $text);
		$this->assertStringContainsString('anthropic.com', $requests[0]['url']);
		$this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
		$this->assertSame(['type' => 'url', 'url' => 'https://example.com/a.jpg'], $body['messages'][0]['content'][0]['source']);
		$this->assertSame(0.2, $body['temperature']);
		$this->assertArrayNotHasKey('tools', $body);
	}

	/**
	 * OpenAI requests go to the Responses API, stored and cached per site, with low detail for economy-sized images.
	 */
	public function test_openai_request(): void
	{
		update_option('twcp_model_provider', 'openai');
		$requests = $this->mock_model($this->model_reply('Alt'));
		$id = $this->create_image(1200, 800);

		$this->assertSame('Alt', twcp_model_vision('Describe', 'System rules', [$id], ['image_size' => 512, 'temperature' => 0.2, 'max_tokens' => 50]));
		$body = $requests[0]['body'];
		$this->assertSame('https://api.openai.com/v1/responses', $requests[0]['url']);
		$this->assertTrue($body['store']);
		$this->assertSame('System rules', $body['instructions']);
		$this->assertSame('twcp_' . md5(home_url()), $body['prompt_cache_key']);
		$this->assertSame(50, $body['max_output_tokens']);
		$image = $body['input'][0]['content'][1];
		$this->assertSame('low', $image['detail']);
		$this->assertStringStartsWith('data:image/jpeg;base64,', $image['image_url']);

		twcp_model_vision('Describe', '', [$id], ['image_size' => 1024, 'model' => 'gpt-5-mini', 'temperature' => 0.2]);
		$this->assertSame('auto', $requests[1]['body']['input'][0]['content'][1]['detail']);
		$this->assertArrayNotHasKey('temperature', $requests[1]['body'], 'Reasoning models reject a temperature');
	}

	/**
	 * Lookup tools can be limited per task; private and write tools are never offered.
	 */
	public function test_lookup_tool_definitions(): void
	{
		$requests = $this->mock_model(['content' => [['type' => 'text', 'text' => 'OK']]]);

		twcp_model_request('Check', 'System', [], ['read_tools' => true]);
		$all = array_column($requests[0]['body']['tools'], 'name');
		$this->assertContains('get_posts', $all);
		$this->assertNotContains('edit_post', $all);
		$this->assertNotContains('preview_images', $all);
		$this->assertNotContains('get_orders', $all);
		$this->assertSame(['type' => 'ephemeral'], end($requests[0]['body']['tools'])['cache_control']);

		twcp_model_request('Check', 'System', [], ['read_tools' => ['get_terms', 'edit_post']]);
		$this->assertSame(['get_terms'], array_column($requests[1]['body']['tools'], 'name'));

		update_option('twcp_disabled_tools', ['get_terms']);
		twcp_model_request('Check', 'System', [], ['read_tools' => ['get_terms']]);
		$this->assertArrayNotHasKey('tools', $requests[2]['body']);
	}

	/**
	 * Tool calls from the model run as read-only lookups and feed back as results.
	 */
	public function test_lookup_round_trip(): void
	{
		$post_id = self::factory()->post->create(['post_title' => 'Looked up']);
		$replies = [
			['content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'get_posts', 'input' => ['id' => $post_id]]]],
			['content' => [['type' => 'text', 'text' => 'Final']]],
		];
		$bodies = [];
		add_filter('pre_http_request', function($response, $args) use (&$replies, &$bodies) {
			$bodies[] = json_decode($args['body'], true);

			return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode(array_shift($replies)), 'cookies' => []];
		}, 10, 2);

		$this->assertSame('Final', twcp_model_request('Check', '', [], ['read_tools' => ['get_posts']]));

		$result = end($bodies[1]['messages'])['content'][0];
		$this->assertSame('tool_result', $result['type']);
		$this->assertSame('Looked up', json_decode($result['content'], true)['title']);
	}

	/**
	 * Stored OpenAI responses continue by ID, so a lookup round sends only the tool results.
	 */
	public function test_openai_lookup_continues_response(): void
	{
		update_option('twcp_model_provider', 'openai');
		$post_id = self::factory()->post->create(['post_title' => 'Looked up']);
		$replies = [
			['id' => 'resp_1', 'output' => [['type' => 'reasoning', 'summary' => []], ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'get_posts', 'arguments' => wp_json_encode(['id' => $post_id])]]],
			$this->model_reply('Final'),
		];
		$bodies = [];
		add_filter('pre_http_request', function($response, $args) use (&$replies, &$bodies) {
			$bodies[] = json_decode($args['body'], true);

			return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode(array_shift($replies)), 'cookies' => []];
		}, 10, 2);

		$this->assertSame('Final', twcp_model_request('Check', '', [], ['read_tools' => ['get_posts']]));

		$this->assertStringContainsString('get_posts', end($bodies[0]['input'])['content']);
		$this->assertSame('resp_1', $bodies[1]['previous_response_id']);
		$this->assertCount(1, $bodies[1]['input']);
		$this->assertSame('call_1', $bodies[1]['input'][0]['call_id']);
		$this->assertSame('Looked up', json_decode($bodies[1]['input'][0]['output'], true)['title']);
	}

	/**
	 * HTTP errors are reported through the last error.
	 */
	public function test_request_error(): void
	{
		add_filter('pre_http_request', fn() => ['response' => ['code' => 401, 'message' => 'Unauthorized'], 'headers' => [], 'body' => '{"error":{"message":"Bad key"}}', 'cookies' => []]);

		$this->assertSame('', twcp_model_request('Hi'));
		$this->assertSame('Bad key', twcp_model_last_error());
	}

	/**
	 * Model JSON responses are decoded from fenced or bare JSON.
	 */
	public function test_model_response(): void
	{
		$this->assertSame(['a' => 1], twcp_model_response('{"a":1}'));
		$this->assertSame(['a' => 1], twcp_model_response("Here:\n```json\n{\"a\":1}\n```"));
		$this->assertNull(twcp_model_response('no json'));
	}
}
