<?php
/**
 * Template: Internal Links
 *
 * Link settings of generated post content, shown in the Content Automation section.
 *
 * @var array $post_links [ max, sources ]
 * @var bool  $woo_active
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$link_sources = [
	'terms'    => __('The post\'s categories and tags', 'twee-mcp'),
	'posts'    => __('Related posts in the same categories', 'twee-mcp'),
	'pages'    => __('Top-level pages', 'twee-mcp'),
	'products' => __('Store categories with the most products', 'twee-mcp'),
	'lookup'   => __('Also allow links to posts and terms the model finds with its lookups', 'twee-mcp'),
];
if (!$woo_active) {
	unset($link_sources['products']);
}
?>

<details class="prompt post_links">
	<summary class="prompt_summary">
		<span class="prompt_label"><?php echo esc_html__('Internal Links', 'twee-mcp'); ?></span>
		<span class="badge <?php echo $post_links['max'] ? 'badge_success' : ''; ?>"><?php echo $post_links['max'] ? esc_html(sprintf(_n('Up to %d link', 'Up to %d links', $post_links['max'], 'twee-mcp'), $post_links['max'])) : esc_html__('Off', 'twee-mcp'); ?></span>
	</summary>
	<p class="text text_muted"><?php echo esc_html__('Links that full content and similar posts may use. The model only gets the links from the sources below, and any other link it writes is removed, keeping its text.', 'twee-mcp'); ?></p>
	<form method="post" action="options.php">
		<?php settings_fields('twcp_content_group'); ?>
		<div class="field">
			<span class="label"><?php echo esc_html__('Link Sources', 'twee-mcp'); ?></span>
			<div class="checks">
				<?php foreach ($link_sources as $value => $label) { ?>
					<label><input type="checkbox" name="twcp_post_links[sources][]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, (array) $post_links['sources'], true)); ?> /> <?php echo esc_html($label); ?></label>
				<?php } ?>
			</div>
		</div>
		<div class="field">
			<label class="label" for="twcp_post_links_max"><?php echo esc_html__('Max Links per Article', 'twee-mcp'); ?></label>
			<input type="number" id="twcp_post_links_max" name="twcp_post_links[max]" value="<?php echo esc_attr($post_links['max']); ?>" min="0" max="15" class="small-text" />
			<span class="text text_muted"><?php echo esc_html__('0 turns links off.', 'twee-mcp'); ?></span>
		</div>
		<div class="buttons">
			<button type="submit" class="button button_primary"><?php echo esc_html__('Save Link Settings', 'twee-mcp'); ?></button>
		</div>
	</form>
</details>
