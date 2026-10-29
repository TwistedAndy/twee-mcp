<?php
/**
 * Tab Template: Images
 *
 * Stock photo provider keys and a search that imports photos into the Media Library.
 *
 * @var array $providers Provider => [ name, description, key, ... ]
 * @var array $keys      Provider => key, for the providers that take one
 * @var bool  $instant_active
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$keyless = array_column(array_diff_key($providers, $keys), 'name');
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Stock Image Providers & MCP Integration', 'twee-mcp'); ?></h2>
		<div class="badges">
			<?php if (!empty($instant_active)) { ?>
				<span class="badge badge_success"><?php echo esc_html__('Instant Images Connected', 'twee-mcp'); ?></span>
			<?php } ?>
			<?php if ($keyless) { ?>
				<span class="badge badge_success"><?php echo esc_html(sprintf(__('%s Ready', 'twee-mcp'), implode(' & ', $keyless))); ?></span>
			<?php } ?>
		</div>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Equips AI agents with external stock image search and automated sideloading directly into the WordPress Media Library, automatically attaching photos to WooCommerce products, blog posts, terms, and ACF blocks.', 'twee-mcp'); ?>
	</p>

	<!-- Provider Status Grid -->
	<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; margin-top: 14px; margin-bottom: 24px;">
		<?php foreach ($providers as $pid => $pinfo) {
			$active = !isset($keys[$pid]) || $keys[$pid] !== ''; ?>
			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
					<strong><?php echo esc_html($pinfo['name']); ?></strong>
					<?php if ($active) { ?>
						<span class="badge badge_success" style="font-size: 11px;"><?php echo esc_html__('Active', 'twee-mcp'); ?></span>
					<?php } else { ?>
						<span class="badge" style="font-size: 11px; opacity: 0.7;"><?php echo esc_html__('No API Key', 'twee-mcp'); ?></span>
					<?php } ?>
				</div>
				<p class="text text_muted" style="margin: 0; font-size: 12px; line-height: 1.4;">
					<?php echo esc_html($pinfo['description'] ?? ''); ?>
				</p>
			</div>
		<?php } ?>
	</div>

	<!-- API Keys Configuration Form -->
	<div class="group" style="margin-top: 20px;">
		<h3 class="title"><?php echo esc_html__('Provider API Keys & Overrides', 'twee-mcp'); ?></h3>
		<p class="text text_muted">
			<?php if (!empty($instant_active)) { ?>
				<?php echo esc_html__('Keys configured in Instant Images are inherited automatically. Use the provider links below to obtain your own keys.', 'twee-mcp'); ?>
			<?php } else { ?>
				<?php echo esc_html__('Add the API keys of the providers below.', 'twee-mcp'); ?>
			<?php } ?>
		</p>

		<form method="post" action="options.php" id="twcp_stock_keys_form">
			<?php settings_fields('twcp_images_group'); ?>
			<?php $saved_twcp_keys = (array) get_option('twcp_stock_api_keys', []); ?>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 14px;">
				<?php foreach ($providers as $pid => $pinfo) {
					if (empty($pinfo['key'])) {
						continue;
					} ?>
					<div class="field" style="margin-bottom: 0;">
						<label class="label" for="twcp_key_<?php echo esc_attr($pid); ?>"><?php echo esc_html($pinfo['key']['label']); ?></label>
						<input
							type="text"
							id="twcp_key_<?php echo esc_attr($pid); ?>"
							name="twcp_stock_api_keys[<?php echo esc_attr($pid); ?>]"
							class="regular-text masked"
							value="<?php echo esc_attr($saved_twcp_keys[$pid] ?? ''); ?>"
							placeholder="<?php echo !empty($keys[$pid]) ? esc_attr__('Inherited from Instant Images', 'twee-mcp') : ''; ?>"
							autocomplete="off"
							spellcheck="false"
							data-1p-ignore
							data-lpignore="true"
							data-bwignore
						/>
						<p class="text text_muted">
							<a href="<?php echo esc_url($pinfo['key']['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($pinfo['key']['link']); ?></a>
							<?php echo esc_html($pinfo['key']['help'] ?? ''); ?>
						</p>
					</div>
				<?php } ?>
			</div>

			<div class="buttons" style="margin-top: 16px;">
				<button type="submit" class="button button_primary">
					<?php echo esc_html__('Save Image API Keys', 'twee-mcp'); ?>
				</button>
			</div>
		</form>
	</div>
</div>

<!-- Interactive Stock Image Search & Test Sideload Card -->
<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Interactive Stock Image Search & Import Test', 'twee-mcp'); ?></h2>
		<span class="badge"><?php echo esc_html__('Test MCP Tool', 'twee-mcp'); ?></span>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Test the image search and media sideload capabilities used by external MCP AI agents. Sideloaded images receive automatic attachment metadata and are imported directly into the WordPress Media Library.', 'twee-mcp'); ?>
	</p>

	<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 14px; margin-bottom: 14px;">
		<input
			type="text"
			id="twcp_stock_search_query"
			placeholder="<?php echo esc_attr__('e.g. team meeting, product on white background, city skyline...', 'twee-mcp'); ?>"
			class="regular-text"
			style="flex: 2; min-width: 260px;"
		/>

		<select id="twcp_stock_search_provider" style="flex: 1; min-width: 150px;">
			<option value="all"><?php echo esc_html__('All Configured Providers', 'twee-mcp'); ?></option>
			<?php foreach ($providers as $pid => $pinfo) { ?>
				<option value="<?php echo esc_attr($pid); ?>"><?php echo esc_html($pinfo['name']); ?></option>
			<?php } ?>
		</select>

		<select id="twcp_stock_search_orientation" style="width: 130px;">
			<option value=""><?php echo esc_html__('Any Orientation', 'twee-mcp'); ?></option>
			<option value="landscape"><?php echo esc_html__('Landscape', 'twee-mcp'); ?></option>
			<option value="portrait"><?php echo esc_html__('Portrait', 'twee-mcp'); ?></option>
			<option value="squarish"><?php echo esc_html__('Square', 'twee-mcp'); ?></option>
		</select>

		<select id="twcp_stock_search_per_page" style="width: 120px;">
			<option value="12" selected><?php echo esc_html__('12 per page', 'twee-mcp'); ?></option>
			<option value="24"><?php echo esc_html__('24 per page', 'twee-mcp'); ?></option>
			<option value="36"><?php echo esc_html__('36 per page', 'twee-mcp'); ?></option>
			<option value="48"><?php echo esc_html__('48 per page', 'twee-mcp'); ?></option>
		</select>

		<button type="button" id="twcp_stock_search_btn" class="button button_primary">
			<?php echo esc_html__('Search Images', 'twee-mcp'); ?>
		</button>
	</div>

	<div id="twcp_stock_search_status" class="status_text" style="margin-bottom: 12px;"></div>

	<!-- Results Container -->
	<div id="twcp_stock_results_grid" class="stock_grid"></div>

	<!-- Pagination Controls -->
	<div id="twcp_stock_pagination" class="stock_pagination" style="display: none;">
		<div id="twcp_stock_pagination_info" class="stock_pagination_info"></div>
		<div id="twcp_stock_pagination_controls" class="stock_pagination_controls"></div>
	</div>
</div>
