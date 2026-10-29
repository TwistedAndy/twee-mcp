<?php

/**
 * WooCommerce product and variation tool tests
 */
class ProductTest extends TWCP_TestCase {

	public function set_up(): void
	{
		parent::set_up();
		$this->require_woocommerce();
	}

	/**
	 * Products are created as drafts with prices, stock, and attributes.
	 */
	public function test_create_product(): void
	{
		$result = $this->call('create_product', [
			'name'           => 'Leather Strap',
			'type'           => 'simple',
			'sku'            => 'LS-20',
			'regular_price'  => '49.00',
			'sale_price'     => '39.00',
			'manage_stock'   => true,
			'stock_quantity' => 7,
			'seo_title'      => 'Leather Strap 20mm',
			'attributes'     => [['name' => 'Width', 'options' => ['20mm', '22mm']]],
		]);

		$this->assertTrue($result['success'], $result['error'] ?? '');
		$product = wc_get_product($result['product']['id']);
		$this->assertSame('draft', $product->get_status());
		$this->assertSame('LS-20', $product->get_sku());
		$this->assertSame('39.00', $product->get_sale_price());
		$this->assertSame(7, $product->get_stock_quantity());
		$this->assertSame(['20mm', '22mm'], $product->get_attribute('Width') ? explode(' | ', $product->get_attribute('Width')) : []);
		$this->assertSame('Leather Strap 20mm', get_post_meta($product->get_id(), 'rank_math_title', true));
	}

	/**
	 * Editing preserves omitted fields and ignores the immutable type.
	 */
	public function test_edit_product(): void
	{
		$product = new WC_Product_Simple();
		$product->set_name('Rubber Strap');
		$product->set_regular_price('30');
		$product->save();

		$result = $this->call('edit_product', ['product_id' => $product->get_id(), 'type' => 'simple', 'sale_price' => '25']);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$product = wc_get_product($product->get_id());
		$this->assertSame('30', $product->get_regular_price());
		$this->assertSame('25', $product->get_sale_price());

		$this->assertArrayHasKey('error', $this->call('edit_product', ['product_id' => 999999, 'name' => 'X']));
	}

	/**
	 * Products filter by SKU, stock status, and low stock.
	 */
	public function test_get_products_filters(): void
	{
		$low = $this->product('Low', ['sku' => 'LOW', 'manage_stock' => true, 'stock_quantity' => 2]);
		$plenty = $this->product('Plenty', ['manage_stock' => true, 'stock_quantity' => 50]);
		$out = $this->product('Out', ['stock_status' => 'outofstock']);

		$this->assertSame($low, $this->call('get_products', ['sku' => 'LOW'])['id']);
		$this->assertSame([$out], array_column($this->call('get_products', ['stock_status' => 'outofstock'])['posts'], 'id'));
		$this->assertSame([$low], array_column($this->call('get_products', ['stock_below' => 5])['posts'], 'id'));
		$this->assertSame([], $this->call('get_products', ['sku' => 'MISSING'])['posts']);
		$this->assertContains($plenty, array_column($this->call('get_products', ['limit' => 10])['posts'], 'id'));
	}

	/**
	 * Products sort by price, a term ID alone filters categories, and an ID of another post type is not a product.
	 */
	public function test_get_products_sorting(): void
	{
		$category = self::factory()->term->create(['taxonomy' => 'product_cat']);
		$cheap = $this->product('Cheap', ['regular_price' => '5', 'category_ids' => [$category]]);
		$dear = $this->product('Dear', ['regular_price' => '90']);
		$middle = $this->product('Middle', ['regular_price' => '40']);

		$this->assertSame([$cheap, $middle, $dear], array_column($this->call('get_products', ['order_by' => 'price', 'order' => 'ASC'])['posts'], 'id'));
		$this->assertSame([$cheap], array_column($this->call('get_products', ['term_id' => $category])['posts'], 'id'));
		$this->assertStringContainsString('Product not found', $this->call('get_products', ['id' => self::factory()->post->create()])['error']);
	}

	/**
	 * A store-wide attribute is saved under its taxonomy with its terms, and products return attributes in the format
	 * create_product takes.
	 */
	public function test_global_attribute(): void
	{
		wc_create_attribute(['name' => 'Color', 'slug' => 'color']);
		register_taxonomy('pa_color', 'product');
		wp_insert_term('Black', 'pa_color');

		$result = $this->call('create_product', [
			'name'       => 'NATO Strap',
			'type'       => 'variable',
			'attributes' => [['name' => 'Color', 'options' => ['Black', 'Olive'], 'variation' => true], ['name' => 'Fit', 'options' => ['Slim']]],
		]);
		$this->assertTrue($result['success'], $result['error'] ?? '');

		$id = $result['product']['id'];
		$this->assertSame(['Black', 'Olive'], wp_get_post_terms($id, 'pa_color', ['fields' => 'names']));
		$this->assertSame([
			['name' => 'Color', 'taxonomy' => 'pa_color', 'options' => ['Black', 'Olive'], 'visible' => true, 'variation' => true],
			['name' => 'Fit', 'options' => ['Slim'], 'visible' => true, 'variation' => false],
		], $this->call('get_products', ['id' => $id])['product']['attributes']);

		$variation = $this->call('save_product_variation', ['product_id' => $id, 'attributes' => ['Color' => 'Olive'], 'regular_price' => '25']);
		$this->assertSame(['pa_color' => 'olive'], $variation['variation']['attributes']);
		$this->assertSame(['Color' => ['Black', 'Olive']], $this->call('get_product_variations', ['product_id' => $id])['attributes']);
	}

	/**
	 * Variations are created from attribute names and listed with prices.
	 */
	public function test_variations(): void
	{
		$parent = new WC_Product_Variable();
		$parent->set_name('Nato Strap');
		$color = new WC_Product_Attribute();
		$color->set_name('Color');
		$color->set_options(['Black', 'Olive']);
		$color->set_variation(true);
		$parent->set_attributes([$color]);
		$parent->save();

		$created = $this->call('save_product_variation', ['product_id' => $parent->get_id(), 'attributes' => ['Color' => 'Olive'], 'regular_price' => '19', 'sku' => 'NATO-OL']);
		$this->assertTrue($created['success'], $created['error'] ?? '');
		$this->assertSame(['color' => 'Olive'], $created['variation']['attributes']);

		$edited = $this->call('save_product_variation', ['product_id' => $parent->get_id(), 'variation_id' => $created['variation']['id'], 'sale_price' => '15']);
		$this->assertSame('19', $edited['variation']['regular_price']);
		$this->assertSame('15', $edited['variation']['sale_price']);

		$list = $this->call('get_product_variations', ['product_id' => $parent->get_id()]);
		$this->assertSame(['Color' => ['Black', 'Olive']], $list['attributes']);
		$this->assertSame(['NATO-OL'], array_column($list['variations'], 'sku'));

		$this->assertStringContainsString('Unknown variation attribute', $this->call('save_product_variation', ['product_id' => $parent->get_id(), 'attributes' => ['Size' => 'L']])['error']);
		$this->assertStringContainsString('required', $this->call('save_product_variation', ['product_id' => $parent->get_id()])['error']);
		$this->assertStringContainsString('Variable product not found', $this->call('get_product_variations', ['product_id' => $this->product('Simple')])['error']);
	}

	/**
	 * Short descriptions are generated without lookup tools and saved as SEO descriptions.
	 */
	public function test_generate_short_description(): void
	{
		update_option('twcp_model_provider', 'anthropic');
		update_option('twcp_model_api_key', 'test-key');
		$requests = $this->mock_model(['content' => [['type' => 'text', 'text' => 'Supple black leather strap with quick-release bars.']]]);
		$id = $this->product('Black Leather');

		$result = $this->call('generate_product_short_description', ['product_id' => $id]);
		$this->assertTrue($result['success']);
		$this->assertSame('Supple black leather strap with quick-release bars.', wc_get_product($id)->get_short_description());
		$this->assertSame('Supple black leather strap with quick-release bars.', get_post_meta($id, '_yoast_wpseo_metadesc', true));
		$this->assertArrayNotHasKey('tools', $requests[0]['body']);

		remove_all_filters('pre_http_request');
		delete_option('twcp_model_provider');
		delete_option('twcp_model_api_key');
	}

	/**
	 * Create a published simple product
	 *
	 * @param string $name
	 * @param array  $props
	 *
	 * @return int
	 */
	private function product(string $name, array $props = []): int
	{
		$product = new WC_Product_Simple();
		$product->set_props($props + ['name' => $name, 'status' => 'publish', 'regular_price' => '10']);

		return $product->save();
	}
}
