<?php
/**
 * Tab Template: Automation
 *
 * Content and shop managers see what needs attention and the batch jobs their role can run.
 *
 * @var array  $overview         [ label, count, url ] items that need attention
 * @var array  $sections         Section => [ label, description ]
 * @var array  $batch_cards      Section => batch cards
 * @var bool   $rank_math_active
 * @var bool   $yoast_active
 * @var bool   $woo_active
 * @var bool   $can_manage       Whether the user can set up the AI provider
 * @var string $model_missing    Why AI generation cannot run yet, empty when it can
 * @var string $model_url
 * @var array  $post_links       [ max, sources ] internal links of generated post content
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
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
				echo esc_html__('Add an API key or sign in with ChatGPT on the AI Provider screen to run the jobs below and the AI bulk actions.', 'twee-mcp');
			} else {
				echo esc_html__('Ask an administrator to add an API key or connect a ChatGPT account on the AI Provider screen. The jobs below and the AI bulk actions run once that is done.', 'twee-mcp');
			} ?>
		</p>
		<?php if ($can_manage) { ?>
			<div class="buttons">
				<a href="<?php echo esc_url($model_url); ?>" class="button button_primary"><?php echo esc_html__('Set Up AI Provider', 'twee-mcp'); ?></a>
			</div>
		<?php } ?>
	</div>
<?php } ?>

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

		<?php if ($section === 'content') {
			$link_sources = [
				'terms'    => __('The post\'s categories and tags', 'twee-mcp'),
				'posts'    => __('Related posts in the same categories', 'twee-mcp'),
				'pages'    => __('Top-level pages', 'twee-mcp'),
				'products' => __('Store categories with the most products', 'twee-mcp'),
				'lookup'   => __('Let the model search other posts and terms (uses more tokens)', 'twee-mcp'),
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
		<?php } ?>

		<?php foreach ($cards as $card) { ?>
			<div class="batch_card" data-type="<?php echo esc_attr($card['type']); ?>" data-calls="<?php echo (int) $card['cost'][0]; ?>" data-tokens="<?php echo (int) $card['cost'][1]; ?>" data-slice="<?php echo (int) $card['slice']; ?>"<?php echo $model_missing ? ' data-locked="1"' : ''; ?>>
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
						<button type="button" class="button button_primary btn_start" <?php disabled((bool) $model_missing); ?>><?php echo esc_html($card['btn_text']); ?></button>
						<?php if ($card['has_pause']) { ?>
							<button type="button" class="button btn_pause" style="display:none;"><?php echo esc_html__('Stop', 'twee-mcp'); ?></button>
							<button type="button" class="button btn_resume" style="display:none;"><?php echo esc_html__('Resume', 'twee-mcp'); ?></button>
						<?php } ?>
						<button type="button" class="button btn_reset"><?php echo esc_html__('Reset', 'twee-mcp'); ?></button>
						<?php if ($card['type'] !== 'blocks') { ?>
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
