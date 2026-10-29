<?php
/**
 * Twee MCP — WooCommerce agent prompts
 *
 * Copy-ready prompts for product copy, store reports, and promotions on the Agent Access tab.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Store prompts in the Agent Prompts, after those of posts and pages, and categories and media
 */
add_filter('twcp_prompt_examples', function(array $groups, string $site_name, string $site_url): array {
	$product_prompt = sprintf(
		<<<'PROMPT'
	I need to review and improve a WooCommerce product on %1$s using MCP (%2$s).

	Target product: [PRODUCT NAME, URL, SKU, OR ID]

	Instructions:
	1. Use `get_products` to retrieve the complete product, including descriptions, price, SKU, stock, attributes, categories, featured image, gallery, and SEO metadata.
	2. Review the live product page and compare the copy with its actual attributes and assigned categories.
	3. Improve the full description with clear benefits, specifications, compatibility details, care guidance, and natural internal links. Use valid HTML with useful H2/H3 headings, lists, and a comparison table where relevant.
	4. Improve the short description so it is concise, specific, and persuasive.
	5. Create an accurate SEO Title and SEO Description for Rank Math and Yoast SEO.
	6. Review the featured and gallery images. Correct missing or inaccurate Alt text with `edit_media`; do not replace images unless requested or clearly necessary.
	7. Use `select_related_products` to recommend relevant complementary or alternative products, but do not change product relationships unless asked.
	8. Save the approved content and SEO changes with `edit_product`, then review the live product page and report exactly what changed.

	Preserve the product name, slug, SKU, prices, stock, attributes, categories, and images unless the requested work specifically requires changing them.
	PROMPT,
		$site_name,
		$site_url
	);

	$store_report_prompt = sprintf(
		<<<'PROMPT'
	Prepare a weekly store report for %1$s using MCP (%2$s).

	1. Use `get_sales_summary` for the last 7 days and for the 7 days before that.
	2. Compare orders, gross and net revenue, refunds, average order value, and items sold, with the change in percent.
	3. List the best sellers of the week.
	4. Use `get_products` with stock_below 5 and with stock_status "outofstock" to list products to restock.
	5. Use `get_orders` with status "on-hold" and "processing" to list orders that need attention, oldest first.

	Present the report as short sections with tables. Do not change any data.
	PROMPT,
		$site_name,
		$site_url
	);

	$coupon_prompt = sprintf(
		<<<'PROMPT'
	Set up a promotion on %1$s using MCP (%2$s).

	Promotion: [e.g. 15%% off one product category until the end of the month]

	1. Use `get_terms` with taxonomy "product_cat" to find the matching product categories.
	2. Use `get_coupons` to make sure no active coupon uses the same code.
	3. Propose a coupon code, discount type, amount, expiry date, and usage limits, then wait for my approval.
	4. Create it with `save_coupon` and restrict it to the matching categories.
	5. Draft a short announcement post with `create_post` as a draft that mentions the code and links the categories.

	Report the coupon details and the draft post link.
	PROMPT,
		$site_name,
		$site_url
	);

	$groups['store'] = [
		'label'    => __('WooCommerce Store', 'twee-mcp'),
		'position' => 30,
		'plugin'   => 'WooCommerce',
		'active'   => twcp_product_active(),
		'prompts'  => [
			[
				'id'          => 'product',
				'title'       => __('Improve a WooCommerce Product', 'twee-mcp'),
				'description' => __('Improve product copy, SEO metadata, and image Alt text without unintentionally changing commercial product data.', 'twee-mcp'),
				'content'     => $product_prompt,
			],
			[
				'id'          => 'store-report',
				'title'       => __('Weekly Store Report', 'twee-mcp'),
				'description' => __('Compare this week with the last one, list best sellers, products to restock, and orders that need attention.', 'twee-mcp'),
				'content'     => $store_report_prompt,
			],
			[
				'id'          => 'coupon',
				'title'       => __('Set Up a Promotion', 'twee-mcp'),
				'description' => __('Create a category coupon after your approval and draft an announcement post.', 'twee-mcp'),
				'content'     => $coupon_prompt,
			],
		],
	];

	return $groups;
}, 10, 3);
