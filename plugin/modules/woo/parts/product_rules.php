<?php
/**
 * Template part: Product copy rules in the store group of the Prompts screen
 *
 * @var array $product_settings
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

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
