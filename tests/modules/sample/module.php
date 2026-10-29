<?php
/**
 * Module: Sample
 * Description: A project module outside the plugin, loaded from a folder added with the twcp_module_dirs filter.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


add_filter('twcp_modules', function(array $modules): array {
	$modules['sample'] = [];

	return $modules;
});


/**
 * A tool anyone may run, as it only returns its input
 */
add_filter('twcp_tools_groups', function(array $groups): array {
	$groups['sample'] = [
		'label'    => 'Sample',
		'position' => 120,
		'module'   => 'sample',
		'tools'    => [
			'sample_echo' => [
				'description' => 'Return the text it gets.',
				'callable'    => function(array $input): array {
					return ['text' => $input['text']];
				},
				'public'      => true,
				'required'    => ['text'],
				'parameters'  => ['text' => ['type' => 'string']],
				'annotations' => ['readOnlyHint' => true],
			],
		],
	];

	return $groups;
});
