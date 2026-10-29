<?php

/**
 * Experimental ChatGPT plan provider: paste-back sign-in, token renewal, and streamed Responses requests
 */
class ChatGPTTest extends TWCP_TestCase {

	public function tear_down(): void
	{
		delete_option('twcp_chatgpt');
		delete_option('twcp_chatgpt_lock');
		delete_transient('twcp_models_chatgpt');
		remove_all_filters('pre_http_request');
		parent::tear_down();
	}

	/**
	 * Reply to OpenAI requests in order and capture them
	 *
	 * @param array $replies [ status, body string or array ] per request
	 *
	 * @return ArrayObject Captured [ url, body ]
	 */
	private function mock_openai(array $replies): ArrayObject
	{
		$requests = new ArrayObject();

		add_filter('pre_http_request', function($response, $args, $url) use ($requests, &$replies) {
			$body = $args['body'] ?? '';
			$requests[] = ['url' => $url, 'body' => is_string($body) ? (json_decode($body, true) ?? $body) : $body];
			[$status, $reply] = array_shift($replies) ?? [500, ''];

			return ['response' => ['code' => $status, 'message' => ''], 'headers' => [], 'body' => is_array($reply) ? wp_json_encode($reply) : $reply, 'cookies' => []];
		}, 10, 3);

		return $requests;
	}

	/**
	 * Server-sent events the way the plan streams them: items finish one by one and the completed response is empty
	 *
	 * @param array $items
	 *
	 * @return string
	 */
	private function stream(array $items): string
	{
		$events = [['type' => 'response.created']];
		foreach ($items as $item) {
			$events[] = ['type' => 'response.output_item.done', 'item' => $item];
		}
		$events[] = ['type' => 'response.completed', 'response' => ['output' => []]];

		return implode('', array_map(fn($event) => 'event: ' . $event['type'] . "\ndata: " . wp_json_encode($event) . "\n\n", $events));
	}

	/**
	 * Unsigned ID token with the given claims
	 *
	 * @param array $claims
	 *
	 * @return string
	 */
	private function id_token(array $claims): string
	{
		return 'e30.' . twcp_oauth_encode(wp_json_encode($claims)) . '.sig';
	}

	/**
	 * Start a sign-in and return its pending state
	 *
	 * @return array
	 */
	private function start(): array
	{
		parse_str((string) wp_parse_url(twcp_chatgpt_start(), PHP_URL_QUERY), $query);

		return $query;
	}

	/**
	 * Save a connected account
	 *
	 * @param int $expires
	 */
	private function connect(int $expires = 3600): void
	{
		twcp_chatgpt_save([
			'client_id'     => 'oaiapp_test',
			'sub'           => 'user-1',
			'access_token'  => 'access-1',
			'refresh_token' => 'refresh-1',
			'expires'       => time() + $expires,
			'scopes'        => ['openid', 'offline_access', 'chatgpt.tokens.use.direct'],
		]);
	}


	/* -------------------------------------------------------------------------
	 * Sign-in
	 * ------------------------------------------------------------------------- */

	/**
	 * A first sign-in registers dynamically with PKCE, the loopback callback, and the site host ID.
	 */
	public function test_start_builds_registration_request(): void
	{
		$url = twcp_chatgpt_start();
		parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);

		$this->assertStringStartsWith(TWCP_CHATGPT_AUTHORIZE . '?', $url);
		$this->assertSame('dynamic_agent_client', $query['client_id']);
		$this->assertSame(TWCP_CHATGPT_REDIRECT, $query['redirect_uri']);
		$this->assertSame('S256', $query['code_challenge_method']);
		$this->assertStringContainsString('chatgpt.tokens.use.direct', $query['scope']);
		$this->assertSame(TWCP_CHATGPT_RESOURCE, $query['resource']);
		$this->assertSame(twcp_chatgpt_host(), $query['ext_agent_host_id']);
		$this->assertStringStartsWith('urn:uuid:', $query['ext_agent_host_id']);
		$this->assertArrayHasKey('agent_name_hint', $query);

		$pending = get_transient('twcp_chatgpt_pending_' . self::$admin_id);
		$this->assertSame($query['state'], $pending['state']);
		$this->assertSame($query['code_challenge'], twcp_oauth_encode(hash('sha256', $pending['verifier'], true)));
	}

	/**
	 * A saved registration signs in again with its issued client ID and without a name hint.
	 */
	public function test_start_reuses_registration(): void
	{
		twcp_chatgpt_save(['client_id' => 'oaiapp_test', 'id_token' => 'old.token.value', 'email' => 'a@example.com']);

		$query = $this->start();
		$this->assertSame('oaiapp_test', $query['client_id']);
		$this->assertSame('old.token.value', $query['id_token_hint']);
		$this->assertArrayNotHasKey('agent_name_hint', $query);
	}

	/**
	 * The pasted callback is exchanged for tokens, and the ID token is checked before saving.
	 */
	public function test_finish_saves_tokens(): void
	{
		$query = $this->start();
		$nonce = get_transient('twcp_chatgpt_pending_' . self::$admin_id)['nonce'];
		$requests = $this->mock_openai([[200, [
			'access_token'  => 'access-1',
			'refresh_token' => 'refresh-1',
			'expires_in'    => 3600,
			'scope'         => 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct',
			'id_token'      => $this->id_token(['iss' => TWCP_CHATGPT_ISSUER, 'aud' => 'oaiapp_new', 'exp' => time() + 600, 'nonce' => $nonce, 'sub' => 'user-1', 'email' => 'a@example.com']),
		]]]);

		$error = twcp_chatgpt_finish(TWCP_CHATGPT_REDIRECT . '?code=ac_1&state=' . rawurlencode($query['state']) . '&client_id=oaiapp_new');

		$this->assertSame('', $error);
		$this->assertTrue(twcp_chatgpt_connected());
		$this->assertSame('a@example.com', twcp_chatgpt_status()['email']);
		$this->assertSame('oaiapp_new', twcp_chatgpt_account()['client_id']);

		$this->assertSame(TWCP_CHATGPT_TOKEN, $requests[0]['url']);
		$this->assertSame('authorization_code', $requests[0]['body']['grant_type']);
		$this->assertSame('oaiapp_new', $requests[0]['body']['client_id']);
		$this->assertSame(TWCP_CHATGPT_REDIRECT, $requests[0]['body']['redirect_uri']);
		$this->assertFalse(get_transient('twcp_chatgpt_pending_' . self::$admin_id), 'A code is exchanged only once');
	}

	/**
	 * Wrong state, a missing registration, a declined plan, and a bad nonce never save tokens.
	 */
	public function test_finish_rejects_bad_callbacks(): void
	{
		$query = $this->start();
		$state = rawurlencode($query['state']);

		$this->assertStringContainsString('full address', twcp_chatgpt_finish('http://127.0.0.1:1455/auth/callback'));
		$this->assertStringContainsString('different sign-in', twcp_chatgpt_finish(TWCP_CHATGPT_REDIRECT . '?code=ac_1&state=other&client_id=oaiapp_new'));
		$this->assertStringContainsString('did not finish registering', twcp_chatgpt_finish(TWCP_CHATGPT_REDIRECT . '?code=ac_1&state=' . $state));

		$this->mock_openai([[200, [
			'access_token' => 'access-1',
			'scope'        => 'openid chatgpt.tokens.use.direct',
			'id_token'     => $this->id_token(['iss' => TWCP_CHATGPT_ISSUER, 'aud' => 'oaiapp_new', 'exp' => time() + 600, 'nonce' => 'forged', 'sub' => 'user-1']),
		]]]);
		$this->assertStringContainsString('failed validation', twcp_chatgpt_finish(TWCP_CHATGPT_REDIRECT . '?code=ac_1&state=' . $state . '&client_id=oaiapp_new'));
		$this->assertFalse(twcp_chatgpt_connected());

		$this->start();
		$this->assertStringContainsString('plan use was not allowed', twcp_chatgpt_finish(TWCP_CHATGPT_REDIRECT . '?error=access_denied'));
	}


	/* -------------------------------------------------------------------------
	 * Tokens
	 * ------------------------------------------------------------------------- */

	/**
	 * A token about to expire is refreshed and the rotated refresh token replaces the old one.
	 */
	public function test_token_refreshes_and_rotates(): void
	{
		$this->connect(60);
		$requests = $this->mock_openai([[200, ['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600, 'scope' => 'openid offline_access chatgpt.tokens.use.direct']]]);

		$this->assertSame('access-2', twcp_chatgpt_token());
		$this->assertSame('refresh_token', $requests[0]['body']['grant_type']);
		$this->assertSame('refresh-1', $requests[0]['body']['refresh_token']);
		$this->assertSame('refresh-2', twcp_chatgpt_account()['refresh_token']);
		$this->assertFalse(get_option('twcp_chatgpt_lock'));

		// A fresh token is reused without another request
		$this->assertSame('access-2', twcp_chatgpt_token());
		$this->assertCount(1, $requests);
	}

	/**
	 * A revoked refresh token drops the tokens, keeps the registration, and asks for a new sign-in.
	 */
	public function test_terminal_refresh_error_requires_sign_in(): void
	{
		$this->connect(-10);
		$this->mock_openai([[400, ['error' => 'invalid_grant']]]);

		$this->assertSame('', twcp_chatgpt_token());
		$this->assertFalse(twcp_chatgpt_connected());
		$this->assertSame('oaiapp_test', twcp_chatgpt_account()['client_id']);
		$this->assertStringContainsString('Sign in again', twcp_model_last_error());
	}


	/* -------------------------------------------------------------------------
	 * Requests
	 * ------------------------------------------------------------------------- */

	/**
	 * Plan requests stream unstored responses, drop unsupported options, and read the finished items.
	 */
	public function test_request_reads_streamed_items(): void
	{
		$this->connect();
		$requests = $this->mock_openai([[200, $this->stream([
			['type' => 'reasoning', 'summary' => []],
			['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Plan OK']]],
		])]]);

		$text = twcp_model_request('Say OK', 'System rules', [], ['provider' => 'chatgpt', 'model' => 'gpt-test', 'temperature' => 0.2, 'max_tokens' => 20]);

		$this->assertSame('Plan OK', $text);
		$body = $requests[0]['body'];
		$this->assertSame(TWCP_CHATGPT_RESOURCE . '/responses', $requests[0]['url']);
		$this->assertFalse($body['store']);
		$this->assertTrue($body['stream']);
		$this->assertSame('System rules', $body['instructions']);
		$this->assertArrayNotHasKey('temperature', $body);
		$this->assertArrayNotHasKey('max_output_tokens', $body);
		$this->assertSame('input_text', $body['input'][0]['content'][0]['type']);
	}

	/**
	 * Without a saved model, requests use the first model the account lists.
	 */
	public function test_request_falls_back_to_first_model(): void
	{
		$this->connect();
		$requests = $this->mock_openai([
			[200, ['models' => [['slug' => 'gpt-first', 'display_name' => 'First', 'visibility' => 'list']]]],
			[200, $this->stream([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'OK']]]])],
		]);

		update_option('twcp_model_provider', 'chatgpt');
		$this->assertSame('OK', twcp_model_request('Hi'));
		$this->assertSame('gpt-first', $requests[1]['body']['model']);
		delete_option('twcp_model_provider');
	}

	/**
	 * Images go in as input_image parts at the configured size.
	 */
	public function test_request_sends_images(): void
	{
		$this->connect();
		$image = $this->create_image(1200, 800);
		$requests = $this->mock_openai([[200, $this->stream([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Blue square']]]])]]);

		$this->assertSame('Blue square', twcp_model_request('Alt text', '', [$image], ['provider' => 'chatgpt', 'model' => 'gpt-test', 'image_size' => 512]));

		$part = $requests[0]['body']['input'][0]['content'][1];
		$this->assertSame('input_image', $part['type']);
		$this->assertStringStartsWith('data:image/jpeg;base64,', $part['image_url']);
		$this->assertSame('low', $part['detail']);
	}

	/**
	 * Lookup calls run read-only tools and resend the conversation with its reasoning, since nothing is stored
	 * between rounds.
	 */
	public function test_request_runs_lookups(): void
	{
		$this->connect();
		self::factory()->post->create(['post_title' => 'Newest Strap Guide']);
		$requests = $this->mock_openai([
			[200, $this->stream([
				['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'sealed'],
				['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'get_posts', 'arguments' => '{"post_type":"post","limit":1}'],
			])],
			[200, $this->stream([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Newest Strap Guide']]]])],
		]);

		$text = twcp_model_request('Newest post title?', '', [], ['provider' => 'chatgpt', 'model' => 'gpt-test', 'read_tools' => ['get_posts']]);

		$this->assertSame('Newest Strap Guide', $text);
		$this->assertSame(['get_posts'], array_column($requests[0]['body']['tools'], 'name'));
		$this->assertSame(['reasoning.encrypted_content'], $requests[0]['body']['include']);

		$input = $requests[1]['body']['input'];
		$this->assertSame(['type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'sealed'], $input[count($input) - 3]);
		$call = $input[count($input) - 2];
		$output = $input[count($input) - 1];
		$this->assertSame('function_call', $call['type']);
		$this->assertArrayNotHasKey('id', $call, 'Unstored item IDs cannot be referenced');
		$this->assertSame('call_1', $output['call_id']);
		$this->assertStringContainsString('Newest Strap Guide', $output['output']);
	}

	/**
	 * Plan errors become readable messages, and a usage limit is not retried.
	 */
	public function test_request_reports_plan_errors(): void
	{
		$this->connect();
		$requests = $this->mock_openai([[429, ['error' => ['code' => 'subscription_sharing_usage_limit_exceeded', 'message' => 'Limit']]]]);

		$this->assertSame('', twcp_model_request('Hi', '', [], ['provider' => 'chatgpt', 'model' => 'gpt-test']));
		$this->assertStringContainsString('usage limit', twcp_model_last_error());
		$this->assertCount(1, $requests);

		$this->mock_openai([[200, "data: {\"type\":\"response.failed\",\"response\":{\"error\":{\"code\":\"subscription_sharing_unsupported_capability\",\"param\":\"tools\"}}}\n\n"]]);
		$this->assertSame('', twcp_model_request('Hi', '', [], ['provider' => 'chatgpt', 'model' => 'gpt-test']));
		$this->assertStringContainsString('(tools)', twcp_model_last_error());
	}

	/**
	 * Without a sign-in the plan provider fails clearly instead of asking for an API key.
	 */
	public function test_request_requires_sign_in(): void
	{
		$this->assertSame('', twcp_model_request('Hi', '', [], ['provider' => 'chatgpt', 'model' => 'gpt-test']));
		$this->assertStringContainsString('not connected', twcp_model_last_error());

		update_option('twcp_model_provider', 'chatgpt');
		$this->assertSame('', twcp_model_settings()['api_key']);
		$this->assertFalse(twcp_model_test()['success']);
		delete_option('twcp_model_provider');
	}

	/**
	 * The model list keeps listed models in the server's order.
	 */
	public function test_models_list(): void
	{
		$this->connect();
		$this->mock_openai([[200, ['models' => [
			['slug' => 'gpt-b', 'display_name' => 'GPT B', 'visibility' => 'list'],
			['slug' => 'gpt-hidden', 'display_name' => 'Hidden', 'visibility' => 'hide'],
			['slug' => 'gpt-a', 'display_name' => 'GPT A', 'visibility' => 'list'],
		]]]]);

		$this->assertSame([['id' => 'gpt-b', 'label' => 'GPT B'], ['id' => 'gpt-a', 'label' => 'GPT A']], twcp_model_fetch('chatgpt', null, true));
		$this->assertSame('gpt-b', get_transient('twcp_models_chatgpt')[0]['id']);
	}
}
