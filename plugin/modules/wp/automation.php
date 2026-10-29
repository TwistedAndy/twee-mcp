<?php
/**
 * Twee MCP — WordPress Automation
 *
 * Batch jobs, system prompts, overview items, and bulk actions for posts, terms, and images on the Automation screen,
 * and the internal link settings of generated content.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Post, term, and image jobs of the Content and Media Automation sections. System prompts, page context, and replies
 * make up most of the tokens of a cost; images and lookups add to them.
 */
add_filter('twcp_batch_types', function(array $types): array {
	$types['post_excerpt'] = [
		'title'       => __('Short Excerpts', 'twee-mcp'),
		'description' => __('Writes 1 to 2 sentence excerpts for items without one.', 'twee-mcp'),
		'button'      => __('Generate Excerpts', 'twee-mcp'),
		'field'       => 'post:post_excerpt',
		'run'         => 'twcp_post_generate_excerpt',
		'prompts'     => ['twcp_system_prompt_post_excerpt'],
		'text'        => 'excerpt',
		'combine'     => true,
		'cost'        => [1, 2500],
	];

	$types['post_content'] = [
		'title'       => __('Full Content', 'twee-mcp'),
		'description' => __('Writes complete HTML articles for empty posts.', 'twee-mcp'),
		'button'      => __('Generate Content', 'twee-mcp'),
		'field'       => 'post:post_content',
		'run'         => 'twcp_post_generate_content',
		'prompts'     => ['twcp_system_prompt_post_content'],
		'text'        => 'article content',
		'slice'       => 3,
		'cost'        => [1, 7000],
	];

	$types['seo'] = [
		'title'       => __('SEO Titles & Meta Descriptions', 'twee-mcp'),
		'description' => __('Saves SEO tags for Rank Math and Yoast SEO.', 'twee-mcp'),
		'button'      => __('Generate SEO Tags', 'twee-mcp'),
		'run'         => 'twcp_post_generate_seo',
		'prompts'     => ['twcp_system_prompt_seo'],
		'combine'     => true,
		'text'        => 'SEO title (50 to 60 characters) and meta description (120 to 155 characters)',
		'cost'        => [1, 3000],
		'fields'      => ['meta:rank_math_title', 'meta:rank_math_description', 'meta:_yoast_wpseo_title', 'meta:_yoast_wpseo_metadesc', 'post:post_excerpt'],
		'args'        => function(bool $override, array $post_types): array {
			$args = ['post_type' => $post_types, 'post_status' => 'publish'];
			if (!$override) {
				// Posts without a title in either plugin
				$args['meta_query'] = ['relation' => 'AND'];
				foreach (['rank_math_title', '_yoast_wpseo_title'] as $key) {
					$args['meta_query'][] = [
						'relation' => 'OR',
						['key' => $key, 'value' => '', 'compare' => '='],
						['key' => $key, 'compare' => 'NOT EXISTS'],
					];
				}
			}

			return $args;
		},
		'current'     => function(int $id): string {
			$title = get_post_meta($id, 'rank_math_title', true) ? : get_post_meta($id, '_yoast_wpseo_title', true);
			$description = get_post_meta($id, 'rank_math_description', true) ? : get_post_meta($id, '_yoast_wpseo_metadesc', true);

			return trim(($title ? 'Title: ' . $title : '') . ($description ? "\nDescription: " . $description : ''));
		},
	];

	$types['featured_images'] = [
		'title'       => __('Featured Images', 'twee-mcp'),
		'description' => __('Picks a matching Media Library image, or imports a stock photo, for items without a featured image.', 'twee-mcp'),
		'button'      => __('Assign Images', 'twee-mcp'),
		'run'         => function(int $id): bool {
			return twcp_post_assign_image($id) and has_post_thumbnail($id);
		},
		'redo'        => false,
		'slice'       => 3,
		'fields'      => ['meta:_thumbnail_id'],
		'args'        => function(bool $override, array $post_types): array {
			$post_types = array_values(array_filter($post_types, function(string $post_type): bool {
				return post_type_supports($post_type, 'thumbnail');
			}));

			return $post_types ? ['post_type' => $post_types, 'post_status' => 'publish', 'meta_query' => [['key' => '_thumbnail_id', 'compare' => 'NOT EXISTS']]] : [];
		},
		'current'     => function(int $id): string {
			return has_post_thumbnail($id) ? get_the_title(get_post_thumbnail_id($id)) : '';
		},
	];

	// Modules add the post types whose category taxonomy has a default term, such as products
	$types['categories'] = [
		'title'       => __('Categories', 'twee-mcp'),
		'description' => __('Moves posts and products out of the default category into 1 to 3 matching ones.', 'twee-mcp'),
		'button'      => __('Assign Categories', 'twee-mcp'),
		'scopes'      => ['all', 'post'],
		'run'         => 'twcp_post_assign_categories',
		'redo'        => __('Reclassify all', 'twee-mcp'),
		'fields'      => function(int $id): array {
			return ['terms:' . twcp_post_category_taxonomy((string) get_post_type($id))];
		},
		'args'        => function(bool $override, array $post_types): array {
			$args = ['post_type' => $post_types, 'post_status' => 'publish'];
			if (!$override) {
				// Still in the default category, saved as default_category or default_product_cat
				$args['tax_query'] = ['relation' => 'OR'];
				foreach ($post_types as $post_type) {
					$taxonomy = twcp_post_category_taxonomy($post_type);
					$args['tax_query'][] = ['taxonomy' => $taxonomy, 'terms' => [(int) get_option('default_' . $taxonomy)]];
				}
			}

			return $args;
		},
		'current'     => function(int $id): string {
			$names = wp_get_post_terms($id, twcp_post_category_taxonomy((string) get_post_type($id)), ['fields' => 'names']);

			return is_array($names) ? implode(', ', $names) : '';
		},
	];

	$types['term_seo'] = [
		'title'       => __('Category & Tag SEO', 'twee-mcp'),
		'description' => __('Saves SEO tags for category, tag, and brand archives.', 'twee-mcp'),
		'button'      => __('Generate SEO Tags', 'twee-mcp'),
		'terms'       => true,
		'field'       => 'term_meta:rank_math_title',
		'run'         => function(int $id, bool $override): bool {
			$term = get_term($id);

			return $term instanceof WP_Term and current_user_can('edit_term', $id) and twcp_term_generate_seo($id, $term->taxonomy, $override);
		},
		'prompts'     => ['twcp_system_prompt_term_seo'],
		'combine'     => true,
		'text'        => 'archive SEO title and description',
		'cost'        => [1, 2500],
		'fields'      => function(int $id): array {
			return ['term_meta:rank_math_title', 'term_meta:rank_math_description', 'yoast_term:' . (get_term($id)->taxonomy ?? ''), 'term:description'];
		},
		'current'     => function(int $id): string {
			$title = get_term_meta($id, 'rank_math_title', true);
			$description = get_term_meta($id, 'rank_math_description', true);

			return trim(($title ? 'Title: ' . $title : '') . ($description ? "\nDescription: " . $description : ''));
		},
	];

	$types['term_description'] = [
		'title'       => __('Category & Tag Descriptions', 'twee-mcp'),
		'description' => __('Writes the short introductions shown on category, tag, and brand archives.', 'twee-mcp'),
		'button'      => __('Generate Descriptions', 'twee-mcp'),
		'terms'       => true,
		'field'       => 'term:description',
		'run'         => function(int $id, bool $override): bool {
			$term = get_term($id);

			return $term instanceof WP_Term and current_user_can('edit_term', $id) and twcp_term_generate_description($id, $term->taxonomy, $override);
		},
		'prompts'     => ['twcp_system_prompt_term_description'],
		'text'        => 'archive description (1 to 3 sentences)',
	];

	$types['alts'] = [
		'title'       => __('Image Alt Text', 'twee-mcp'),
		'description' => __('Describes images without Alt text using the vision model.', 'twee-mcp'),
		'button'      => __('Generate Alt Text', 'twee-mcp'),
		'section'     => 'media',
		'post_type'   => 'attachment',
		'unit'        => __('Images', 'twee-mcp'),
		'field'       => 'meta:_wp_attachment_image_alt',
		'run'         => 'twcp_media_generate_alt',
		'prompts'     => ['twcp_system_prompt_media_alt'],
		'capability'  => 'upload_files',
		'text'        => 'image Alt text (8 to 20 words)',
		'args'        => function(bool $override): array {
			$args = ['post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit'];
			if (!$override) {
				$args['meta_query'] = [
					'relation' => 'OR',
					['key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='],
					['key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'],
				];
			}

			return $args;
		},
	];

	// Longer descriptions and FAQs go to ACF term fields, when the site has them
	if (twcp_term_acf_fields('content')) {
		$types['term_content'] = [
			'title'       => __('Full Term Descriptions', 'twee-mcp'),
			'description' => __('Writes longer archive descriptions with headings and a list into the chosen term field.', 'twee-mcp'),
			'button'      => __('Generate Full Descriptions', 'twee-mcp'),
			'terms'       => true,
			'targets'     => function(): array {
				return twcp_term_acf_fields('content');
			},
			'run'         => function(int $id, bool $override): bool {
				return current_user_can('edit_term', $id) and twcp_term_generate_content($id, twcp_batch_field('term_content')['key'] ?? '', $override);
			},
			'prompts'     => ['twcp_system_prompt_term_content'],
			'text'        => 'archive full description',
			'slice'       => 3,
			'cost'        => [1, 5000],
		];
	}
	if (twcp_term_acf_fields('faq')) {
		$types['term_faq'] = [
			'title'       => __('Term FAQs', 'twee-mcp'),
			'description' => __('Writes 4 to 6 questions and answers into the chosen term repeater.', 'twee-mcp'),
			'button'      => __('Generate FAQs', 'twee-mcp'),
			'terms'       => true,
			'targets'     => function(): array {
				return twcp_term_acf_fields('faq');
			},
			'run'         => function(int $id, bool $override): bool {
				return current_user_can('edit_term', $id) and twcp_term_generate_faq($id, twcp_batch_field('term_faq')['key'] ?? '', $override);
			},
			'prompts'     => ['twcp_system_prompt_term_faq'],
			'text'        => 'archive FAQ',
			'slice'       => 3,
			'cost'        => [1, 4000],
		];
	}

	return $types;
});


/**
 * Prompts of posts, terms, and images, in the sections of their jobs
 */
add_filter('twcp_prompt_groups', function(array $groups): array {
	return $groups + [
		'content' => [
			'label'       => __('Posts & Pages', 'twee-mcp'),
			'description' => __('Excerpts, full articles, similar posts, and SEO tags for posts, pages, and custom post types.', 'twee-mcp'),
			'position'    => 20,
			'section'     => 'content',
		],
		'terms'   => [
			'label'       => __('Categories & Tags', 'twee-mcp'),
			'description' => __('SEO tags for category, tag, brand, and other taxonomy archives.', 'twee-mcp'),
			'position'    => 30,
			'section'     => 'content',
		],
		'media'   => [
			'label'       => __('Images', 'twee-mcp'),
			'description' => __('Alt text written by the vision model.', 'twee-mcp'),
			'position'    => 40,
			'section'     => 'media',
		],
	];
});

add_filter('twcp_prompt_registry', function(array $prompts): array {
	return $prompts + [
		'twcp_system_prompt_post_excerpt'  => [
			'group'       => 'content',
			'label'       => __('Post Excerpt', 'twee-mcp'),
			'description' => __('Short 1 to 2 sentence summaries saved as the post excerpt.', 'twee-mcp'),
			'default'     => 'twcp_prompt_excerpt_default',
			'format'      => 'twcp_prompt_excerpt_format',
			'used_by'     => [__('Batch: Post excerpts', 'twee-mcp'), __('Bulk actions', 'twee-mcp'), 'create_similar_post'],
			'rows'        => 5,
		],
		'twcp_system_prompt_post_content'  => [
			'group'       => 'content',
			'label'       => __('Post Full Content', 'twee-mcp'),
			'description' => __('Complete HTML articles with headings, lists, and tables for empty posts.', 'twee-mcp'),
			'default'     => 'twcp_prompt_content_default',
			'format'      => 'twcp_prompt_content_format',
			'used_by'     => [__('Batch: Post content', 'twee-mcp'), __('Bulk actions', 'twee-mcp'), 'create_similar_post'],
			'rows'        => 6,
		],
		'twcp_system_prompt_post_similar'  => [
			'group'       => 'content',
			'label'       => __('Similar Post', 'twee-mcp'),
			'description' => __('New posts on adjacent topics: title, excerpt, content, image keywords, and SEO tags.', 'twee-mcp'),
			'default'     => 'twcp_prompt_similar_default',
			'format'      => 'twcp_prompt_similar_format',
			'used_by'     => ['create_similar_post', __('Bulk actions', 'twee-mcp')],
			'rows'        => 6,
		],
		'twcp_system_prompt_seo'           => [
			'group'       => 'content',
			'label'       => __('Post & Page SEO', 'twee-mcp'),
			'description' => __('SEO titles of 50 to 60 characters and meta descriptions of 120 to 155 characters, saved for Rank Math and Yoast SEO.', 'twee-mcp'),
			'default'     => 'twcp_prompt_seo_default',
			'format'      => 'twcp_prompt_seo_format',
			'used_by'     => [__('Batch: SEO tags', 'twee-mcp'), __('Bulk actions', 'twee-mcp'), 'create_similar_post'],
			'rows'        => 5,
		],
		'twcp_system_prompt_term_seo'      => [
			'group'       => 'terms',
			'label'       => __('Taxonomy Archive SEO', 'twee-mcp'),
			'description' => __('SEO titles and meta descriptions for category, brand, and tag archives.', 'twee-mcp'),
			'default'     => 'twcp_prompt_term_default',
			'format'      => 'twcp_prompt_term_format',
			'used_by'     => [__('Batch: Term SEO', 'twee-mcp'), __('Bulk actions', 'twee-mcp')],
			'rows'        => 5,
		],
		'twcp_system_prompt_term_description' => [
			'group'       => 'terms',
			'label'       => __('Taxonomy Archive Description', 'twee-mcp'),
			'description' => __('Short introductions shown on category, tag, and brand archives.', 'twee-mcp'),
			'default'     => 'twcp_prompt_term_description_default',
			'format'      => 'twcp_prompt_term_description_format',
			'used_by'     => [__('Batch: Term descriptions', 'twee-mcp'), __('Bulk actions', 'twee-mcp')],
			'rows'        => 5,
		],
		'twcp_system_prompt_term_content'  => [
			'group'       => 'terms',
			'label'       => __('Taxonomy Archive Full Description', 'twee-mcp'),
			'description' => __('Longer archive descriptions with headings and a list, saved to an ACF term field.', 'twee-mcp'),
			'default'     => 'twcp_prompt_term_content_default',
			'format'      => 'twcp_prompt_term_content_format',
			'used_by'     => [__('Batch: Full term descriptions', 'twee-mcp')],
			'rows'        => 6,
		],
		'twcp_system_prompt_term_faq'      => [
			'group'       => 'terms',
			'label'       => __('Taxonomy Archive FAQ', 'twee-mcp'),
			'description' => __('Questions and answers for archives, saved to an ACF term repeater.', 'twee-mcp'),
			'default'     => 'twcp_prompt_term_faq_default',
			'format'      => 'twcp_prompt_term_faq_format',
			'used_by'     => [__('Batch: Term FAQs', 'twee-mcp')],
			'rows'        => 6,
		],
		'twcp_system_prompt_media_alt'     => [
			'group'       => 'media',
			'label'       => __('Image Alt Text', 'twee-mcp'),
			'description' => __('Accessibility and image SEO guidelines for describing photos in 8 to 20 words.', 'twee-mcp'),
			'default'     => 'twcp_prompt_alt_default',
			'format'      => 'twcp_prompt_alt_format',
			'used_by'     => [__('Batch: Image Alt text', 'twee-mcp'), 'check_media_alt_text', __('Bulk actions', 'twee-mcp')],
			'rows'        => 5,
		],
	];
});


/**
 * Comments, drafts, and SEO and Alt text gaps in the overview of the Automation screen
 */
add_filter('twcp_batch_overview', function(array $items): array {
	if (current_user_can('moderate_comments')) {
		$items['comments'] = [
			'label' => __('Comments awaiting moderation', 'twee-mcp'),
			'count' => (int) wp_count_comments()->moderated,
			'url'   => admin_url('edit-comments.php?comment_status=moderated'),
		];
	}
	if (current_user_can('edit_others_posts')) {
		$posts = wp_count_posts();
		$items['drafts'] = [
			'label' => __('Drafts and pending posts', 'twee-mcp'),
			'count' => (int) $posts->draft + (int) $posts->pending,
			'url'   => admin_url('edit.php?post_status=draft'),
		];
		$items['seo'] = [
			'label' => __('Posts without SEO tags', 'twee-mcp'),
			'count' => twcp_batch_total('seo', false, 'post'),
			'url'   => '',
		];
	}
	if (current_user_can('upload_files')) {
		$items['alts'] = [
			'label' => __('Images without Alt text', 'twee-mcp'),
			'count' => twcp_media_alt_count(),
			'url'   => admin_url('upload.php?mode=list&orderby=twcp_alt&order=asc'),
		];
	}

	return $items;
});


/**
 * AI bulk actions on the Media, post type, and taxonomy screens
 */
add_filter('twcp_batch_actions', function(array $screens): array {
	$screens['upload']['twcp_alt_check'] = [
		'label' => __('Check Alt Text', 'twee-mcp'),
		'run'   => function(array $ids): array {
			// Poor Alt text is rewritten, and the rest is kept
			$result = twcp_media_check_batch($ids);

			return ['processed' => $result['updated'], 'skipped' => $result['checked'] - $result['updated']];
		},
		'ids'   => true,
	];
	$screens['upload']['twcp_alt'] = [
		'label' => __('Generate Alt Text', 'twee-mcp'),
		'run'   => 'twcp_media_generate_alt',
		'type'  => 'alts',
	];
	$screens['upload']['twcp_alt_regen'] = [
		'label' => __('Regenerate Alt Text', 'twee-mcp'),
		'run'   => function(int $id) {
			return twcp_media_generate_alt($id, true);
		},
	];

	$post_types = array_unique(array_merge(get_post_types(['public' => true]), get_post_types(['show_ui' => true])));
	$post_types = array_diff($post_types, ['attachment', 'wp_block', 'wp_navigation', 'acf-field-group', 'acf-field', 'shop_order', 'shop_coupon', 'shop_order_placehold']);

	foreach ($post_types as $post_type) {
		$screen = 'edit-' . $post_type;

		// A draft copy with a new title, excerpt, content, featured image, and SEO tags
		$screens[$screen]['twcp_create_similar'] = [
			'label' => __('Create Similar', 'twee-mcp'),
			'run'   => 'twcp_post_create_similar',
		];

		// Generate fills empty values, and Regenerate replaces them
		$screens[$screen]['twcp_seo'] = [
			'label' => __('Generate SEO Tags', 'twee-mcp'),
			'run'   => 'twcp_post_generate_seo',
			'type'  => 'seo',
		];
		$screens[$screen]['twcp_seo_regen'] = [
			'label' => __('Regenerate SEO Tags', 'twee-mcp'),
			'run'   => function(int $id): bool {
				return twcp_post_generate_seo($id, true);
			},
		];
		$screens[$screen]['twcp_featured_image'] = [
			'label' => __('Assign Featured Image', 'twee-mcp'),
			'run'   => 'twcp_post_assign_image',
			'type'  => 'featured_images',
		];
		$screens[$screen]['twcp_excerpt'] = [
			'label' => __('Generate Short Excerpt', 'twee-mcp'),
			'run'   => 'twcp_post_generate_excerpt',
			'type'  => 'post_excerpt',
		];
		$screens[$screen]['twcp_excerpt_regen'] = [
			'label' => __('Regenerate Short Excerpt', 'twee-mcp'),
			'run'   => function(int $id): bool {
				return twcp_post_generate_excerpt($id, true);
			},
		];
		$screens[$screen]['twcp_content'] = [
			'label' => __('Generate Full Content', 'twee-mcp'),
			'run'   => 'twcp_post_generate_content',
			'type'  => 'post_content',
		];
		$screens[$screen]['twcp_content_regen'] = [
			'label' => __('Regenerate Full Content', 'twee-mcp'),
			'run'   => function(int $id): bool {
				return twcp_post_generate_content($id, true);
			},
		];

		if ($post_type === 'post') {
			$screens[$screen]['twcp_categories'] = [
				'label' => __('Assign Categories', 'twee-mcp'),
				'run'   => 'twcp_post_assign_categories',
			];
		}
	}

	foreach (get_taxonomies(['public' => true]) as $taxonomy) {
		$screen = 'edit-' . $taxonomy;

		$screens[$screen]['twcp_seo'] = [
			'label' => __('Generate SEO Tags', 'twee-mcp'),
			'run'   => function(int $id) use ($taxonomy): bool {
				return twcp_term_generate_seo($id, $taxonomy);
			},
			'type'  => 'term_seo',
		];
		$screens[$screen]['twcp_seo_regen'] = [
			'label' => __('Regenerate SEO Tags', 'twee-mcp'),
			'run'   => function(int $id) use ($taxonomy): bool {
				return twcp_term_generate_seo($id, $taxonomy, true);
			},
		];
		$screens[$screen]['twcp_description'] = [
			'label' => __('Generate Descriptions', 'twee-mcp'),
			'run'   => function(int $id) use ($taxonomy): bool {
				return twcp_term_generate_description($id, $taxonomy);
			},
			'type'  => 'term_description',
		];
		$screens[$screen]['twcp_description_regen'] = [
			'label' => __('Regenerate Descriptions', 'twee-mcp'),
			'run'   => function(int $id) use ($taxonomy): bool {
				return twcp_term_generate_description($id, $taxonomy, true);
			},
		];
	}

	return $screens;
});


/**
 * Sources of the internal links generated content may use, added by modules with the twcp_post_link_sources filter.
 * The lookups of the model are a source of their own, always offered last.
 *
 * @return array Source => label
 */
function twcp_post_link_sources(): array
{
	return (array) apply_filters('twcp_post_link_sources', [
		'terms' => __('The post\'s categories and tags', 'twee-mcp'),
		'posts' => __('Related posts in the same categories', 'twee-mcp'),
		'pages' => __('Top-level pages', 'twee-mcp'),
	]);
}


/**
 * Internal link settings in the Content Automation section
 */
add_action('twcp_batch_section', function(string $section) {
	if ($section === 'content') {
		echo twcp_template('links', [
			'post_links'   => array_merge(['max' => 4, 'sources' => ['terms', 'posts', 'products']], (array) get_option('twcp_post_links', [])),
			'link_sources' => twcp_post_link_sources(),
		]);
	}
});


/**
 * Register the internal link settings
 */
add_action('admin_init', 'twcp_post_admin_init');

/**
 * Register the internal links of generated post content, saved from the Content Automation section by anyone who runs
 * its jobs
 *
 * @return void
 */
function twcp_post_admin_init(): void
{
	register_setting('twcp_content_group', 'twcp_post_links', [
		'type'              => 'array',
		'sanitize_callback' => function($input) {
			$input = is_array($input) ? $input : [];

			return [
				'max'     => isset($input['max']) ? min(15, max(0, (int) $input['max'])) : 4,
				'sources' => array_values(array_intersect(array_merge(array_keys(twcp_post_link_sources()), ['lookup']), (array) ($input['sources'] ?? []))),
			];
		},
		'default'           => [],
	]);
	add_filter('option_page_capability_twcp_content_group', function(): string {
		return 'edit_others_posts';
	});
}


/**
 * The home page, for the profile of the site the model writes
 */
add_filter('twcp_site_facts', function(array $facts): array {
	$front = get_option('show_on_front') === 'page' ? twcp_post_context((int) get_option('page_on_front')) : null;
	if ($front) {
		$text = trim(preg_replace('/\s+/', ' ', $front['blocks_text'] . ' ' . $front['content']));
		$facts[] = 'Home page: ' . $front['title'] . ($text ? ' - ' . mb_substr($text, 0, 1500) : '');
	}

	return $facts;
});
