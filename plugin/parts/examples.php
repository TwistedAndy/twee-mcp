<?php
/**
 * Tab Template: Agent Prompts
 *
 * Copy-ready prompts for Claude or ChatGPT, grouped by area.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

$site_name = wp_strip_all_tags((string) get_bloginfo('name'));
$site_url = home_url('/');

$article_prompt = sprintf(
	<<<'PROMPT'
I need to create a new post on %1$s using MCP (%2$s).

Instructions to create a new post:
1. Review the available post category list and select one or two categories for the post.
2. Choose a relevant and unique title that fits the selected categories and includes the site's target keywords.
3. Create the post with a featured image, assign the selected categories, and publish it.
4. Add relevant images from the WordPress media library; if none are available, use the `search_stock_images` MCP command to find stock images, add them to the media library, and use them in the article.
5. Ensure the article has the correct SEO Title, SEO Description, and short excerpt.
6. Review the newly created post on the website.

Requirements for post content:
* Align with the store's topic and selected categories.
* Be engaging and interesting, combining technical information with fascinating facts.
* Length: 2500 to 5000 words, divided into 4 to 8 sections using proper heading tags.
* Use valid HTML without extra wrapper tags or duplicate H1 titles.
* Start each section with an H2 subtitle, covering the section topic thoroughly.
* Some sections may include 2 to 4 H3 tags for subtopics, with at least two paragraphs per H3 heading.
* Avoid generic words like “introduction,” “conclusion,” or “summary” in H2 headings.
* Subtitles should relate to the main topic; some can rephrase it, while others should be natural.
* Include ordered or unordered lists with 3 to 8 items, using OL/UL tags and LI for items.
* In addition to lists, consider using regular tables to present relevant data.
* Begin the first section with a natural, engaging summary—consider using an open question to invite further reading.
* Include 5 to 10 links to product categories, pages, article categories, etc., naturally integrated into the text. You can modify link text and add words to fit better in the text.
* Utilize MCP tools to gather relevant products, categories, or terms (e.g., `get_posts`, `get_terms`).
* Include a pros and cons section if applicable, with lists followed by comparative summaries.
* Incorporate categories, relevant products or services, and technical details to make the article more informative.
PROMPT,
	$site_name,
	$site_url
);

$update_post_prompt = sprintf(
	<<<'PROMPT'
I need to review and improve an existing post on %1$s using MCP (%2$s).

Target post: [POST URL OR ID]

Instructions:
1. Use `get_posts` to retrieve the complete post, including its current title, content, excerpt, categories, featured image, and SEO metadata.
2. Review the live page and identify outdated, thin, duplicated, or poorly structured content.
3. Use `get_terms`, `get_posts`, and `get_products` to find relevant categories, articles, and products for accurate internal links.
4. Improve the article while preserving its permalink, publication status, useful existing content, and established search intent.
5. Use valid WordPress-ready HTML with no duplicate H1. Organize the content with descriptive H2 and H3 headings, paragraphs, lists, and tables where useful.
6. Update the short excerpt, SEO Title, and SEO Description. Keep the SEO title concise and the meta description natural and compelling.
7. Reuse suitable Media Library images. Update inaccurate or missing image Alt text, and add a relevant featured image only when needed.
8. Save the changes with `edit_post`, then review the live page to confirm the content, links, images, headings, and metadata.

Before editing, summarize the planned changes. After editing, report exactly what changed and flag anything that could not be verified.
PROMPT,
	$site_name,
	$site_url
);

$term_prompt = sprintf(
	<<<'PROMPT'
I need to improve a category on %1$s using MCP (%2$s).

Target taxonomy: [category OR product_cat]
Target category: [CATEGORY NAME, URL, OR TERM ID]

Instructions:
1. Use `get_terms` to retrieve the category and its current description, hierarchy, post count, link, and SEO metadata.
2. Review related categories and use `get_posts` or `get_products` to understand the content or products assigned to it.
3. Write a useful, customer-focused category description that matches the actual items in the category. Use valid HTML, descriptive headings, short paragraphs, and internal links where appropriate.
4. Create a unique SEO Title and SEO Description that match the category's search intent without keyword stuffing.
5. Use `edit_term` to update only the description and SEO fields unless another change is explicitly necessary.
6. Check the current category image. If it is missing or clearly incorrect, choose a suitable Media Library image, give it accurate Alt text with `edit_media`, and assign it with `assign_media_image`.
7. Review the live category page and report the final URL, changed fields, and anything that could not be verified.

Do not rename the category, change its slug or parent, or replace its image unless the existing value is clearly wrong.
PROMPT,
	$site_name,
	$site_url
);

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

$comments_prompt = sprintf(
	<<<'PROMPT'
Moderate the comment queue on %1$s using MCP (%2$s).

1. Use `get_comments` with status "hold" to load comments awaiting moderation.
2. For each comment, read the post it belongs to with `get_posts` when the context matters.
3. Mark obvious spam (links to unrelated sites, gibberish, ads) with `moderate_comment` action "spam".
4. Approve genuine comments with `moderate_comment` action "approve".
5. For approved questions, draft a short, friendly, accurate reply. Show me the drafts first, then post the ones I approve with `reply_comment`.

Finish with a table: comment ID, author, decision, and reply status.
PROMPT,
	$site_name,
	$site_url
);

$images_prompt = sprintf(
	<<<'PROMPT'
Audit the images of recent content on %1$s using MCP (%2$s).

1. Use `get_posts` (and `get_products` if the store is active) to list the 10 most recent published items.
2. For each item, call `preview_images` with its post_id to see the featured image, gallery, and inline images as small previews.
3. Check that each image matches the item's topic and that its Alt text describes what the preview shows.
4. Fix inaccurate or missing Alt text with `edit_media`.
5. When a featured image clearly does not match, find a better one with `get_images` (check candidates with `preview_images`) or `search_stock_images`, and propose it before assigning it with `assign_media_image`.

Report each item with its images, what was wrong, and what you changed.
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

$groups = [
	'content' => [
		'label'   => __('Posts & Pages', 'twee-mcp'),
		'prompts' => [
			[
				'id'          => 'article',
				'title'       => __('Create and Publish an Article', 'twee-mcp'),
				'description' => __('Research a topic, write a complete long-form article, add images and internal links, set SEO fields, publish it, and verify the result.', 'twee-mcp'),
				'content'     => $article_prompt,
			],
			[
				'id'          => 'update-post',
				'title'       => __('Review and Improve an Existing Post', 'twee-mcp'),
				'description' => __('Audit an existing article, improve its structure and usefulness, update SEO and media metadata, and verify the live page.', 'twee-mcp'),
				'content'     => $update_post_prompt,
			],
			[
				'id'          => 'comments',
				'title'       => __('Moderate Comments', 'twee-mcp'),
				'description' => __('Clear the moderation queue: mark spam, approve genuine comments, and reply to questions after your approval.', 'twee-mcp'),
				'content'     => $comments_prompt,
			],
		],
	],
	'terms'   => [
		'label'   => __('Categories & Media', 'twee-mcp'),
		'prompts' => [
			[
				'id'          => 'term-seo',
				'title'       => __('Improve a Category and Its SEO', 'twee-mcp'),
				'description' => __('Improve a blog or product category description, SEO metadata, and image while preserving its identity and hierarchy.', 'twee-mcp'),
				'content'     => $term_prompt,
			],
			[
				'id'          => 'images',
				'title'       => __('Audit Images and Alt Text', 'twee-mcp'),
				'description' => __('Check with small previews that images match their content, fix Alt text, and propose better featured images.', 'twee-mcp'),
				'content'     => $images_prompt,
			],
		],
	],
	'store'   => [
		'label'   => __('WooCommerce Store', 'twee-mcp'),
		'plugin'  => 'WooCommerce',
		'active'  => twcp_module_active('woo'),
		'prompts' => [
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
	],
];
$count = array_sum(array_map(fn($group) => count($group['prompts']), $groups));
?>

<div class="item" id="twcp_agent_prompts">
	<div class="header">
		<h2 class="heading"><?php echo esc_html__('Agent Prompts', 'twee-mcp'); ?></h2>
		<span class="badge badge_success"><?php echo esc_html(sprintf(__('%d Prompts', 'twee-mcp'), $count)); ?></span>
	</div>
	<p class="text">
		<?php echo esc_html__('Copy a prompt into Claude or ChatGPT after connecting this MCP server. Replace any bracketed placeholders before sending it.', 'twee-mcp'); ?>
	</p>
	<p class="text text_muted">
		<?php echo esc_html__('The prompts are starting points. Review permissions and requested write operations before allowing an agent to change published content.', 'twee-mcp'); ?>
	</p>

	<?php foreach ($groups as $group) {
		$inactive = isset($group['active']) && !$group['active'];
		?>
		<h3 class="title prompt_group_title">
			<?php echo esc_html($group['label']); ?>
			<?php if ($inactive) { ?>
				<span class="badge badge_warning"><?php echo esc_html(sprintf(__('Requires %s', 'twee-mcp'), $group['plugin'])); ?></span>
			<?php } ?>
		</h3>

		<?php foreach ($group['prompts'] as $prompt) {
			$input_id = 'twcp_example_' . $prompt['id'];
			$rows = min(36, max(12, substr_count($prompt['content'], "\n") + 2));
			?>
			<details class="prompt">
				<summary class="prompt_summary">
					<span class="prompt_label"><?php echo esc_html($prompt['title']); ?></span>
					<span class="text text_muted"><?php echo esc_html($prompt['description']); ?></span>
				</summary>
				<div class="field">
					<label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($prompt['title']); ?></label>
					<textarea id="<?php echo esc_attr($input_id); ?>" class="large-text code" rows="<?php echo esc_attr($rows); ?>" readonly><?php echo esc_textarea($prompt['content']); ?></textarea>
				</div>
				<div class="buttons">
					<button type="button" class="button button_primary twcp_copy_prompt" data-target="<?php echo esc_attr($input_id); ?>">
						<?php echo esc_html__('Copy Prompt', 'twee-mcp'); ?>
					</button>
				</div>
			</details>
		<?php } ?>
	<?php } ?>
</div>
