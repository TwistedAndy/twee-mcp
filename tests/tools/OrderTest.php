<?php

/**
 * WooCommerce order, sales summary, and coupon tool tests
 */
class OrderTest extends TWCP_TestCase {

	public function set_up(): void
	{
		parent::set_up();
		$this->require_woocommerce();
	}

	/**
	 * Orders are listed by status and read one by one with notes.
	 */
	public function test_get_orders(): void
	{
		$processing = $this->order('processing', 2);
		$this->order('completed', 1);

		$list = $this->call('get_orders', ['status' => 'wc-processing']);
		$this->assertSame(1, $list['total']);
		$this->assertSame($processing, $list['orders'][0]['id']);
		$this->assertSame('Order Strap', $list['orders'][0]['items'][0]['name']);
		$this->assertArrayNotHasKey('email', $list['orders'][0]);

		wc_get_order($processing)->add_order_note('Packed');
		$single = $this->call('get_orders', ['id' => $processing]);
		$this->assertSame('buyer@example.org', $single['email']);
		$this->assertContains('Packed', array_column($single['notes'], 'content'));

		$this->assertSame(2, $this->call('get_orders', ['date_from' => wp_date('Y-m-d'), 'date_to' => wp_date('Y-m-d')])['total']);
		$this->assertStringContainsString('YYYY-MM-DD', $this->call('get_orders', ['date_from' => '01/02/2025'])['error']);
		$this->assertStringContainsString('not found', $this->call('get_orders', ['id' => 999999])['error']);
		$this->assertStringContainsString('Unknown order status', $this->call('get_orders', ['status' => 'shipped'])['error']);
	}

	/**
	 * Status changes and notes are saved; unknown statuses are refused.
	 */
	public function test_update_order(): void
	{
		$id = $this->order('processing', 1);

		$result = $this->call('update_order', ['order_id' => $id, 'status' => 'completed', 'note' => 'Shipped with DHL', 'customer_note' => true]);
		$this->assertTrue($result['success']);
		$this->assertSame('completed', wc_get_order($id)->get_status());

		$notes = wc_get_order_notes(['order_id' => $id]);
		$customer = array_values(array_filter($notes, fn($note) => $note->content === 'Shipped with DHL'));
		$this->assertTrue($customer[0]->customer_note);

		$this->assertStringContainsString('Unknown order status', $this->call('update_order', ['order_id' => $id, 'status' => 'lost'])['error']);
		$this->assertArrayHasKey('error', $this->call('update_order', ['order_id' => $id]));
	}

	/**
	 * The summary counts paid orders, revenue, refunds, and best sellers.
	 */
	public function test_sales_summary(): void
	{
		$this->order('completed', 2);
		$refunded = $this->order('processing', 1);
		$this->order('pending', 5);
		wc_create_refund(['order_id' => $refunded, 'amount' => 5]);

		$summary = $this->call('get_sales_summary', []);
		$this->assertSame(2, $summary['orders']);
		$this->assertEquals(60.0, $summary['gross']);
		$this->assertEquals(5.0, $summary['refunds']);
		$this->assertEquals(55.0, $summary['net']);
		$this->assertSame(3, $summary['items_sold']);
		$this->assertSame('Order Strap', $summary['top_products'][0]['name']);

		$this->assertArrayHasKey('error', $this->call('get_sales_summary', ['date_from' => '2025-02-01', 'date_to' => '2025-01-01']));
	}

	/**
	 * Coupons are created, edited, listed, and kept unique.
	 */
	public function test_coupons(): void
	{
		$created = $this->call('save_coupon', ['code' => 'Spring15', 'discount_type' => 'percent', 'amount' => '15', 'date_expires' => '2099-03-31', 'usage_limit' => 100]);
		$this->assertTrue($created['success'], $created['error'] ?? '');
		$this->assertSame('spring15', $created['coupon']['code']);
		$this->assertSame('publish', $created['coupon']['status']);
		$this->assertSame('2099-03-31', $created['coupon']['date_expires']);

		$edited = $this->call('save_coupon', ['coupon_id' => $created['coupon']['id'], 'amount' => '20', 'date_expires' => '']);
		$this->assertSame('20', $edited['coupon']['amount']);
		$this->assertSame('percent', $edited['coupon']['discount_type']);
		$this->assertNull($edited['coupon']['date_expires']);

		$this->assertSame(['spring15'], array_column($this->call('get_coupons', ['search' => 'spring'])['coupons'], 'code'));
		$this->assertStringContainsString('already uses', $this->call('save_coupon', ['code' => 'SPRING15'])['error']);
		$this->assertStringContainsString('required', $this->call('save_coupon', ['amount' => '5'])['error']);
		$this->assertStringContainsString('not found', $this->call('save_coupon', ['coupon_id' => self::factory()->post->create(), 'amount' => '5'])['error']);
	}

	/**
	 * Shop managers can use order tools; editors cannot.
	 */
	public function test_order_capabilities(): void
	{
		wp_set_current_user(self::factory()->user->create(['role' => 'shop_manager']));
		$this->assertArrayHasKey('orders', $this->call('get_orders'));

		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->assertStringContainsString('cannot use the get_orders tool', $this->call('get_orders')['error']);
	}

	/**
	 * Create an order with one 20.00 product line
	 *
	 * @param string $status
	 * @param int    $quantity
	 *
	 * @return int
	 */
	private function order(string $status, int $quantity): int
	{
		$product = new WC_Product_Simple();
		$product->set_props(['name' => 'Order Strap', 'status' => 'publish', 'regular_price' => '20']);
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, $quantity);
		$order->set_billing_email('buyer@example.org');
		$order->set_billing_first_name('Ann');
		$order->calculate_totals();
		$order->set_status($status);
		$order->save();

		return $order->get_id();
	}
}
