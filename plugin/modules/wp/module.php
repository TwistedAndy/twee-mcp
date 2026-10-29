<?php
/**
 * Module: WordPress
 * Description: Base tools for content, terms, comments, the Media Library, the site, and search, which the other modules build on.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

require_once __DIR__ . '/post.php';
require_once __DIR__ . '/term.php';
require_once __DIR__ . '/comment.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/automation.php';
require_once __DIR__ . '/examples.php';


/**
 * The base WordPress tools need no other plugin
 */
add_filter('twcp_modules', function(array $modules): array {
	$modules['wp'] = [];

	return $modules;
});


/**
 * Add the post tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$post_fields = [
		'title'           => ['type' => 'string', 'description' => 'Post title.'],
		'content'         => ['type' => 'string', 'description' => 'HTML body; supports native [gallery] shortcodes.'],
		'excerpt'         => ['type' => 'string'],
		'seo_title'       => ['type' => 'string', 'description' => 'SEO title saved for both Rank Math and Yoast SEO.'],
		'seo_description' => ['type' => 'string', 'description' => 'SEO meta description saved for both Rank Math and Yoast SEO.'],
		'post_status'     => [
			'type'        => 'string',
			'enum'        => ['draft', 'pending', 'publish', 'private', 'future'],
			'description' => 'New posts default to publish.',
		],
		'date'            => ['type' => 'string', 'description' => 'Local publication date in YYYY-MM-DD HH:MM:SS format; required for future status.'],
		'slug'            => ['type' => 'string'],
		'parent'          => ['type' => 'integer', 'minimum' => 0],
		'thumbnail_id'    => ['type' => 'integer', 'minimum' => 0, 'description' => 'Image attachment ID; 0 removes it.'],
		'taxonomies'      => [
			'type'        => 'object',
			'description' => 'Map of registered taxonomy names to arrays of existing term IDs. Empty arrays clear assignments.',
		],
		'append_terms'    => ['type' => 'boolean', 'description' => 'Append term IDs instead of replacing them (default false).'],
		'meta'            => ['type' => 'object', 'description' => 'Editable custom metadata. Protected internals require dedicated tools.'],
	];

	$groups['content'] = [
		'label'    => __('Content & Posts', 'twee-mcp'),
		'position' => 10,
		'tools'    => [
			'get_posts'           => [
				'description' => 'Query posts, pages, WooCommerce products, or custom post types. Provide "id" to get a single post with full content.',
				'hint'        => 'Read posts, pages, and custom post types with get_posts.',
				'callable'    => 'twcp_post_query',
				'parameters'  => [
					'id'          => ['type' => 'integer', 'description' => 'Single post ID.'],
					'post_type'   => ['type' => 'string', 'description' => 'Post type: "post", "page", "product", or a custom type from get_schema (default "any", which leaves out types hidden from search).'],
					'search'      => ['type' => 'string', 'description' => 'Keyword search in titles and content.'],
					'post_status' => ['type' => 'string', 'description' => 'Status: "publish", "draft", "private", or "any" (default "publish").'],
					'taxonomy'    => ['type' => 'string', 'description' => 'Taxonomy of term_id; found from the term when omitted.'],
					'term_id'     => ['type' => 'integer', 'description' => 'Only posts with this term.'],
					'limit'       => ['type' => 'integer', 'description' => 'Number of posts (default 20, max 50).'],
					'offset'      => ['type' => 'integer', 'description' => 'Pagination offset.'],
					'order_by'    => ['type' => 'string', 'enum' => ['date', 'modified', 'title', 'menu_order', 'rand'], 'description' => 'Sort field (default "date").'],
					'order'       => ['type' => 'string', 'description' => '"ASC" or "DESC" (default "DESC").'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'create_post'         => [
				'description' => 'Create a post, page, or registered custom post type with title, content, thumbnail, taxonomies, SEO metadata, and custom meta. Prefer create_product for WooCommerce.',
				'hint'        => 'Create and edit posts or custom post types with create_post and edit_post. Omitted fields are preserved.',
				'callable'    => 'twcp_post_create',
				'access'      => 'twcp_post_create_access',
				'required'    => ['title'],
				'parameters'  => [
						'post_type' => [
							'type'        => 'string',
							'description' => 'Registered post type; use create_product for WooCommerce products.',
						],
					] + $post_fields,
				'annotations' => ['readOnlyHint' => false],
			],
			'edit_post'           => [
				'description' => 'Edit an existing post, page, product, or custom post type, including SEO title and meta description.',
				'callable'    => 'twcp_post_edit',
				'required'    => ['id'],
				'parameters'  => ['id' => ['type' => 'integer', 'minimum' => 1]] + $post_fields,
				'annotations' => ['readOnlyHint' => false],
			],
			'trash_post'          => [
				'description' => 'Move an existing post, page, product, or custom post to WordPress Trash. Does not permanently delete content.',
				'hint'        => 'trash_post moves content to recoverable WordPress Trash; it never permanently deletes content.',
				'callable'    => 'twcp_post_trash',
				'access'      => 'twcp_post_trash_access',
				'required'    => ['id'],
				'parameters'  => ['id' => ['type' => 'integer', 'minimum' => 1]],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
			],
			'create_similar_post' => [
				'description' => 'Duplicate an existing post, page, product, or custom post type and create a new similar post on an adjacent topic in the same category. Rewrites title, excerpt, and full content, selects a relevant featured image, and generates SEO meta tags. Creates as draft.',
				'hint'        => 'Duplicate and create similar posts or custom post types on adjacent topics with create_similar_post.',
				'callable'    => 'twcp_post_similar_tool',
				'required'    => ['post_id'],
				'parameters'  => [
					'post_id' => ['type' => 'integer', 'description' => 'Source post ID to duplicate and base the similar post on.'],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'get_schema'          => [
				'description' => 'List registered post types and taxonomies with labels, descriptions, and capabilities.',
				'hint'        => 'Discover post types, taxonomies, and access capabilities with get_schema.',
				'callable'    => 'twcp_post_schema',
				'parameters'  => [
					'type' => ['type' => 'string', 'description' => '"all", "post_types", or "taxonomies".'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
		],
	];

	return $groups;
});


/**
 * Add the taxonomy term tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['terms'] = [
		'label'    => __('Taxonomies', 'twee-mcp'),
		'position' => 20,
		'hint'     => 'Use get_terms, create_term, edit_term, and assign_terms to manage taxonomy terms. Assignments can replace, append, remove, or clear terms.',
		'tools'    => [
			'get_terms'    => [
				'description' => 'Get existing taxonomy terms (categories, tags, product categories) with search, post counts, and links.',
				'callable'    => 'twcp_term_query',
				'parameters'  => [
					'taxonomy'   => ['type' => 'string', 'description' => 'Taxonomy name (e.g. "category", "product_cat"). Defaults to all.'],
					'search'     => ['type' => 'string', 'description' => 'Keyword search in term names.'],
					'parent'     => ['type' => 'integer', 'description' => 'Parent term ID; 0 lists top-level terms.'],
					'limit'      => ['type' => 'integer', 'description' => 'Number of terms (default 50, max 100).'],
					'offset'     => ['type' => 'integer', 'description' => 'Offset for pagination.'],
					'hide_empty' => ['type' => 'boolean', 'description' => 'Hide empty terms (default false).'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'create_term'  => [
				'description' => 'Create a new taxonomy term (e.g. category or product category), optionally with SEO metadata.',
				'callable'    => 'twcp_term_create',
				'access'      => 'twcp_term_create_access',
				'required'    => ['taxonomy', 'name'],
				'parameters'  => [
					'taxonomy'        => ['type' => 'string', 'description' => 'Taxonomy name.'],
					'name'            => ['type' => 'string', 'description' => 'Term name.'],
					'slug'            => ['type' => 'string', 'description' => 'Optional slug.'],
					'description'     => ['type' => 'string', 'description' => 'Optional description.'],
					'parent'          => ['type' => 'integer', 'description' => 'Parent term ID.'],
					'seo_title'       => ['type' => 'string', 'description' => 'SEO title saved for both Rank Math and Yoast SEO.'],
					'seo_description' => ['type' => 'string', 'description' => 'SEO meta description saved for both Rank Math and Yoast SEO.'],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'edit_term'    => [
				'description' => 'Edit an existing taxonomy term name, slug, description, parent, or SEO metadata.',
				'callable'    => 'twcp_term_edit',
				'required'    => ['term_id'],
				'parameters'  => [
					'term_id'         => ['type' => 'integer', 'description' => 'Term ID to edit.'],
					'taxonomy'        => ['type' => 'string', 'description' => 'Taxonomy of the term; found from the term when omitted.'],
					'name'            => ['type' => 'string', 'description' => 'New term name.'],
					'slug'            => ['type' => 'string', 'description' => 'New slug.'],
					'description'     => ['type' => 'string', 'description' => 'New description.'],
					'parent'          => ['type' => 'integer', 'description' => 'New parent term ID.'],
					'seo_title'       => ['type' => 'string', 'description' => 'SEO title saved for both Rank Math and Yoast SEO.'],
					'seo_description' => ['type' => 'string', 'description' => 'SEO meta description saved for both Rank Math and Yoast SEO.'],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'assign_terms' => [
				'description' => 'Assign existing categories, tags, brands, or other taxonomy terms to an existing post, page, product, or registered custom post type. Replace, append, or remove IDs; replace with [] clears that taxonomy.',
				'callable'    => 'twcp_term_assign',
				'required'    => ['post_id', 'taxonomy', 'term_ids'],
				'parameters'  => [
					'post_id'  => ['type' => 'integer', 'minimum' => 1],
					'taxonomy' => ['type' => 'string'],
					'term_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
					'mode'     => ['type' => 'string', 'enum' => ['replace', 'append', 'remove']],
				],
				'annotations' => ['readOnlyHint' => false],
			],
		],
	];

	return $groups;
});


/**
 * Add the comment tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['comments'] = [
		'label'    => __('Comments', 'twee-mcp'),
		'position' => 30,
		'hint'     => 'Moderate comments and product reviews with get_comments (status "hold" lists the queue), moderate_comment, and reply_comment.',
		'tools'    => [
			'get_comments'     => [
				'description' => 'Read comments and WooCommerce product reviews for moderation: pending, approved, spam, or trashed, with author, date, content, and post. Filter by post, status, or keyword.',
				'callable'    => 'twcp_comment_query',
				'capability'  => 'moderate_comments',
				'parameters'  => [
					'post_id' => ['type' => 'integer', 'description' => 'Only comments of this post.'],
					'status'  => ['type' => 'string', 'enum' => ['all', 'hold', 'approve', 'spam', 'trash'], 'description' => 'Comment status (default "all"); "hold" lists comments awaiting moderation.'],
					'search'  => ['type' => 'string', 'description' => 'Keyword search in comment author and content.'],
					'limit'   => ['type' => 'integer', 'description' => 'Number of comments (default 20, max 50).'],
					'offset'  => ['type' => 'integer', 'description' => 'Pagination offset.'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'moderate_comment' => [
				'description' => 'Approve, unapprove (hold), mark as spam, restore from spam, move to Trash, or restore from Trash a comment. Trash is recoverable.',
				'callable'    => 'twcp_comment_moderate',
				'capability'  => 'moderate_comments',
				'required'    => ['comment_id', 'action'],
				'parameters'  => [
					'comment_id' => ['type' => 'integer', 'minimum' => 1],
					'action'     => ['type' => 'string', 'enum' => ['approve', 'hold', 'spam', 'unspam', 'trash', 'untrash']],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'reply_comment'    => [
				'description' => 'Publish an approved reply to a comment as the connected user. The parent comment is approved first when it is pending.',
				'callable'    => 'twcp_comment_reply',
				'capability'  => 'moderate_comments',
				'required'    => ['comment_id', 'content'],
				'parameters'  => [
					'comment_id' => ['type' => 'integer', 'minimum' => 1],
					'content'    => ['type' => 'string', 'description' => 'Reply text; basic HTML is allowed.'],
				],
				'annotations' => ['readOnlyHint' => false],
			],
		],
	];

	return $groups;
});


/**
 * Add the Media Library tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['media'] = [
		'label'    => __('Media Library', 'twee-mcp'),
		'position' => 40,
		'tools'    => [
			'get_images'              => [
				'description' => 'Browse and search media library images. Returns original dimensions (width, height, orientation, filesize), file extension (svg, png, jpg, webp), mime_type, is_vector flag, Alt text, and URLs. Pass an id, or details=true, for intermediate sizes and the HTML tag. Use type="svg" to find SVG icon masks or type="raster" for photos.',
				'hint'        => 'Use get_images and edit_media for Media Library images. Lists are compact; pass an id for every size and the HTML tag.',
				'callable'    => 'twcp_media_images',
				'parameters'  => [
					'search'    => ['type' => 'string', 'description' => 'Keyword to search in title, filename, or alt text.'],
					'type'      => ['type' => 'string', 'description' => 'Filter by image type: "svg" (vector icons) or "raster" (photos: jpg, png, webp).'],
					'mime_type' => ['type' => 'string', 'description' => 'Specific MIME type filter (e.g. "image/svg+xml", "image/jpeg").'],
					'size'      => ['type' => 'string', 'description' => 'Image size for HTML tag (default "full").'],
					'details'   => ['type' => 'boolean', 'description' => 'Include intermediate sizes and the HTML tag in lists (default false).'],
					'limit'     => ['type' => 'integer', 'description' => 'Max images to return (default 20, max 50).'],
					'offset'    => ['type' => 'integer', 'description' => 'Offset for pagination.'],
					'id'        => ['type' => 'integer', 'description' => 'Specific attachment ID to fetch full metadata and available sizes.'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'preview_images'          => [
				'description' => 'Look at small previews of Media Library images to check that they match the content: pass attachment_ids, or post_id for the featured image, product gallery, and images inserted in the content. Previews are scaled down, so they cost far fewer tokens than full images.',
				'hint'        => 'Use preview_images to look at small image previews before choosing, assigning, or describing images. It is much cheaper than opening full-size URLs.',
				'callable'    => 'twcp_media_preview',
				'private'     => true,
				'parameters'  => [
					'attachment_ids' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'integer', 'minimum' => 1]],
					'post_id'        => ['type' => 'integer', 'description' => 'Post, page, or product whose images to preview.'],
					'size'           => ['type' => 'string', 'enum' => ['small', 'medium'], 'description' => '"small" is 256 px (default), "medium" is 512 px for fine details.'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'edit_media'              => [
				'description' => 'Update an image attachment title, Alt text, caption, or description. Omitted fields are preserved.',
				'callable'    => 'twcp_media_edit',
				'required'    => ['attachment_id'],
				'parameters'  => [
					'attachment_id' => ['type' => 'integer', 'minimum' => 1],
					'title'         => ['type' => 'string', 'description' => 'Media Library title.'],
					'alt'           => [
						'type'        => 'string',
						'description' => 'Image Alt text saved to the WordPress attachment ALT field for accessibility and image SEO.'
					],
					'caption'       => ['type' => 'string', 'description' => 'Media Library caption.'],
					'description'   => ['type' => 'string', 'description' => 'Media Library attachment description.'],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'build_gallery_shortcode' => [
				'description' => 'Build a native WordPress [gallery] shortcode from ordered existing image attachment IDs. Returns text to insert into post content or an ACF rich text field; does not edit anything. Product galleries use edit_product.gallery_image_ids.',
				'hint'        => 'Use build_gallery_shortcode for native WordPress galleries in post content or ACF rich-text fields.',
				'callable'    => 'twcp_media_gallery',
				'required'    => ['attachment_ids'],
				'parameters'  => [
					'attachment_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'integer', 'minimum' => 1]],
					'columns'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9],
					'size'           => ['type' => 'string'],
					'link'           => ['type' => 'string', 'enum' => ['file', 'none', 'attachment']],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'assign_media_image'      => [
				'description' => 'Assign an existing WordPress Media Library attachment to a WooCommerce product (featured or gallery), post/page (featured thumbnail or content), taxonomy term (category thumbnail), or ACF flexible content block.',
				'callable'    => 'twcp_media_assign',
				'access'      => 'twcp_media_assign_access',
				'required'    => ['attachment_id', 'type', 'id'],
				'parameters'  => [
					'attachment_id' => ['type' => 'integer', 'description' => 'WordPress media attachment ID.'],
					'type'          => ['type' => 'string', 'description' => 'Target entity type: "product", "post", "term", "block".'],
					'id'            => ['type' => 'integer', 'description' => 'Target entity ID (product ID, post ID, or term ID).'],
					'assign_as'     => [
						'type'        => 'string',
						'description' => 'Assignment role: for product: "featured", "gallery", "both"; for post: "featured", "content". Default "featured".',
					],
					'taxonomy'      => ['type' => 'string', 'description' => 'Taxonomy of the term if type is "term"; found from the term when omitted.'],
					'block_index'   => ['type' => 'integer', 'description' => '0-based index of the ACF block if type is "block".'],
					'field_name'    => [
						'type'        => 'string',
						'description' => 'ACF block subfield name if type is "block" (e.g. "image", "background", "icon"). Default "image".',
					],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'check_media_alt_text'    => [
				'description' => 'Audit the Alt text quality of media library images, detect poor or generic Alt tags (e.g. filenames, keyword stuffing, placeholders), and regenerate high-quality Alt text using AI vision analysis.',
				'callable'    => 'twcp_media_check_tool',
				'required'    => ['attachment_ids'],
				'parameters'  => [
					'attachment_ids' => [
						'type'        => 'array',
						'items'       => ['type' => 'integer'],
						'description' => 'List of image attachment IDs to audit and improve.',
					],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'import_media_image'      => [
				'description' => 'Download an image from a URL or stock photo and import/sideload it directly into the WordPress Media Library with automatic responsive sizes, alt text, title, and attribution. Can optionally assign it immediately to a product, post, term, or block.',
				'callable'    => 'twcp_media_import',
				'access'      => 'twcp_media_import_access',
				'required'    => ['url'],
				'parameters'  => [
					'url'        => ['type' => 'string', 'description' => 'Direct download URL of the remote raster image. SVG imports are not supported.'],
					'provider'   => ['type' => 'string', 'description' => 'Provider from stock search.'],
					'source_url' => ['type' => 'string', 'description' => 'Original provider page URL from stock search.'],
					'author'     => ['type' => 'string'],
					'author_url' => ['type' => 'string'],
					'license'    => ['type' => 'string', 'description' => 'License name from stock search; preserve required attribution in caption.'],
					'title'      => ['type' => 'string', 'description' => 'Title for the imported media attachment.'],
					'alt'        => ['type' => 'string', 'description' => 'Descriptive Alt text for accessibility and SEO.'],
					'caption'    => ['type' => 'string', 'description' => 'Caption or attribution credit.'],
					'filename'   => ['type' => 'string', 'description' => 'SEO-friendly filename without extension, e.g. "red-running-shoes-side-view".'],
					'post_id'    => ['type' => 'integer', 'description' => 'Optional parent post ID to attach the image to.'],
					'assign_to'  => [
						'type'        => 'object',
						'description' => 'Optional entity target to assign immediately: { "type": "product"|"post"|"term"|"block", "id": 123, "assign_as": "featured"|"gallery"|"both"|"content", "field_name": "image", "block_index": 0 }',
					],
				],
				'annotations' => ['readOnlyHint' => false],
			],
		],
	];

	return $groups;
});


/**
 * Add the site and menu tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['site'] = [
		'label'    => __('Site & Menus', 'twee-mcp'),
		'position' => 70,
		'hint'     => 'Read site settings with get_site_info. Manage navigation with get_menus, add_menu_item, and remove_menu_item.',
		'tools'    => [
			'get_site_info'    => [
				'description' => 'Get general site settings: name, tagline, URLs, language, timezone, date format, permalink structure, front page and blog page, active theme, active plugins, and store currency.',
				'callable'    => 'twcp_site_info',
				'parameters'  => [],
				'annotations' => ['readOnlyHint' => true],
			],
			'get_users'        => [
				'description' => 'Find users by name, email, or login, or list the users of a role such as shop managers. Returns names, emails, roles, registration dates, and when each user was last active.',
				'hint'        => 'Find users by name, email, login, or role, such as shop managers, with get_users.',
				'callable'    => 'twcp_site_users',
				'capability'  => 'list_users',
				'private'     => true,
				'parameters'  => [
					'id'     => ['type' => 'integer', 'description' => 'Single user ID.'],
					'search' => ['type' => 'string', 'description' => 'Part of a name, email, or login.'],
					'role'   => ['type' => 'string', 'description' => 'Role slug, e.g. "shop_manager", "administrator", "customer".'],
					'limit'  => ['type' => 'integer', 'description' => 'Number of users (default 20, max 100).'],
					'offset' => ['type' => 'integer', 'description' => 'Pagination offset.'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'get_menus'        => [
				'description' => 'List navigation menus with their theme locations and ordered items (title, URL, linked object, parent item).',
				'callable'    => 'twcp_site_menus',
				'parameters'  => [
					'menu_id' => ['type' => 'integer', 'description' => 'Optional single menu ID.'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'add_menu_item'    => [
				'description' => 'Add a link to a navigation menu: a post, page, or product (type "post_type"), a category or other term (type "taxonomy"), or a custom URL (type "custom").',
				'callable'    => 'twcp_site_menu_add',
				'capability'  => 'edit_theme_options',
				'required'    => ['menu_id', 'type'],
				'parameters'  => [
					'menu_id'   => ['type' => 'integer', 'minimum' => 1],
					'type'      => ['type' => 'string', 'enum' => ['post_type', 'taxonomy', 'custom']],
					'object_id' => ['type' => 'integer', 'description' => 'Post or term ID for post_type and taxonomy items.'],
					'title'     => ['type' => 'string', 'description' => 'Label; defaults to the linked object title. Required for custom links.'],
					'url'       => ['type' => 'string', 'description' => 'Custom link URL.'],
					'parent_id' => ['type' => 'integer', 'description' => 'Parent menu item ID for a submenu entry.'],
					'position'  => ['type' => 'integer', 'description' => 'Menu order position (default last).'],
				],
				'annotations' => ['readOnlyHint' => false],
			],
			'remove_menu_item' => [
				'description' => 'Remove an item from a navigation menu. The linked post or term is not affected.',
				'callable'    => 'twcp_site_menu_remove',
				'capability'  => 'edit_theme_options',
				'required'    => ['item_id'],
				'parameters'  => [
					'item_id' => ['type' => 'integer', 'minimum' => 1],
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
			],
		],
	];

	return $groups;
});


/**
 * Add the public search and fetch tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['search'] = [
		'label'    => __('Discovery & Search', 'twee-mcp'),
		'position' => 110,
		'tools'    => [
			'search' => [
				'description' => 'Unified search across published posts, pages, products, and public taxonomy terms (ChatGPT connector compatible).',
				'callable'    => 'twcp_search_query',
				'public'      => true,
				'required'    => ['query'],
				'parameters'  => [
					'query' => ['type' => 'string', 'description' => 'Search keyword.'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'fetch'  => [
				'description' => 'Fetch entity details by search ID, e.g. "post:123", "term:45", "image:67" (ChatGPT connector compatible).',
				'callable'    => 'twcp_search_fetch',
				'public'      => true,
				'required'    => ['id'],
				'parameters'  => [
					'id' => ['type' => 'string', 'description' => 'Entity ID (post:ID, term:ID, image:ID).'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
		],
	];

	return $groups;
});
