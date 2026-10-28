<?php
/**
 * Twee MCP — AI Tools Admin Interface
 *
 * Top-level "AI Tools" menu (last item of the admin menu) with one submenu page per tab:
 * - Automation   (batch jobs for content and shop managers, internal link settings)
 * - Prompts      (system prompts by area, copy-ready agent prompts)
 * - Library      (ACF blocks and stock image providers)
 * - AI Provider  (text & vision models, image size, API key, connection test)
 * - Agent Access (server toggle, endpoint, setup guides, OAuth sessions)
 * - Agent Tools  (MCP tool switches)
 *
 * Every tab renders template parts from parts/{part}.php that receive only the data they need.
 * Automation is open to editors and shop managers; the other tabs need manage_options.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Admin tabs
 *
 * @return array Tab => [ label, capability, template parts ]
 */
function twcp_admin_tabs(): array
{
	return [
		'automation' => ['label' => __('Automation', 'twee-mcp'), 'capability' => 'edit_others_posts', 'parts' => ['automation']],
		'prompts'    => ['label' => __('Prompts', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['prompts', 'examples']],
		'library'    => ['label' => __('Library', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['blocks', 'images']],
		'model'      => ['label' => __('AI Provider', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['model']],
		'connection' => ['label' => __('Agent Access', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['connection']],
		'tools'      => ['label' => __('Agent Tools', 'twee-mcp'), 'capability' => 'manage_options', 'parts' => ['tools']],
	];
}


/**
 * Page slugs of removed tabs, kept so bookmarks open the tab that took them over
 *
 * @return array Slug => tab
 */
function twcp_admin_legacy(): array
{
	return [
		'tw-mcp-blocks'   => 'library',
		'tw-mcp-images'   => 'library',
		'tw-mcp-examples' => 'prompts',
	];
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

	// Prompts, and the product copy rules saved with them
	foreach (array_keys(twcp_prompt_registry()) as $option) {
		register_setting('twcp_prompts_group', $option, [
			'type'              => 'string',
			'sanitize_callback' => fn($input): string => twcp_prompt_sanitize($input, $option),
			'default'           => '',
		]);
	}

	// MCP tools switched off on the Agent Tools screen
	register_setting('twcp_tools_group', 'twcp_disabled_tools', ['type' => 'array', 'sanitize_callback' => 'twcp_tools_sanitize_disabled', 'default' => []]);

	register_setting('twcp_prompts_group', 'twcp_product_settings', [
		'type'              => 'array',
		'sanitize_callback' => function($input) {
			$input = is_array($input) ? $input : [];
			$clean = [];
			$clean['max_links'] = isset($input['max_links']) ? min(15, max(0, (int) $input['max_links'])) : 6;
			$rules = sanitize_textarea_field((string) ($input['custom_rules'] ?? ''));

			// Guidelines equal to the built-in text are stored empty, so a newer default still applies
			$clean['custom_rules'] = $rules === twcp_product_settings_default()['custom_rules'] ? '' : $rules;

			return $clean;
		},
		'default'           => [],
	]);

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

	// Blocks
	register_setting('twcp_blocks_group', 'twcp_blocks_field', ['type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'blocks']);
	register_setting('twcp_blocks_group', 'twcp_block_previews_folder', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'assets/preview'
	]);
	register_setting('twcp_blocks_group', 'twcp_block_conventions', [
		'type'              => 'string',
		// Conventions equal to the built-in text are stored empty, so a newer default still applies
		'sanitize_callback' => function($input): string {
			$text = sanitize_textarea_field((string) $input);
			$default = sanitize_textarea_field(twcp_block_conventions_default());

			return str_replace("\r\n", "\n", $text) === str_replace("\r\n", "\n", $default) ? '' : $text;
		},
		'default'           => '',
	]);
	register_setting('twcp_blocks_group', 'twcp_block_descriptions', [
		'type'              => 'array',
		// The Library lists the layouts of the current field only, so the descriptions it does not list are kept
		'sanitize_callback' => function($input) {
			$clean = (array) get_option('twcp_block_descriptions', []);
			foreach ((array) $input as $layout => $description) {
				$clean[sanitize_key($layout)] = sanitize_textarea_field($description);
			}

			return $clean;
		},
		'default'           => [],
	]);

	// Stock images
	register_setting('twcp_images_group', 'twcp_stock_api_keys', [
		'type'              => 'array',
		'sanitize_callback' => function($input) {
			$input = is_array($input) ? $input : [];
			$clean = [];
			foreach (['unsplash', 'pexels', 'pixabay', 'giphy'] as $provider) {
				$clean[$provider] = !empty($input[$provider]) ? sanitize_text_field($input[$provider]) : '';
			}

			return $clean;
		},
		'default'           => [],
	]);
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
 * AJAX: Generate block descriptions from the previews folder
 */
add_action('wp_ajax_twcp_generate_block_descriptions', 'twcp_admin_ajax_blocks');

/**
 * Generate missing page-builder block descriptions.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_blocks(): void
{
	twcp_admin_ajax();

	if (function_exists('set_time_limit')) {
		@set_time_limit(300);
	}

	$folder = isset($_POST['folder']) ? sanitize_text_field($_POST['folder']) : null;
	$layout = isset($_POST['layout']) ? sanitize_key($_POST['layout']) : null;

	if ($folder !== null and trim($folder) !== '') {
		update_option('twcp_block_previews_folder', trim($folder));
	}

	if (!empty($layout)) {
		$desc = twcp_block_describe_single($layout, $folder);
		if ($desc === null) {
			wp_send_json_error(['message' => sprintf(__('Layout %s not found.', 'twee-mcp'), $layout)]);
		}

		wp_send_json_success([
			'layout'       => $layout,
			'description'  => $desc,
			'descriptions' => twcp_block_descriptions(),
			'message'      => sprintf(__('Generated description for %s.', 'twee-mcp'), $layout),
		]);
	}

	$count = twcp_block_generate_descriptions($folder, !empty($_POST['overwrite']));

	wp_send_json_success([
		'generated'    => $count,
		'descriptions' => twcp_block_descriptions(),
		'message'      => sprintf(__('Successfully generated %d block descriptions from previews.', 'twee-mcp'), $count),
	]);
}


/**
 * AJAX: Search stock images
 */
add_action('wp_ajax_twcp_search_stock_images', 'twcp_admin_ajax_search');

/**
 * Search configured stock-image providers from WordPress admin.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_search(): void
{
	twcp_admin_ajax();

	wp_send_json_success(twcp_image_search(isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '', [
		'provider'    => isset($_POST['provider']) ? sanitize_key($_POST['provider']) : 'all',
		'orientation' => isset($_POST['orientation']) ? sanitize_key($_POST['orientation']) : '',
		'per_page'    => isset($_POST['per_page']) ? min(60, max(1, (int) $_POST['per_page'])) : 12,
		'page'        => isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1,
	]));
}


/**
 * AJAX: Import a remote image into the media library
 */
add_action('wp_ajax_twcp_import_stock_image', 'twcp_admin_ajax_import');

/**
 * Import a selected stock image into the Media Library.
 *
 * @return void Sends a JSON response.
 */
function twcp_admin_ajax_import(): void
{
	twcp_admin_ajax('upload_files');

	$image = isset($_POST['image']) && is_array($_POST['image']) ? wp_unslash($_POST['image']) : [];

	$url = !empty($image['download_url']) ? esc_url_raw($image['download_url']) : (!empty($image['url']) ? esc_url_raw($image['url']) : (isset($_POST['url']) ? esc_url_raw($_POST['url']) : ''));

	$meta = [
		'title'      => !empty($image['title']) ? sanitize_text_field($image['title']) : (isset($_POST['title']) ? sanitize_text_field($_POST['title']) : ''),
		'provider'   => !empty($image['provider']) ? sanitize_key($image['provider']) : (isset($_POST['provider']) ? sanitize_key($_POST['provider']) : ''),
		'author'     => !empty($image['author']) ? sanitize_text_field($image['author']) : (!empty($image['author_name']) ? sanitize_text_field($image['author_name']) : ''),
		'author_url' => !empty($image['author_url']) ? esc_url_raw($image['author_url']) : '',
		'source_url' => !empty($image['source_url']) ? esc_url_raw($image['source_url']) : '',
		'license'    => !empty($image['license']) ? sanitize_text_field($image['license']) : '',
		'caption'    => !empty($image['caption']) ? sanitize_textarea_field($image['caption']) : '',
		'alt'        => !empty($image['alt']) ? sanitize_text_field($image['alt']) : '',
	];

	$result = twcp_image_import($url, $meta);

	if (!empty($result['error'])) {
		wp_send_json_error(['message' => $result['error']]);
	}

	wp_send_json_success($result);
}


/**
 * Data needed by a template part
 *
 * @param string $part
 *
 * @return array
 */
function twcp_admin_data(string $part): array
{
	switch ($part) {
		case 'model':
			return [
				'model_settings' => twcp_model_settings(),
				'providers'      => twcp_model_providers(),
				'chatgpt'        => twcp_chatgpt_status(),
			];

		case 'automation':
			return [
				'overview'         => twcp_batch_overview(),
				'sections'         => twcp_batch_sections(),
				'batch_cards'      => twcp_batch_cards(),
				'rank_math_active' => class_exists('RankMath'),
				'yoast_active'     => defined('WPSEO_VERSION'),
				'woo_active'       => twcp_product_active(),
				'can_manage'       => current_user_can('manage_options'),
				'model_missing'    => twcp_model_missing(),
				'model_url'        => admin_url('admin.php?page=' . twcp_admin_slug('model')),
				'post_links'       => array_merge(['max' => 4, 'sources' => ['terms', 'posts', 'products']], (array) get_option('twcp_post_links', [])),
			];

		case 'tools':
			return [
				'tools'            => twcp_tools_registry(),
				'tool_groups'      => twcp_tools_groups(),
				'disabled_tools'   => twcp_tools_disabled(),
				'enabled_count'    => count(twcp_tools_enabled()),
			];

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

			return [
				'groups'           => twcp_prompt_groups(),
				'prompts'          => $prompts,
				'product_settings' => twcp_product_settings(),
			];

		case 'blocks':
			$field = twcp_block_field_name();

			return [
				'available_blocks_fields' => twcp_block_available_fields(),
				'current_blocks_field'    => $field,
				'previews_folder'         => twcp_block_previews_folder(),
				'previews_dir'            => twcp_block_previews_dir(),
				'available_previews'      => twcp_block_previews(),
				'dynamic_layouts'         => twcp_block_get_layouts($field),
				'default_descriptions'    => twcp_block_default_descriptions(),
				'saved_descriptions'      => (array) get_option('twcp_block_descriptions', []),
				'conventions'             => twcp_block_conventions(),
				'conventions_default'     => twcp_block_conventions_default(),
				'saved_conventions'       => (string) get_option('twcp_block_conventions', ''),
			];

		case 'images':
			return [
				'keys'           => twcp_image_keys(),
				'instant_active' => defined('INSTANT_IMAGES_VERSION') || class_exists('Instant_Images'),
			];

		case 'connection':
			return [
				'is_enabled' => twcp_is_enabled(),
				'server_url' => twcp_mcp_url(),
				'hosts'      => twcp_oauth_hosts(),
				'sessions'   => twcp_oauth_sessions(get_current_user_id()),
			];
	}

	return [];
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
