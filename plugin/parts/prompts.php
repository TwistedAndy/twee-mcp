<?php
/**
 * Tab Template: System Prompts
 *
 * Prompts are grouped by the area they write for. Each card shows where the prompt is used,
 * whether it was customized, and its approximate size in tokens.
 *
 * @var array $groups           Group => [ label, description, plugin, active ]
 * @var array $prompts          Group => [ option => prompt with value, default, customized ]
 * @var array $product_settings Product copy rules
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$customized = count(array_filter(array_merge(...array_values(array_map('array_values', $prompts))), fn($prompt) => $prompt['customized']));
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('System Prompts', 'twee-mcp'); ?></h2>
		<span class="badge <?php echo $customized ? 'badge_success' : ''; ?>"><?php echo esc_html(sprintf(_n('%d Customized', '%d Customized', $customized, 'twee-mcp'), $customized)); ?></span>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('System prompts guide the AI model behind batch actions, bulk actions, and generator tools. The shared context is added to every prompt, and both are cached by OpenAI and Anthropic, so a long prompt costs full price only on the first request of a batch.', 'twee-mcp'); ?>
	</p>
	<nav class="section_nav">
		<?php foreach ($groups as $group => $info) { ?>
			<a href="#twcp_prompts_<?php echo esc_attr($group); ?>" class="button button_small"><?php echo esc_html($info['label']); ?></a>
		<?php } ?>
		<a href="#twcp_agent_prompts" class="button button_small"><?php echo esc_html__('Agent Prompts', 'twee-mcp'); ?></a>
	</nav>
</div>

<form method="post" action="options.php">
	<?php settings_fields('twcp_prompts_group'); ?>

	<?php foreach ($groups as $group => $info) {
		$inactive = isset($info['active']) && !$info['active'];
		?>
		<div class="item prompt_group <?php echo $inactive ? 'prompt_group_inactive' : ''; ?>" id="twcp_prompts_<?php echo esc_attr($group); ?>">
			<div class="header">
				<h2 class="heading"><?php echo esc_html($info['label']); ?></h2>
				<?php if ($inactive) { ?>
					<span class="badge badge_warning"><?php echo esc_html(sprintf(__('Requires %s', 'twee-mcp'), $info['plugin'])); ?></span>
				<?php } ?>
			</div>
			<p class="text text_muted"><?php echo esc_html($info['description']); ?></p>

			<?php foreach ($prompts[$group] ?? [] as $option => $prompt) { ?>
				<details class="prompt" <?php echo $prompt['customized'] ? 'open' : ''; ?>>
					<summary class="prompt_summary">
						<span class="prompt_label"><?php echo esc_html($prompt['label']); ?></span>
						<span class="badge <?php echo $prompt['customized'] ? 'badge_success' : ''; ?>"><?php echo $prompt['customized'] ? esc_html__('Customized', 'twee-mcp') : esc_html__('Default', 'twee-mcp'); ?></span>
						<span class="text text_muted"><?php echo esc_html(sprintf(__('~%s tokens', 'twee-mcp'), number_format_i18n((int) ceil(strlen($prompt['value']) / 4)))); ?></span>
					</summary>
					<p class="text text_muted"><?php echo esc_html($prompt['description']); ?></p>
					<div class="chips">
						<span class="text text_muted"><?php echo esc_html__('Used by:', 'twee-mcp'); ?></span>
						<?php foreach ($prompt['used_by'] as $use) { ?>
							<code class="chip"><?php echo esc_html($use); ?></code>
						<?php } ?>
					</div>
					<div class="field">
						<label class="screen-reader-text" for="<?php echo esc_attr($option); ?>"><?php echo esc_html($prompt['label']); ?></label>
						<textarea id="<?php echo esc_attr($option); ?>" name="<?php echo esc_attr($option); ?>" rows="<?php echo esc_attr($prompt['rows']); ?>" class="large-text" data-default="<?php echo esc_attr($prompt['default']); ?>"><?php echo esc_textarea($prompt['value']); ?></textarea>
						<button type="button" class="button twcp_reset_prompt" data-target="<?php echo esc_attr($option); ?>"><?php echo esc_html__('Reset to default', 'twee-mcp'); ?></button>
					</div>
				</details>
			<?php } ?>

			<?php if ($group === 'store') { ?>
				<details class="prompt">
					<summary class="prompt_summary">
						<span class="prompt_label"><?php echo esc_html__('Product Copy Rules', 'twee-mcp'); ?></span>
					</summary>
					<p class="text text_muted"><?php echo esc_html__('Appended to the full product description prompt. Product attributes, brand, and categories are always included.', 'twee-mcp'); ?></p>
					<div class="field">
						<label class="label" for="twcp_product_max_links"><?php echo esc_html__('Max Internal Links', 'twee-mcp'); ?></label>
						<input type="number" id="twcp_product_max_links" name="twcp_product_settings[max_links]" value="<?php echo esc_attr($product_settings['max_links'] ?? 6); ?>" min="0" max="15" class="small-text" />
						<div class="text text_muted"><?php echo esc_html__('Category, brand, and attribute links embedded in each description.', 'twee-mcp'); ?></div>
					</div>
					<div class="field">
						<label class="label" for="twcp_product_custom_rules"><?php echo esc_html__('Store Guidelines', 'twee-mcp'); ?></label>
						<textarea id="twcp_product_custom_rules" name="twcp_product_settings[custom_rules]" rows="3" class="large-text"><?php echo esc_textarea($product_settings['custom_rules'] ?? ''); ?></textarea>
						<div class="text text_muted"><?php echo esc_html__('Style and voice rules, e.g. "Highlight Italian calfskin" or "Do not mention waterproofing".', 'twee-mcp'); ?></div>
					</div>
				</details>
			<?php } ?>
		</div>
	<?php } ?>

	<div class="item prompt_save">
		<div class="buttons">
			<button type="submit" class="button button_primary"><?php echo esc_html__('Save Prompts', 'twee-mcp'); ?></button>
			<span class="text text_muted"><?php echo esc_html__('Reset restores the built-in text of one prompt; save to apply edits and resets.', 'twee-mcp'); ?></span>
		</div>
	</div>
</form>
