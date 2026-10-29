<?php

/**
 * OAuth redirect validation, token sessions, and bearer authentication tests
 */
class OAuthTest extends TWCP_TestCase {

	public function tear_down(): void
	{
		delete_option('twcp_redirect_hosts');
		parent::tear_down();
	}

	/**
	 * Only HTTPS redirects to allowed hosts and loopback HTTP redirects pass.
	 */
	public function test_redirect_valid(): void
	{
		$this->assertTrue(twcp_oauth_redirect_valid('https://claude.ai/api/mcp/auth_callback'));
		$this->assertTrue(twcp_oauth_redirect_valid('https://app.claude.ai/callback'));
		$this->assertTrue(twcp_oauth_redirect_valid('http://localhost:3000/callback'));

		$this->assertFalse(twcp_oauth_redirect_valid('https://evil-claude.ai/callback'));
		$this->assertFalse(twcp_oauth_redirect_valid('http://claude.ai/callback'));
		$this->assertFalse(twcp_oauth_redirect_valid('https://claude.ai/callback#frag'));
		$this->assertFalse(twcp_oauth_redirect_valid('https://user@claude.ai/callback'));
		$this->assertFalse(twcp_oauth_redirect_valid(['https://claude.ai']));

		update_option('twcp_redirect_hosts', "agents.example.com");
		$this->assertTrue(twcp_oauth_redirect_valid('https://agents.example.com/cb'));
		$this->assertTrue(twcp_oauth_redirect_valid('https://claude.ai/cb'), 'Defaults stay allowed');
	}

	/**
	 * Base64url encoding and signatures are stable and URL-safe.
	 */
	public function test_encode_and_sign(): void
	{
		$this->assertSame('-_8', twcp_oauth_encode("\xfb\xff"));
		$this->assertSame(twcp_oauth_sign('payload'), twcp_oauth_sign('payload'));
		$this->assertNotSame(twcp_oauth_sign('payload'), twcp_oauth_sign('other'));
		$this->assertDoesNotMatchRegularExpression('/[+\/=]/', twcp_oauth_sign('payload'));
	}

	/**
	 * A built client ID decodes to its name and redirects, and a changed payload is rejected.
	 */
	public function test_client_id(): void
	{
		$callback = 'https://chatgpt.com/connector/oauth/abc123';
		$client_id = twcp_oauth_client_id('ChatGPT', [$callback]);

		$this->assertSame(['n' => 'ChatGPT', 'r' => [$callback]], twcp_oauth_client($client_id));

		[, $signature] = explode('.', $client_id);
		$forged = twcp_oauth_encode((string) wp_json_encode(['n' => 'ChatGPT', 'r' => ['https://chatgpt.com/other']])) . '.' . $signature;
		$this->assertNull(twcp_oauth_client($forged));
	}

	/**
	 * Dynamic registration issues the same client ID as the admin generator.
	 */
	public function test_register_issues_client_id(): void
	{
		$callback = 'https://chatgpt.com/connector/oauth/abc123';
		$request = new WP_REST_Request('POST', '/register');
		$request->set_header('content-type', 'application/json');
		$request->set_body(wp_json_encode(['client_name' => 'ChatGPT', 'redirect_uris' => [$callback]]));

		$response = twcp_oauth_register($request);

		$this->assertSame(201, $response->get_status());
		$this->assertSame(twcp_oauth_client_id('ChatGPT', [$callback]), $response->get_data()['client_id']);
	}

	/**
	 * Issued tokens resolve to their session until revoked.
	 */
	public function test_sessions(): void
	{
		$tokens = twcp_oauth_issue(self::$admin_id, 'client-hash', 'Claude');

		$session = twcp_oauth_session($tokens['access']);
		$this->assertSame(['user' => self::$admin_id, 'client' => 'client-hash', 'name' => 'Claude'], $session);
		$this->assertSame(self::$admin_id, twcp_oauth_session($tokens['refresh'], 'refresh')['user']);
		$this->assertNull(twcp_oauth_session($tokens['refresh']), 'A refresh token is not an access token');
		$this->assertNull(twcp_oauth_session(''));

		twcp_oauth_revoke(self::$admin_id, 'client-hash');
		$this->assertNull(twcp_oauth_session($tokens['access']));
	}

	/**
	 * Expired access tokens are refused.
	 */
	public function test_expired_session(): void
	{
		$tokens = twcp_oauth_issue(self::$admin_id, 'client-hash', 'Claude');
		$sessions = twcp_oauth_sessions(self::$admin_id);
		$sessions['client-hash']['expires'] = time() - 1;
		update_user_meta(self::$admin_id, 'twcp_sessions', $sessions);

		$this->assertNull(twcp_oauth_session($tokens['access']));
	}

	/**
	 * Bearer tokens authenticate the REST request as the session user.
	 */
	public function test_bearer_authentication(): void
	{
		wp_set_current_user(0);
		$tokens = twcp_oauth_issue(self::$admin_id, 'client-hash', 'Claude');

		$request = new WP_REST_Request('POST', '/straps/v1/mcp');
		$request->set_header('Authorization', 'Bearer ' . $tokens['access']);
		$this->assertTrue(twcp_tools_authenticate($request));
		$this->assertSame(self::$admin_id, get_current_user_id());

		$request->set_header('Authorization', 'Bearer wrong');
		$this->assertFalse(twcp_tools_authenticate($request));
		$this->assertSame(0, get_current_user_id());
	}

	/**
	 * The REST handler requires authentication for management calls.
	 */
	public function test_rest_handler_requires_auth(): void
	{
		wp_set_current_user(0);

		$request = new WP_REST_Request('POST', '/straps/v1/mcp');
		$request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_posts']]));
		$response = twcp_tools_rest_handler($request);
		$this->assertSame(401, $response->get_status());
		$this->assertStringContainsString('resource_metadata', $response->get_headers()['WWW-Authenticate']);

		$request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
		$this->assertSame(200, twcp_tools_rest_handler($request)->get_status());

		$request->set_body('not json');
		$this->assertSame(400, twcp_tools_rest_handler($request)->get_status());
	}

	/**
	 * The discovery metadata is served under the REST API too, and the issuer leads clients to it there.
	 */
	public function test_metadata_under_rest(): void
	{
		update_option('permalink_structure', '/%postname%/');
		$server = rest_get_server();

		$resource = $server->dispatch(new WP_REST_Request('GET', '/straps/v1/.well-known/oauth-protected-resource'))->get_data();
		$this->assertSame(twcp_mcp_url(), $resource['resource']);
		$this->assertContains('offline_access', $resource['scopes_supported']);

		$issuer = $resource['authorization_servers'][0];
		$this->assertSame(rest_url('straps/v1/.well-known/openid-configuration'), $issuer . '/.well-known/openid-configuration');

		$config = $server->dispatch(new WP_REST_Request('GET', '/straps/v1/.well-known/openid-configuration'))->get_data();
		$this->assertSame($issuer, $config['issuer']);
		$this->assertSame(home_url('/register'), $config['registration_endpoint']);

		$this->assertStringContainsString(rest_url('straps/v1/.well-known/oauth-protected-resource'), twcp_tools_unauthorized()->get_headers()['WWW-Authenticate']);
	}
}
