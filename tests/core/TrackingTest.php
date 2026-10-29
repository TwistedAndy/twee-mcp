<?php

require_once dirname(__DIR__) . '/stubs/wsal.php';

/**
 * Editing time and term change tracking tests
 */
class TrackingTest extends TWCP_TestCase {

	public function set_up(): void
	{
		parent::set_up();
		twcp_tracking_hooks();
		update_option('twcp_tracking_roles', ['editor', 'ghost']);
		register_taxonomy('twcp_brand', 'post', ['show_ui' => true, 'labels' => ['name' => 'Brands']]);
	}

	/**
	 * Users of the chosen roles are tracked.
	 */
	public function test_tracked_users(): void
	{
		$this->assertSame(['editor'], twcp_tracking_roles(), 'Unknown roles are dropped');
		$this->assertTrue(twcp_tracking_user(new WP_User(self::factory()->user->create(['role' => 'editor']))));
		$this->assertFalse(twcp_tracking_user(new WP_User(self::factory()->user->create(['role' => 'author']))));
		$this->assertFalse(twcp_tracking_user(new WP_User(0)));
	}

	/**
	 * Active seconds are logged with the item or screen, at most ten minutes at a time.
	 */
	public function test_time(): void
	{
		$lamp = self::factory()->post->create(['post_title' => 'Lamp']);

		$item = twcp_tracking_time(90, $lamp, 'post');
		$this->assertSame([$lamp, 90, '2 minutes', 'the post Lamp'], [$item['PostID'], $item['Seconds'], $item['Duration'], $item['Target']]);

		$screen = twcp_tracking_time(5000, 0, 'edit-product');
		$this->assertSame([660, 'the edit-product screen'], [$screen['Seconds'], $screen['Target']]);
		$this->assertArrayNotHasKey('PostID', $screen);

		$this->assertSame([], twcp_tracking_time(3, $lamp, 'post'), 'Too short to log');
		$this->assertSame([TWCP_TIME_EVENT, $screen], end(\WSAL\Controllers\Alert_Manager::$events), 'Logged with WP Activity Log');
	}

	/**
	 * Term changes of a request are logged once per item and taxonomy, with the terms before and after.
	 */
	public function test_term_changes(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$bocci = self::factory()->term->create(['taxonomy' => 'twcp_brand', 'name' => 'Bocci']);
		$flos = self::factory()->term->create(['taxonomy' => 'twcp_brand', 'name' => 'Flos & Co']);
		$category = self::factory()->category->create();
		$lamp = self::factory()->post->create(['post_title' => 'Lamp']);
		twcp_tracking_flush();

		wp_set_object_terms($lamp, [$bocci], 'twcp_brand');
		wp_set_object_terms($lamp, [$flos], 'twcp_brand');
		wp_set_object_terms($lamp, [$category], 'category');
		$logged = twcp_tracking_flush();
		$this->assertCount(1, $logged, 'Categories are logged by WP Activity Log');
		$this->assertSame(['PostID' => $lamp, 'PostType' => 'post', 'PostTypeName' => 'post', 'PostStatus' => 'publish', 'PostTitle' => 'Lamp', 'Taxonomy' => 'twcp_brand', 'TaxonomyName' => 'brands', 'OldTerms' => 'None', 'NewTerms' => 'Flos & Co'], $logged[0]);

		wp_set_object_terms($lamp, [$flos], 'twcp_brand');
		$this->assertSame([], twcp_tracking_flush(), 'Unchanged terms');

		wp_set_object_terms($lamp, [$bocci, $flos], 'twcp_brand');
		$this->assertSame(['Flos & Co', 'Bocci, Flos & Co'], array_values(array_intersect_key(twcp_tracking_flush()[0], array_flip(['OldTerms', 'NewTerms']))));

		$draft = self::factory()->post->create(['post_status' => 'auto-draft']);
		wp_set_object_terms($draft, [$bocci], 'twcp_brand');
		$this->assertSame([], twcp_tracking_flush(), 'New items not saved yet');

		wp_set_current_user(self::factory()->user->create(['role' => 'author']));
		wp_set_object_terms($lamp, [], 'twcp_brand');
		$this->assertSame([], twcp_tracking_flush(), 'Untracked role');
	}

	/**
	 * Changes of custom price fields are logged with the numbers that changed, leaving out term IDs.
	 */
	public function test_price_changes(): void
	{
		update_option('twcp_tracking_price_fields', 'base_prices, additional_prices');
		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$lamp = self::factory()->post->create(['post_title' => 'Lamp']);
		$row = function(float $price, string $size): array {
			return [['pa_size' => [$size], 'prices' => [['base' => (string) $price, 'price_1' => $price]]]];
		};
		twcp_tracking_flush();

		add_post_meta($lamp, 'base_prices', $row(375, '4240'));
		update_post_meta($lamp, 'other_field', 5);
		$logged = twcp_tracking_flush();
		$this->assertCount(1, $logged);
		$this->assertSame(['base_prices', '0.prices.0.base: -, 0.prices.0.price_1: -', '0.prices.0.base: 375, 0.prices.0.price_1: 375'], [$logged[0]['Field'], $logged[0]['OldPrices'], $logged[0]['NewPrices']]);

		update_post_meta($lamp, 'base_prices', $row(375, '4241'));
		$this->assertSame([], twcp_tracking_flush(), 'Only a term ID changed');

		update_post_meta($lamp, 'base_prices', $row(390, '4241'));
		update_post_meta($lamp, 'base_prices', $row(410.5, '4241'));
		$this->assertSame(['0.prices.0.base: 375, 0.prices.0.price_1: 375', '0.prices.0.base: 410.5, 0.prices.0.price_1: 410.5'], array_values(array_intersect_key(twcp_tracking_flush()[0], array_flip(['OldPrices', 'NewPrices']))));
	}
}
