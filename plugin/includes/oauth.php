<?php
/**
 * Twee MCP — OAuth 2.1 Authorization
 *
 * Implements discovery metadata, dynamic client registration (RFC 7591),
 * PKCE authorization code flow (S256), and refresh tokens for connecting
 * Claude Code, Claude Web/Desktop, ChatGPT, and other MCP clients.
 *
 * Client IDs are stateless signed payloads. Active tokens are stored hashed
 * in user meta `twcp_sessions`.
 *
 * Endpoints:
 * - Discovery:             /.well-known/oauth-protected-resource
 *                          /.well-known/oauth-authorization-server
 * - Dynamic Registration:  /register
 * - Authorization Consent: /authorize
 * - Token Exchange:        /token
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Serve OAuth discovery metadata and authorization endpoints
 */
add_action('init', 'twcp_oauth_init');

/**
 * Handle OAuth discovery, client registration, consent, and token exchange.
 *
 * @return void
 */
function twcp_oauth_init(): void
{
	$path = (string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);

	$site_path = (string) wp_parse_url(home_url(), PHP_URL_PATH);
	if (!empty($site_path) and $site_path !== '/' and strpos($path, $site_path) === 0) {
		$path = substr($path, strlen($site_path));
	}

	$trimmed_path = untrailingslashit($path);
	$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
	$home = home_url();

	// 1. Protected Resource Metadata (RFC 9728)
	if ($trimmed_path === '/.well-known/oauth-protected-resource' or str_starts_with($trimmed_path, '/.well-known/oauth-protected-resource/')) {

		twcp_oauth_send([
			'resource'                 => twcp_mcp_url(),
			'resource_name'            => get_bloginfo('name'),
			'authorization_servers'    => [$home],
			'bearer_methods_supported' => ['header'],
			'scopes_supported'         => ['straps:manage'],
		]);

		// 2. Authorization Server Metadata (RFC 8414 & OpenID Connect Discovery)
	} elseif ($trimmed_path === '/.well-known/oauth-authorization-server') {

		twcp_oauth_send([
			'issuer'                                => $home,
			'authorization_endpoint'                => $home . '/authorize',
			'token_endpoint'                        => $home . '/token',
			'registration_endpoint'                 => $home . '/register',
			'response_types_supported'              => ['code'],
			'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported'      => ['S256'],
			'token_endpoint_auth_methods_supported' => ['none'],
			'scopes_supported'                      => ['straps:manage'],
		]);

		// 3. Authorization Screen (Authorize Consent)
	} elseif ($trimmed_path === '/authorize') {

		if (!twcp_is_enabled()) {
			wp_die(__('MCP Server is currently disabled.', 'twee-mcp'), __('Service Unavailable', 'twee-mcp'), ['response' => 503]);
		}

		twcp_oauth_authorize();

		// 4. Dynamic Client Registration
	} elseif ($method === 'POST' and $trimmed_path === '/register') {

		$request = new WP_REST_Request('POST', '/register');
		$raw_input = (string) file_get_contents('php://input');
		$request->set_body($raw_input);

		$json_input = json_decode($raw_input, true);
		if (is_array($json_input)) {
			$request->set_header('content-type', 'application/json');
		}

		$response = twcp_oauth_register($request);
		status_header($response->get_status());
		foreach ($response->get_headers() as $header => $value) {
			header("$header: $value");
		}
		twcp_oauth_send($response->get_data());

		// 5. Token Endpoint
	} elseif ($method === 'POST' and $trimmed_path === '/token') {

		$request = new WP_REST_Request('POST', '/token');
		$raw_input = (string) file_get_contents('php://input');
		$request->set_body($raw_input);

		$json_input = json_decode($raw_input, true);
		if (is_array($json_input)) {
			$request->set_header('content-type', 'application/json');
		} else {
			parse_str($raw_input, $body_input);
			$request->set_body_params($body_input);
		}

		$response = twcp_oauth_token($request);
		status_header($response->get_status());
		foreach ($response->get_headers() as $header => $value) {
			header("$header: $value");
		}
		twcp_oauth_send($response->get_data());

	}
}


/**
 * Output a public JSON document with CORS headers and stop
 *
 * @param array $data
 */
function twcp_oauth_send(array $data): void
{
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Origin, X-Requested-With');

	if (isset($_SERVER['REQUEST_METHOD']) and strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
		status_header(204);
		exit;
	}

	wp_send_json($data);
}


/**
 * URL-safe base64 encoding without padding
 *
 * @param string $data
 *
 * @return string
 */
function twcp_oauth_encode(string $data): string
{
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}


/**
 * Sign payload using WordPress cryptographic salts
 *
 * @param string $data
 *
 * @return string
 */
function twcp_oauth_sign(string $data): string
{
	$key = defined('AUTH_KEY') ? AUTH_KEY : 'twcp_salt';

	return twcp_oauth_encode(hash_hmac('sha256', $data, $key, true));
}


/**
 * List allowed OAuth redirect hosts
 *
 * @return array
 */
function twcp_oauth_hosts(): array
{
	$hosts = trim((string) get_option('twcp_redirect_hosts', ''));

	if ($hosts === '') {
		$hosts = defined('TWCP_REDIRECT_HOSTS') ? TWCP_REDIRECT_HOSTS : "claude.ai\nclaude.com\nanthropic.com\nchatgpt.com\nopenai.com";
	}

	$custom = preg_split('/[\s,]+/', strtolower($hosts), -1, PREG_SPLIT_NO_EMPTY);
	$defaults = ['claude.ai', 'claude.com', 'anthropic.com', 'chatgpt.com', 'openai.com'];

	return array_values(array_unique(array_merge($defaults, $custom ? : [])));
}


/**
 * Validate client redirect URI
 *
 * @param mixed $uri
 *
 * @return bool
 */
function twcp_oauth_redirect_valid($uri): bool
{
	if (!is_string($uri) or strlen($uri) > 500) {
		return false;
	}

	$parts = wp_parse_url($uri);

	if (empty($parts['scheme']) or empty($parts['host']) or isset($parts['fragment']) or isset($parts['user'])) {
		return false;
	}

	$scheme = strtolower($parts['scheme']);
	$host = strtolower($parts['host']);

	if ($scheme === 'https') {
		foreach (twcp_oauth_hosts() as $allowed) {
			if ($host === $allowed or str_ends_with($host, '.' . $allowed)) {
				return true;
			}
		}

		return false;
	}

	if ($scheme === 'http') {
		return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
	}

	return false;
}


/**
 * Get active agent sessions for a user
 *
 * @param int $user_id
 *
 * @return array
 */
function twcp_oauth_sessions(int $user_id): array
{
	$sessions = get_user_meta($user_id, 'twcp_sessions', true);

	return is_array($sessions) ? $sessions : [];
}


/**
 * Revoke agent sessions for a user
 *
 * @param int         $user_id
 * @param string|null $client_hash
 */
function twcp_oauth_revoke(int $user_id, ?string $client_hash = null): void
{
	if ($client_hash === null) {
		delete_user_meta($user_id, 'twcp_sessions');

		return;
	}

	$sessions = twcp_oauth_sessions($user_id);
	if (isset($sessions[$client_hash])) {
		unset($sessions[$client_hash]);
		update_user_meta($user_id, 'twcp_sessions', $sessions);
	}
}


add_action('after_password_reset', function($user) {
	if (isset($user->ID)) {
		twcp_oauth_revoke($user->ID);
	}
});

add_action('profile_update', function($user_id, $old_user) {
	$user = get_userdata($user_id);
	if ($user and $user->user_pass !== $old_user->user_pass) {
		twcp_oauth_revoke($user_id);
	}
}, 10, 2);


/**
 * Build an uncached REST response
 *
 * @param array $data
 * @param int   $status
 *
 * @return WP_REST_Response
 */
function twcp_oauth_response(array $data, int $status = 200): WP_REST_Response
{
	$response = new WP_REST_Response($data, $status);
	$response->header('Cache-Control', 'no-store');

	return $response;
}


/**
 * Build an OAuth error response
 *
 * @param string $error
 * @param string $description
 * @param int    $status
 *
 * @return WP_REST_Response
 */
function twcp_oauth_error(string $error, string $description = '', int $status = 400): WP_REST_Response
{
	return twcp_oauth_response(['error' => $error, 'error_description' => $description], $status);
}


/**
 * Dynamic client registration endpoint (RFC 7591)
 *
 * @param WP_REST_Request $request
 *
 * @return WP_REST_Response
 */
function twcp_oauth_register(WP_REST_Request $request): WP_REST_Response
{
	if (!twcp_is_enabled()) {
		return twcp_oauth_error('temporarily_unavailable', 'MCP Server is currently disabled.', 503);
	}

	$params = array_merge((array) $request->get_params(), (array) $request->get_json_params());

	$raw_uris = $params['redirect_uris'] ?? $params['redirect_uri'] ?? [];
	if (is_string($raw_uris)) {
		$raw_uris = [$raw_uris];
	}
	$uris = is_array($raw_uris) ? array_values($raw_uris) : [];

	if (empty($uris) or count($uris) > 10 or count(array_filter($uris, 'twcp_oauth_redirect_valid')) !== count($uris)) {
		return twcp_oauth_error('invalid_redirect_uri', 'Provide 1-10 redirect URIs: HTTPS on an allowed host or HTTP on localhost.');
	}

	$name = !empty($params['client_name']) ? mb_substr(sanitize_text_field((string) $params['client_name']), 0, 80) : '';

	if ($name === '') {
		$name = 'MCP Client';
	}

	return twcp_oauth_response([
		'client_id'                  => twcp_oauth_client_id($name, $uris),
		'client_id_issued_at'        => time(),
		'client_name'                => $name,
		'redirect_uris'              => $uris,
		'grant_types'                => ['authorization_code', 'refresh_token'],
		'response_types'             => ['code'],
		'token_endpoint_auth_method' => 'none',
	], 201);
}


/**
 * Build a signed, stateless client ID for a client name and its redirect URIs
 *
 * @param string $name
 * @param array  $uris
 *
 * @return string
 */
function twcp_oauth_client_id(string $name, array $uris): string
{
	$payload = twcp_oauth_encode((string) wp_json_encode(['n' => $name, 'r' => $uris]));

	return $payload . '.' . twcp_oauth_sign('client:' . $payload);
}


/**
 * Decode and verify a client ID
 *
 * @param mixed $client_id
 *
 * @return array|null
 */
function twcp_oauth_client($client_id): ?array
{
	if (!is_string($client_id) or substr_count($client_id, '.') !== 1) {
		return null;
	}

	[$payload, $signature] = explode('.', $client_id);

	if (!hash_equals(twcp_oauth_sign('client:' . $payload), $signature)) {
		return null;
	}

	$client = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

	if (!is_array($client) or empty($client['r']) or !is_array($client['r'])) {
		return null;
	}

	return $client;
}


/**
 * Redirect back to client after authorization
 *
 * @param string $redirect
 * @param array  $args
 */
function twcp_oauth_return(string $redirect, array $args): void
{
	if (isset($_GET['state'])) {
		$args['state'] = wp_unslash((string) $_GET['state']);
	}

	nocache_headers();
	wp_redirect($redirect . (strpos($redirect, '?') === false ? '?' : '&') . http_build_query($args));
	exit;
}


/**
 * The authorization endpoint: sign in, consent screen, and issue authorization code
 */
function twcp_oauth_authorize(): void
{
	$client_id = isset($_GET['client_id']) ? wp_unslash((string) $_GET['client_id']) : '';
	$redirect = isset($_GET['redirect_uri']) ? wp_unslash((string) $_GET['redirect_uri']) : '';
	$challenge = isset($_GET['code_challenge']) ? wp_unslash((string) $_GET['code_challenge']) : '';
	$challenge = rtrim($challenge, '=');

	$client = twcp_oauth_client($client_id);

	if (!$client or !in_array($redirect, $client['r'], true) or !twcp_oauth_redirect_valid($redirect)) {
		wp_die('Unknown application or redirect address. Please reconnect the agent.', 'Authorization failed', ['response' => 400]);
	}

	$type = isset($_GET['response_type']) ? (string) $_GET['response_type'] : '';
	$method = isset($_GET['code_challenge_method']) ? (string) $_GET['code_challenge_method'] : '';

	if ($type !== 'code' or $method !== 'S256' or !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
		twcp_oauth_return($redirect, ['error' => 'invalid_request', 'error_description' => 'response_type=code with an S256 code_challenge is required.']);
	}

	if (!is_user_logged_in()) {
		auth_redirect();
	}

	if (!current_user_can(TWCP_CAPABILITY)) {
		wp_die('Your account does not have access to connect MCP agents.', 'Access denied', ['response' => 403]);
	}

	nocache_headers();
	header('X-Frame-Options: DENY');

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		check_admin_referer('twcp_authorize');

		if (empty($_POST['approve'])) {
			twcp_oauth_return($redirect, ['error' => 'access_denied']);
		}

		$code = bin2hex(random_bytes(32));

		set_transient('twcp_code_' . hash('sha256', $code), [
			'user'      => get_current_user_id(),
			'client'    => hash('sha256', $client_id),
			'name'      => $client['n'],
			'redirect'  => $redirect,
			'challenge' => $challenge,
		], TWCP_CODE_TTL);

		twcp_oauth_return($redirect, ['code' => $code]);

	}

	$user = wp_get_current_user();
	$target = strtolower((string) wp_parse_url($redirect, PHP_URL_HOST));
	$local = !in_array($target, twcp_oauth_hosts(), true);

	?>
	<!DOCTYPE html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>Connect to <?php echo esc_html(get_bloginfo('name')); ?></title>
		<style>
			body {
				margin: 0;
				padding: 40px 20px;
				background: #f0f0f1;
				color: #1d2327;
				font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			}

			.box {
				max-width: 440px;
				margin: 0 auto;
				padding: 30px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 8px;
				box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
			}

			h1 {
				margin: 0 0 16px;
				font-size: 21px;
				line-height: 1.3;
			}

			p {
				margin: 0 0 14px;
			}

			.note {
				color: #646970;
				font-size: 13px;
			}

			.target {
				padding: 10px 14px;
				background: #f6f7f7;
				border-left: 4px solid #2271b1;
				border-radius: 2px;
				margin: 16px 0;
			}

			.target strong {
				display: block;
				font-size: 16px;
				word-break: break-all;
			}

			.buttons {
				display: flex;
				gap: 10px;
				margin-top: 24px;
			}

			button {
				flex: 1;
				padding: 10px 14px;
				border: 1px solid #2271b1;
				border-radius: 4px;
				background: #fff;
				color: #2271b1;
				font: inherit;
				font-weight: 500;
				cursor: pointer;
			}

			button.primary {
				background: #2271b1;
				color: #fff;
			}

			button.primary:hover {
				background: #135e96;
			}
		</style>
	</head>
	<body>
	<form class="box" method="post">
		<h1><?php echo esc_html($client['n']); ?> wants to connect to <?php echo esc_html(get_bloginfo('name')); ?></h1>
		<p>The agent will be able to manage store content (posts, pages, products, terms, blocks, and images) on your behalf.</p>
		<div class="target">
			Access will be granted to:
			<strong><?php echo esc_html($local ? 'This computer (' . $target . ')' : $target); ?></strong>
		</div>
		<p class="note">Only allow this if you initiated the connection. Signed in as <strong><?php echo esc_html($user->display_name); ?></strong>.</p>
		<?php wp_nonce_field('twcp_authorize'); ?>
		<div class="buttons">
			<button type="submit" name="approve" value="0">Cancel</button>
			<button type="submit" name="approve" value="1" class="primary">Allow</button>
		</div>
	</form>
	</body>
	</html>
	<?php

	exit;
}


/**
 * Exchange an authorization code or refresh token for tokens
 *
 * @param WP_REST_Request $request
 *
 * @return WP_REST_Response
 */
function twcp_oauth_token(WP_REST_Request $request): WP_REST_Response
{
	if (!twcp_is_enabled()) {
		return twcp_oauth_error('temporarily_unavailable', 'MCP Server is currently disabled.', 503);
	}

	$params = array_merge((array) $request->get_params(), (array) $request->get_json_params());

	$grant = (string) ($params['grant_type'] ?? '');
	$client_id = (string) ($params['client_id'] ?? '');

	if ($client_id === '') {
		$auth = (string) $request->get_header('authorization');
		if (preg_match('/^Basic\s+(.+)$/i', trim($auth), $matches)) {
			$decoded = base64_decode($matches[1]);
			if ($decoded !== false) {
				$parts = explode(':', $decoded, 2);
				$client_id = $parts[0];
			}
		}
	}

	if (!twcp_oauth_client($client_id)) {
		return twcp_oauth_error('invalid_client', 'Unknown client.', 401);
	}

	$client_hash = hash('sha256', $client_id);

	if ($grant === 'authorization_code') {

		$code = (string) ($params['code'] ?? '');
		$key = 'twcp_code_' . hash('sha256', $code);
		$data = get_transient($key);

		delete_transient($key);

		$verifier = (string) ($params['code_verifier'] ?? '');
		$redirect_uri = (string) ($params['redirect_uri'] ?? '');

		if (!is_array($data) or !hash_equals($data['client'], $client_hash) or $data['redirect'] !== $redirect_uri or !hash_equals($data['challenge'], twcp_oauth_encode(hash('sha256', $verifier, true)))) {
			return twcp_oauth_error('invalid_grant', 'The authorization code is invalid or expired.');
		}

		$tokens = twcp_oauth_issue((int) $data['user'], $client_hash, $data['name']);

	} elseif ($grant === 'refresh_token') {

		$token = (string) ($params['refresh_token'] ?? '');

		$session = twcp_oauth_session($token, 'refresh');

		if (!$session or !hash_equals($session['client'], $client_hash)) {
			return twcp_oauth_error('invalid_grant', 'The refresh token is invalid or has expired.');
		}

		$tokens = twcp_oauth_issue((int) $session['user'], $client_hash, $session['name']);

	} else {
		return twcp_oauth_error('unsupported_grant_type', 'Supported grant types are authorization_code and refresh_token.');
	}

	return twcp_oauth_response([
		'access_token'  => $tokens['access'],
		'token_type'    => 'Bearer',
		'expires_in'    => TWCP_ACCESS_TTL,
		'refresh_token' => $tokens['refresh'],
		'scope'         => 'straps:manage',
	]);
}


/**
 * Issue and persist a new access token and refresh token pair
 *
 * @param int    $user_id
 * @param string $client_hash
 * @param string $client_name
 *
 * @return array
 */
function twcp_oauth_issue(int $user_id, string $client_hash, string $client_name): array
{
	$access = bin2hex(random_bytes(32));
	$refresh = bin2hex(random_bytes(32));

	$sessions = twcp_oauth_sessions($user_id);

	$sessions[$client_hash] = [
		'access'          => hash('sha256', $access),
		'refresh'         => hash('sha256', $refresh),
		'name'            => $client_name,
		'expires'         => time() + TWCP_ACCESS_TTL,
		'refresh_expires' => time() + TWCP_REFRESH_TTL,
		'updated'         => time(),
	];

	update_user_meta($user_id, 'twcp_sessions', $sessions);

	return ['access' => $access, 'refresh' => $refresh];
}


/**
 * Look up a session by raw access or refresh token
 *
 * @param string $token
 * @param string $type
 *
 * @return array|null
 */
function twcp_oauth_session(string $token, string $type = 'access'): ?array
{
	if ($token === '' or !in_array($type, ['access', 'refresh'], true)) {
		return null;
	}
	$hash = hash('sha256', $token);

	global $wpdb;

	$results = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'twcp_sessions' AND meta_value LIKE %s", '%' . $wpdb->esc_like($hash) . '%'));

	foreach ($results as $row) {
		$sessions = maybe_unserialize($row->meta_value);

		if (!is_array($sessions)) {
			continue;
		}

		foreach ($sessions as $client => $session) {
			if (!empty($session[$type]) and hash_equals($session[$type], $hash)) {
				$expires = $type === 'access' ? ($session['expires'] ?? 0) : ($session['refresh_expires'] ?? (($session['updated'] ?? 0) + TWCP_REFRESH_TTL));
				if (time() >= $expires) {
					return null;
				}

				return [
					'user'   => (int) $row->user_id,
					'client' => $client,
					'name'   => $session['name'],
				];
			}
		}
	}

	return null;
}
