<?php
/**
 * Tab Template: Automation
 *
 * Content and shop managers see what needs attention and the batch jobs their role can run.
 *
 * @var array  $overview         Item => [ label, count, url ] that needs attention
 * @var array  $sections         Section => [ label, description, position ]
 * @var array  $batch_cards      Section => batch cards
 * @var array  $plugins          Names of the SEO plugins and the plugins of the modules that are running
 * @var bool   $can_manage       Whether the user can set up the AI provider
 * @var string $model_missing    Why AI generation cannot run yet, empty when it can
 * @var string $model_url
 * @var array  $prompt_groups    Group => [ label, description, section, prompts ] for administrators
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$prompts = $prompt_groups ? array_merge(...array_values(array_column($prompt_groups, 'prompts'))) : [];
?>

<?php if ($model_missing and $batch_cards) { ?>
	<div class="item setup_notice">
		<div class="header">
			<h2 class="heading"><?php echo esc_html__('Connect an AI Model', 'twee-mcp'); ?></h2>
			<span class="badge badge_warning"><?php echo esc_html__('Automation Paused', 'twee-mcp'); ?></span>
		</div>
		<p class="text">
			<?php echo esc_html($model_missing); ?>
			<?php if ($can_manage) {
				echo esc_html__('Add an API key or sign in with ChatGPT on the Connection screen to run the jobs below and the AI bulk actions.', 'twee-mcp');
			} else {
				echo esc_html__('Ask an administrator to add an API key or connect a ChatGPT account on the Connection screen. The jobs below and the AI bulk actions run once that is done.', 'twee-mcp');
			} ?>
		</p>
		<?php if ($can_manage) { ?>
			<div class="buttons">
				<a href="<?php echo esc_url($model_url); ?>" class="button button_primary"><?php echo esc_html__('Set Up AI Provider', 'twee-mcp'); ?></a>
			</div>
		<?php } ?>
	</div>
<?php } ?>

<?php foreach ($prompt_groups as $group => $info) {
	if (empty($info['section'])) { ?>
		<div class="item">
			<div class="header">
				<h2 class="heading"><?php echo esc_html($info['label']); ?></h2>
			</div>
			<p class="text text_muted"><?php echo esc_html($info['description']); ?></p>
			<?php foreach ($info['prompts'] ?? [] as $option => $prompt) {
				echo twcp_template('prompt', ['option' => $option, 'prompt' => $prompt]);
			} ?>
		</div>
	<?php }
} ?>

<?php if ($overview) { ?>
	<div class="item">
		<div class="header">
			<h2 class="heading"><?php echo esc_html__('Needs Attention', 'twee-mcp'); ?></h2>
			<div class="badges">
				<?php foreach ($plugins as $plugin) { ?>
					<span class="badge badge_success"><?php echo esc_html($plugin); ?></span>
				<?php } ?>
			</div>
		</div>
		<div class="overview">
			<?php foreach ($overview as $item) {
				$tag = $item['url'] ? 'a' : 'div';
				?>
				<<?php echo $tag; ?> class="overview_item <?php echo $item['count'] ? 'overview_item_active' : ''; ?>" <?php echo $item['url'] ? 'href="' . esc_url($item['url']) . '"' : ''; ?>>
					<span class="overview_count"><?php echo esc_html(number_format_i18n($item['count'])); ?></span>
					<span class="overview_label"><?php echo esc_html($item['label']); ?></span>
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

		<?php // Prompts no job of the section runs with, such as those of agent tools, stay with the section
		$on_cards = array_merge(...array_values(array_column($cards, 'prompts')));
		foreach ($prompts as $option => $prompt) {
			if ($prompt['section'] === $section and !in_array($option, $on_cards, true)) {
				echo twcp_template('prompt', ['option' => $option, 'prompt' => $prompt]);
			}
		} ?>

		<?php do_action('twcp_batch_section', $section); ?>

		<?php foreach ($cards as $card) { ?>
			<div class="batch_card" data-type="<?php echo esc_attr($card['type']); ?>" data-calls="<?php echo (int) $card['cost'][0]; ?>" data-tokens="<?php echo (int) $card['cost'][1]; ?>" data-slice="<?php echo (int) $card['slice']; ?>"<?php echo $card['combine'] ? ' data-combine="1"' : ''; ?><?php echo $model_missing ? ' data-locked="1"' : ''; ?>>
				<div class="batch_header">
					<div class="batch_title">
						<strong><?php echo esc_html($card['title']); ?></strong>
						<span class="batch_badge badge"><?php echo esc_html($card['badge']); ?></span>
						<span class="batch_estimate text_muted" title="<?php echo esc_attr__('A rough estimate for the items left. Real usage depends on the content, the model, and the prompts.', 'twee-mcp'); ?>"></span>
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
						<?php if ($card['fields']) { ?>
							<select class="twcp_batch_field" aria-label="<?php echo esc_attr__('Field', 'twee-mcp'); ?>">
								<?php foreach ($card['fields'] as $value => $label) { ?>
									<option value="<?php echo esc_attr($value); ?>" <?php selected($card['data']['field'], $value); ?>><?php echo esc_html($label); ?></option>
								<?php } ?>
							</select>
						<?php } ?>
						<?php if ($card['modes']) { ?>
							<select class="twcp_batch_mode" aria-label="<?php echo esc_attr__('Mode', 'twee-mcp'); ?>">
								<?php foreach ($card['modes'] as $value => $label) { ?>
									<option value="<?php echo esc_attr($value); ?>" <?php selected($card['data']['mode'], $value); ?>><?php echo esc_html($label); ?></option>
								<?php } ?>
							</select>
						<?php } ?>
						<button type="button" class="button button_primary btn_start" <?php disabled((bool) $model_missing); ?>><?php echo esc_html($card['button']); ?></button>
						<button type="button" class="button btn_pause" style="display:none;"><?php echo esc_html__('Stop', 'twee-mcp'); ?></button>
						<button type="button" class="button btn_resume" style="display:none;"><?php echo esc_html__('Resume', 'twee-mcp'); ?></button>
						<button type="button" class="button btn_reset"><?php echo esc_html__('Reset', 'twee-mcp'); ?></button>
						<?php if ($card['undo']) { ?>
							<button type="button" class="button btn_undo" title="<?php echo esc_attr__('Restore the values the last run changed', 'twee-mcp'); ?>" <?php echo $card['data']['undo'] ? '' : 'style="display:none;"'; ?>><?php echo esc_html__('Undo Last Run', 'twee-mcp'); ?></button>
						<?php } ?>
					</div>
				</div>
				<div class="progress_container">
					<div class="progress_bar"><div class="progress_fill" style="width: 0%;"></div></div>
					<div class="progress_info">
						<span class="info_text"><?php echo sprintf(esc_html__('Processed: %d / %d', 'twee-mcp'), $card['data']['processed'], $card['data']['total']); ?></span>
						<span class="percent_text">0%</span>
					</div>
				</div>

				<?php $card_prompts = array_intersect_key($prompts, array_flip($card['prompts']));
				if ($card_prompts) { ?>
					<div class="batch_prompts">
						<?php foreach ($card_prompts as $option => $prompt) {
							echo twcp_template('prompt', ['option' => $option, 'prompt' => $prompt]);
						} ?>

						<details class="prompt batch_preview">
							<summary class="prompt_summary">
								<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
								<span class="prompt_label"><?php echo esc_html__('Preview', 'twee-mcp'); ?></span>
								<span class="text text_muted"><?php echo esc_html__('Try the instructions on one item, unsaved edits included. Nothing is saved.', 'twee-mcp'); ?></span>
								<span class="prompt_toggle">
									<span class="prompt_edit"><?php echo esc_html__('Open', 'twee-mcp'); ?></span>
									<span class="prompt_close"><?php echo esc_html__('Close', 'twee-mcp'); ?></span>
									<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
								</span>
							</summary>
							<div class="batch_preview_controls">
								<input type="search" class="twcp_preview_search" placeholder="<?php echo esc_attr__('Search items', 'twee-mcp'); ?>" aria-label="<?php echo esc_attr__('Search items', 'twee-mcp'); ?>">
								<select class="twcp_preview_item" aria-label="<?php echo esc_attr__('Item to preview', 'twee-mcp'); ?>">
									<option value=""><?php echo esc_html__('Loading items…', 'twee-mcp'); ?></option>
								</select>
								<button type="button" class="button button_primary twcp_preview_run"><?php echo esc_html__('Run Preview', 'twee-mcp'); ?></button>
							</div>
							<div class="batch_preview_result" hidden></div>
						</details>
					</div>
				<?php } ?>

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
