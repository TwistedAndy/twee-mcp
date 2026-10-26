<?php
/**
 * Experimental ChatGPT plan provider ("Sign in with ChatGPT")
 *
 * Runs model requests on a ChatGPT Plus or Pro plan instead of an API key.
 * OpenAI only lets the sign-in return to a loopback address, so an administrator approves
 * the connection in the browser and pastes the final 127.0.0.1 address back into the plugin.
 *
 * - Sign-in:   https://developers.openai.com/siwc/token-sharing-open-source/sign-in
 * - Inference: https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference
 * - Limits:    https://developers.openai.com/siwc/token-sharing-open-source/preview-limitations
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

define('TWCP_CHATGPT_AUTHORIZE', 'https://auth.openai.com/api/accounts/authorize');
define('TWCP_CHATGPT_TOKEN', 'https://auth.openai.com/api/accounts/oauth/token');
define('TWCP_CHATGPT_ISSUER', 'https://auth.openai.com');
define('TWCP_CHATGPT_REDIRECT', 'http://127.0.0.1:1455/auth/callback');
define('TWCP_CHATGPT_RESOURCE', 'https://api.openai.com/v1');
define('TWCP_CHATGPT_SCOPE', 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct');
define('TWCP_CHATGPT_INTEREST', 'https://openai.com/form/sign-in-with-chatgpt-interest/');


/**
 * Saved ChatGPT registration and tokens
 *
 * @return array client_id, access_token, refresh_token, id_token, expires, scopes, email, connected
 */
function twcp_chatgpt_account(): array
{
	$account = get_option('twcp_chatgpt', []);

	return is_array($account) ? $account : [];
}


/**
 * Save the ChatGPT registration without autoloading the tokens on every request
 *
 * @param array $account
 */
function twcp_chatgpt_save(array $account): void
{
	update_option('twcp_chatgpt', $account, false);
}


/**
 * Whether model requests can run on the ChatGPT plan
 *
 * @return bool
 */
function twcp_chatgpt_connected(): bool
{
	$account = twcp_chatgpt_account();

	return !empty($account['refresh_token']) and in_array('chatgpt.tokens.use.direct', $account['scopes'] ?? [], true);
}


/**
 * Stable host ID of this site. OpenAI tells hosts of the same account apart by it.
 *
 * @return string
 */
function twcp_chatgpt_host(): string
{
	$host = (string) get_option('twcp_chatgpt_host', '');

	if ($host === '') {
		$host = 'urn:uuid:' . wp_generate_uuid4();
		update_option('twcp_chatgpt_host', $host, false);
	}

	return $host;
}


/**
 * Connection details for the AI Provider screen
 *
 * @return array
 */
function twcp_chatgpt_status(): array
{
	$account = twcp_chatgpt_account();

	return [
		'connected'  => twcp_chatgpt_connected(),
		'registered' => !empty($account['client_id']),
		'email'      => $account['email'] ?? '',
		'since'      => (int) ($account['connected'] ?? 0),
		'error'      => $account['error'] ?? '',
		'redirect'   => TWCP_CHATGPT_REDIRECT,
		'interest'   => TWCP_CHATGPT_INTEREST,
	];
}


/**
 * Start a sign-in and return the OpenAI authorization address.
 * A saved registration signs in again with its issued client ID.
 *
 * @return string
 */
function twcp_chatgpt_start(): string
{
	$account = twcp_chatgpt_account();
	$verifier = twcp_oauth_encode(random_bytes(48));
	$pending = [
		'verifier' => $verifier,
		'state'    => twcp_oauth_encode(random_bytes(24)),
		'nonce'    => twcp_oauth_encode(random_bytes(24)),
		'client'   => $account['client_id'] ?? '',
	];

	set_transient('twcp_chatgpt_pending_' . get_current_user_id(), $pending, 30 * MINUTE_IN_SECONDS);

	$args = [
		'response_type'         => 'code',
		'client_id'             => $pending['client'] ? : 'dynamic_agent_client',
		'redirect_uri'          => TWCP_CHATGPT_REDIRECT,
		'scope'                 => TWCP_CHATGPT_SCOPE,
		'resource'              => TWCP_CHATGPT_RESOURCE,
		'state'                 => $pending['state'],
		'nonce'                 => $pending['nonce'],
		'code_challenge'        => twcp_oauth_encode(hash('sha256', $verifier, true)),
		'code_challenge_method' => 'S256',
		'ext_agent_host_id'     => twcp_chatgpt_host(),
	];

	if ($pending['client']) {
		if (!empty($account['id_token'])) {
			$args['id_token_hint'] = $account['id_token'];
		}
		if (!empty($account['email'])) {
			$args['login_hint'] = $account['email'];
		}
	} else {
		$args['agent_name_hint'] = mb_substr((get_bloginfo('name') ? : 'WordPress') . ' AI Tools', 0, 60);
	}

	return TWCP_CHATGPT_AUTHORIZE . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
}


/**
 * Finish a sign-in from the pasted 127.0.0.1 callback address
 *
 * @param string $callback
 *
 * @return string Error message, empty on success
 */
function twcp_chatgpt_finish(string $callback): string
{
	$key = 'twcp_chatgpt_pending_' . get_current_user_id();
	$pending = get_transient($key);

	if (!is_array($pending)) {
		return __('The sign-in expired. Click Sign in with ChatGPT again.', 'twee-mcp');
	}

	parse_str((string) wp_parse_url(trim($callback), PHP_URL_QUERY), $query);

	if (($query['error'] ?? '') === 'access_denied') {
		delete_transient($key);

		return __('ChatGPT plan use was not allowed. Sign in again and allow plan usage on the consent screen, or use an API key instead.', 'twee-mcp');
	}
	if (!empty($query['error'])) {
		return sprintf(__('OpenAI returned an error: %s', 'twee-mcp'), sanitize_text_field($query['error_description'] ?? $query['error']));
	}
	if (empty($query['code']) or empty($query['state'])) {
		return __('Paste the full address from the browser bar. It starts with http://127.0.0.1:1455/auth/callback?code=', 'twee-mcp');
	}
	if (!hash_equals($pending['state'], (string) $query['state'])) {
		return __('This address belongs to a different sign-in. Click Sign in with ChatGPT again.', 'twee-mcp');
	}

	// A new registration must come back with its issued client ID, and a repeated one with the same ID
	$client_id = (string) ($query['client_id'] ?? '');
	if ($pending['client']) {
		if ($client_id !== '' and $client_id !== $pending['client']) {
			return __('OpenAI returned a different registration. Disconnect and sign in again.', 'twee-mcp');
		}
		$client_id = $pending['client'];
	} elseif ($client_id === '' or $client_id === 'dynamic_agent_client') {
		return __('OpenAI did not finish registering this site. Click Sign in with ChatGPT again.', 'twee-mcp');
	}

	delete_transient($key);

	$tokens = twcp_chatgpt_grant([
		'grant_type'    => 'authorization_code',
		'client_id'     => $client_id,
		'code'          => (string) $query['code'],
		'code_verifier' => $pending['verifier'],
		'redirect_uri'  => TWCP_CHATGPT_REDIRECT,
		'resource'      => TWCP_CHATGPT_RESOURCE,
	]);
	if (is_string($tokens)) {
		return $tokens;
	}

	$claims = twcp_chatgpt_claims($tokens['id_token'] ?? '', $client_id, $pending['nonce']);
	if (is_string($claims)) {
		return $claims;
	}

	$account = twcp_chatgpt_account();
	if (!empty($account['sub']) and $account['sub'] !== $claims['sub'] and $pending['client']) {
		return __('You signed in with a different ChatGPT account. Disconnect first to switch accounts.', 'twee-mcp');
	}

	$account = twcp_chatgpt_tokens(['client_id' => $client_id, 'sub' => $claims['sub'], 'email' => $claims['email'] ?? '', 'connected' => time()], $tokens);
	twcp_chatgpt_save($account);
	delete_transient('twcp_models_chatgpt');

	if (!twcp_chatgpt_connected()) {
		return __('Signed in, but ChatGPT plan usage was not granted. Sign in again and allow plan usage on the consent screen.', 'twee-mcp');
	}

	// List the account's models now, so the model fields are filled after the reload
	twcp_model_fetch('chatgpt', null, true);

	return '';
}


/**
 * Check the ID token claims. Its signature is not verified: the token comes straight from
 * the OpenAI token endpoint over TLS, which OpenID Connect accepts as proof of origin.
 *
 * @param string $token
 * @param string $client_id
 * @param string $nonce
 *
 * @return array|string Claims, or an error message
 */
function twcp_chatgpt_claims(string $token, string $client_id, string $nonce)
{
	$parts = explode('.', $token);
	$claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;

	if (!is_array($claims) or empty($claims['sub'])) {
		return __('OpenAI did not return a valid ID token.', 'twee-mcp');
	}

	$audience = (array) ($claims['aud'] ?? []);
	if (($claims['iss'] ?? '') !== TWCP_CHATGPT_ISSUER or !in_array($client_id, $audience, true) or (int) ($claims['exp'] ?? 0) < time() - 300 or !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
		return __('The ID token failed validation. Click Sign in with ChatGPT again.', 'twee-mcp');
	}

	return $claims;
}


/**
 * Request tokens from the OpenAI token endpoint
 *
 * @param array $params
 *
 * @return array|string Token response, or an error message
 */
function twcp_chatgpt_grant(array $params)
{
	$response = wp_remote_post(TWCP_CHATGPT_TOKEN, [
		'timeout' => 20,
		'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
		'body'    => $params,
	]);

	if (is_wp_error($response)) {
		return $response->get_error_message();
	}

	$data = json_decode(wp_remote_retrieve_body($response), true);
	if (wp_remote_retrieve_response_code($response) !== 200 or empty($data['access_token'])) {
		$code = is_array($data) ? (string) ($data['error'] ?? '') : '';

		return $code !== '' ? 'oauth:' . $code : sprintf(__('The OpenAI token request failed (HTTP %d).', 'twee-mcp'), wp_remote_retrieve_response_code($response));
	}

	return $data;
}


/**
 * Merge a token response into the saved account
 *
 * @param array $account
 * @param array $tokens
 *
 * @return array
 */
function twcp_chatgpt_tokens(array $account, array $tokens): array
{
	$account = array_merge(twcp_chatgpt_account(), $account);

	$account['access_token'] = $tokens['access_token'];
	$account['expires'] = time() + (int) ($tokens['expires_in'] ?? 3600);
	if (!empty($tokens['refresh_token'])) {
		$account['refresh_token'] = $tokens['refresh_token'];
	}
	if (!empty($tokens['id_token'])) {
		$account['id_token'] = $tokens['id_token'];
	}
	if (isset($tokens['scope'])) {
		$account['scopes'] = preg_split('/\s+/', trim((string) $tokens['scope']), -1, PREG_SPLIT_NO_EMPTY);
	}
	unset($account['error']);

	return $account;
}


/**
 * Current access token, refreshed shortly before it expires.
 * Refresh tokens rotate, so concurrent batch requests take turns through a lock.
 *
 * @return string Empty when the plan is not connected
 */
function twcp_chatgpt_token(): string
{
	$locked = false;

	for ($attempt = 0; $attempt < 20; $attempt++) {
		wp_cache_delete('twcp_chatgpt', 'options');
		$account = twcp_chatgpt_account();

		if (empty($account['refresh_token'])) {
			if ($locked) {
				delete_option('twcp_chatgpt_lock');
			}
			twcp_model_last_error($account['error'] ?? __('ChatGPT is not connected. Sign in on the AI Provider screen.', 'twee-mcp'));

			return '';
		}
		if (!empty($account['access_token']) and (int) ($account['expires'] ?? 0) > time() + 120) {
			if ($locked) {
				delete_option('twcp_chatgpt_lock');
			}

			return $account['access_token'];
		}
		if ($locked) {
			break;
		}

		// add_option() fails while another request holds the lock; read the account once more after taking it
		if (add_option('twcp_chatgpt_lock', time(), '', false)) {
			$locked = true;
			continue;
		}
		wp_cache_delete('twcp_chatgpt_lock', 'options');
		if ((int) get_option('twcp_chatgpt_lock') < time() - 30) {
			delete_option('twcp_chatgpt_lock');
			continue;
		}
		usleep(500000);
	}

	if (!$locked) {
		twcp_model_last_error(__('Another request is renewing the ChatGPT sign-in. Try again in a moment.', 'twee-mcp'));

		return '';
	}

	$tokens = twcp_chatgpt_grant([
		'grant_type'    => 'refresh_token',
		'client_id'     => $account['client_id'] ?? '',
		'refresh_token' => $account['refresh_token'],
		'resource'      => TWCP_CHATGPT_RESOURCE,
	]);

	if (is_array($tokens)) {
		$account = twcp_chatgpt_tokens([], $tokens);
		twcp_chatgpt_save($account);
	} elseif (str_starts_with($tokens, 'oauth:') and $tokens !== 'oauth:invalid_client') {
		// The refresh token is unusable: keep the registration and ask for a new sign-in
		unset($account['access_token'], $account['refresh_token']);
		$account['error'] = __('The ChatGPT sign-in expired or was revoked. Sign in again.', 'twee-mcp');
		twcp_chatgpt_save($account);
	}

	delete_option('twcp_chatgpt_lock');

	if (is_string($tokens)) {
		twcp_model_last_error($account['error'] ?? sprintf(__('Could not renew the ChatGPT sign-in: %s', 'twee-mcp'), $tokens));

		return '';
	}

	return $account['access_token'];
}


/**
 * Sign out: revoke the refresh token and drop the tokens.
 * The registration and host ID stay, so the next sign-in reuses them.
 *
 * @return string Notice about the revocation
 */
function twcp_chatgpt_disconnect(): string
{
	$account = twcp_chatgpt_account();
	$revoked = empty($account['refresh_token']);

	if (!$revoked) {
		$config = wp_remote_get(TWCP_CHATGPT_ISSUER . '/.well-known/openid-configuration', ['timeout' => 10]);
		$endpoint = is_wp_error($config) ? '' : (string) (json_decode(wp_remote_retrieve_body($config), true)['revocation_endpoint'] ?? '');

		if ($endpoint) {
			$response = wp_remote_post($endpoint, [
				'timeout' => 15,
				'body'    => [
					'token'           => $account['refresh_token'],
					'token_type_hint' => 'refresh_token',
					'client_id'       => $account['client_id'] ?? '',
				],
			]);
			$revoked = (!is_wp_error($response) and wp_remote_retrieve_response_code($response) === 200);
		}
	}

	unset($account['access_token'], $account['refresh_token'], $account['expires'], $account['error']);
	twcp_chatgpt_save($account);
	delete_transient('twcp_models_chatgpt');

	return $revoked ? __('Disconnected from ChatGPT.', 'twee-mcp') : __('Disconnected here, but OpenAI did not confirm the revocation. Remove the app in ChatGPT Settings to be sure.', 'twee-mcp');
}


/**
 * Models the signed-in plan can use, in the server's order
 *
 * @return array[] [ id, label ]
 */
function twcp_chatgpt_models(): array
{
	$token = twcp_chatgpt_token();
	if ($token === '') {
		return [];
	}

	$response = wp_remote_get(TWCP_CHATGPT_RESOURCE . '/models', [
		'timeout' => 15,
		'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
	]);
	$data = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);

	$models = [];
	foreach ((array) ($data['models'] ?? []) as $model) {
		if (($model['visibility'] ?? '') === 'list' and !empty($model['slug'])) {
			$models[] = ['id' => $model['slug'], 'label' => $model['display_name'] ?? $model['slug']];
		}
	}

	return $models;
}


/**
 * Send a request to the Responses API on the ChatGPT plan
 *
 * Plan requests must stream, cannot be stored, and reject temperature and output limits,
 * so those options are dropped. The whole conversation is sent with every lookup round.
 *
 * @param string $prompt
 * @param string $system
 * @param array  $images
 * @param string $model
 * @param array  $options
 *
 * @return string
 */
function twcp_chatgpt_request(string $prompt, string $system, array $images, string $model, array $options): string
{
	// No saved model yet: use the first model the account lists
	if ($model === '') {
		$model = (string) (twcp_model_fetch('chatgpt')[0]['id'] ?? '');
	}
	if ($model === '') {
		twcp_model_last_error(__('Choose a ChatGPT model on the AI Provider screen.', 'twee-mcp'));

		return '';
	}

	$content = [['type' => 'input_text', 'text' => $prompt]];
	$max_edge = (int) ($options['image_size'] ?? 0) ? : twcp_model_settings()['vision_size'];
	foreach ($images as $image) {
		$payload = twcp_model_image_payload($image, $max_edge);
		if ($payload) {
			$content[] = ['type' => 'input_image', 'image_url' => $payload['url'], 'detail' => $max_edge <= 512 ? 'low' : 'auto'];
		}
	}

	$body = [
		'model'  => $model,
		'input'  => [['role' => 'user', 'content' => $content]],
		'store'  => false,
		'stream' => true,
	];
	if ($system !== '') {
		$body['instructions'] = $system;
	}

	$read_tools = $options['read_tools'] ?? false;
	foreach (twcp_model_lookup_tools($read_tools) as $tool) {
		$body['tools'][] = ['type' => 'function', 'name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['schema']];
	}
	if (!empty($body['tools'])) {
		$body['input'][] = ['role' => 'user', 'content' => twcp_model_lookup_note()];
	}

	$deadline = time() + 180;
	$tool_count = 0;

	for ($round = 0; $round <= (isset($body['tools']) ? 3 : 0); $round++) {
		if ($round === 3 or $tool_count >= 8) {
			unset($body['tools']);
		}
		if ($deadline <= time()) {
			break;
		}

		$output = twcp_chatgpt_send($body, $deadline);
		if ($output === null) {
			return '';
		}

		$text = '';
		$calls = [];
		foreach ($output as $item) {
			if (($item['type'] ?? '') === 'message') {
				foreach ($item['content'] ?? [] as $part) {
					$text .= ($part['type'] ?? '') === 'output_text' ? $part['text'] : '';
				}
			} elseif (($item['type'] ?? '') === 'function_call') {
				$calls[] = $item;
			}
		}

		if (!$calls or !isset($body['tools'])) {
			return trim(str_replace(['```html', '```json', '```'], '', $text));
		}

		foreach ($calls as $call) {
			// Unstored responses cannot be referenced by item ID, so the call is sent back without it
			$body['input'][] = ['type' => 'function_call', 'call_id' => $call['call_id'], 'name' => $call['name'], 'arguments' => $call['arguments']];
			$body['input'][] = [
				'type'    => 'function_call_output',
				'call_id' => $call['call_id'],
				'output'  => twcp_model_lookup_call($call['name'], json_decode($call['arguments'] ?? '', true), $tool_count),
			];
		}
	}

	twcp_model_last_error('Model lookup budget exhausted without a final response.');

	return '';
}


/**
 * POST one streamed Responses request and return the output items of the completed response
 *
 * @param array $body
 * @param int   $deadline
 *
 * @return array|null Output items, or null with the last error set
 */
function twcp_chatgpt_send(array $body, int $deadline): ?array
{
	for ($attempt = 0; $attempt < 2; $attempt++) {
		$token = twcp_chatgpt_token();
		if ($token === '') {
			return null;
		}

		$response = wp_remote_post(TWCP_CHATGPT_RESOURCE . '/responses', [
			'timeout' => max(1, min(120, $deadline - time())),
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'text/event-stream',
			],
			'body'    => wp_json_encode($body),
		]);
		if (is_wp_error($response)) {
			twcp_model_last_error($response->get_error_message());

			return null;
		}

		$status = wp_remote_retrieve_response_code($response);
		$raw = wp_remote_retrieve_body($response);
		$error = null;

		if ($status === 200) {
			// Unstored responses complete with an empty output, so items are collected as they finish
			$output = [];
			foreach (twcp_chatgpt_events($raw) as $event) {
				$type = $event['type'] ?? '';
				if ($type === 'response.output_item.done' and isset($event['item'])) {
					$output[] = $event['item'];
				}
				if ($type === 'response.completed') {
					return $output ? : (array) ($event['response']['output'] ?? []);
				}
				if ($type === 'response.failed' or $type === 'error') {
					$error = $event['response']['error'] ?? $event['error'] ?? $event;
				}
				if ($type === 'response.incomplete') {
					$error = ['code' => 'incomplete', 'message' => 'The response stopped early: ' . ($event['response']['incomplete_details']['reason'] ?? 'unknown reason') . '.'];
				}
			}
			$error = $error ?? ['code' => 'interrupted', 'message' => 'The response stream ended before it completed.'];
		} else {
			$data = json_decode($raw, true);
			$error = $data['error'] ?? ['code' => '', 'message' => is_array($data) ? (string) ($data['detail'] ?? '') : ''];
			$error['status'] = $status;
		}

		$code = (string) ($error['code'] ?? '');
		$retry = ($attempt === 0 and (in_array($code, ['subscription_sharing_usage_unavailable', 'subscription_sharing_user_unavailable'], true) or ($error['status'] ?? 0) === 503));
		if (!$retry or $deadline - time() < 10) {
			twcp_model_last_error(twcp_chatgpt_error($error, $status));

			return null;
		}
		sleep(3);
	}

	return null;
}


/**
 * Decode the data lines of a server-sent event stream
 *
 * @param string $raw
 *
 * @return array[]
 */
function twcp_chatgpt_events(string $raw): array
{
	$events = [];

	foreach (preg_split('/\r?\n\r?\n/', $raw) as $chunk) {
		$data = '';
		foreach (preg_split('/\r?\n/', $chunk) as $line) {
			if (str_starts_with($line, 'data:')) {
				$data .= ltrim(substr($line, 5));
			}
		}
		$event = $data !== '' ? json_decode($data, true) : null;
		if (is_array($event)) {
			$events[] = $event;
		}
	}

	return $events;
}


/**
 * Readable message for a plan request error
 *
 * @param array $error
 * @param int   $status
 *
 * @return string
 */
function twcp_chatgpt_error(array $error, int $status): string
{
	$code = (string) ($error['code'] ?? '');
	$messages = [
		'subscription_sharing_usage_limit_exceeded'  => __('The ChatGPT plan usage limit for this app is reached. Check ChatGPT Settings > Usage, or switch to an API key for now.', 'twee-mcp'),
		'subscription_sharing_user_not_eligible'     => __('ChatGPT plan usage is not available for this account or workspace.', 'twee-mcp'),
		'subscription_sharing_usage_unavailable'     => __('ChatGPT plan usage is temporarily unavailable. Try again later.', 'twee-mcp'),
		'subscription_sharing_user_unavailable'      => __('ChatGPT plan usage is temporarily unavailable. Try again later.', 'twee-mcp'),
		'subscription_sharing_invalid_user'          => __('OpenAI did not accept the ChatGPT sign-in. Sign in again on the AI Provider screen.', 'twee-mcp'),
		'subscription_sharing_unsupported_capability' => sprintf(__('The ChatGPT plan does not support this request (%s). Try another model or turn the feature off.', 'twee-mcp'), $error['param'] ?? 'unknown'),
	];

	if (isset($messages[$code])) {
		return $messages[$code];
	}

	$message = trim((string) ($error['message'] ?? ''));

	return 'ChatGPT plan request failed' . ($status !== 200 ? ' (HTTP ' . $status . ')' : '') . ($code !== '' ? ' [' . $code . ']' : '') . ($message !== '' ? ': ' . $message : '.');
}
