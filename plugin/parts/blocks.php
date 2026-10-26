<?php
/**
 * Tab Template: ACF Blocks
 *
 * @var array  $available_blocks_fields
 * @var string $current_blocks_field
 * @var string $previews_folder
 * @var string $previews_dir
 * @var array  $available_previews
 * @var array  $dynamic_layouts
 * @var array  $default_descriptions
 * @var array  $saved_descriptions
 * @var string $conventions_default
 * @var string $saved_conventions
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('ACF Page Builder Blocks & Previews', 'twee-mcp'); ?></h2>
		<span class="badge"><?php echo sprintf(esc_html__('%d Layouts Available', 'twee-mcp'), count($dynamic_layouts)); ?></span>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Manage the ACF Flexible Content field used for page building, configure the preview screenshots folder, and inspect or generate AI layout descriptions.', 'twee-mcp'); ?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields('twcp_blocks_group'); ?>

		<div class="field">
			<label class="label" for="twcp_blocks_field"><?php echo esc_html__('Active ACF Flexible Content Field', 'twee-mcp'); ?></label>
			<select id="twcp_blocks_field" name="twcp_blocks_field" class="regular-text">
				<?php if (!empty($available_blocks_fields)) { ?>
					<?php foreach ($available_blocks_fields as $field_key => $field_data) { ?>
						<option value="<?php echo esc_attr($field_data['name']); ?>" <?php selected($current_blocks_field, $field_data['name']); ?>>
							<?php echo esc_html(($field_data['label'] ?? '') . ' (' . $field_data['name'] . ') — ' . count($field_data['layouts'] ?? []) . ' layouts'); ?>
						</option>
					<?php } ?>
				<?php } else { ?>
					<option value="blocks" <?php selected($current_blocks_field, 'blocks'); ?>>
						<?php echo esc_html__('blocks (Standard)', 'twee-mcp'); ?>
					</option>
				<?php } ?>
			</select>
			<div class="text text_muted" style="margin-top: 4px;">
				<?php echo esc_html__('Select which registered ACF Flexible Content field contains your theme\'s modular page builder layouts.', 'twee-mcp'); ?>
			</div>
		</div>

		<div class="field">
			<label class="label" for="twcp_block_previews_folder"><?php echo esc_html__('Block Previews Folder', 'twee-mcp'); ?></label>
			<div class="control" style="display: flex; gap: 8px; align-items: center; max-width: 650px;">
				<input type="text" id="twcp_block_previews_folder" name="twcp_block_previews_folder" class="regular-text" style="flex: 1;" value="<?php echo esc_attr($previews_folder); ?>" placeholder="assets/preview" />
				<button type="button" id="twcp_generate_blocks_btn" class="button">
					<?php echo esc_html__('Generate Descriptions from Previews', 'twee-mcp'); ?>
				</button>
			</div>
			<div class="text text_muted" style="margin-top: 4px;">
				<?php echo sprintf(esc_html__('Path relative to active theme (or absolute). Resolved: %s. Found: %d preview images.', 'twee-mcp'), '<code>' . esc_html($previews_dir ? : 'None') . '</code>', count($available_previews)); ?>
				<span id="twcp_generate_blocks_status" class="status_text" style="margin-left: 8px;"></span>
			</div>
		</div>

		<div class="field" style="margin-top: 24px;">
			<label class="label" for="twcp_block_conventions"><?php echo esc_html__('Block Conventions for AI Agents', 'twee-mcp'); ?></label>
			<textarea id="twcp_block_conventions" name="twcp_block_conventions" rows="14" class="large-text" style="font-family: monospace; font-size: 12px; line-height: 1.5;" data-default="<?php echo esc_attr($conventions_default); ?>"><?php echo esc_textarea($saved_conventions !== '' ? $saved_conventions : $conventions_default); ?></textarea>
			<div style="margin-top: 6px;">
				<button type="button" class="button twcp_reset_prompt twcp_restore_prompt_btn" data-target="twcp_block_conventions" data-default="<?php echo esc_attr($conventions_default); ?>" aria-label="<?php echo esc_attr__('Reset block conventions to default', 'twee-mcp'); ?>"><?php echo esc_html__('Reset to default', 'twee-mcp'); ?></button>
			</div>
			<div class="text text_muted" style="margin-top: 6px;">
				<?php echo esc_html__('Explains the shared fields (contents, settings, items), media rules (SVG icons vs. raster images), custom product/post selection, and text formats. Sent once with get_block_types, so keep it short.', 'twee-mcp'); ?>
			</div>
		</div>

		<div class="field" style="margin-top: 24px;">
			<label class="label"><?php echo esc_html__('ACF Block Descriptions Registry', 'twee-mcp'); ?></label>
			<p class="text text_muted" style="margin-bottom: 12px;">
				<?php echo esc_html__('These descriptions are exposed directly in the MCP tool registry (get_block_types) so AI agents understand each block\'s visual layout, subfields, and when to pick it.', 'twee-mcp'); ?>
			</p>

			<table class="widefat striped" style="border: 1px solid #dcdcde;">
				<thead>
				<tr>
					<th style="width: 80px; text-align: center; font-weight: 600;"><?php echo esc_html__('Preview', 'twee-mcp'); ?></th>
					<th style="width: 200px; font-weight: 600;"><?php echo esc_html__('Layout Name', 'twee-mcp'); ?></th>
					<th style="font-weight: 600;"><?php echo esc_html__('Agent Description & Semantics', 'twee-mcp'); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ($dynamic_layouts as $layout) {
					$lname = $layout['name'];
					$llabel = $layout['label'] ?? $lname;
					// The fallback is only a placeholder, so saving the form does not store it as a written description
					$cur_desc = $saved_descriptions[$lname] ?? '';
					$fallback = $default_descriptions[$lname] ?? '';
					$preview_file = function_exists('twcp_block_find_preview') ? twcp_block_find_preview($lname) : null;
					$preview_url = $preview_file && function_exists('twcp_block_preview_url') ? twcp_block_preview_url($preview_file) : '';
					$sub_count = count($layout['sub_fields'] ?? []);
					?>
					<tr data-layout="<?php echo esc_attr($lname); ?>">
						<td style="text-align: center; vertical-align: top; padding-top: 12px;">
							<?php if ($preview_url) { ?>
								<img src="<?php echo esc_url($preview_url); ?>" alt="<?php echo esc_attr($llabel); ?>" style="width: 60px; height: 45px; object-fit: cover; border-radius: 4px; border: 1px solid #c3c4c7;" />
							<?php } else { ?>
								<div style="width: 60px; height: 45px; background: #f0f0f1; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; color: #8c8f94; font-size: 10px;">
									No img
								</div>
							<?php } ?>
						</td>
						<td style="vertical-align: top; padding-top: 12px;">
							<strong><?php echo esc_html($llabel); ?></strong>
							<br />
							<code><?php echo esc_html($lname); ?></code>
							<br />
							<span style="font-size: 11px; color: #646970;"><?php echo sprintf(esc_html__('%d Subfields', 'twee-mcp'), $sub_count); ?></span>
							<div style="margin-top: 6px;">
								<button type="button" class="button button-small twcp_generate_single_block_btn" data-layout="<?php echo esc_attr($lname); ?>">
									<?php echo esc_html__('Generate', 'twee-mcp'); ?>
								</button>
							</div>
						</td>
						<td style="vertical-align: top;">
							<textarea id="twcp_desc_<?php echo esc_attr($lname); ?>" name="twcp_block_descriptions[<?php echo esc_attr($lname); ?>]" rows="3" class="large-text" style="width: 100%; transition: background-color 0.4s ease;" placeholder="<?php echo esc_attr($fallback); ?>"><?php echo esc_textarea($cur_desc); ?></textarea>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>

		<div class="buttons" style="margin-top: 16px;">
			<button type="submit" class="button button_primary"><?php echo esc_html__('Save Block Descriptions', 'twee-mcp'); ?></button>
		</div>
	</form>
</div>
