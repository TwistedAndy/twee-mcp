<?php
/**
 * Twee MCP — ACF Page Builder Blocks Management
 *
 * Implements MCP tools and automated AI description generation for
 * ACF Flexible Content layouts dynamically discovered from the selected ACF field.
 * Supports visual preview screenshots from a configurable folder to enrich AI context.
 * Completely decoupled from the theme.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * MCP tools for ACF page builder blocks and custom fields
 *
 * @return array Tool name => [ description, callable, parameters, required, annotations ]
 */
function twcp_block_tools(): array
{
	return [
		'get_block_types'             => [
			'description' => 'List ACF page builder layouts with label, description, preview URL, and the full typed field structure. Fields repeated across layouts (contents, settings, buttons) are listed once under "shared" and referenced as {"$ref": "shared.<name>"}. Every field has a "format" (plain text, HTML, ID, choice list), follow it exactly. "conventions" explains contents / settings / items, hand-picking posts and products (order=custom + items IDs), and media rules (icons = SVG masks, image/background = raster). Pass layout=<name> to get one fully resolved layout.',
			'callable'    => 'twcp_block_catalog',
			'parameters'  => [
				'layout' => ['type' => 'string', 'description' => 'Optional specific layout name (e.g. "hero", "cards", "faq").'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'generate_block_descriptions' => [
			'description' => 'Generate AI descriptions for ACF Flexible Content layout blocks using their schema and UI preview screenshots from the specified (or configured) previews folder.',
			'callable'    => 'twcp_block_describe',
			'capability'  => 'manage_options',
			'parameters'  => [
				'folder'    => [
					'type'        => 'string',
					'description' => 'Optional relative or absolute directory path to block preview screenshots (defaults to configured setting, e.g. "assets/preview").',
				],
				'overwrite' => ['type' => 'boolean', 'description' => 'Whether to regenerate and overwrite existing block descriptions (default false).'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'get_custom_fields'           => [
			'description' => 'Get ACF custom fields associated with a post or term (including custom post types and taxonomies). Returns field names, labels, types, instructions, choices, and stored values. Includes special resolution and expansion for Block Sets (reusable shared blocks from post_type=block_set).',
			'callable'    => 'twcp_custom_fields_get',
			'required'    => ['object_id'],
			'parameters'  => [
				'object_id'         => ['type' => 'integer', 'description' => 'Post ID, Page ID, Product ID, CPT ID, or Term ID.'],
				'object_type'       => [
					'type'        => 'string',
					'enum'        => ['post', 'term'],
					'description' => 'Object type: "post" (posts, pages, products, block sets, CPTs) or "term" (categories, tags, custom taxonomies). Default "post".'
				],
				'taxonomy'          => [
					'type'        => 'string',
					'description' => 'Optional taxonomy name if object_type is "term" (e.g. "category", "product_cat"). Auto-detected if omitted.'
				],
				'expand_block_sets' => [
					'type'        => 'boolean',
					'description' => 'Whether to expand and resolve shared blocks inside referenced Block Sets (default true).'
				],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'get_blocks'                  => [
			'description' => 'Get current ACF blocks for a post or term with their 0-based index and values.',
			'callable'    => 'twcp_block_get',
			'required'    => ['object_id'],
			'parameters'  => [
				'object_id'   => ['type' => 'integer', 'description' => 'Post or Term ID.'],
				'object_type' => ['type' => 'string', 'description' => '"post" or "term" (default "post").'],
			],
			'annotations' => ['readOnlyHint' => true],
		],
		'create_block'                => [
			'description' => 'Add a block to a post or term at an optional position. Data must follow the layout schema from get_block_types, with groups as nested objects, e.g. {"contents": {"title": "...", "text": "<p>...</p>", "buttons": [{"type": "default", "link": {"url": "...", "title": "..."}}]}, "settings": {"background": "light"}, "items": [{...}]}. Values are sanitized per field format; unknown fields and invalid choices are dropped and listed in "warnings", so check them.',
			'callable'    => 'twcp_block_create',
			'required'    => ['object_id', 'layout', 'data'],
			'parameters'  => [
				'object_id'   => ['type' => 'integer', 'description' => 'Post or Term ID.'],
				'object_type' => ['type' => 'string', 'description' => '"post" or "term" (default "post").'],
				'layout'      => ['type' => 'string', 'description' => 'Layout name (hero, heading, cards, etc.).'],
				'data'        => [
					'type'        => 'object',
					'description' => 'Field values keyed by field name, shaped like the layout schema (nested objects for groups, arrays of rows for repeaters).',
				],
				'position'    => ['type' => 'integer', 'description' => 'Index position to insert (defaults to append).'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'edit_block'                  => [
			'description' => 'Update the block at a 0-based index. Groups (contents, settings) are merged key by key, so send only what changes. Lists (items, buttons) are replaced as a whole, so send the complete list. Follow the field formats from get_block_types; dropped values are listed in "warnings".',
			'callable'    => 'twcp_block_edit',
			'required'    => ['object_id', 'index', 'data'],
			'parameters'  => [
				'object_id'   => ['type' => 'integer', 'description' => 'Post or Term ID.'],
				'object_type' => ['type' => 'string', 'description' => '"post" or "term" (default "post").'],
				'index'       => ['type' => 'integer', 'description' => '0-based index of block to update.'],
				'data'        => ['type' => 'object', 'description' => 'Changed field values, shaped like the layout schema.'],
				'layout'      => ['type' => 'string', 'description' => 'Optional new layout name.'],
			],
			'annotations' => ['readOnlyHint' => false],
		],
		'remove_block'                => [
			'description' => 'Remove an ACF block at a specific index (or "all") from a post or term.',
			'callable'    => 'twcp_block_remove',
			'required'    => ['object_id', 'index'],
			'parameters'  => [
				'object_id'   => ['type' => 'integer', 'description' => 'Post or Term ID.'],
				'object_type' => ['type' => 'string', 'description' => '"post" or "term" (default "post").'],
				'index'       => ['type' => ['integer', 'string'], 'description' => '0-based index of block to remove, or string "all".'],
			],
			'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
		],
	];
}


/**
 * Get the configured ACF field name used for blocks
 *
 * @return string
 */
function twcp_block_field_name(): string
{
	$name = (string) get_option('twcp_blocks_field', 'blocks');

	return !empty($name) ? sanitize_key($name) : 'blocks';
}


/**
 * Get configured block previews folder setting
 *
 * @return string
 */
function twcp_block_previews_folder(): string
{
	$folder = (string) get_option('twcp_block_previews_folder', 'assets/preview');

	return trim($folder) !== '' ? trim($folder) : 'assets/preview';
}


/**
 * Resolve absolute directory path for the block previews folder.
 * Supports both absolute paths (e.g. C:\... or Z:\...) and relative paths
 * (relative to child theme, parent theme, or ABSPATH).
 *
 * @param string|null $folder Optional folder path override
 *
 * @return string Trailing-slashed path or empty string if not found
 */
function twcp_block_previews_dir(?string $folder = null): string
{
	$dir = $folder !== null ? trim($folder) : twcp_block_previews_folder();
	if ($dir === '') {
		return '';
	}

	// 1. Check if already an absolute filesystem path
	if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $dir)) {
		if (is_dir($dir)) {
			return rtrim(wp_normalize_path($dir), '/') . '/';
		}
	}

	// 2. Relative to child theme directory
	$child = wp_normalize_path(get_stylesheet_directory() . '/' . ltrim($dir, '/\\'));
	if (is_dir($child)) {
		return rtrim($child, '/') . '/';
	}

	// 3. Relative to parent theme directory
	$parent = wp_normalize_path(get_template_directory() . '/' . ltrim($dir, '/\\'));
	if (is_dir($parent)) {
		return rtrim($parent, '/') . '/';
	}

	// 4. Relative to WordPress ABSPATH
	$abs = wp_normalize_path(ABSPATH . ltrim($dir, '/\\'));
	if (is_dir($abs)) {
		return rtrim($abs, '/') . '/';
	}

	return '';
}


/**
 * Convert a local preview file path into an accessible URL (if within web root)
 *
 * @param string $file_path
 *
 * @return string
 */
function twcp_block_preview_url(string $file_path): string
{
	$normalized = wp_normalize_path($file_path);
	$content_dir = wp_normalize_path(WP_CONTENT_DIR);
	$content_url = content_url();

	if (str_starts_with($normalized, $content_dir)) {
		return str_replace($content_dir, $content_url, $normalized);
	}

	$theme_dir = wp_normalize_path(get_template_directory());
	$theme_url = get_template_directory_uri();
	if (str_starts_with($normalized, $theme_dir)) {
		return str_replace($theme_dir, $theme_url, $normalized);
	}

	return '';
}


/**
 * Find preview image file for a given layout in the resolved preview directory
 *
 * @param string      $layout_name
 * @param string|null $dir
 *
 * @return string|null Absolute file path or null
 */
function twcp_block_find_preview(string $layout_name, ?string $dir = null): ?string
{
	$preview_dir = $dir ? : twcp_block_previews_dir();
	if (empty($preview_dir) or !is_dir($preview_dir)) {
		return null;
	}

	$extensions = ['webp', 'png', 'jpg', 'jpeg', 'svg'];
	foreach ($extensions as $ext) {
		$candidate = $preview_dir . $layout_name . '.' . $ext;
		if (file_exists($candidate)) {
			return wp_normalize_path($candidate);
		}
	}

	return null;
}


/**
 * List all available preview files found in the configured preview directory
 *
 * @param string|null $dir
 *
 * @return array Map of layout name => array info
 */
function twcp_block_previews(?string $dir = null): array
{
	$preview_dir = $dir ? : twcp_block_previews_dir();
	if (empty($preview_dir) or !is_dir($preview_dir)) {
		return [];
	}

	$files = glob($preview_dir . '*.{webp,png,jpg,jpeg,svg}', GLOB_BRACE);
	if (!$files) {
		return [];
	}

	$previews = [];
	foreach ($files as $file) {
		$name = pathinfo($file, PATHINFO_FILENAME);
		$norm = wp_normalize_path($file);
		$previews[$name] = [
			'filename' => basename($file),
			'path'     => $norm,
			'url'      => twcp_block_preview_url($norm),
		];
	}

	return $previews;
}


/**
 * Scan theme & child theme local ACF JSON files and return decoded field group data
 *
 * @return array List of decoded field group arrays
 */
function twcp_block_local_groups(): array
{
	static $cached = null;
	if ($cached !== null) {
		return $cached;
	}

	$dirs = [
		get_template_directory() . '/includes/acf',
		get_template_directory() . '/acf-json',
		get_stylesheet_directory() . '/includes/acf',
		get_stylesheet_directory() . '/acf-json',
	];

	$groups = [];
	foreach (array_unique($dirs) as $dir) {
		if (!is_dir($dir)) {
			continue;
		}
		$json_files = glob($dir . '/*.json');
		if (!$json_files) {
			continue;
		}
		foreach ($json_files as $file) {
			$raw = file_get_contents($file);
			if (!$raw) {
				continue;
			}
			$data = json_decode($raw, true);
			if (is_array($data) && !empty($data['fields'])) {
				if (empty($data['title'])) {
					$data['title'] = basename($file, '.json');
				}
				$groups[] = $data;
			}
		}
	}

	$cached = $groups;

	return $cached;
}


/**
 * Discover available ACF flexible content fields in the installation
 *
 * @return array Associative array of field_name => [ 'name' => string, 'label' => string, 'key' => string, 'group' => string, 'layouts' => array ]
 */
function twcp_block_available_fields(): array
{
	// 1. Theme & child theme local JSON files, 2. field groups registered in ACF
	$sources = [];
	foreach (twcp_block_local_groups() as $data) {
		$sources[] = [$data['title'] ?? 'ACF', $data['fields']];
	}

	if (function_exists('acf_get_field_groups') and function_exists('acf_get_fields')) {
		foreach ((array) acf_get_field_groups() as $g) {
			$sources[] = [!empty($g['title']) ? $g['title'] : 'ACF', (array) acf_get_fields($g['key'])];
		}
	}

	$fields = [];
	foreach ($sources as [$group_title, $group_fields]) {
		foreach ($group_fields as $f) {
			if (isset($f['type']) and $f['type'] === 'flexible_content' and !empty($f['name'])) {
				$fields[$f['name']] = [
					'name'    => $f['name'],
					'label'   => !empty($f['label']) ? $f['label'] : $f['name'],
					'key'     => !empty($f['key']) ? $f['key'] : '',
					'group'   => $group_title,
					'layouts' => !empty($f['layouts']) && is_array($f['layouts']) ? array_values($f['layouts']) : [],
				];
			}
		}
	}

	// Always ensure standard 'blocks' is available
	if (!isset($fields['blocks'])) {
		$fields['blocks'] = [
			'name'    => 'blocks',
			'label'   => 'Blocks',
			'key'     => 'field_61f6679ca03f0',
			'group'   => 'Content Blocks',
			'layouts' => twcp_block_get_layouts('blocks'),
		];
	}

	return $fields;
}


/**
 * Get ACF field key for a field name
 *
 * @param string $field_name
 *
 * @return string
 */
function twcp_block_field_key(string $field_name): string
{
	$available = twcp_block_available_fields();
	if (isset($available[$field_name]) and !empty($available[$field_name]['key'])) {
		return $available[$field_name]['key'];
	}

	if (function_exists('acf_get_field')) {
		$f = acf_get_field($field_name);
		if (!empty($f['key'])) {
			return $f['key'];
		}
	}

	return 'field_61f6679ca03f0';
}


/**
 * Dynamically get all available layouts for the selected ACF flexible content field
 *
 * @param string|null $field_name Optional field name; defaults to twcp_block_field_name()
 *
 * @return array Array of layout definitions
 */
function twcp_block_get_layouts(?string $field_name = null): array
{
	$field_name = !empty($field_name) ? sanitize_key($field_name) : twcp_block_field_name();

	// 1. Check acf_get_field if ACF is initialized
	if (function_exists('acf_get_field')) {
		$f = acf_get_field($field_name);
		if ($f && !empty($f['layouts']) && is_array($f['layouts'])) {
			return array_values($f['layouts']);
		}
	}

	// 2. Scan local JSON files in theme & child theme
	foreach (twcp_block_local_groups() as $data) {
		foreach ($data['fields'] as $f) {
			if (isset($f['name']) && $f['name'] === $field_name && isset($f['type']) && $f['type'] === 'flexible_content' && !empty($f['layouts'])) {
				return array_values($f['layouts']);
			}
		}
	}

	return [];
}


/**
 * Default fallback descriptions for layouts
 *
 * @return array
 */
function twcp_block_default_descriptions(): array
{
	$built_in = [
		'hero'     => 'Prominent page banner with large background image, primary heading, subtitle/caption, call-to-action button, and search form. Used at the top of landing pages.',
		'heading'  => 'Section title banner with centered or aligned heading and introductory lead paragraph to divide content sections.',
		'content'  => 'Rich text typography section for articles, legal policies, or guides. Supports headings, styled body text, and lists.',
		'media'    => 'Visual media section supporting images or videos with optional side text, captions, and responsive layout variations.',
		'cards'    => 'Multi-column grid of content feature cards. Each card can contain an image icon, title, description, and link.',
		'faq'      => 'Accordion-style Frequently Asked Questions section with expandable question titles and detailed answers.',
		'message'  => 'Highlighted notification or alert banner (info, success, warning) for notices or store announcements.',
		'form'     => 'Embedded Gravity Form or contact form block with title and introductory text.',
		'posts'    => 'Grid or list of blog posts or custom post type items with thumbnails, excerpts, and date metadata.',
		'products' => 'Curated WooCommerce product showcase grid (featured, sale, or recent items) with price, ratings, and add-to-cart buttons.',
		'contact'  => 'Contact information block with physical address, phone numbers, email, business hours, and interactive Google map.',
		'product'  => 'Single featured product spotlight banner highlighting specs, special discounts, and direct checkout action.',
		'catalog'  => 'Full store catalog grid or category navigation tiles for browsing product collections.',
		'comments' => 'Customer reviews and comments list section with submission form and star ratings.',
		'set'      => 'Reusable block set reference that embeds a pre-configured collection of blocks from post_type block_set.',
	];

	$layouts = twcp_block_get_layouts();
	$defaults = [];

	foreach ($layouts as $l) {
		$name = $l['name'];
		if (isset($built_in[$name])) {
			$defaults[$name] = $built_in[$name];
		} else {
			$sub_labels = array_filter(array_column($l['sub_fields'] ?? [], 'label'));
			$label = $l['label'] ?? $name;
			$defaults[$name] = "Layout '{$label}' section with " . (empty($sub_labels) ? 'content fields.' : implode(', ', array_slice($sub_labels, 0, 8)) . '.');
		}
	}

	return $defaults;
}


/**
 * Block descriptions saved in settings, including the legacy option name
 *
 * @return array Layout => description
 */
function twcp_block_saved_descriptions(): array
{
	return (array) get_option('twcp_block_descriptions', get_option('tw_mcp_block_descriptions', []));
}


/**
 * Get active block descriptions (combining saved settings with dynamic defaults)
 *
 * @return array
 */
function twcp_block_descriptions(): array
{
	$layouts = twcp_block_get_layouts();
	$defaults = twcp_block_default_descriptions();
	$saved = twcp_block_saved_descriptions();

	$result = [];
	foreach ($layouts as $l) {
		$name = $l['name'];
		if (!empty($saved[$name]) and is_string($saved[$name])) {
			$result[$name] = trim($saved[$name]);
		} elseif (!empty($defaults[$name])) {
			$result[$name] = $defaults[$name];
		} else {
			$label = $l['label'] ?? $name;
			$sub_labels = array_filter(array_column($l['sub_fields'] ?? [], 'label'));
			$result[$name] = "Layout '{$label}' (" . implode(', ', array_slice($sub_labels, 0, 8)) . ').';
		}
	}

	return $result;
}


/**
 * Default conventions explaining the shared block fields to AI agents
 *
 * @return string
 */
function twcp_block_conventions_default(): string
{
	return <<<CONVENTIONS
BLOCK CONVENTIONS (shared structure used by almost every layout)

PAGE STRUCTURE
- Blocks render top to bottom. Open a page with one heading block whose contents.tag is h1; every other block uses h2 (h3 for minor sections). One h1 per page. The post and author layouts already default to h1.
- The page field blocks_type decides how blocks meet the editor content: replace (blocks only) or append (post content first, then blocks).
- Alternate settings.background between neighbouring blocks (default, light, dark) for rhythm, and keep related blocks together instead of repeating the same layout back to back.

contents (group): the heading area of a block.
- caption: short eyebrow label above the title, 1 to 4 words.
- title: plain text headline. tag: h1, h2 (default), or h3.
- text: basic rich text intro: paragraphs, links, bold, italics, lists. No headings or images.
- buttons: repeater of {type, icon, link{url, title, target}}. type: default (primary), secondary, tertiary, outline, white, black, link. icon: none, prev, next. Use 1 or 2 buttons, primary first.
- In grid and list layouts the buttons render below the items. Query layouts hide them when the loader or current option is on.
Fill only what the section needs and leave the rest empty; an empty contents group renders nothing.

settings (group): presentation of the block.
- background: default, light, or dark.
- options: hidden keeps the block saved but skips it on the site (use it to park drafts instead of deleting); border adds a divider line.
- top / bottom padding: default, half, none, normal. Keep default; use half or none only to tighten two blocks that share a background.
- block_id: optional anchor slug for in-page links, e.g. "pricing" for #pricing.

LAYOUT OPTIONS (the options checkbox of each layout)
- slider: turns the items into a carousel. Use it when there are more items than columns.
- columns: items per row. Match the item count (3 or 6 items -> 3, 4 or 8 -> 4).
- heading: image_1 is the desktop image, image_2 an optional mobile image. compact makes a shorter banner, background puts the image behind the text, clean shows the image without decoration. align: left, center, right.
- media: reverse swaps image and text, full shows a full-width image, card boxes the section.
- content: text is full rich text with h2 to h4, lists, quotes, and images. The navigation option adds a sticky table of contents built from the h2 headings, so use it for long guides.
- cards: each card takes its own title, text, image, and link, or points to a post or term that fills the blank ones. layout picks the card style (services or cases).
- form: the form field holds a Gravity Forms shortcode; keep the existing one unless asked to change it.
- set: embeds reusable Block Sets (post_type block_set) by ID. Editing a set changes every page that embeds it; read them with get_custom_fields and expand_block_sets.

QUERY LAYOUTS (posts, products, services, cases, reviews, locations, offices)
- number: how many items (-1 for all). offset: how many to skip.
- order: date (newest first), title, menu_order, rand, related (shares terms with the current post, for single post templates), or custom. Products also take best (best sellers), wishlist, and viewed (the visitor's own lists, for cart and account pages).
- custom: put the hand-picked post IDs into items in display order. items is ignored for every other order; custom products with no items fall back to best sellers.
- Filters: posts take term ID arrays in category and post_tag, services in service_category. Products list the taxonomies to filter by in taxonomy, then take term IDs in a field named after each one (product_cat, pa_brand, and so on). Leave filters empty for custom orders.
- current: renders the page's own archive query with pagination and ignores number and order. Use it only on listing pages. loader adds a Load More button instead.

REPEATER ITEMS: row fields differ per layout, see that layout's items.fields. number fields hold short stats such as "120+" or "24/7". Keep sibling items parallel in length and structure, 3 to 6 items in most sections.

MEDIA: fields with "icon" in their name or label take SVG attachment IDs, which are rendered as CSS masks (-webkit-mask), so use get_images with type="svg". Fields named or labeled image / background / banner take raster photo attachment IDs, so use get_images with type="raster" and prefer a width that fits the block. Check candidates with preview_images before assigning. Video file fields take mp4 or webm attachment IDs; video link fields take a URL.

TEXT FORMATS: every field has a "format" hint, follow it exactly. Single-line text fields take plain text, textareas take plain text without HTML, rich text (wysiwyg) fields take HTML with paragraphs added automatically.
CONVENTIONS;
}


/**
 * Active conventions (user configured or default)
 *
 * @return string
 */
function twcp_block_conventions(): string
{
	$custom = trim((string) get_option('twcp_block_conventions', ''));

	return $custom !== '' ? $custom : twcp_block_conventions_default();
}


/**
 * Describe the value format a field expects
 *
 * @param array $f ACF field definition
 *
 * @return string
 */
function twcp_block_hint(array $f): string
{
	$type = $f['type'] ?? 'text';
	$choices = !empty($f['choices']) && is_array($f['choices']);

	switch ($type) {
		case 'text':
			return 'Plain text, single line, no paragraphs. Inline <br>, <em>, <strong>, <span> are output as-is; nothing else.';

		case 'textarea':
			$lines = $f['new_lines'] ?? '';
			if ($lines === 'wpautop') {
				return 'Plain text. Separate paragraphs with a blank line, <p> is added automatically. No HTML.';
			}
			if ($lines === 'br') {
				return 'Plain text. Single line breaks become <br>. No HTML.';
			}

			return 'Plain text, output as-is. No HTML; line breaks are NOT converted.';

		case 'wysiwyg':
			$full = ($f['toolbar'] ?? '') === 'full';

			return 'HTML, paragraphs are added automatically: separate paragraphs with a blank line or wrap them in <p>. Allowed: <p>, <a href>, <strong>, <em>, <ul>/<ol>/<li>' . ($full ? ', <h2>-<h4>, <blockquote>, <img>.' : '. Basic toolbar: no headings or images.');

		case 'url':
			return 'Absolute URL string.';

		case 'link':
			return 'Object {"url": "https://...", "title": "Visible label", "target": "" or "_blank"}.';

		case 'image':
			return 'Media attachment ID (integer).';

		case 'file':
			return 'Media attachment ID (integer) of a video or file.';

		case 'number':
			return 'Number.';

		case 'true_false':
			return 'Boolean (true / false).';

		case 'select':
		case 'button_group':
		case 'radio':
			return $choices ? 'One value from choices.' : 'Value from a dynamically populated list, look it up first (e.g. with get_terms for taxonomy-driven fields).';

		case 'checkbox':
			return 'Array of values from choices.';

		case 'post_object':
			return 'Post ID (integer)' . (!empty($f['post_type']) ? ' of type ' . implode('/', (array) $f['post_type']) : '') . '.';

		case 'relationship':
			return 'Array of post IDs in display order' . (!empty($f['post_type']) ? ' (types: ' . implode(', ', (array) $f['post_type']) . ')' : '') . '.';

		case 'taxonomy':
		case 'acfe_taxonomy_terms':
			return 'Array of term IDs.';

		case 'repeater':
			return 'Array of row objects with the listed fields.';

		case 'group':
			return 'Object with the listed fields.';
	}

	return 'Scalar value.';
}


/**
 * Format ACF fields into compact, typed schemas for MCP agents
 *
 * @param array $sub_fields
 *
 * @return array
 */
function twcp_block_fields(array $sub_fields): array
{
	$schema = [];

	foreach ($sub_fields as $sf) {
		if (empty($sf['name'])) {
			continue;
		}

		$haystack = strtolower($sf['name'] . ' ' . ($sf['label'] ?? ''));

		$item = [
			'type'   => $sf['type'] ?? 'text',
			'label'  => $sf['label'] ?? $sf['name'],
			'format' => twcp_block_hint($sf),
		];

		if (str_contains($haystack, 'icon')) {
			$item['media'] = 'SVG icon (CSS mask), use get_images type="svg"';
		} elseif (preg_match('/image|background|banner|preview/', $haystack) and in_array($item['type'], ['image', 'file'], true)) {
			$item['media'] = 'Raster photo, use get_images type="raster"';
		}

		if (!empty($sf['instructions'])) {
			$item['instructions'] = $sf['instructions'];
		}

		if (!empty($sf['choices']) and is_array($sf['choices'])) {
			$item['choices'] = array_map('strval', array_keys($sf['choices']));
		}

		if (!empty($sf['sub_fields']) and is_array($sf['sub_fields'])) {
			$item['fields'] = twcp_block_fields($sf['sub_fields']);
		}

		$schema[$sf['name']] = $item;
	}

	return $schema;
}


/**
 * Action/Tool: Catalog of ACF Flexible Content layouts for the active field
 *
 * Groups that repeat identically across layouts (contents, settings, ...) are listed once in "shared"
 * and referenced from each layout with {"$ref": "shared.<name>"}, which keeps the response small.
 *
 * @param array $args
 *
 * @return array
 */
function twcp_block_catalog(array $args = []): array
{
	$requested = !empty($args['layout']) ? sanitize_key($args['layout']) : '';
	$field_name = twcp_block_field_name();
	$descriptions = twcp_block_descriptions();
	$catalog = [];

	foreach (twcp_block_get_layouts($field_name) as $l) {
		if (empty($l['name'])) {
			continue;
		}

		$name = $l['name'];
		$entry = [
			'label'       => $l['label'] ?? $name,
			'description' => $descriptions[$name] ?? '',
			'fields'      => twcp_block_fields($l['sub_fields'] ?? []),
		];

		$preview = twcp_block_find_preview($name);
		if ($preview) {
			$entry['preview'] = twcp_block_preview_url($preview);
		}

		$catalog[$name] = $entry;
	}

	if ($requested) {
		if (!isset($catalog[$requested])) {
			return ['field_name' => $field_name, 'error' => 'Unknown layout: ' . $requested, 'available' => array_keys($catalog)];
		}

		return [
			'field_name'  => $field_name,
			'conventions' => twcp_block_conventions(),
			'layout'      => $requested,
			'schema'      => $catalog[$requested],
		];
	}

	// Hoist identical groups that appear in more than one layout
	$variants = [];
	foreach ($catalog as $name => $entry) {
		foreach ($entry['fields'] as $key => $spec) {
			if (in_array($spec['type'], ['group', 'repeater'], true)) {
				$variants[$key][md5(wp_json_encode($spec))][] = $name;
			}
		}
	}

	$shared = [];
	foreach ($variants as $key => $by_hash) {
		uasort($by_hash, fn($a, $b) => count($b) <=> count($a));
		$layouts = reset($by_hash);
		if (count($layouts) > 1) {
			$shared[$key] = $catalog[$layouts[0]]['fields'][$key];
			foreach ($layouts as $name) {
				$catalog[$name]['fields'][$key] = ['$ref' => 'shared.' . $key];
			}
		}
	}

	return [
		'field_name'    => $field_name,
		'total_layouts' => count($catalog),
		'conventions'   => twcp_block_conventions(),
		'shared'        => $shared,
		'layouts'       => $catalog,
	];
}


/**
 * Sanitize one block value according to its ACF field definition
 *
 * @param array  $f
 * @param mixed  $value
 * @param string $path
 * @param array  $warnings
 *
 * @return mixed Null when the value is invalid and must be dropped
 */
function twcp_block_clean_value(array $f, $value, string $path, array &$warnings)
{
	$type = $f['type'] ?? 'text';
	$keys = !empty($f['choices']) && is_array($f['choices']) ? array_map('strval', array_keys($f['choices'])) : [];

	switch ($type) {
		case 'text':
			return wp_kses(trim((string) $value), [
				'br'     => [],
				'em'     => [],
				'strong' => [],
				'b'      => [],
				'i'      => [],
				'sup'    => [],
				'sub'    => [],
				'span'   => ['class' => true]
			]);

		case 'textarea':
			return sanitize_textarea_field((string) $value);

		case 'wysiwyg':
			return wp_kses_post((string) $value);

		case 'url':
			return esc_url_raw((string) $value);

		case 'link':
			$value = is_string($value) ? ['url' => $value] : (array) $value;

			return [
				'url'    => esc_url_raw((string) ($value['url'] ?? '')),
				'title'  => sanitize_text_field((string) ($value['title'] ?? '')),
				'target' => ($value['target'] ?? '') === '_blank' ? '_blank' : '',
			];

		case 'image':
		case 'file':
			return absint(is_array($value) ? ($value['ID'] ?? $value['id'] ?? 0) : $value);

		case 'number':
			return is_numeric($value) ? $value + 0 : null;

		case 'true_false':
			return (int) filter_var($value, FILTER_VALIDATE_BOOLEAN);

		case 'select':
		case 'button_group':
		case 'radio':
			$value = sanitize_text_field((string) $value);
			if ($keys and !in_array($value, $keys, true)) {
				$warnings[] = "{$path}: '{$value}' is not a valid choice (" . implode(', ', $keys) . '), ignored';

				return null;
			}

			return $value;

		case 'checkbox':
			$values = array_map('strval', (array) $value);
			$valid = $keys ? array_values(array_intersect($values, $keys)) : $values;
			if ($valid !== $values) {
				$warnings[] = "{$path}: invalid choices removed (" . implode(', ', $keys) . ')';
			}

			return $valid;

		case 'post_object':
		case 'relationship':
		case 'taxonomy':
		case 'acfe_taxonomy_terms':
			$ids = array_values(array_filter(array_map('absint', is_array($value) ? $value : [$value])));

			return ($type === 'post_object' and empty($f['multiple'])) ? ($ids[0] ?? 0) : $ids;

		case 'repeater':
			$rows = [];
			foreach (array_values((array) $value) as $i => $row) {
				if (is_array($row)) {
					$rows[] = twcp_block_clean_fields($f['sub_fields'] ?? [], $row, "{$path}[{$i}]", $warnings);
				}
			}

			return $rows;

		case 'group':
			return twcp_block_clean_fields($f['sub_fields'] ?? [], (array) $value, $path, $warnings);
	}

	return is_scalar($value) ? sanitize_text_field((string) $value) : $value;
}


/**
 * Sanitize a set of block fields against their ACF definitions
 *
 * @param array  $defs ACF field definitions
 * @param array  $data
 * @param string $path
 * @param array  $warnings
 *
 * @return array
 */
function twcp_block_clean_fields(array $defs, array $data, string $path, array &$warnings): array
{
	$map = [];
	foreach ($defs as $def) {
		if (!empty($def['name'])) {
			$map[$def['name']] = $def;
		}
	}

	$clean = [];
	foreach ($data as $key => $value) {
		if ($key === 'acf_fc_layout') {
			continue;
		}

		if (!isset($map[$key])) {
			$warnings[] = "{$path}.{$key}: unknown field, ignored";
			continue;
		}

		$value = twcp_block_clean_value($map[$key], $value, "{$path}.{$key}", $warnings);
		if ($value !== null) {
			$clean[$key] = $value;
		}
	}

	return $clean;
}


/**
 * Sanitize block data for a layout
 *
 * @param string $layout
 * @param array  $data
 *
 * @return array [ data, warnings ] or [ error ]
 */
function twcp_block_clean(string $layout, array $data): array
{
	$layouts = twcp_block_get_layouts();

	foreach ($layouts as $l) {
		if (($l['name'] ?? '') === $layout) {
			$warnings = [];
			$clean = twcp_block_clean_fields($l['sub_fields'] ?? [], $data, $layout, $warnings);

			return ['data' => $clean, 'warnings' => $warnings];
		}
	}

	return ['error' => "Unknown layout '{$layout}'. Available: " . implode(', ', array_column($layouts, 'name'))];
}


/**
 * Merge block data: groups are merged key by key, lists (repeaters) and scalars are replaced
 *
 * @param array $old
 * @param array $new
 *
 * @return array
 */
function twcp_block_merge(array $old, array $new): array
{
	foreach ($new as $key => $value) {
		if (is_array($value) and isset($old[$key]) and is_array($old[$key]) and !array_is_list($value) and !array_is_list($old[$key])) {
			$old[$key] = twcp_block_merge($old[$key], $value);
		} else {
			$old[$key] = $value;
		}
	}

	return $old;
}


/**
 * Format stored blocks for MCP responses
 *
 * @param mixed $blocks
 *
 * @return array
 */
function twcp_block_format_list($blocks): array
{
	$formatted = [];

	foreach (is_array($blocks) ? array_values($blocks) : [] as $index => $block) {
		$data = is_array($block) ? $block : [];
		$formatted[] = [
			'index'  => $index,
			'layout' => !empty($data['acf_fc_layout']) ? $data['acf_fc_layout'] : 'unknown',
			'data'   => $data,
		];
	}

	return $formatted;
}


/**
 * Stored blocks of a post or term
 *
 * @param int    $object_id
 * @param string $object_type "post" or "term"
 *
 * @return array
 */
function twcp_block_load(int $object_id, string $object_type): array
{
	$blocks = get_metadata($object_type === 'term' ? 'term' : 'post', $object_id, twcp_block_field_name(), true);

	return array_values(array_filter(is_array($blocks) ? $blocks : [], 'is_array'));
}


/**
 * Save blocks of a post or term and make sure ACF can map the field
 *
 * @param int    $object_id
 * @param string $object_type "post" or "term"
 * @param array  $blocks
 *
 * @return void
 */
function twcp_block_save(int $object_id, string $object_type, array $blocks): void
{
	$meta_type = $object_type === 'term' ? 'term' : 'post';
	$field = twcp_block_field_name();

	update_metadata($meta_type, $object_id, $field, array_values($blocks));

	$acf_map = get_metadata($meta_type, $object_id, '_acf_map', true);
	$acf_map = is_array($acf_map) ? $acf_map : [];
	if (empty($acf_map[$field])) {
		$acf_map[$field] = twcp_block_field_key($field);
		update_metadata($meta_type, $object_id, '_acf_map', $acf_map);
	}
}


/**
 * Action/Tool: Get current blocks for a post or term
 *
 * @param array $args
 *
 * @return array
 */
function twcp_block_get(array $args): array
{
	if (empty($args['object_id'])) {
		return ['error' => 'Missing object_id'];
	}

	$object_id = (int) $args['object_id'];
	$object_type = !empty($args['object_type']) ? sanitize_key($args['object_type']) : 'post';
	$field = twcp_block_field_name();

	if ($object_type === 'term') {
		$term = get_term($object_id);
		if (!$term or is_wp_error($term)) {
			return ['error' => 'Term not found.'];
		}
	} elseif (!get_post($object_id)) {
		return ['error' => 'Post not found.'];
	}

	$formatted = twcp_block_format_list(twcp_block_load($object_id, $object_type));
	$acf_map = get_metadata($object_type === 'term' ? 'term' : 'post', $object_id, '_acf_map', true);

	return [
		'object_id'    => $object_id,
		'object_type'  => $object_type,
		'field_name'   => $field,
		'total_blocks' => count($formatted),
		'has_acf_map'  => !empty($acf_map),
		'blocks'       => $formatted,
	];
}


/**
 * Action/Tool: Add an ACF block to a post or term
 *
 * @param array $args
 *
 * @return array
 */
function twcp_block_create(array $args): array
{
	if (empty($args['object_id']) or empty($args['layout']) or !isset($args['data'])) {
		return ['error' => 'Missing required parameters: object_id, layout, data'];
	}

	$object_id = (int) $args['object_id'];
	$object_type = !empty($args['object_type']) ? sanitize_key($args['object_type']) : 'post';
	$layout = sanitize_key($args['layout']);
	$block_data = is_array($args['data']) ? $args['data'] : [];
	$position = isset($args['position']) ? (int) $args['position'] : null;
	$blocks = twcp_block_load($object_id, $object_type);
	$cleaned = twcp_block_clean($layout, $block_data);
	if (!empty($cleaned['error'])) {
		return ['error' => $cleaned['error']];
	}

	$warnings = $cleaned['warnings'];
	$block_data = $cleaned['data'];
	$block_data['acf_fc_layout'] = $layout;

	if ($position !== null and $position >= 0 and $position < count($blocks)) {
		array_splice($blocks, $position, 0, [$block_data]);
		$inserted_index = $position;
	} else {
		$blocks[] = $block_data;
		$inserted_index = count($blocks) - 1;
	}

	twcp_block_save($object_id, $object_type, $blocks);

	return [
		'success'        => true,
		'object_id'      => $object_id,
		'object_type'    => $object_type,
		'field_name'     => twcp_block_field_name(),
		'inserted_index' => $inserted_index,
		'layout'         => $layout,
		'total_blocks'   => count($blocks),
		'warnings'       => $warnings,
	];
}


/**
 * Action/Tool: Edit an ACF block at index
 *
 * @param array $args
 *
 * @return array
 */
function twcp_block_edit(array $args): array
{
	if (empty($args['object_id']) or !isset($args['index']) or !isset($args['data'])) {
		return ['error' => 'Missing required parameters: object_id, index, data'];
	}

	$object_id = (int) $args['object_id'];
	$object_type = !empty($args['object_type']) ? sanitize_key($args['object_type']) : 'post';
	$index = (int) $args['index'];
	$new_data = is_array($args['data']) ? $args['data'] : [];
	$blocks = twcp_block_load($object_id, $object_type);

	if (!isset($blocks[$index])) {
		return ['error' => "Block at index {$index} does not exist. Total blocks: " . count($blocks)];
	}

	$current_layout = $blocks[$index]['acf_fc_layout'] ?? '';
	$layout = !empty($args['layout']) ? sanitize_key($args['layout']) : $current_layout;

	$cleaned = twcp_block_clean($layout, $new_data);
	if (!empty($cleaned['error'])) {
		return ['error' => $cleaned['error']];
	}

	$merged = twcp_block_merge($blocks[$index], $cleaned['data']);
	$merged['acf_fc_layout'] = $layout;

	$blocks[$index] = $merged;
	twcp_block_save($object_id, $object_type, $blocks);

	return [
		'success'     => true,
		'object_id'   => $object_id,
		'object_type' => $object_type,
		'field_name'  => twcp_block_field_name(),
		'index'       => $index,
		'layout'      => $layout,
		'warnings'    => $cleaned['warnings'],
		'block'       => $blocks[$index],
	];
}


/**
 * Action/Tool: Remove an ACF block
 *
 * @param array $args
 *
 * @return array
 */
function twcp_block_remove(array $args): array
{
	if (empty($args['object_id']) or !isset($args['index'])) {
		return ['error' => 'Missing required parameters: object_id, index'];
	}

	$object_id = (int) $args['object_id'];
	$object_type = !empty($args['object_type']) ? sanitize_key($args['object_type']) : 'post';
	$index = $args['index'];
	$blocks = twcp_block_load($object_id, $object_type);

	if ($index === 'all') {
		$removed_count = count($blocks);
		$blocks = [];
	} else {
		$idx = (int) $index;
		if (!isset($blocks[$idx])) {
			return ['error' => "Block at index {$idx} does not exist. Total blocks: " . count($blocks)];
		}
		array_splice($blocks, $idx, 1);
		$removed_count = 1;
	}

	twcp_block_save($object_id, $object_type, $blocks);

	return [
		'success'       => true,
		'object_id'     => $object_id,
		'object_type'   => $object_type,
		'field_name'    => twcp_block_field_name(),
		'removed_count' => $removed_count,
		'remaining'     => count($blocks),
	];
}


/**
 * Count missing block descriptions for the active ACF field
 *
 * @return int
 */
function twcp_block_count_missing(): int
{
	$saved = twcp_block_saved_descriptions();
	$layouts = twcp_block_get_layouts();

	$missing = 0;
	foreach ($layouts as $l) {
		$name = $l['name'];
		if (empty($saved[$name])) {
			$missing++;
		}
	}

	return $missing;
}


/**
 * Generate description for a single block layout definition
 *
 * @param array       $layout Layout array definition
 * @param string|null $folder Optional previews folder path override
 *
 * @return string Generated description
 */
function twcp_block_generate_description(array $layout, ?string $folder = null): string
{
	$name = $layout['name'];
	$label = $layout['label'] ?? $name;
	$sub_labels = array_filter(array_column($layout['sub_fields'] ?? [], 'label'));
	$sub_info = !empty($sub_labels) ? 'Contains fields: ' . implode(', ', $sub_labels) . '.' : '';

	$images = [];
	$preview_dir = twcp_block_previews_dir($folder);
	$preview_file = twcp_block_find_preview($name, $preview_dir);
	if ($preview_file) {
		$images[] = $preview_file;
	}

	$prompt = "Write a clear, comprehensive description (2 to 4 sentences) for an ACF Flexible Content page builder block named '{$name}' (Title: '{$label}').\n";
	if ($preview_file) {
		$prompt .= "An image preview/screenshot of this block in action is attached. Describe what this block looks like, its visual layout, UI elements, and best usage context.\n";
	}
	if ($sub_info) {
		$prompt .= "Structure: {$sub_info}\n";
	}
	$general_context = twcp_general_context();
	if ($general_context) {
		$prompt .= "Website Context: {$general_context}\n";
	}
	$prompt .= "This description will be exposed in an MCP (Model Context Protocol) tool registry so AI agents know what this block contains, its visual structure, and when to use it when constructing pages.\n";
	$prompt .= "Output only the description in plain text without quotes or labels.\n";

	$system = "You are a senior UI/UX architect and technical writer documenting web page layout blocks based on their technical structure and visual UI previews.";

	// Previews are tall page screenshots, so they keep the largest size to stay readable
	$desc = twcp_model_vision($prompt, $system, $images, ['image_size' => 1568]);

	if (!empty($desc)) {
		return trim($desc);
	}

	return "{$label} section. " . $sub_info;
}


/**
 * Generate description for a single layout by name and update saved descriptions
 *
 * @param string      $layout_name Layout slug
 * @param string|null $folder      Optional folder path override
 *
 * @return string|null Generated description or null if layout not found
 */
function twcp_block_describe_single(string $layout_name, ?string $folder = null): ?string
{
	$layouts = twcp_block_get_layouts();
	$target = null;
	foreach ($layouts as $l) {
		if ($l['name'] === $layout_name) {
			$target = $l;
			break;
		}
	}

	if (!$target) {
		return null;
	}

	$desc = twcp_block_generate_description($target, $folder);
	$saved = twcp_block_saved_descriptions();
	$saved[$layout_name] = $desc;
	update_option('twcp_block_descriptions', $saved);

	return $desc;
}


/**
 * Generate descriptions for missing blocks using the configured (or specified) previews folder
 *
 * @param string|null $folder    Optional folder path override (relative or absolute)
 * @param bool        $overwrite Whether to overwrite existing descriptions
 *
 * @return int Number of generated descriptions
 */
function twcp_block_generate_descriptions(?string $folder = null, bool $overwrite = false): int
{
	if (function_exists('set_time_limit')) {
		@set_time_limit(300);
	}

	$saved = twcp_block_saved_descriptions();
	$layouts = twcp_block_get_layouts();
	$generated = 0;

	foreach ($layouts as $l) {
		$name = $l['name'];
		if (!empty($saved[$name]) and !$overwrite) {
			continue;
		}

		$desc = twcp_block_generate_description($l, $folder);
		$saved[$name] = $desc;
		$generated++;
	}

	if ($generated > 0) {
		update_option('twcp_block_descriptions', $saved);
	}

	return $generated;
}


/**
 * Tool Callable: Generate Block Descriptions
 *
 * @param array $args
 *
 * @return array
 */
function twcp_block_describe(array $args = []): array
{
	$folder = !empty($args['folder']) ? sanitize_text_field($args['folder']) : null;
	$overwrite = !empty($args['overwrite']);

	$count = twcp_block_generate_descriptions($folder, $overwrite);
	$descriptions = twcp_block_descriptions();

	return [
		'success'         => true,
		'generated_count' => $count,
		'previews_dir'    => twcp_block_previews_dir($folder),
		'descriptions'    => $descriptions,
	];
}
