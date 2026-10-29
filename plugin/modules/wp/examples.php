<?php
/**
 * Twee MCP — WordPress agent prompts
 *
 * Copy-ready prompts for posts, comments, categories, and images on the Agent Access tab.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Prompts for posts and pages, and categories and media, first in the Agent Prompts
 */
add_filter('twcp_prompt_examples', function(array $groups, string $site_name, string $site_url): array {
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

	return $groups + [
		'content' => [
			'label'    => __('Posts & Pages', 'twee-mcp'),
			'position' => 10,
			'prompts'  => [
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
			'label'    => __('Categories & Media', 'twee-mcp'),
			'position' => 20,
			'prompts'  => [
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
	];
}, 10, 3);
