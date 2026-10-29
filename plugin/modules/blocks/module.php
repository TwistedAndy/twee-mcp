<?php
/**
 * Module: ACF Blocks
 * Description: Page builder blocks and custom fields of ACF Pro, and the Blocks tab with their conventions and descriptions.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

require_once __DIR__ . '/blocks.php';
require_once __DIR__ . '/fields.php';


/**
 * The block tools, block descriptions, and the Blocks tab need ACF Pro
 */
add_filter('twcp_modules', function(array $modules): array {
	$modules['blocks'] = ['plugin' => 'ACF Pro', 'active' => defined('ACF_PRO')];

	return $modules;
});


/**
 * Add the ACF block tools to the MCP server
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['blocks'] = [
		'label'    => __('ACF Blocks', 'twee-mcp'),
		'position' => 60,
		'module'   => 'blocks',
		'hint'     => 'Inspect get_block_types before using get_blocks, create_block, edit_block, or remove_block. Follow field formats and check warnings.',
		'tools'    => [
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
				'hint'        => 'Inspect ACF custom fields, labels, types, and resolve reusable Block Sets with get_custom_fields.',
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
					'object_type' => ['type' => 'string', 'enum' => ['post', 'term'], 'description' => '"post" or "term" (default "post").'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
			'create_block'                => [
				'description' => 'Add a block to a post or term at an optional position. Data must follow the layout schema from get_block_types, with groups as nested objects, e.g. {"contents": {"title": "...", "text": "<p>...</p>", "buttons": [{"type": "default", "link": {"url": "...", "title": "..."}}]}, "settings": {"background": "light"}, "items": [{...}]}. Values are sanitized per field format; unknown fields and invalid choices are dropped and listed in "warnings", so check them.',
				'callable'    => 'twcp_block_create',
				'required'    => ['object_id', 'layout', 'data'],
				'parameters'  => [
					'object_id'   => ['type' => 'integer', 'description' => 'Post or Term ID.'],
					'object_type' => ['type' => 'string', 'enum' => ['post', 'term'], 'description' => '"post" or "term" (default "post").'],
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
					'object_type' => ['type' => 'string', 'enum' => ['post', 'term'], 'description' => '"post" or "term" (default "post").'],
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
					'object_type' => ['type' => 'string', 'enum' => ['post', 'term'], 'description' => '"post" or "term" (default "post").'],
					'index'       => ['type' => ['integer', 'string'], 'description' => '0-based index of block to remove, or string "all".'],
				],
				'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
			],
		],
	];

	return $groups;
});
