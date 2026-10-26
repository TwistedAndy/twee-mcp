<?php
/**
 * Tab Template: Stock Images & Media Integration
 *
 * @var array $keys
 * @var bool  $instant_active
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$providers = [
	'unsplash'  => [
		'name'        => 'Unsplash',
		'status'      => !empty($keys['unsplash']),
		'description' => __('High-resolution photography with generous API limits.', 'twee-mcp'),
		'needs_key'   => true,
	],
	'pixabay'   => [
		'name'        => 'Pixabay',
		'status'      => !empty($keys['pixabay']),
		'description' => __('Over 4 million royalty-free photos, illustrations, and vectors.', 'twee-mcp'),
		'needs_key'   => true,
	],
	'pexels'    => [
		'name'        => 'Pexels',
		'status'      => !empty($keys['pexels']),
		'description' => __('Curated authentic photography licensed under Pexels license.', 'twee-mcp'),
		'needs_key'   => true,
	],
	'openverse' => [
		'name'        => 'Openverse',
		'status'      => true,
		'description' => __('WordPress Foundation search engine for 700M+ Creative Commons works (No API key required).', 'twee-mcp'),
		'needs_key'   => false,
	],
	'wikimedia' => [
		'name'        => 'Wikimedia Commons',
		'status'      => true,
		'description' => __('Millions of freely usable educational & historical media files (No API key required).', 'twee-mcp'),
		'needs_key'   => false,
	],
	'giphy'     => [
		'name'        => 'Giphy',
		'status'      => !empty($keys['giphy']),
		'description' => __('Short-form animated GIF search and stickers library.', 'twee-mcp'),
		'needs_key'   => true,
	],
];
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Stock Image Providers & MCP Integration', 'twee-mcp'); ?></h2>
		<div class="badges">
			<?php if (!empty($instant_active)) { ?>
				<span class="badge badge_success"><?php echo esc_html__('Instant Images Connected', 'twee-mcp'); ?></span>
			<?php } ?>
			<span class="badge badge_success"><?php echo esc_html__('Openverse & Wikimedia Ready', 'twee-mcp'); ?></span>
		</div>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Equips AI agents with external stock image search and automated sideloading directly into the WordPress Media Library, automatically attaching photos to WooCommerce products, blog posts, terms, and ACF blocks.', 'twee-mcp'); ?>
	</p>

	<!-- Provider Status Grid -->
	<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; margin-top: 14px; margin-bottom: 24px;">
		<?php foreach ($providers as $pid => $pinfo) { ?>
			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
					<strong><?php echo esc_html($pinfo['name']); ?></strong>
					<?php if ($pinfo['status']) { ?>
						<span class="badge badge_success" style="font-size: 11px;"><?php echo esc_html__('Active', 'twee-mcp'); ?></span>
					<?php } else { ?>
						<span class="badge" style="font-size: 11px; opacity: 0.7;"><?php echo esc_html__('No API Key', 'twee-mcp'); ?></span>
					<?php } ?>
				</div>
				<p class="text text_muted" style="margin: 0; font-size: 12px; line-height: 1.4;">
					<?php echo esc_html($pinfo['description']); ?>
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
				<?php echo esc_html__('Configure API keys for Unsplash, Pixabay, Pexels, or Giphy below.', 'twee-mcp'); ?>
			<?php } ?>
		</p>

		<form method="post" action="options.php" id="twcp_stock_keys_form">
			<?php settings_fields('twcp_images_group'); ?>
			<?php $saved_twcp_keys = (array) get_option('twcp_stock_api_keys', []); ?>

			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 14px;">
				<div class="field" style="margin-bottom: 0;">
					<label class="label" for="twcp_key_unsplash"><?php echo esc_html__('Unsplash Access Key', 'twee-mcp'); ?></label>
					<input
						type="password"
						id="twcp_key_unsplash"
						name="twcp_stock_api_keys[unsplash]"
						class="regular-text"
						value="<?php echo esc_attr($saved_twcp_keys['unsplash'] ?? ''); ?>"
						placeholder="<?php echo !empty($keys['unsplash']) ? esc_attr__('Inherited from Instant Images', 'twee-mcp') : 'Access Key'; ?>"
						autocomplete="off"
					/>
					<p class="text text_muted">
						<a href="https://unsplash.com/developers" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Get an Unsplash Access Key', 'twee-mcp'); ?></a>
						<?php echo esc_html__('Sign in, register a new application, and copy its Access Key (not the Secret Key) into this field.', 'twee-mcp'); ?>
					</p>
				</div>

				<div class="field" style="margin-bottom: 0;">
					<label class="label" for="twcp_key_pexels"><?php echo esc_html__('Pexels API Key', 'twee-mcp'); ?></label>
					<input
						type="password"
						id="twcp_key_pexels"
						name="twcp_stock_api_keys[pexels]"
						class="regular-text"
						value="<?php echo esc_attr($saved_twcp_keys['pexels'] ?? ''); ?>"
						placeholder="<?php echo !empty($keys['pexels']) ? esc_attr__('Inherited from Instant Images', 'twee-mcp') : 'API Key'; ?>"
						autocomplete="off"
					/>
					<p class="text text_muted">
						<a href="https://www.pexels.com/join-consumer/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Get a Pexels API Key', 'twee-mcp'); ?></a>
						<?php echo esc_html__('Create or sign in to a Pexels account, request API access, and copy your API key into this field.', 'twee-mcp'); ?>
					</p>
				</div>

				<div class="field" style="margin-bottom: 0;">
					<label class="label" for="twcp_key_pixabay"><?php echo esc_html__('Pixabay API Key', 'twee-mcp'); ?></label>
					<input
						type="password"
						id="twcp_key_pixabay"
						name="twcp_stock_api_keys[pixabay]"
						class="regular-text"
						value="<?php echo esc_attr($saved_twcp_keys['pixabay'] ?? ''); ?>"
						placeholder="<?php echo !empty($keys['pixabay']) ? esc_attr__('Inherited from Instant Images', 'twee-mcp') : 'API Key'; ?>"
						autocomplete="off"
					/>
					<p class="text text_muted">
						<a href="https://pixabay.com/service/about/api/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Get a Pixabay API Key', 'twee-mcp'); ?></a>
						<?php echo esc_html__('Sign in to Pixabay, open the API documentation, and copy the key shown for your account into this field.', 'twee-mcp'); ?>
					</p>
				</div>

				<div class="field" style="margin-bottom: 0;">
					<label class="label" for="twcp_key_giphy"><?php echo esc_html__('Giphy API Key', 'twee-mcp'); ?></label>
					<input
						type="password"
						id="twcp_key_giphy"
						name="twcp_stock_api_keys[giphy]"
						class="regular-text"
						value="<?php echo esc_attr($saved_twcp_keys['giphy'] ?? ''); ?>"
						placeholder="<?php echo !empty($keys['giphy']) ? esc_attr__('Inherited from Instant Images', 'twee-mcp') : 'API Key'; ?>"
						autocomplete="off"
					/>
					<p class="text text_muted">
						<a href="https://developers.giphy.com/" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Get a GIPHY API Key', 'twee-mcp'); ?></a>
						<?php echo esc_html__('Sign in to GIPHY Developers, create an app with the API option, and copy its API key into this field.', 'twee-mcp'); ?>
					</p>
				</div>
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
			placeholder="<?php echo esc_attr__('e.g. leather watch strap, vintage wristwatch, minimalist buckle...', 'twee-mcp'); ?>"
			class="regular-text"
			style="flex: 2; min-width: 260px;"
		/>

		<select id="twcp_stock_search_provider" style="flex: 1; min-width: 150px;">
			<option value="all"><?php echo esc_html__('All Configured Providers', 'twee-mcp'); ?></option>
			<option value="unsplash"><?php echo esc_html__('Unsplash', 'twee-mcp'); ?></option>
			<option value="pixabay"><?php echo esc_html__('Pixabay', 'twee-mcp'); ?></option>
			<option value="pexels"><?php echo esc_html__('Pexels', 'twee-mcp'); ?></option>
			<option value="openverse"><?php echo esc_html__('Openverse (Creative Commons)', 'twee-mcp'); ?></option>
			<option value="wikimedia"><?php echo esc_html__('Wikimedia Commons', 'twee-mcp'); ?></option>
			<option value="giphy"><?php echo esc_html__('Giphy (Animated GIFs)', 'twee-mcp'); ?></option>
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
