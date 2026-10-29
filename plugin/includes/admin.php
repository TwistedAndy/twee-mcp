<?php
/**
 * Twee MCP — AI Tools Admin Interface
 *
 * Top-level "AI Tools" menu (last item of the admin menu) with one submenu page per tab:
 * - Automation   (batch jobs for content and shop managers, internal link settings)
 * - Prompts      (system prompts by area, copy-ready agent prompts)
 * - Library      (filled by modules: ACF blocks, stock image providers)
 * - AI Provider  (text & vision models, image size, API key, connection test)
 * - Agent Access (server toggle, endpoint, setup guides, OAuth sessions)
 * - Agent Tools  (MCP tool switches)
 *
 * Every tab renders template parts from parts/{part}.php that receive only the data they need. Modules add their
 * parts with the twcp_admin_tabs filter and their data with the twcp_admin_data filter.
 * Automation is open to editors and shop managers; the other tabs need manage_options.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Admin tabs. Modules add their template parts with the twcp_admin_tabs filter; a tab no module adds parts to,
 * such as the Library, is left out.
 *
 * @return array Tab => [ label, capability, template parts ]
 */
function twcp_admin_tabs(): array
{
	$tabs = (array) apply_filters('twcp_admin_tabs', [
		'automation' => ['label' => __('Automation', 'twee-mcp'), 'capability' => 'edit_others_posts', 'parts' => ['automation']],
		'prompts'    => ['label' => __('Prompts', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['prompts', 'examples']],
		'library'    => ['label' => __('Library', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => []],
		'model'      => ['label' => __('AI Provider', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['model']],
		'connection' => ['label' => __('Agent Access', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['connection']],
		'tools'      => ['label' => __('Agent Tools', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['modules', 'tools']],
	]);

	foreach ($tabs as $tab => $info) {
		if (empty($info['parts'])) {
			unset($tabs[$tab]);
		}
	}

	return $tabs;
}


/**
 * Page slugs of removed tabs, kept so bookmarks open the tab that took them over while it is there
 *
 * @return array Slug => tab
 */
function twcp_admin_legacy(): array
{
	return array_intersect([
		'tw-mcp-blocks'   => 'library',
		'tw-mcp-images'   => 'library',
		'tw-mcp-examples' => 'prompts',
	], array_keys(twcp_admin_tabs()));
}


/**
 * Menu page slug of a tab (the first tab owns the parent slug)
 *
 * @param string $tab
 *
 * @return string
 */
function twcp_admin_slug(string $tab): string
{
	return $tab === array_key_first(twcp_admin_tabs()) ? 'tw-mcp' : 'tw-mcp-' . $tab;
}


/**
 * Tab shown on the current admin page
 *
 * @return string
 */
function twcp_admin_tab(): string
{
	$page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

	foreach (array_keys(twcp_admin_tabs()) as $tab) {
		if (twcp_admin_slug($tab) === $page) {
			return $tab;
		}
	}

	return twcp_admin_legacy()[$page] ?? array_key_first(twcp_admin_tabs());
}


/**
 * Register settings
 */
add_action('admin_init', 'twcp_admin_init');

/**
 * Register plugin settings and sanitization callbacks.
 *
 * @return void
 */
function twcp_admin_init(): void
{
	$text = ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => ''];

	// Connection
	register_setting('twcp_general_group', 'twcp_enabled', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1]);
	register_setting('twcp_general_group', 'twcp_redirect_hosts', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => defined('TWCP_REDIRECT_HOSTS') ? TWCP_REDIRECT_HOSTS : "claude.ai\nclaude.com\nchatgpt.com",
	]);

	// Models
	register_setting('twcp_model_group', 'twcp_model_provider', ['type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'openai']);
	register_setting('twcp_model_group', 'twcp_model_api_key', $text);
	register_setting('twcp_model_group', 'twcp_model_name', $text);
	register_setting('twcp_model_group', 'twcp_vision_model', $text);
	register_setting('twcp_model_group', 'twcp_vision_size', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 768]);

	// Prompts, and the settings modules save with them
	foreach (array_keys(twcp_prompt_registry()) as $option) {
		register_setting('twcp_prompts_group', $option, [
			'type'              => 'string',
			'sanitize_callback' => fn($input): string => twcp_prompt_sanitize($input, $option),
			'default'           => '',
		]);
	}

	// Modules and MCP tools switched off on the Agent Tools screen
	register_setting('twcp_modules_group', 'twcp_disabled_modules', ['type' => 'array', 'sanitize_callback' => 'twcp_admin_sanitize_modules', 'default' => []]);
	register_setting('twcp_tools_group', 'twcp_disabled_tools', ['type' => 'array', 'sanitize_callback' => 'twcp_tools_sanitize_disabled', 'default' => []]);

	// Internal links of generated post content, saved from the Content Automation section by anyone who runs its jobs
	register_setting('twcp_content_group', 'twcp_post_links', [
		'type'              => 'array',
		'sanitize_callback' => function($input) {
			$input = is_array($input) ? $input : [];

			return [
				'max'     => isset($input['max']) ? min(15, max(0, (int) $input['max'])) : 4,
				'sources' => array_values(array_intersect(['terms', 'posts', 'pages', 'products', 'lookup'], (array) ($input['sources'] ?? []))),
			];
		},
		'default'           => [],
	]);
	add_filter('option_page_capability_twcp_content_group', fn() => 'edit_others_posts');

}


/**
 * Register the AI Tools menu and one submenu page per tab
 */
add_action('admin_menu', 'twcp_admin_menu');

/**
 * Register the Twee MCP settings pages in WordPress admin.
 *
 * @return void
 */
function twcp_admin_menu(): void
{
	$tabs = twcp_admin_tabs();
	$first = array_key_first($tabs);

	add_menu_page(__('AI Tools', 'twee-mcp'), __('AI Tools', 'twee-mcp'), $tabs[$first]['capability'], twcp_admin_slug($first), 'twcp_admin_page', 'dashicons-rest-api', 99);

	foreach ($tabs as $tab => $info) {
		add_submenu_page(twcp_admin_slug($first), sprintf(__('%s ‹ AI Tools', 'twee-mcp'), $info['label']), $info['label'], $info['capability'], twcp_admin_slug($tab), 'twcp_admin_page');
	}

	// Hidden pages for old bookmarks
	foreach (twcp_admin_legacy() as $slug => $tab) {
		if (!in_array($slug, array_map('twcp_admin_slug', array_keys($tabs)), true)) {
			add_submenu_page('', $tabs[$tab]['label'], $tabs[$tab]['label'], $tabs[$tab]['capability'], $slug, 'twcp_admin_page');
		}
	}
}


/**
 * Load assets on every AI Tools page
 */
add_action('admin_enqueue_scripts', 'twcp_admin_assets');

/**
 * Enqueue plugin assets on Twee MCP admin screens.
 *
 * @return void
 */
function twcp_admin_assets(): void
{
	$page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

	if ($page !== 'tw-mcp' and !str_starts_with($page, 'tw-mcp-')) {
		return;
	}

	$css = TWCP_PATH . 'assets/css/mcp.css';
	$js = TWCP_PATH . 'assets/js/mcp.js';

	wp_enqueue_style('twcp_mcp', TWCP_URL . 'assets/css/mcp.css', [], file_exists($css) ? (string) filemtime($css) : TWCP_VERSION);
	wp_enqueue_script('twcp_mcp', TWCP_URL . 'assets/js/mcp.js', ['jquery'], file_exists($js) ? (string) filemtime($js) : TWCP_VERSION, true);

	wp_localize_script('twcp_mcp', 'twcpAdmin', [
		'ajaxurl'    => admin_url('admin-ajax.php'),
		'nonce'      => wp_create_nonce('twcp_admin_nonce'),
		'copied'     => __('Copied!', 'twee-mcp'),
		'copyFailed' => __('Copy failed. The text is selected so you can copy it manually.', 'twee-mcp'),
	]);
}


/**
 * Verify an admin AJAX request
 *
 * @param string $capability
 */
function twcp_admin_ajax(string $capability = 'manage_options'): void
{
	check_ajax_referer('twcp_admin_nonce', 'nonce');

	if (!current_user_can($capability)) {
		wp_send_json_error(['message' => __('Unauthorized.', 'twee-mcp')]);
	}
}


/**
 * AJAX: Load models from the provider API
 */
add_action('wp_ajax_twcp_fetch_models', 'twcp_admin_ajax_models');

/**
 * Fetch available remote AI models for the settings screen.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_models(): void
{
	twcp_admin_ajax();

	$provider = isset($_POST['provider']) ? sanitize_key($_POST['provider']) : null;
	// An empty field falls back to the saved key, then to the one in wp-config.php
	$api_key = !empty($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : null;
	$models = twcp_model_fetch($provider, $api_key, true);

	if (empty($models)) {
		wp_send_json_error(['message' => __('No models returned or failed to authenticate with provider API.', 'twee-mcp')]);
	}

	wp_send_json_success([
		'provider' => $provider,
		'models'   => $models,
		'count'    => count($models),
		'message'  => sprintf(__('Successfully loaded %d models via API.', 'twee-mcp'), count($models)),
	]);
}


/**
 * AJAX: Start a ChatGPT plan sign-in
 */
add_action('wp_ajax_twcp_chatgpt_start', 'twcp_admin_ajax_chatgpt_start');

/**
 * Return the OpenAI authorization address for a new sign-in.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_chatgpt_start(): void
{
	twcp_admin_ajax();

	wp_send_json_success(['url' => twcp_chatgpt_start()]);
}


/**
 * AJAX: Finish a ChatGPT plan sign-in from the pasted callback address
 */
add_action('wp_ajax_twcp_chatgpt_finish', 'twcp_admin_ajax_chatgpt_finish');

/**
 * Exchange the pasted callback address for plan tokens.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_chatgpt_finish(): void
{
	twcp_admin_ajax();

	$error = twcp_chatgpt_finish(isset($_POST['callback']) ? esc_url_raw(wp_unslash($_POST['callback']), ['http']) : '');

	$error ? wp_send_json_error(['message' => $error]) : wp_send_json_success(['message' => __('ChatGPT is connected.', 'twee-mcp')]);
}


/**
 * AJAX: Disconnect the ChatGPT plan
 */
add_action('wp_ajax_twcp_chatgpt_disconnect', 'twcp_admin_ajax_chatgpt_disconnect');

/**
 * Revoke the plan sign-in and drop its tokens.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_chatgpt_disconnect(): void
{
	twcp_admin_ajax();

	wp_send_json_success(['message' => twcp_chatgpt_disconnect()]);
}


/**
 * AJAX: Create an OAuth client ID for a connector callback
 */
add_action('wp_ajax_twcp_client_id', 'twcp_admin_ajax_client_id');

/**
 * Sign a client ID for the pasted callback address, for clients that cannot register on their own.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_client_id(): void
{
	twcp_admin_ajax();

	$callback = isset($_POST['callback']) ? trim(wp_unslash((string) $_POST['callback'])) : '';

	if (!twcp_oauth_redirect_valid($callback)) {
		wp_send_json_error(['message' => __('Paste the Callback URL from ChatGPT. It must be an HTTPS address on an allowed redirect host.', 'twee-mcp')]);
	}

	wp_send_json_success(['client_id' => twcp_oauth_client_id('ChatGPT', [$callback])]);
}


/**
 * AJAX: Test model connectivity
 */
add_action('wp_ajax_twcp_test_model', 'twcp_admin_ajax_test');

/**
 * Test the configured AI model connection.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_test(): void
{
	twcp_admin_ajax();

	$result = twcp_model_test();
	$result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
}


/**
 * AJAX: Generate the store profile from the website
 */
add_action('wp_ajax_twcp_generate_context', 'twcp_admin_ajax_context');

/**
 * Generate the Store Profile & Website Context text for the Prompts screen.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_context(): void
{
	twcp_admin_ajax();

	$missing = twcp_model_missing();
	if ($missing) {
		wp_send_json_error(['message' => $missing . ' ' . __('Set up a model on the AI Provider screen first.', 'twee-mcp')]);
	}

	$context = twcp_general_context_generate();
	$context ? wp_send_json_success(['context' => $context]) : wp_send_json_error(['message' => twcp_model_last_error() ? : __('The model returned no text.', 'twee-mcp')]);
}


/**
 * Sanitize the disabled modules setting. The Agent Tools screen posts folder => 1|0 (1 = enabled), code may store a
 * plain list of folders.
 *
 * @param mixed $input
 *
 * @return string[]
 */
function twcp_admin_sanitize_modules($input): array
{
	$found = twcp_modules_found();
	$disabled = [];

	foreach ((array) $input as $key => $value) {
		$folder = is_int($key) ? (string) $value : (string) $key;
		if (isset($found[$folder]) and (is_int($key) or !$value)) {
			$disabled[] = $folder;
		}
	}

	return array_values(array_unique($disabled));
}


/**
 * Data needed by a template part. Modules add the data of their parts with the twcp_admin_data filter.
 *
 * @param string $part
 *
 * @return array
 */
function twcp_admin_data(string $part): array
{
	$data = [];

	switch ($part) {
		case 'model':
			$data = [
				'model_settings' => twcp_model_settings(),
				'providers'      => twcp_model_providers(),
				'chatgpt'        => twcp_chatgpt_status(),
			];
			break;

		case 'automation':
			$data = [
				'overview'         => twcp_batch_overview(),
				'sections'         => twcp_batch_sections(),
				'batch_cards'      => twcp_batch_cards(),
				'rank_math_active' => class_exists('RankMath'),
				'yoast_active'     => defined('WPSEO_VERSION'),
				'woo_active'       => class_exists('WooCommerce'),
				'can_manage'       => current_user_can('manage_options'),
				'model_missing'    => twcp_model_missing(),
				'model_url'        => admin_url('admin.php?page=' . twcp_admin_slug('model')),
				'post_links'       => array_merge(['max' => 4, 'sources' => ['terms', 'posts', 'products']], (array) get_option('twcp_post_links', [])),
			];
			break;

		case 'modules':
			$data = [
				'modules'          => twcp_modules_found(),
				'loaded'           => twcp_modules(),
				'disabled_modules' => (array) get_option('twcp_disabled_modules', []),
			];
			break;

		case 'tools':
			$data = [
				'tools'            => twcp_tools_registry(),
				'tool_groups'      => twcp_tools_groups(),
				'disabled_tools'   => twcp_tools_disabled(),
				'enabled_count'    => count(twcp_tools_enabled()),
			];
			break;

		case 'prompts':
			$prompts = [];
			foreach (twcp_prompt_registry() as $option => $prompt) {
				$saved = trim((string) get_option($option, ''));
				$default = call_user_func($prompt['default']);
				$prompts[$prompt['group']][$option] = [
						'value'      => $saved !== '' ? $saved : $default,
						'default'    => $default,
						'customized' => $saved !== '' and $saved !== $default,
					] + $prompt;
			}

			$data = [
				'groups'  => twcp_prompt_groups(),
				'prompts' => $prompts,
			];
			break;

		case 'connection':
			$data = [
				'is_enabled' => twcp_is_enabled(),
				'server_url' => twcp_mcp_url(),
				'hosts'      => twcp_oauth_hosts(),
				'sessions'   => twcp_oauth_sessions(get_current_user_id()),
			];
			break;
	}

	return (array) apply_filters('twcp_admin_data', $data, $part);
}


/**
 * Render an AI Tools page
 */
function twcp_admin_page(): void
{
	$tabs = array_filter(twcp_admin_tabs(), fn(array $info) => current_user_can($info['capability']));
	$active = twcp_admin_tab();

	if (!isset($tabs[$active])) {
		wp_die(__('You do not have access to this page.'));
	}

	?>
	<div class="wrap mcp_box">
		<div class="contents">
			<h1 class="title"><?php echo esc_html__('AI Tools', 'twee-mcp'); ?></h1>
			<p class="text">
				<?php echo esc_html__('Automate content and store work, tune the prompts behind it, and connect Claude or ChatGPT to this site over MCP.', 'twee-mcp'); ?>
			</p>
		</div>

		<?php if (isset($_GET['settings-updated'])) { ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo esc_html__('Settings saved successfully.', 'twee-mcp'); ?></p>
			</div>
		<?php } ?>

		<nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
			<?php foreach ($tabs as $tab => $info) { ?>
				<a href="<?php echo esc_url(admin_url('admin.php?page=' . twcp_admin_slug($tab))); ?>" class="nav-tab <?php echo $tab === $active ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html($info['label']); ?>
				</a>
			<?php } ?>
		</nav>

		<div class="items">
			<?php foreach ($tabs[$active]['parts'] as $part) {
				echo twcp_template($part, twcp_admin_data($part));
			} ?>
		</div>
	</div>
	<?php
}
