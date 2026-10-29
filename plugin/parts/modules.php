<?php
/**
 * Tab Template: Modules
 *
 * Administrators switch off modules they do not use. A module switched off is not loaded, so its tools, screens,
 * and jobs are gone.
 *
 * @var array $modules          Folder => [ name, description ]
 * @var array $loaded           Module => [ plugin, active ] of the modules that are loaded
 * @var array $disabled_modules
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Modules', 'twee-mcp'); ?></h2>
		<span class="badge badge_success"><?php echo sprintf(esc_html__('%1$d of %2$d Modules Enabled', 'twee-mcp'), count(array_diff(array_keys($modules), $disabled_modules)), count($modules)); ?></span>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Switch off the modules you do not use. A module that is switched off is not loaded: its tools, settings, and jobs are gone until you switch it on again. The WordPress tools are always on.', 'twee-mcp'); ?>
	</p>
	<form method="post" action="options.php">
		<?php settings_fields('twcp_modules_group'); ?>

		<table class="widefat striped tools_table">
			<thead>
			<tr>
				<th class="tool_switch"><?php echo esc_html__('Enabled', 'twee-mcp'); ?></th>
				<th class="tool_name"><?php echo esc_html__('Module', 'twee-mcp'); ?></th>
				<th><?php echo esc_html__('Description', 'twee-mcp'); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($modules as $folder => $module) {
				$input_id = 'twcp_module_' . $folder;
				$status = $loaded[$folder] ?? ['active' => true];
				?>
				<tr>
					<td class="tool_switch">
						<label class="switch switch_small" for="<?php echo esc_attr($input_id); ?>">
							<input type="hidden" name="twcp_disabled_modules[<?php echo esc_attr($folder); ?>]" value="0" />
							<input type="checkbox" id="<?php echo esc_attr($input_id); ?>" name="twcp_disabled_modules[<?php echo esc_attr($folder); ?>]" value="1" <?php checked(!in_array($folder, $disabled_modules, true)); ?> />
							<span class="screen-reader-text"><?php echo esc_html($module['name']); ?></span>
						</label>
					</td>
					<td class="tool_name">
						<?php echo esc_html($module['name'] ? : $folder); ?>
						<?php if (!$status['active']) { ?>
							<span class="badge badge_error"><?php echo sprintf(esc_html__('Requires %s', 'twee-mcp'), esc_html($status['plugin'])); ?></span>
						<?php } ?>
					</td>
					<td><?php echo esc_html($module['description']); ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>

		<div class="buttons">
			<button type="submit" class="button button_primary"><?php echo esc_html__('Save Modules', 'twee-mcp'); ?></button>
		</div>
	</form>
</div>
