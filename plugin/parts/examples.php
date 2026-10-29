<?php
/**
 * Template part: Agent prompts of the Connection tab
 *
 * Copy-ready prompts for Claude or ChatGPT, grouped by area, see twcp_prompt_examples().
 *
 * @var array $groups Group => [ label, position, plugin, active, prompts [ id, title, description, content ] ]
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$count = array_sum(array_map(fn($group) => count($group['prompts']), $groups));
?>

<div class="item" id="twcp_agent_prompts">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Agent Prompts', 'twee-mcp'); ?></h2>
		<span class="badge badge_success"><?php echo esc_html(sprintf(__('%d Prompts', 'twee-mcp'), $count)); ?></span>
	</div>
	<p class="text">
		<?php echo esc_html__('Copy a prompt into Claude or ChatGPT after connecting this MCP server. Replace any bracketed placeholders before sending it.', 'twee-mcp'); ?>
	</p>
	<p class="text text_muted">
		<?php echo esc_html__('The prompts are starting points. Review permissions and requested write operations before allowing an agent to change published content.', 'twee-mcp'); ?>
	</p>

	<?php foreach ($groups as $group) {
		$inactive = isset($group['active']) && !$group['active'];
		?>
		<h3 class="title prompt_group_title">
			<?php echo esc_html($group['label']); ?>
			<?php if ($inactive) { ?>
				<span class="badge badge_warning"><?php echo esc_html(sprintf(__('Requires %s', 'twee-mcp'), $group['plugin'])); ?></span>
			<?php } ?>
		</h3>

		<?php foreach ($group['prompts'] as $prompt) {
			$input_id = 'twcp_example_' . $prompt['id'];
			$rows = min(36, max(12, substr_count($prompt['content'], "\n") + 2));
			?>
			<details class="prompt">
				<summary class="prompt_summary">
					<span class="prompt_label"><?php echo esc_html($prompt['title']); ?></span>
					<span class="text text_muted"><?php echo esc_html($prompt['description']); ?></span>
				</summary>
				<div class="field">
					<label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($prompt['title']); ?></label>
					<textarea id="<?php echo esc_attr($input_id); ?>" class="large-text code" rows="<?php echo esc_attr($rows); ?>" readonly><?php echo esc_textarea($prompt['content']); ?></textarea>
				</div>
				<div class="buttons">
					<button type="button" class="button button_primary twcp_copy_prompt" data-target="<?php echo esc_attr($input_id); ?>">
						<?php echo esc_html__('Copy Prompt', 'twee-mcp'); ?>
					</button>
				</div>
			</details>
		<?php } ?>
	<?php } ?>
</div>
