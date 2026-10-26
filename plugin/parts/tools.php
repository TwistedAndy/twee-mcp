<?php
/**
 * Tab Template: Tools & Automation
 *
 * Content and shop managers see the overview and the batch jobs their role can run.
 * Administrators also manage which MCP tools AI agents can use.
 *
 * @var array $overview         [ label, count, url ] items that need attention
 * @var array $sections         Section => [ label, description ]
 * @var array $batch_cards      Section => batch cards
 * @var bool  $rank_math_active
 * @var bool  $yoast_active
 * @var bool  $woo_active
 * @var bool  $can_manage       Whether the user can change MCP tool switches
 * @var array $tools
 * @var array $tool_groups
 * @var array $disabled_tools
 * @var int   $enabled_count
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<?php if ($overview) { ?>
	<div class="item">
		<div class="header">
			<h2 class="heading"><?php echo esc_html__('Needs Attention', 'twee-mcp'); ?></h2>
			<div class="badges">
				<?php if ($rank_math_active) { ?><span class="badge badge_success">Rank Math</span><?php } ?>
				<?php if ($yoast_active) { ?><span class="badge badge_success">Yoast SEO</span><?php } ?>
				<?php if ($woo_active) { ?><span class="badge badge_success">WooCommerce</span><?php } ?>
			</div>
		</div>
		<div class="overview">
			<?php foreach ($overview as [$label, $count, $url]) {
				$tag = $url ? 'a' : 'div';
				?>
				<<?php echo $tag; ?> class="overview_item <?php echo $count ? 'overview_item_active' : ''; ?>" <?php echo $url ? 'href="' . esc_url($url) . '"' : ''; ?>>
					<span class="overview_count"><?php echo esc_html(number_format_i18n($count)); ?></span>
					<span class="overview_label"><?php echo esc_html($label); ?></span>
				</<?php echo $tag; ?>>
			<?php } ?>
		</div>
		<p class="text text_muted">
			<?php echo esc_html__('The same AI actions are available as bulk actions on the Posts, Pages, Products, Media, and category screens for hand-picked items.', 'twee-mcp'); ?>
		</p>
	</div>
<?php } ?>

<?php foreach ($batch_cards as $section => $cards) { ?>
	<div class="item">
		<div class="header">
			<h2 class="heading"><?php echo esc_html($sections[$section]['label']); ?></h2>
		</div>
		<p class="text text_muted"><?php echo esc_html($sections[$section]['description']); ?></p>

		<?php foreach ($cards as $card) { ?>
			<div class="batch_card" data-type="<?php echo esc_attr($card['type']); ?>">
				<div class="batch_header">
					<div class="batch_title">
						<strong><?php echo esc_html($card['title']); ?></strong>
						<span class="batch_badge badge"><?php echo esc_html($card['badge']); ?></span>
						<div class="text text_muted batch_description"><?php echo esc_html($card['description']); ?></div>
					</div>
					<div class="batch_controls">
						<?php if (!empty($card['scopes'])) { ?>
							<select class="twcp_batch_post_type" aria-label="<?php echo esc_attr__('Scope', 'twee-mcp'); ?>">
								<?php foreach ($card['scopes'] as $value => $label) { ?>
									<option value="<?php echo esc_attr($value); ?>" <?php selected($card['data']['post_type'] ?? 'all', $value); ?>><?php echo esc_html($label); ?></option>
								<?php } ?>
							</select>
						<?php } ?>
						<?php if (!empty($card['override_label'])) { ?>
							<label class="switch switch_small batch_override_label">
								<input type="checkbox" class="twcp_batch_override" value="1" <?php checked(!empty($card['data']['override'])); ?> />
								<span><?php echo esc_html($card['override_label']); ?></span>
							</label>
						<?php } ?>
						<button type="button" class="button button_primary btn_start"><?php echo esc_html($card['btn_text']); ?></button>
						<?php if ($card['has_pause']) { ?>
							<button type="button" class="button btn_pause" style="display:none;"><?php echo esc_html__('Stop', 'twee-mcp'); ?></button>
							<button type="button" class="button btn_resume" style="display:none;"><?php echo esc_html__('Resume', 'twee-mcp'); ?></button>
						<?php } ?>
						<button type="button" class="button btn_reset"><?php echo esc_html__('Reset', 'twee-mcp'); ?></button>
					</div>
				</div>
				<div class="progress_container">
					<div class="progress_bar"><div class="progress_fill" style="width: 0%;"></div></div>
					<div class="progress_info">
						<span class="info_text"><?php echo sprintf(esc_html__('Processed: %d / %d', 'twee-mcp'), $card['data']['processed'], $card['data']['total']); ?></span>
						<span class="percent_text">0%</span>
					</div>
				</div>

				<div class="batch_log_wrapper" style="display: none;">
					<div class="batch_log_header">
						<span class="batch_log_title"><?php echo esc_html__('Live Activity Log', 'twee-mcp'); ?></span>
						<button type="button" class="btn_clear_log"><?php echo esc_html__('Clear', 'twee-mcp'); ?></button>
					</div>
					<div class="batch_log_box">
						<?php if (!empty($card['data']['logs'])) { ?>
							<?php foreach ($card['data']['logs'] as $log_entry) { ?>
								<div class="log_entry" style="color: <?php echo !empty($log_entry['success']) ? '#1e7e34' : '#d63638'; ?>;">
									<span style="color: #8c8f94;">[<?php echo esc_html($log_entry['time']); ?>]</span> <?php echo esc_html($log_entry['message']); ?>
								</div>
							<?php } ?>
						<?php } else { ?>
							<div class="log_empty" style="color: #8c8f94; font-style: italic;"><?php echo esc_html__('No items processed in this session yet.', 'twee-mcp'); ?></div>
						<?php } ?>
					</div>
				</div>
			</div>
		<?php } ?>
	</div>
<?php } ?>

<?php if (!$can_manage) {
	return;
} ?>

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
				$available = $info['active'] ?? true;
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
