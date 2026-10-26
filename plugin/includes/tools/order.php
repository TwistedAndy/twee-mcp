<?php
/**
 * Twee MCP — WooCommerce Orders, Coupons & Sales
 *
 * Implements MCP tools for reading and updating orders, sales summaries, and coupons.
 * Order tools return customer data, so they are private: internal model lookups never call them.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * MCP tools for WooCommerce orders and coupons
 *
 * @return array Tool name => [ description, callable, parameters, required, annotations ]
 */
function twcp_order_tools(): array
{
	return [
		'get_orders'        => [
			'description' => 'Read WooCommerce orders with status, totals, customer, line items, and payment method. Provide "id" to get one order with its notes. Filter by status, customer, or date range.',
			'callable'    => 'twcp_order_query',
			'capability'  => 'edit_shop_orders',
			'private'     => true,
			'parameters'  => [
				'id'          => ['type' => 'integer', 'description' => 'Single order ID.'],
				'status'      => ['type' => 'string', 'description' => 'Order status without the "wc-" prefix, e.g. "processing", "on-hold", "completed".'],
				'customer_id' => ['type' => 'integer', 'description' => 'Customer user ID.'],
				'date_from'   => ['type' => 'string', 'description' => 'Created on or after, YYYY-MM-DD.'],
				'date_to'     => ['type' => 'string', 'description' => 'Created on or before, YYYY-MM-DD.'],
				'limit'       => ['type' => 'integer', 'description' => 'Number of orders (default 20, max 50).'],
				'offset'      => ['type' => 'integer', 'description' => 'Pagination offset.'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'update_order'      => [
			'description' => 'Change a WooCommerce order status and/or add an order note. Customer notes are emailed to the customer; private notes are visible to staff only.',
			'callable'    => 'twcp_order_update',
			'capability'  => 'edit_shop_orders',
			'required'    => ['order_id'],
			'parameters'  => [
				'order_id'      => ['type' => 'integer', 'minimum' => 1],
				'status'        => ['type' => 'string', 'description' => 'New status without the "wc-" prefix.'],
				'note'          => ['type' => 'string', 'description' => 'Order note text.'],
				'customer_note' => ['type' => 'boolean', 'description' => 'Send the note to the customer (default false).'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'get_sales_summary' => [
			'description' => 'Summarize WooCommerce sales for a date range: paid order count, gross and net revenue, refunds, average order value, items sold, and best-selling products.',
			'callable'    => 'twcp_order_summary',
			'capability'  => 'view_woocommerce_reports',
			'private'     => true,
			'parameters'  => [
				'date_from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (default 30 days ago).'],
				'date_to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (default today).'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'get_coupons'       => [
			'description' => 'List WooCommerce coupons with discount type, amount, usage, limits, expiry, and product restrictions.',
			'callable'    => 'twcp_coupon_query',
			'capability'  => 'edit_shop_coupons',
			'parameters'  => [
				'search' => ['type' => 'string', 'description' => 'Search in coupon codes.'],
				'limit'  => ['type' => 'integer', 'description' => 'Number of coupons (default 20, max 50).'],
				'offset' => ['type' => 'integer', 'description' => 'Pagination offset.'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'save_coupon'       => [
			'description' => 'Create a WooCommerce coupon, or edit one by coupon_id. Omitted fields are preserved when editing.',
			'callable'    => 'twcp_coupon_save',
			'capability'  => 'edit_shop_coupons',
			'parameters'  => [
				'coupon_id'            => ['type' => 'integer', 'description' => 'Existing coupon ID to edit.'],
				'code'                 => ['type' => 'string', 'description' => 'Coupon code; required for new coupons.'],
				'discount_type'        => ['type' => 'string', 'enum' => ['percent', 'fixed_cart', 'fixed_product']],
				'amount'               => ['type' => 'string', 'description' => 'Discount amount, e.g. "15".'],
				'description'          => ['type' => 'string'],
				'date_expires'         => ['type' => 'string', 'description' => 'Expiry date YYYY-MM-DD; empty string removes it.'],
				'usage_limit'          => ['type' => 'integer', 'minimum' => 0],
				'usage_limit_per_user' => ['type' => 'integer', 'minimum' => 0],
				'individual_use'       => ['type' => 'boolean'],
				'free_shipping'        => ['type' => 'boolean'],
				'minimum_amount'       => ['type' => 'string'],
				'maximum_amount'       => ['type' => 'string'],
				'product_ids'          => ['type' => 'array', 'items' => ['type' => 'integer']],
				'excluded_product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
				'product_categories'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Product category IDs.'],
				'status'               => ['type' => 'string', 'enum' => ['publish', 'draft']],
			],
			'annotations' => ['readOnlyHint' => false],
		],
	];
}


/**
 * Validate a YYYY-MM-DD date
 *
 * @param mixed $date
 *
 * @return string|null Normalized date or null
 */
function twcp_order_date($date): ?string
{
	$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date, wp_timezone());

	return ($parsed and $parsed->format('Y-m-d') === $date) ? $date : null;
}


/**
 * Format an order for MCP responses
 *
 * @param WC_Order $order
 * @param bool     $details Include addresses and notes
 *
 * @return array
 */
function twcp_order_format(WC_Order $order, bool $details = false): array
{
	$items = [];
	foreach ($order->get_items() as $item) {
		$items[] = [
			'name'         => $item->get_name(),
			'product_id'   => $item->get_product_id(),
			'variation_id' => $item->get_variation_id(),
			'quantity'     => $item->get_quantity(),
			'total'        => $item->get_total(),
		];
	}

	$created = $order->get_date_created();
	$data = [
		'id'             => $order->get_id(),
		'number'         => $order->get_order_number(),
		'status'         => $order->get_status(),
		'date_created'   => $created ? $created->date('Y-m-d H:i:s') : null,
		'currency'       => $order->get_currency(),
		'total'          => $order->get_total(),
		'total_refunded' => (string) $order->get_total_refunded(),
		'payment_method' => $order->get_payment_method_title(),
		'customer_id'    => $order->get_customer_id(),
		'customer'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
		'items'          => $items,
		'edit_link'      => $order->get_edit_order_url(),
	];

	if ($details) {
		$data['email'] = $order->get_billing_email();
		$data['phone'] = $order->get_billing_phone();
		$data['billing'] = $order->get_formatted_billing_address();
		$data['shipping'] = $order->get_formatted_shipping_address();
		$data['customer_note'] = $order->get_customer_note();
		$data['notes'] = array_map(fn($note) => [
			'date'          => $note->date_created ? $note->date_created->date('Y-m-d H:i:s') : null,
			'content'       => $note->content,
			'customer_note' => (bool) $note->customer_note,
		], wc_get_order_notes(['order_id' => $order->get_id()]));
	}

	return $data;
}


/**
 * Action/Tool: Query orders
 *
 * @param array $args
 *
 * @return array
 */
function twcp_order_query(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	if (!empty($args['id'])) {
		$order = wc_get_order(absint($args['id']));

		return $order instanceof WC_Order ? twcp_order_format($order, true) : ['error' => 'Order not found: ' . absint($args['id'])];
	}

	$limit = isset($args['limit']) ? min(50, max(1, (int) $args['limit'])) : 20;
	$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

	$query = [
		'type'     => 'shop_order',
		'limit'    => $limit,
		'offset'   => $offset,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'paginate' => true,
	];

	if (!empty($args['status'])) {
		$query['status'] = 'wc-' . sanitize_key(preg_replace('/^wc-/', '', $args['status']));
	}
	if (!empty($args['customer_id'])) {
		$query['customer_id'] = absint($args['customer_id']);
	}

	$from = isset($args['date_from']) ? twcp_order_date($args['date_from']) : null;
	$to = isset($args['date_to']) ? twcp_order_date($args['date_to']) : null;
	if ((isset($args['date_from']) and !$from) or (isset($args['date_to']) and !$to)) {
		return ['error' => 'Dates must use YYYY-MM-DD format.'];
	}
	if ($from or $to) {
		$query['date_created'] = ($from ? : '1970-01-01') . '...' . ($to ? : wp_date('Y-m-d'));
	}

	$result = wc_get_orders($query);

	return [
		'total'  => (int) $result->total,
		'limit'  => $limit,
		'offset' => $offset,
		'orders' => array_map('twcp_order_format', $result->orders),
	];
}


/**
 * Action/Tool: Update an order status and add notes
 *
 * @param array $args
 *
 * @return array
 */
function twcp_order_update(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	$order = wc_get_order(absint($args['order_id'] ?? 0));
	if (!($order instanceof WC_Order)) {
		return ['error' => 'Order not found.'];
	}

	$status = isset($args['status']) ? sanitize_key(preg_replace('/^wc-/', '', $args['status'])) : '';
	$note = isset($args['note']) ? sanitize_textarea_field($args['note']) : '';

	if ($status === '' and $note === '') {
		return ['error' => 'Provide a status, a note, or both.'];
	}
	if ($status !== '' and !array_key_exists('wc-' . $status, wc_get_order_statuses())) {
		return ['error' => 'Unknown order status. Available: ' . implode(', ', array_map(fn($key) => substr($key, 3), array_keys(wc_get_order_statuses())))];
	}

	if ($note !== '') {
		$order->add_order_note($note, !empty($args['customer_note']) ? 1 : 0, true);
	}
	if ($status !== '' and $status !== $order->get_status()) {
		$order->update_status($status, __('Status changed by an MCP agent.', 'twee-mcp'), true);
	}

	return ['success' => true, 'order' => twcp_order_format(wc_get_order($order->get_id()), true)];
}


/**
 * Action/Tool: Sales summary for a date range
 *
 * @param array $args
 *
 * @return array
 */
function twcp_order_summary(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	$to = isset($args['date_to']) ? twcp_order_date($args['date_to']) : wp_date('Y-m-d');
	$from = isset($args['date_from']) ? twcp_order_date($args['date_from']) : wp_date('Y-m-d', strtotime('-30 days'));
	if (!$from or !$to or $from > $to) {
		return ['error' => 'Provide a valid YYYY-MM-DD date range.'];
	}

	$orders = wc_get_orders([
		'type'         => 'shop_order',
		'status'       => wc_get_is_paid_statuses(),
		'date_created' => $from . '...' . $to,
		'limit'        => 2000,
	]);

	$gross = 0.0;
	$refunds = 0.0;
	$items_sold = 0;
	$products = [];

	foreach ($orders as $order) {
		$gross += (float) $order->get_total();
		$refunds += (float) $order->get_total_refunded();

		foreach ($order->get_items() as $item) {
			$id = $item->get_product_id();
			$items_sold += $item->get_quantity();
			$products[$id] ??= ['product_id' => $id, 'name' => $item->get_name(), 'quantity' => 0, 'revenue' => 0.0];
			$products[$id]['quantity'] += $item->get_quantity();
			$products[$id]['revenue'] += (float) $item->get_total();
		}
	}

	usort($products, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
	$count = count($orders);

	return [
		'date_from'     => $from,
		'date_to'       => $to,
		'currency'      => get_woocommerce_currency(),
		'orders'        => $count,
		'gross'         => round($gross, 2),
		'refunds'       => round($refunds, 2),
		'net'           => round($gross - $refunds, 2),
		'average_order' => $count ? round($gross / $count, 2) : 0,
		'items_sold'    => $items_sold,
		'top_products'  => array_slice($products, 0, 10),
	];
}


/**
 * Format a coupon for MCP responses
 *
 * @param WC_Coupon $coupon
 *
 * @return array
 */
function twcp_coupon_format(WC_Coupon $coupon): array
{
	$expires = $coupon->get_date_expires();

	return [
		'id'                   => $coupon->get_id(),
		'code'                 => $coupon->get_code(),
		'status'               => get_post_status($coupon->get_id()),
		'discount_type'        => $coupon->get_discount_type(),
		'amount'               => $coupon->get_amount(),
		'description'          => $coupon->get_description(),
		'date_expires'         => $expires ? $expires->date('Y-m-d') : null,
		'usage_count'          => $coupon->get_usage_count(),
		'usage_limit'          => $coupon->get_usage_limit(),
		'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
		'individual_use'       => $coupon->get_individual_use(),
		'free_shipping'        => $coupon->get_free_shipping(),
		'minimum_amount'       => $coupon->get_minimum_amount(),
		'maximum_amount'       => $coupon->get_maximum_amount(),
		'product_ids'          => $coupon->get_product_ids(),
		'excluded_product_ids' => $coupon->get_excluded_product_ids(),
		'product_categories'   => $coupon->get_product_categories(),
	];
}


/**
 * Action/Tool: List coupons
 *
 * @param array $args
 *
 * @return array
 */
function twcp_coupon_query(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	$limit = isset($args['limit']) ? min(50, max(1, (int) $args['limit'])) : 20;
	$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

	$query = new WP_Query([
		'post_type'      => 'shop_coupon',
		'post_status'    => ['publish', 'draft', 'pending', 'future'],
		'posts_per_page' => $limit,
		'offset'         => $offset,
		's'              => !empty($args['search']) ? sanitize_text_field($args['search']) : '',
		'fields'         => 'ids',
	]);

	return [
		'total'   => (int) $query->found_posts,
		'limit'   => $limit,
		'offset'  => $offset,
		'coupons' => array_map(fn($id) => twcp_coupon_format(new WC_Coupon($id)), $query->posts),
	];
}


/**
 * Action/Tool: Create or edit a coupon
 *
 * @param array $args
 *
 * @return array
 */
function twcp_coupon_save(array $args): array
{
	if (!twcp_product_active()) {
		return ['error' => 'WooCommerce is not active'];
	}

	if (!empty($args['coupon_id'])) {
		$coupon = new WC_Coupon(absint($args['coupon_id']));
		if (!$coupon->get_id() or get_post_type($coupon->get_id()) !== 'shop_coupon') {
			return ['error' => 'Coupon not found.'];
		}
	} else {
		if (empty($args['code'])) {
			return ['error' => 'A coupon code is required.'];
		}
		$coupon = new WC_Coupon();
	}

	if (!empty($args['code'])) {
		$code = wc_format_coupon_code(sanitize_text_field($args['code']));
		$existing = wc_get_coupon_id_by_code($code, $coupon->get_id());
		if ($existing) {
			return ['error' => 'Another coupon already uses this code.'];
		}
		$coupon->set_code($code);
	}

	if (isset($args['date_expires'])) {
		if ($args['date_expires'] !== '' and !twcp_order_date($args['date_expires'])) {
			return ['error' => 'date_expires must use YYYY-MM-DD format.'];
		}
		$coupon->set_date_expires($args['date_expires'] !== '' ? $args['date_expires'] : null);
	}

	$setters = [
		'discount_type'        => 'sanitize_key',
		'amount'               => 'wc_format_decimal',
		'description'          => 'sanitize_textarea_field',
		'usage_limit'          => 'absint',
		'usage_limit_per_user' => 'absint',
		'individual_use'       => 'boolval',
		'free_shipping'        => 'boolval',
		'minimum_amount'       => 'wc_format_decimal',
		'maximum_amount'       => 'wc_format_decimal',
		'product_ids'          => fn($ids) => array_map('absint', (array) $ids),
		'excluded_product_ids' => fn($ids) => array_map('absint', (array) $ids),
		'product_categories'   => fn($ids) => array_map('absint', (array) $ids),
		'status'               => 'sanitize_key',
	];

	try {
		foreach ($setters as $field => $sanitize) {
			if (array_key_exists($field, $args)) {
				$coupon->{'set_' . $field}($sanitize($args[$field]));
			}
		}
		if (!$coupon->get_id() and !array_key_exists('status', $args)) {
			$coupon->set_status('publish');
		}
		$coupon->save();
	} catch (WC_Data_Exception $error) {
		return ['error' => $error->getMessage()];
	}

	return ['success' => true, 'coupon' => twcp_coupon_format(new WC_Coupon($coupon->get_id()))];
}
