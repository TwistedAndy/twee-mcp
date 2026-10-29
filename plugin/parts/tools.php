<?php
/**
 * Template part: MCP tools of the Tools tab
 *
 * Administrators choose which MCP tools connected AI agents can see and call.
 *
 * @var array $tools
 * @var array $tool_groups
 * @var array $disabled_tools
 * @var int   $enabled_count
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Model Context Protocol (MCP) Tools', 'twee-mcp'); ?></h2>
		<span class="badge badge_success"><?php echo sprintf(esc_html__('%1$d of %2$d Tools Enabled', 'twee-mcp'), $enabled_count, count($tools)); ?></span>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Choose which server tools autonomous AI agents can see and call. Disabled tools are removed from the MCP tools list and from read-only model lookups. Tools that depend on an inactive plugin are switched off automatically.', 'twee-mcp'); ?>
	</p>
	<form method="post" action="options.php">
		<?php settings_fields('twcp_tools_group'); ?>

		<table class="widefat striped tools_table">
			<thead>
			<tr>
				<th class="tool_switch"><?php echo esc_html__('Enabled', 'twee-mcp'); ?></th>
				<th class="tool_name"><?php echo esc_html__('Tool Name', 'twee-mcp'); ?></th>
				<th><?php echo esc_html__('Capability & Description', 'twee-mcp'); ?></th>
			</tr>
			</thead>
			<?php foreach ($tool_groups as $group => $info) {
				$group_tools = array_filter($tools, fn(array $tool) => $tool['group'] === $group);
				$available = $info['active'];
				if (!$group_tools) {
					continue;
				}
				?>
				<tbody>
				<tr class="tools_group">
					<th colspan="3">
						<?php echo esc_html($info['label']); ?>
						<?php if (!$available) { ?>
							<span class="badge badge_error"><?php echo sprintf(esc_html__('Requires %s', 'twee-mcp'), esc_html($info['plugin'])); ?></span>
						<?php } ?>
					</th>
				</tr>
				<?php foreach ($group_tools as $name => $tool) {
					$enabled = !in_array($name, $disabled_tools, true);
					$input_id = 'twcp_tool_' . $name;
					?>
					<tr class="<?php echo $available ? '' : 'tool_unavailable'; ?>">
						<td class="tool_switch">
							<label class="switch switch_small" for="<?php echo esc_attr($input_id); ?>">
								<?php if ($available) { ?>
									<input type="hidden" name="twcp_disabled_tools[<?php echo esc_attr($name); ?>]" value="0" />
									<input type="checkbox" id="<?php echo esc_attr($input_id); ?>" name="twcp_disabled_tools[<?php echo esc_attr($name); ?>]" value="1" <?php checked($enabled); ?> />
								<?php } else { ?>
									<input type="hidden" name="twcp_disabled_tools[<?php echo esc_attr($name); ?>]" value="<?php echo $enabled ? '1' : '0'; ?>" />
									<input type="checkbox" id="<?php echo esc_attr($input_id); ?>" disabled />
								<?php } ?>
								<span class="screen-reader-text"><?php echo esc_html($name); ?></span>
							</label>
						</td>
						<td class="tool_name">
							<code><?php echo esc_html($name); ?></code>
							<?php if (!empty($tool['annotations']['destructiveHint'])) { ?>
								<span class="badge badge_error"><?php echo esc_html__('Destructive', 'twee-mcp'); ?></span>
							<?php } elseif (!empty($tool['annotations']['readOnlyHint'])) { ?>
								<span class="badge"><?php echo esc_html__('Read-only', 'twee-mcp'); ?></span>
							<?php } ?>
						</td>
						<td><?php echo esc_html($tool['description']); ?></td>
					</tr>
				<?php } ?>
				</tbody>
			<?php } ?>
		</table>

		<div class="buttons">
			<button type="submit" class="button button_primary"><?php echo esc_html__('Save Tool Settings', 'twee-mcp'); ?></button>
		</div>
	</form>
</div>
