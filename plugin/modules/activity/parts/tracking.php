<?php
/**
 * Template part: Activity Tracking settings on the Agent Tools screen
 *
 * @var bool   $tracking_active
 * @var array  $tracking_roles
 * @var array  $roles
 * @var string $price_fields
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;
?>

<div class="item">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Activity Tracking', 'twee-mcp'); ?></h2>
		<?php if (!$tracking_active) { ?>
			<span class="badge badge_error"><?php echo esc_html__('Requires WP Activity Log', 'twee-mcp'); ?></span>
		<?php } elseif ($tracking_roles) { ?>
			<span class="badge badge_success"><?php echo esc_html__('On', 'twee-mcp'); ?></span>
		<?php } else { ?>
			<span class="badge"><?php echo esc_html__('Off', 'twee-mcp'); ?></span>
		<?php } ?>
	</div>
	<p class="text text_muted">
		<?php echo esc_html__('Logs what WP Activity Log does not record, for the roles below, as WP Activity Log events: the time each wp-admin tab is in front and used, per item and screen, and changes of brands, tags, collections and other terms of an item, and of the price fields below. The activity tools report them. Let the people you track know about it.', 'twee-mcp'); ?>
	</p>
	<form method="post" action="options.php">
		<?php settings_fields('twcp_tracking_group'); ?>
		<input type="hidden" name="twcp_tracking_roles[]" value="" />
		<div class="field">
			<span class="label"><?php echo esc_html__('Tracked Roles', 'twee-mcp'); ?></span>
			<div class="checks">
				<?php foreach ($roles as $role => $label) { ?>
					<label><input type="checkbox" name="twcp_tracking_roles[]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $tracking_roles, true)); ?> <?php disabled(!$tracking_active); ?> /> <?php echo esc_html($label); ?></label>
				<?php } ?>
			</div>
		</div>
		<div class="field">
			<label class="label" for="twcp_tracking_price_fields"><?php echo esc_html__('Price Fields', 'twee-mcp'); ?></label>
			<input type="text" id="twcp_tracking_price_fields" name="twcp_tracking_price_fields" value="<?php echo esc_attr($price_fields); ?>" class="regular-text" placeholder="base_prices, additional_prices" <?php disabled(!$tracking_active); ?> />
			<p class="text text_muted"><?php echo esc_html__('Meta keys of custom price fields, separated by commas, whose changes count as price updates. WooCommerce prices are logged by WP Activity Log already.', 'twee-mcp'); ?></p>
		</div>
		<div class="buttons">
			<button type="submit" class="button button_primary" <?php disabled(!$tracking_active); ?>><?php echo esc_html__('Save Tracking Settings', 'twee-mcp'); ?></button>
		</div>
	</form>
</div>
