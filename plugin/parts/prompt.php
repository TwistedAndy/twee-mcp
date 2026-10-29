<?php
/**
 * Template: System Prompt
 *
 * One prompt in a form of its own, with the settings modules save with it. Closed, it shows the start of its text,
 * whether it was customized, and its approximate size in tokens.
 *
 * @var string $option
 * @var array  $prompt [ label, description, format, used_by, rows, value, default, customized ]
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<details class="prompt" <?php echo $prompt['customized'] ? 'open' : ''; ?>>
	<summary class="prompt_summary">
		<span class="dashicons dashicons-edit" aria-hidden="true"></span>
		<span class="prompt_label"><?php echo esc_html($prompt['label']); ?></span>
		<span class="badge <?php echo $prompt['customized'] ? 'badge_success' : ''; ?>"><?php echo $prompt['customized'] ? esc_html__('Customized', 'twee-mcp') : esc_html__('Default', 'twee-mcp'); ?></span>
		<span class="text text_muted"><?php echo esc_html(sprintf(__('~%s tokens', 'twee-mcp'), number_format_i18n((int) ceil(strlen($prompt['value']) / 4)))); ?></span>
		<span class="prompt_toggle">
			<span class="prompt_edit"><?php echo esc_html__('Edit', 'twee-mcp'); ?></span>
			<span class="prompt_close"><?php echo esc_html__('Close', 'twee-mcp'); ?></span>
			<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
		</span>
		<span class="prompt_preview"><?php echo esc_html(wp_html_excerpt($prompt['value'], 240)); ?></span>
	</summary>
	<p class="text text_muted">
		<?php echo esc_html($prompt['description']); ?>
		<?php if (!empty($prompt['format'])) {
			echo esc_html__('The output format, language, and examples are added automatically, so the reply can always be saved.', 'twee-mcp');
		} ?>
	</p>
	<div class="chips">
		<span class="text text_muted"><?php echo esc_html__('Used by:', 'twee-mcp'); ?></span>
		<?php foreach ($prompt['used_by'] as $use) { ?>
			<code class="chip"><?php echo esc_html($use); ?></code>
		<?php } ?>
	</div>

	<?php // The number field of a module sits in a closed section, where a value the browser rejects would block the save without a message ?>
	<form method="post" action="options.php" novalidate>
		<?php settings_fields($option); ?>
		<div class="field">
			<label class="screen-reader-text" for="<?php echo esc_attr($option); ?>"><?php echo esc_html($prompt['label']); ?></label>
			<textarea id="<?php echo esc_attr($option); ?>" name="<?php echo esc_attr($option); ?>" rows="<?php echo esc_attr($prompt['rows']); ?>" class="large-text" data-default="<?php echo esc_attr($prompt['default']); ?>"><?php echo esc_textarea($prompt['value']); ?></textarea>
		</div>
		<?php do_action('twcp_prompt_fields', $option); ?>
		<div class="buttons">
			<button type="submit" class="button button_primary"><?php echo esc_html__('Save Prompt', 'twee-mcp'); ?></button>
			<button type="button" class="button twcp_reset_prompt" data-target="<?php echo esc_attr($option); ?>"><?php echo esc_html__('Reset to default', 'twee-mcp'); ?></button>
			<?php if ($option === 'twcp_general_context') { ?>
				<button type="button" class="button twcp_generate_context" data-target="<?php echo esc_attr($option); ?>"><?php echo esc_html__('Generate from website', 'twee-mcp'); ?></button>
			<?php } ?>
		</div>
	</form>
</details>
