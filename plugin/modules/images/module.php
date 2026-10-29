<?php
/**
 * Module: Stock Images
 * Description: Searches free stock photo providers, and imports their photos into the Media Library.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;

require_once __DIR__ . '/images.php';

// Each provider adds itself with the twcp_image_providers filter, so a provider file can be added or removed on its own
foreach (glob(__DIR__ . '/providers/*.php') as $file) {
	require_once $file;
}


/**
 * Stock images need no other plugin
 */
add_filter('twcp_modules', function(array $modules): array {
	$modules['images'] = [];

	return $modules;
});


/**
 * Add the stock image tools to the MCP server
 */
add_filter('twcp_tools', function(array $groups): array {
	$providers = twcp_image_providers();

	$groups[] = [
		'group'  => 'images',
		'label'  => __('Stock Images', 'twee-mcp'),
		'module' => 'images',
		'tools'  => [
			'search_stock_images'  => [
				'description' => 'Search free high-quality stock photos and graphics across ' . implode(', ', array_column($providers, 'name')) . '. Returns image metadata, dimensions, author credits, and download URLs.',
				'hint'        => 'For external images, search_stock_images, preserve attribution, then use import_media_image and assign_media_image.',
				'callable'    => 'twcp_image_search',
				'required'    => ['query'],
				'parameters'  => [
					'query'       => [
						'type'        => 'string',
						'description' => 'Keyword search term (e.g. "team meeting in office", "red running shoes", "city skyline at night").',
					],
					'provider'    => [
						'type'        => 'string',
						'description' => 'Stock provider to search: "all" (default, merges results across providers), "' . implode('", "', array_keys($providers)) . '".',
					],
					'orientation' => ['type' => 'string', 'description' => 'Image orientation filter: "landscape", "portrait", "square", "all".'],
					'per_page'    => ['type' => 'integer', 'description' => 'Number of images to return (default 10, max 50).'],
					'page'        => ['type' => 'integer', 'description' => 'Page offset for pagination (default 1).'],
				],
				'annotations' => ['readOnlyHint' => true],
			],
		],
	];

	return $groups;
}, 50);
