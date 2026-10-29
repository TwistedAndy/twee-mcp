<?php
/**
 * Twee MCP — Tools Implementation & JSON-RPC Protocol Dispatcher
 *
 * Implements the Model Context Protocol (Streamable HTTP / JSON-RPC 2.0)
 * endpoint at /wp-json/straps/v1/mcp.
 *
 * Each module adds groups of tools with the twcp_tools_groups filter.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Get MCP server REST endpoint URL
 *
 * @param string $path
 *
 * @return string
 */
function twcp_mcp_url(string $path = ''): string
{
	return set_url_scheme(rest_url('straps/v1/mcp' . $path));
}


/**
 * Register MCP REST endpoint and CORS filters
 */
add_action('rest_api_init', 'twcp_tools_init');

/**
 * Register the MCP REST endpoint.
 *
 * @return void
 */
function twcp_tools_init(): void
{
	register_rest_route('straps/v1', '/mcp', [
		'methods'             => 'GET, POST, DELETE',
		'callback'            => 'twcp_tools_rest_handler',
		'permission_callback' => '__return_true',
	]);
}

add_filter('rest_allowed_cors_headers', function($headers) {
	return array_merge($headers, ['Mcp-Session-Id', 'MCP-Protocol-Version', 'Authorization']);
});

add_filter('rest_exposed_cors_headers', function($headers) {
	return array_merge($headers, ['WWW-Authenticate']);
});

add_filter('rest_pre_serve_request', function($served, $result, $request) {
	if ($request->get_route() === '/straps/v1/mcp' and $result->get_status() === 202) {
		return true;
	}

	return $served;
}, 20, 3);


/**
 * Authenticate incoming request via OAuth Bearer token or Application Password
 *
 * @param WP_REST_Request $request
 *
 * @return bool
 */
function twcp_tools_authenticate(WP_REST_Request $request): bool
{
	$header = (string) $request->get_header('authorization');

	if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
		$session = twcp_oauth_session($matches[1], 'access');
		if (!$session) {
			wp_set_current_user(0);

			return false;
		}
		wp_set_current_user($session['user']);
	}

	return current_user_can(TWCP_CAPABILITY);
}


/**
 * Format a JSON-RPC 2.0 error response
 *
 * @param mixed  $id
 * @param int    $code
 * @param string $message
 *
 * @return array
 */
function twcp_tools_error($id, int $code, string $message): array
{
	return [
		'jsonrpc' => '2.0',
		'id'      => $id,
		'error'   => [
			'code'    => $code,
			'message' => $message,
		],
	];
}


/**
 * All MCP tools, each with the group it was added in, and the hint of that group unless it has its own
 *
 * @return array Tool name => [ group, description, callable, capability, private, parameters, required, annotations, hint ]
 */
function twcp_tools_registry(): array
{
	static $tools;
	if (isset($tools)) {
		return $tools;
	}

	$tools = [];
	foreach (twcp_tools_groups() as $group => $info) {
		foreach ($info['tools'] as $name => $tool) {
			$tools[$name] = $tool + ['group' => $group, 'hint' => $info['hint'] ?? ''];
		}
	}

	return $tools;
}


/**
 * Validate a YYYY-MM-DD date
 *
 * @param mixed $date
 *
 * @return string|null Normalized date or null
 */
function twcp_tools_date($date): ?string
{
	$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, wp_timezone());

	return ($parsed and $parsed->format('Y-m-d') === $date) ? $date : null;
}


/**
 * Tool groups added with the twcp_tools_groups filter, shown on the Agent Tools screen in the order of their position.
 * A group of a module whose plugin is not running has "active" set to false, so its tools are unavailable.
 *
 * Each tool has a description, its callable, parameters, required parameters, and annotations, and may set a "hint"
 * of its own, "private" to keep it out of model lookups, and who may run it, see twcp_tools_access().
 *
 * @return array Group => [ label, position, module, hint, tools, plugin, active ]
 */
function twcp_tools_groups(): array
{
	static $added;
	$added ??= twcp_position_sort((array) apply_filters('twcp_tools_groups', []));

	$groups = $added;
	$modules = twcp_modules();
	foreach ($groups as $group => $info) {
		$module = $modules[$info['module'] ?? ''] ?? [];
		$groups[$group] += [
			'plugin' => $module['plugin'] ?? '',
			'active' => !isset($info['module']) or !empty($module['active']),
		];
	}

	return $groups;
}


/**
 * Plugin a tool requires when that plugin is not active
 *
 * @param string $name
 *
 * @return string|null Plugin name, or null when the tool can run
 */
function twcp_tools_missing(string $name): ?string
{
	$registry = twcp_tools_registry();
	$groups = twcp_tools_groups();
	$group = $groups[$registry[$name]['group'] ?? ''] ?? ['active' => true];

	return $group['active'] ? null : $group['plugin'];
}


/**
 * Tools switched off on the Agent Tools screen
 *
 * @return string[]
 */
function twcp_tools_disabled(): array
{
	return twcp_tools_sanitize_disabled(get_option('twcp_disabled_tools', []));
}


/**
 * Sanitize the disabled tools setting.
 * The Agent Tools screen posts name => 1|0 (1 = enabled), code may store a plain list of names.
 *
 * @param mixed $input
 *
 * @return string[]
 */
function twcp_tools_sanitize_disabled($input): array
{
	$registry = twcp_tools_registry();
	$disabled = [];

	foreach ((array) $input as $key => $value) {
		$name = is_int($key) ? (string) $value : (string) $key;
		if (isset($registry[$name]) and (is_int($key) or !$value)) {
			$disabled[] = $name;
		}
	}

	return array_values(array_unique($disabled));
}


/**
 * Registry tools the site exposes: required plugins are active and the tool is not disabled
 *
 * @return array
 */
function twcp_tools_enabled(): array
{
	$disabled = twcp_tools_disabled();
	$inactive = [];
	foreach (twcp_tools_groups() as $group => $info) {
		if (!$info['active']) {
			$inactive[] = $group;
		}
	}

	return array_filter(twcp_tools_registry(), function(array $tool, string $name) use ($disabled, $inactive): bool {
		return !in_array($name, $disabled, true) and !in_array($tool['group'], $inactive, true);
	}, ARRAY_FILTER_USE_BOTH);
}


/**
 * Build the MCP tools list from the enabled registry tools
 *
 * @return array
 */
function twcp_tools_list(): array
{
	$tools = [];

	foreach (twcp_tools_enabled() as $name => $tool) {
		$tools[] = [
			'name'        => $name,
			'description' => $tool['description'],
			'inputSchema' => twcp_tools_schema($tool),
			'annotations' => !empty($tool['annotations']) ? $tool['annotations'] : [],
		];
	}

	return $tools;
}


/**
 * JSON schema of a registry tool's arguments
 *
 * @param array $tool
 *
 * @return array
 */
function twcp_tools_schema(array $tool): array
{
	return [
		'type'       => 'object',
		'properties' => !empty($tool['parameters']) ? $tool['parameters'] : new stdClass(),
		'required'   => !empty($tool['required']) ? $tool['required'] : [],
	];
}


/**
 * Check whether the current user can run a registered tool. A tool with "public" runs for anyone, as it only returns
 * what the user may see. A tool with "capability" needs it, and its callable checks single objects. A tool with
 * "access" checks its arguments itself: function(array $input) returning an error message or null. The others change
 * the post or term their arguments name, see twcp_tools_access_target(). Read-only tools need no more than a
 * signed-in user who can edit posts.
 *
 * @param string $name      Tool name.
 * @param array  $input     Validated tool arguments.
 * @param bool   $read_only Whether the registry marks the tool as read-only.
 *
 * @return string|null Error message, or null when access is allowed.
 */
function twcp_tools_access(string $name, array $input, bool $read_only = false): ?string
{
	$tool = twcp_tools_registry()[$name] ?? [];
	if (!empty($tool['public'])) {
		return null;
	}
	if (!current_user_can(TWCP_CAPABILITY)) {
		return 'This account cannot use content management tools.';
	}
	if (!empty($tool['capability'])) {
		return current_user_can($tool['capability']) ? null : 'This account cannot use the ' . $name . ' tool.';
	}
	if ($read_only) {
		return null;
	}

	return isset($tool['access']) ? call_user_func($tool['access'], $input) : twcp_tools_access_target($input);
}


/**
 * Check that the current user can change the post or term the arguments of a tool name: the first of the ID
 * arguments, a term when it is term_id or the object type says so
 *
 * @param array  $input
 * @param string $capability Post capability, such as delete_post for a tool that trashes the post
 *
 * @return string|null Error message, or null when access is allowed
 */
function twcp_tools_access_target(array $input, string $capability = 'edit_post'): ?string
{
	$id = 0;
	$target = 'post';
	foreach (['id', 'post_id', 'product_id', 'attachment_id', 'object_id', 'term_id', 'target_id'] as $key) {
		if (isset($input[$key])) {
			$id = absint($input[$key]);
			$target = $key === 'term_id' ? 'term' : 'post';
			break;
		}
	}

	$target = $input['object_type'] ?? $input['type'] ?? $input['target_type'] ?? $target;
	if (in_array($target, ['term', 'category'], true)) {
		return current_user_can('edit_term', $id) ? null : 'You cannot edit this term.';
	}

	$post = get_post($id);
	if (!$post) {
		return 'Target post, product, or attachment was not found.';
	}
	if (!current_user_can($capability, $id)) {
		return 'You cannot modify this content.';
	}

	$status = $input['post_status'] ?? $input['status'] ?? '';
	if (in_array($status, ['publish', 'future', 'private'], true) and !current_user_can(get_post_type_object($post->post_type)->cap->publish_posts)) {
		return 'You cannot publish this post type.';
	}

	return null;
}


/**
 * Check that the current user can create posts of a type, and publish them when the status asks for it
 *
 * @param string $post_type
 * @param string $status
 *
 * @return string|null Error message, or null when access is allowed
 */
function twcp_tools_access_create(string $post_type, string $status): ?string
{
	$type = get_post_type_object($post_type);
	if (!$type or !$type->show_ui) {
		return 'Unknown or unsupported post type.';
	}
	if (!current_user_can($type->cap->create_posts)) {
		return 'You cannot create this post type.';
	}
	if (in_array($status, ['publish', 'future', 'private'], true) and !current_user_can($type->cap->publish_posts)) {
		return 'You cannot publish this post type.';
	}

	return null;
}


/**
 * Validate tool input, check access, and invoke the registered callable.
 *
 * @param string $name      Tool name.
 * @param array  $input     Tool arguments.
 * @param bool   $read_only Restrict execution to read-only tools.
 *
 * @return mixed Tool result.
 */
function twcp_tools_call(string $name, array $input, bool $read_only = false)
{
	$registry = twcp_tools_registry();
	if (!isset($registry[$name])) {
		return ['error' => 'Unsupported tool: ' . $name];
	}

	$plugin = twcp_tools_missing($name);
	if ($plugin !== null) {
		return ['error' => sprintf('The %s tool requires %s, which is not active on this site.', $name, $plugin)];
	}
	if (in_array($name, twcp_tools_disabled(), true)) {
		return ['error' => sprintf('The %s tool is disabled on this site.', $name)];
	}

	$is_read_only = !empty($registry[$name]['annotations']['readOnlyHint']);
	if ($read_only and (!$is_read_only or !empty($registry[$name]['private']))) {
		return ['error' => 'This tool is unavailable in read-only model lookups.'];
	}

	$schema = twcp_tools_schema($registry[$name]);
	$schema['properties'] = (array) $schema['properties'];
	$valid = rest_validate_value_from_schema($input, $schema, $name);
	if (is_wp_error($valid)) {
		return ['error' => $valid->get_error_message()];
	}

	$error = twcp_tools_access($name, $input, $is_read_only);
	if ($error !== null) {
		return ['error' => $error];
	}

	return $registry[$name]['callable']($input);
}


/**
 * Domain instructions sent to the AI client on initialize
 *
 * @return string
 */
function twcp_tools_instructions(): string
{
	$store_name = get_bloginfo('name') ? : 'Store';

	// A hint is sent once when one of the enabled tools carries it
	$lines = [];
	foreach (twcp_tools_enabled() as $tool) {
		if (!empty($tool['hint'])) {
			$lines[] = '- ' . $tool['hint'];
		}
	}
	$lines = implode("\n", array_unique($lines));

	return <<<INSTRUCTIONS
{$store_name} content management.

{$lines}

Management and lookup tools require authentication. Public search and fetch expose accessible content only.
INSTRUCTIONS;
}


/**
 * Check whether a request is safe for unauthenticated connector discovery
 *
 * @param array $body
 *
 * @return bool
 */
function twcp_tools_public(array $body): bool
{
	$messages = isset($body[0]) ? $body : [$body];

	foreach ($messages as $message) {
		if (!is_array($message)) {
			return false;
		}

		$method = isset($message['method']) ? (string) $message['method'] : '';

		// Methods that start the session or list what the server offers
		if (in_array($method, ['initialize', 'notifications/initialized', 'notifications/cancelled', 'ping', 'tools/list', 'prompts/list'], true)) {
			continue;
		}

		if ($method === 'tools/call') {
			$tool_name = $message['params']['name'] ?? '';
			// Only the public search and fetch tools; the annotations of a tool do not grant access
			if (!in_array($tool_name, ['search', 'fetch'], true)) {
				return false;
			}
			continue;
		}

		// Any other method needs a signed-in user
		return false;
	}

	return true;
}


/**
 * 401 response pointing the client to the OAuth protected resource metadata
 *
 * @return WP_REST_Response
 */
function twcp_tools_unauthorized(): WP_REST_Response
{
	$response = new WP_REST_Response(twcp_tools_error(null, -32001, 'Authentication required for content management tools.'), 401);
	$response->header('WWW-Authenticate', 'Bearer realm="straps", resource_metadata="' . set_url_scheme(rest_url('straps/v1/.well-known/oauth-protected-resource')) . '"');

	return $response;
}


/**
 * Handle incoming MCP REST request
 *
 * @param WP_REST_Request $request
 *
 * @return WP_REST_Response
 */
function twcp_tools_rest_handler(WP_REST_Request $request): WP_REST_Response
{
	if (!twcp_is_enabled()) {
		return new WP_REST_Response(twcp_tools_error(null, -32000, 'MCP Server is currently disabled.'), 503);
	}

	if ($request->get_method() === 'DELETE') {
		return new WP_REST_Response(null, 204);
	}

	if ($request->get_method() === 'GET') {
		return twcp_tools_unauthorized();
	}

	if ($request->get_method() !== 'POST') {
		$response = new WP_REST_Response(twcp_tools_error(null, -32600, 'Method not allowed.'), 405);
		$response->header('Allow', 'GET, POST, DELETE');

		return $response;
	}

	$body = json_decode($request->get_body(), true);

	if (!is_array($body) or empty($body)) {
		return new WP_REST_Response(twcp_tools_error(null, -32700, 'Parse error.'), 400);
	}

	// Only discovery and public connector search/fetch are available without authentication.
	$is_authenticated = twcp_tools_authenticate($request);
	$is_public = twcp_tools_public($body);

	if (!$is_authenticated and !$is_public) {
		if (is_user_logged_in()) {
			return new WP_REST_Response(twcp_tools_error(null, -32001, 'This account is not authorized to use content management tools.'), 403);
		}

		return twcp_tools_unauthorized();
	}

	$is_batch = isset($body[0]);
	$replies = [];

	foreach ($is_batch ? $body : [$body] as $message) {
		$reply = is_array($message) ? twcp_tools_dispatch($message) : twcp_tools_error(null, -32600, 'Invalid request.');
		if ($reply) {
			$replies[] = $reply;
		}
	}

	if (empty($replies)) {
		return new WP_REST_Response(null, 202);
	}

	return new WP_REST_Response($is_batch ? $replies : $replies[0], 200);
}


/**
 * Dispatch an individual JSON-RPC message
 *
 * @param array $message
 *
 * @return array|null
 */
function twcp_tools_dispatch(array $message): ?array
{
	$method = isset($message['method']) ? (string) $message['method'] : '';
	$id = $message['id'] ?? null;
	$params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

	switch ($method) {
		case 'initialize':
			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => [
					'protocolVersion' => TWCP_PROTOCOL,
					'capabilities'    => [
						'tools'   => ['listChanged' => false],
						'prompts' => ['listChanged' => false],
					],
					'serverInfo'      => [
						'name'    => 'straps-mcp',
						'version' => TWCP_VERSION,
					],
					'instructions'    => twcp_tools_instructions(),
				],
			];

		case 'notifications/notifications/initialized':
		case 'notifications/initialized':
		case 'notifications/cancelled':
			return null;

		case 'ping':
			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => new stdClass(),
			];

		case 'tools/list':
			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => [
					'tools' => twcp_tools_list(),
				],
			];

		case 'tools/call':
			$name = isset($params['name']) ? (string) $params['name'] : '';
			$args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

			try {
				$res = twcp_tools_call($name, $args);
			} catch (Throwable $e) {
				$res = ['error' => 'Tool failed: ' . $e->getMessage()];
			}

			// Tools such as preview_images return images as MCP image content
			$images = [];
			if (is_array($res) and isset($res['_mcp_images'])) {
				$images = array_map(fn(array $image) => ['type' => 'image'] + $image, $res['_mcp_images']);
				unset($res['_mcp_images']);
			}

			// Compact JSON: indentation costs the client tokens on every result
			$is_error = is_array($res) && isset($res['error']);
			if ($is_error) {
				$text = is_string($res['error']) ? $res['error'] : (string) wp_json_encode($res);
			} else {
				$text = is_string($res) ? $res : (string) wp_json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}

			$result = ['content' => array_merge([['type' => 'text', 'text' => $text]], $images)];
			if ($is_error) {
				$result['isError'] = true;
			}

			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			];

		case 'prompts/list':
			return [
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => ['prompts' => []],
			];

		default:
			if ($id === null) {
				return null;
			}

			return twcp_tools_error($id, -32601, 'Method not found: ' . $method);
	}
}
