<?php
/**
 * Tab Template: Model Configuration
 *
 * @var array $model_settings
 * @var array $providers
 * @var array $chatgpt        ChatGPT plan sign-in status
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('AI Model API Configuration', 'twee-mcp'); ?></h2>
		<div class="badges">
			<?php if ($model_settings['provider'] === 'chatgpt') { ?>
				<span class="badge <?php echo $chatgpt['connected'] ? 'badge_success' : ''; ?>">
					<?php echo $chatgpt['connected'] ? esc_html__('ChatGPT Connected', 'twee-mcp') : esc_html__('ChatGPT Not Connected', 'twee-mcp'); ?>
				</span>
			<?php } else { ?>
				<span class="badge <?php echo !empty($model_settings['api_key']) ? 'badge_success' : ''; ?>">
					<?php echo !empty($model_settings['api_key']) ? esc_html__('API Key Configured', 'twee-mcp') : esc_html__('No API Key', 'twee-mcp'); ?>
				</span>
			<?php } ?>
		</div>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Configure ChatGPT (OpenAI), OpenRouter, or Anthropic (Claude) API credentials for text generation, SEO tags, product copywriting, and multimodal image inspection. A ChatGPT Plus or Pro plan can be used instead of an API key as an experimental option.', 'twee-mcp'); ?>
	</p>

	<form method="post" action="options.php" id="twcp_model_form">
		<?php settings_fields('twcp_model_group'); ?>

		<div class="field">
			<label class="label" for="twcp_model_provider"><?php echo esc_html__('AI Provider', 'twee-mcp'); ?></label>
			<select id="twcp_model_provider" name="twcp_model_provider" class="regular-text">
				<?php foreach ($providers as $pk => $pinfo) { ?>
					<option value="<?php echo esc_attr($pk); ?>" <?php selected($model_settings['provider'], $pk); ?>>
						<?php echo esc_html($pinfo['name']); ?>
					</option>
				<?php } ?>
			</select>
		</div>

		<div class="chatgpt_panel" data-provider-panel="chatgpt" <?php echo $model_settings['provider'] === 'chatgpt' ? '' : 'style="display: none;"'; ?>>
			<div class="chatgpt_header">
				<strong><?php echo esc_html__('Sign in with ChatGPT', 'twee-mcp'); ?></strong>
				<span class="badge badge_warning"><?php echo esc_html__('Experimental', 'twee-mcp'); ?></span>
				<?php if ($chatgpt['connected']) { ?>
					<span class="badge badge_success">
						<?php echo esc_html($chatgpt['email'] ? sprintf(__('Connected as %s', 'twee-mcp'), $chatgpt['email']) : __('Connected', 'twee-mcp')); ?>
					</span>
				<?php } ?>
			</div>

			<ul class="chatgpt_limits">
				<li><?php echo esc_html__('OpenAI preview: the sign-in and the plan API can change or stop working without notice. Keep an API key at hand as a fallback.', 'twee-mcp'); ?></li>
				<li>
					<?php echo esc_html__('OpenAI offers plan usage to open-source tools and personal projects that run locally. For a public or client site, apply through the OpenAI interest form first.', 'twee-mcp'); ?>
					<a href="<?php echo esc_url($chatgpt['interest']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Interest form', 'twee-mcp'); ?></a>
				</li>
				<li><?php echo esc_html__('Every batch job, bulk action, and generator on this site spends the plan limits of the person who signs in, whoever starts it. Set a weekly cap for this app in ChatGPT Settings > Usage.', 'twee-mcp'); ?></li>
				<li><?php echo esc_html__('Temperature and output length limits are not supported and are ignored. The model list depends on the account; image input works only with models that accept images.', 'twee-mcp'); ?></li>
				<li><?php echo esc_html__('Tokens are stored in the WordPress database. The sign-in renews itself while it is used and expires after 30 days without use.', 'twee-mcp'); ?></li>
			</ul>

			<?php if ($chatgpt['error']) { ?>
				<p class="text chatgpt_error"><?php echo esc_html($chatgpt['error']); ?></p>
			<?php } ?>

			<ol class="chatgpt_steps">
				<li>
					<?php echo esc_html__('Open the OpenAI sign-in in a new tab.', 'twee-mcp'); ?>
					<div class="chatgpt_action">
						<button type="button" id="twcp_chatgpt_start" class="button"><?php echo $chatgpt['connected'] ? esc_html__('Sign in Again', 'twee-mcp') : esc_html__('Sign in with ChatGPT', 'twee-mcp'); ?></button>
					</div>
				</li>
				<li><?php echo esc_html__('Approve the connection and keep ChatGPT plan usage allowed.', 'twee-mcp'); ?></li>
				<li>
					<?php echo esc_html(sprintf(__('The tab then ends on a page at %s that cannot be reached. That is expected and no local server is needed: the sign-in code is in the address bar. Copy the full address from that tab and paste it here. This works from any computer.', 'twee-mcp'), '127.0.0.1:1455')); ?>
					<div class="chatgpt_action chatgpt_finish">
						<?php // A URL field hidden with the panel blocks the form when the browser fills a login name into it ?>
						<input type="text" id="twcp_chatgpt_callback" class="regular-text" placeholder="<?php echo esc_attr($chatgpt['redirect'] . '?code=...'); ?>" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-bwignore />
						<button type="button" id="twcp_chatgpt_finish" class="button button_primary"><?php echo esc_html__('Complete Sign-in', 'twee-mcp'); ?></button>
					</div>
				</li>
				<li><?php echo esc_html__('The models of the account load after the sign-in, and the first one is used until you choose others below and save the settings.', 'twee-mcp'); ?></li>
			</ol>

			<div class="buttons">
				<?php if ($chatgpt['connected']) { ?>
					<button type="button" id="twcp_chatgpt_disconnect" class="button"><?php echo esc_html__('Disconnect', 'twee-mcp'); ?></button>
				<?php } ?>
				<span id="twcp_chatgpt_status" class="status_text"></span>
			</div>
		</div>

		<div class="field">
			<label class="label" for="twcp_model_name"><?php echo esc_html__('Text & Copywriting Model', 'twee-mcp'); ?></label>
			<div class="control" style="display: flex; gap: 8px; align-items: center; max-width: 650px;">
				<select id="twcp_model_name" name="twcp_model_name" class="regular-text" style="flex: 1;">
					<?php foreach ($providers as $pk => $pinfo) { ?>
						<optgroup label="<?php echo esc_attr($pinfo['name']); ?>" data-provider="<?php echo esc_attr($pk); ?>">
							<?php
							$has_selected = false;
							foreach ($pinfo['models'] as $mkey => $mlabel) {
								if ($model_settings['model'] === $mkey && $model_settings['provider'] === $pk) {
									$has_selected = true;
								}
								?>
								<option value="<?php echo esc_attr($mkey); ?>" <?php selected($model_settings['model'], $mkey); ?>>
									<?php echo esc_html($mlabel . ' (' . $mkey . ')'); ?>
								</option>
							<?php }
							if (!$has_selected && $model_settings['provider'] === $pk && !empty($model_settings['model'])) { ?>
								<option value="<?php echo esc_attr($model_settings['model']); ?>" selected>
									<?php echo esc_html($model_settings['model']); ?>
								</option>
							<?php } ?>
						</optgroup>
					<?php } ?>
				</select>
				<button type="button" id="twcp_fetch_models_btn" class="button">
					<?php echo esc_html__('Load Models from API', 'twee-mcp'); ?>
				</button>
			</div>
			<div class="text text_muted" style="margin-top: 4px;">
				<?php echo esc_html__('Primary model used for product copywriting, SEO titles & descriptions, and general text tasks.', 'twee-mcp'); ?>
				<span id="twcp_fetch_models_status" class="status_text" style="margin-left: 8px;"></span>
			</div>
		</div>

		<div class="field">
			<label class="label" for="twcp_vision_model"><?php echo esc_html__('Vision & Image Model', 'twee-mcp'); ?></label>
			<div class="control" style="display: flex; gap: 8px; align-items: center; max-width: 650px;">
				<select id="twcp_vision_model" name="twcp_vision_model" class="regular-text" style="flex: 1;">
					<?php foreach ($providers as $pk => $pinfo) { ?>
						<optgroup label="<?php echo esc_attr($pinfo['name']); ?>" data-provider="<?php echo esc_attr($pk); ?>">
							<?php
							$has_vision_selected = false;
							foreach ($pinfo['models'] as $mkey => $mlabel) {
								if ($model_settings['vision_model'] === $mkey && $model_settings['provider'] === $pk) {
									$has_vision_selected = true;
								}
								?>
								<option value="<?php echo esc_attr($mkey); ?>" <?php selected($model_settings['vision_model'], $mkey); ?>>
									<?php echo esc_html($mlabel . ' (' . $mkey . ')'); ?>
								</option>
							<?php }
							if (!$has_vision_selected && $model_settings['provider'] === $pk && !empty($model_settings['vision_model'])) { ?>
								<option value="<?php echo esc_attr($model_settings['vision_model']); ?>" selected>
									<?php echo esc_html($model_settings['vision_model']); ?>
								</option>
							<?php } ?>
						</optgroup>
					<?php } ?>
				</select>
			</div>
			<div class="text text_muted" style="margin-top: 4px;">
				<?php echo esc_html__('Dedicated multimodal model used for image Alt text generation and visual layout previews (e.g. GPT-4o, Claude 3.5 Sonnet).', 'twee-mcp'); ?>
			</div>
		</div>

		<div class="field">
			<label class="label" for="twcp_vision_size"><?php echo esc_html__('Vision Image Size', 'twee-mcp'); ?></label>
			<div class="control">
				<select id="twcp_vision_size" name="twcp_vision_size" class="regular-text">
					<?php foreach (twcp_model_vision_sizes() as $pixels => $label) { ?>
						<option value="<?php echo esc_attr($pixels); ?>" <?php selected($model_settings['vision_size'], $pixels); ?>><?php echo esc_html($label); ?></option>
					<?php } ?>
				</select>
			</div>
			<div class="text text_muted" style="margin-top: 4px;">
				<?php echo esc_html__('Images are scaled down to this longest edge before they are sent for Alt text and block descriptions. Image tokens grow with the pixel count, so 768 px costs about a quarter of 1568 px, and 512 px uses the OpenAI low-detail rate.', 'twee-mcp'); ?>
			</div>
		</div>

		<div class="field" data-provider-hide="chatgpt" <?php echo $model_settings['provider'] === 'chatgpt' ? 'style="display: none;"' : ''; ?>>
			<label class="label" for="twcp_model_api_key"><?php echo esc_html__('API Key', 'twee-mcp'); ?></label>
			<div class="control">
				<?php // A password field gets the saved site login filled in, and the stored key is shown for every provider, so saving never replaces it ?>
				<input type="text" id="twcp_model_api_key" name="twcp_model_api_key" class="regular-text masked" value="<?php echo esc_attr(get_option('twcp_model_api_key', '')); ?>" placeholder="sk-..." autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-bwignore />
				<button type="button" id="twcp_toggle_key_btn" class="button">
					Show
				</button>
			</div>
			<?php foreach ($providers as $pk => $pinfo) {
				if (empty($pinfo['keys_url'])) {
					continue;
				} ?>
				<div class="text text_muted" style="margin-top: 4px;<?php echo $model_settings['provider'] === $pk ? '' : ' display: none;'; ?>" data-provider-panel="<?php echo esc_attr($pk); ?>">
					<?php
					printf(
						/* translators: 1: provider name, 2: API keys page link, 3: getting started guide link */
						esc_html__('Create the key on the %1$s %2$s page. New to the API? Read the %3$s.', 'twee-mcp'),
						esc_html($pinfo['name']),
						'<a href="' . esc_url($pinfo['keys_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('API keys', 'twee-mcp') . '</a>',
						'<a href="' . esc_url($pinfo['guide_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('getting started guide', 'twee-mcp') . '</a>'
					);
					?>
				</div>
			<?php } ?>
		</div>

		<div class="buttons" style="align-items: center;">
			<button type="submit" class="button button_primary"><?php echo esc_html__('Save API Settings', 'twee-mcp'); ?></button>
			<button type="button" id="twcp_test_btn" class="button">
				<?php echo esc_html__('Test Connection', 'twee-mcp'); ?>
			</button>
			<span id="twcp_test_status" class="status_text"></span>
		</div>
	</form>
</div>
