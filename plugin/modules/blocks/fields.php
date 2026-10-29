<?php
/**
 * Twee MCP — ACF Custom Fields & Block Sets Inspection Tool
 *
 * Provides AI agents with ACF custom fields associated with a post or term
 * (including custom post types and taxonomies), their names, labels, types,
 * choices, instructions, and current values.
 *
 * Includes special handling for reusable Block Sets (post_type = block_set / set),
 * resolving and expanding shared blocks embedded in flexible content layouts,
 * direct fields, and theme setting defaults.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Get registered post types considered Block Sets
 *
 * @return array
 */
function twcp_block_set_types(): array
{
	return ['block_set', 'set'];
}


/**
 * Check if a post is a Block Set
 *
 * @param WP_Post|int $post
 *
 * @return bool
 */
function twcp_is_block_set($post): bool
{
	if (is_numeric($post)) {
		$post = get_post($post);
	}

	return ($post instanceof WP_Post) && in_array($post->post_type, twcp_block_set_types(), true);
}


/**
 * Resolve and format a Block Set post and its contained shared blocks
 *
 * @param int   $set_id  Block set post ID
 * @param array $visited Array of visited set IDs to prevent circular loops
 * @param int   $depth   Current recursion depth
 *
 * @return array|null
 */
function twcp_resolve_block_set(int $set_id, array &$visited = [], int $depth = 0): ?array
{
	if ($depth > 3 or in_array($set_id, $visited, true)) {
		return null;
	}

	$visited[] = $set_id;
	$post = get_post($set_id);
	if (!$post) {
		return null;
	}

	$raw_blocks = function_exists('get_field') ? get_field('blocks', $post->ID) : null;
	if (empty($raw_blocks)) {
		$raw_blocks = get_post_meta($post->ID, 'blocks', true);
	}
	if (!is_array($raw_blocks)) {
		$raw_blocks = [];
	}

	$formatted_blocks = [];
	foreach ($raw_blocks as $idx => $block) {
		if (!is_array($block)) {
			continue;
		}

		$formatted_block = [
			'index'  => $idx,
			'layout' => !empty($block['acf_fc_layout']) ? $block['acf_fc_layout'] : 'unknown',
			'data'   => $block,
		];

		// Check for nested block sets
		if (!empty($block['acf_fc_layout']) and $block['acf_fc_layout'] === 'set' and !empty($block['set'])) {
			$nested_set_ids = is_array($block['set']) ? $block['set'] : [$block['set']];
			$nested_resolved = [];
			foreach ($nested_set_ids as $nested_id) {
				$res = twcp_resolve_block_set((int) $nested_id, $visited, $depth + 1);
				if ($res) {
					$nested_resolved[] = $res;
				}
			}
			$formatted_block['resolved_sets'] = $nested_resolved;
		}

		$formatted_blocks[] = $formatted_block;
	}

	return [
		'id'           => $post->ID,
		'title'        => $post->post_title,
		'slug'         => $post->post_name,
		'post_type'    => $post->post_type,
		'status'       => $post->post_status,
		'total_blocks' => count($formatted_blocks),
		'blocks'       => $formatted_blocks,
	];
}


/**
 * Find where a Block Set is referenced across posts, terms, and theme settings
 *
 * @param int $set_id Block set post ID
 *
 * @return array
 */
function twcp_block_set_usages(int $set_id): array
{
	global $wpdb;
	$set_id = absint($set_id);
	$usages = [
		'theme_settings' => [],
		'posts'          => [],
		'terms'          => [],
	];

	if ($set_id <= 0) {
		return $usages;
	}

	// Theme options, such as options_set_post, options_set_404, or options_set_product
	$opts = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'options_set_%' OR option_name LIKE 'set_%'");
	if (is_array($opts)) {
		foreach ($opts as $o) {
			$val = maybe_unserialize($o->option_value);
			if (is_array($val) and (in_array($set_id, $val, false) or in_array((string) $set_id, $val, true))) {
				$usages['theme_settings'][] = $o->option_name;
			}
		}
	}

	// Posts that use the set layout
	$posts = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'blocks' AND meta_value LIKE %s LIMIT 50", '%' . $set_id . '%'));
	if (is_array($posts)) {
		foreach ($posts as $p) {
			$val = maybe_unserialize($p->meta_value);
			if (is_array($val)) {
				foreach ($val as $b) {
					if (!empty($b['acf_fc_layout']) and $b['acf_fc_layout'] === 'set' and !empty($b['set'])) {
						$set_ids = (array) $b['set'];
						if (in_array($set_id, $set_ids, false) or in_array((string) $set_id, $set_ids, true)) {
							$p_obj = get_post($p->post_id);
							if ($p_obj) {
								$usages['posts'][] = [
									'id'        => (int) $p->post_id,
									'title'     => $p_obj->post_title,
									'post_type' => $p_obj->post_type,
								];
							}
							break;
						}
					}
				}
			}
		}
	}

	// Terms that use the set layout
	$terms = $wpdb->get_results($wpdb->prepare("SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'blocks' AND meta_value LIKE %s LIMIT 50", '%' . $set_id . '%'));
	if (is_array($terms)) {
		foreach ($terms as $t) {
			$val = maybe_unserialize($t->meta_value);
			if (is_array($val)) {
				foreach ($val as $b) {
					if (!empty($b['acf_fc_layout']) and $b['acf_fc_layout'] === 'set' and !empty($b['set'])) {
						$set_ids = (array) $b['set'];
						if (in_array($set_id, $set_ids, false) or in_array((string) $set_id, $set_ids, true)) {
							$t_obj = get_term($t->term_id);
							if ($t_obj and !is_wp_error($t_obj)) {
								$usages['terms'][] = [
									'id'       => (int) $t->term_id,
									'name'     => $t_obj->name,
									'taxonomy' => $t_obj->taxonomy,
								];
							}
							break;
						}
					}
				}
			}
		}
	}

	return $usages;
}


/**
 * Get catalog of all available published Block Sets in the site
 *
 * @return array
 */
function twcp_block_sets_catalog(): array
{
	$posts = get_posts([
		'post_type'      => twcp_block_set_types(),
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'orderby'        => 'title',
		'order'          => 'ASC',
	]);

	$catalog = [];
	foreach ($posts as $p) {
		$raw_blocks = function_exists('get_field') ? get_field('blocks', $p->ID) : null;
		if (empty($raw_blocks)) {
			$raw_blocks = get_post_meta($p->ID, 'blocks', true);
		}
		$catalog[] = [
			'id'           => $p->ID,
			'title'        => $p->post_title,
			'slug'         => $p->post_name,
			'total_blocks' => is_array($raw_blocks) ? count($raw_blocks) : 0,
		];
	}

	return $catalog;
}


/**
 * Recursively format an ACF field definition and its config
 *
 * @param array $field ACF field array
 *
 * @return array
 */
function twcp_format_acf_field(array $field): array
{
	$config = [];

	if (!empty($field['choices']) and is_array($field['choices'])) {
		$config['choices'] = $field['choices'];
	}

	if (!empty($field['post_type'])) {
		$config['post_type'] = (array) $field['post_type'];
		$config['multiple'] = !empty($field['multiple']);
		$config['return_format'] = $field['return_format'] ?? 'id';
	}

	if (!empty($field['taxonomy'])) {
		$config['taxonomy'] = $field['taxonomy'];
		$config['field_type'] = $field['field_type'] ?? '';
	}

	if (!empty($field['sub_fields']) and is_array($field['sub_fields'])) {
		$config['sub_fields'] = [];
		foreach ($field['sub_fields'] as $sub) {
			if (is_array($sub) and !empty($sub['name'])) {
				$config['sub_fields'][] = [
					'name'         => $sub['name'],
					'label'        => $sub['label'] ?? $sub['name'],
					'type'         => $sub['type'] ?? 'text',
					'key'          => $sub['key'] ?? '',
					'instructions' => $sub['instructions'] ?? '',
					'required'     => !empty($sub['required']),
				];
			}
		}
	}

	if (!empty($field['layouts']) and is_array($field['layouts'])) {
		$config['layouts'] = [];
		foreach ($field['layouts'] as $layout) {
			if (is_array($layout) and !empty($layout['name'])) {
				$layout_info = [
					'name'       => $layout['name'],
					'label'      => $layout['label'] ?? $layout['name'],
					'sub_fields' => [],
				];
				if (!empty($layout['sub_fields']) and is_array($layout['sub_fields'])) {
					foreach ($layout['sub_fields'] as $sub) {
						if (is_array($sub) and !empty($sub['name'])) {
							$layout_info['sub_fields'][] = [
								'name'         => $sub['name'],
								'label'        => $sub['label'] ?? $sub['name'],
								'type'         => $sub['type'] ?? 'text',
								'instructions' => $sub['instructions'] ?? '',
							];
						}
					}
				}
				$config['layouts'][] = $layout_info;
			}
		}
	}

	return [
		'name'         => $field['name'] ?? '',
		'label'        => $field['label'] ?? ($field['name'] ?? ''),
		'type'         => $field['type'] ?? 'text',
		'key'          => $field['key'] ?? '',
		'instructions' => $field['instructions'] ?? '',
		'required'     => !empty($field['required']),
		'config'       => !empty($config) ? $config : null,
	];
}


/**
 * Tool: Get ACF custom fields associated with a post or term
 *
 * @param array $args Tool arguments:
 *                    - object_id (int, required): Post ID or Term ID
 *                    - object_type (string, optional): 'post' or 'term' (default 'post')
 *                    - taxonomy (string, optional): Taxonomy name if object_type is 'term'
 *                    - expand_block_sets (bool, optional): Expand referenced Block Sets (default true)
 *
 * @return array
 */
function twcp_custom_fields_get(array $args): array
{
	if (empty($args['object_id'])) {
		return ['error' => 'object_id parameter is required'];
	}

	$object_id = absint($args['object_id']);
	if ($object_id <= 0) {
		return ['error' => 'object_id must be a positive integer'];
	}

	$object_type = !empty($args['object_type']) ? sanitize_key($args['object_type']) : 'post';
	$expand_block_sets = !isset($args['expand_block_sets']) || $args['expand_block_sets'];

	$entity_info = [];
	$acf_target = null;
	$subtype = '';

	if ($object_type === 'term') {
		$taxonomy_hint = !empty($args['taxonomy']) ? sanitize_key($args['taxonomy']) : '';
		$term = get_term($object_id, $taxonomy_hint);
		if (!$term or is_wp_error($term)) {
			return ['error' => 'Term not found: ' . $object_id];
		}

		$tax_obj = get_taxonomy($term->taxonomy);
		if ($tax_obj and !$tax_obj->public and !current_user_can('edit_term', $term->term_id)) {
			return ['error' => 'Permission denied: Term not accessible.'];
		}

		$subtype = $term->taxonomy;
		$acf_target = 'term_' . $term->term_id;
		$entity_info = [
			'id'           => $term->term_id,
			'type'         => 'term',
			'subtype'      => $term->taxonomy,
			'title'        => $term->name,
			'slug'         => $term->slug,
			'link'         => get_term_link($term),
			'is_block_set' => false,
		];
	} else {
		$post = get_post($object_id);
		if (!$post) {
			return ['error' => 'Post not found: ' . $object_id];
		}

		$is_public = is_post_publicly_viewable($post) && $post->post_password === '';
		if (!current_user_can(TWCP_CAPABILITY) and !$is_public) {
			return ['error' => 'Permission denied: Post not accessible.'];
		}

		$subtype = $post->post_type;
		$acf_target = $post->ID;
		$is_set = in_array($post->post_type, twcp_block_set_types(), true);

		$entity_info = [
			'id'           => $post->ID,
			'type'         => 'post',
			'subtype'      => $post->post_type,
			'title'        => $post->post_title,
			'slug'         => $post->post_name,
			'status'       => $post->post_status,
			'link'         => get_permalink($post),
			'is_block_set' => $is_set,
		];
	}

	// The ACF field groups of this object
	$field_groups = [];
	if (function_exists('acf_get_field_groups')) {
		if ($object_type === 'term') {
			$by_context = acf_get_field_groups([
				'taxonomy' => $subtype,
				'term'     => $object_id,
				'post_id'  => $acf_target,
			]);
			$by_tax = acf_get_field_groups(['taxonomy' => $subtype]);
			$by_id = acf_get_field_groups(['post_id' => $acf_target]);
			$all_groups = array_merge($by_context ? : [], $by_tax ? : [], $by_id ? : []);
		} else {
			$by_post_id = acf_get_field_groups(['post_id' => $object_id]);
			$by_post_type = acf_get_field_groups(['post_type' => $subtype]);
			$all_groups = array_merge($by_post_id ? : [], $by_post_type ? : []);
		}

		$keyed_groups = [];
		foreach ($all_groups as $group) {
			if (is_array($group) and !empty($group['key']) and !isset($keyed_groups[$group['key']])) {
				$keyed_groups[$group['key']] = $group;
			}
		}
		$field_groups = array_values($keyed_groups);
	}

	// Their fields and values
	$fields_flat = [];
	$groups_formatted = [];
	$seen_field_keys = [];
	$referenced_set_ids = [];

	foreach ($field_groups as $group) {
		$group_fields = function_exists('acf_get_fields') ? acf_get_fields($group['key']) : [];
		if (!is_array($group_fields)) {
			$group_fields = [];
		}

		$formatted_group_fields = [];

		foreach ($group_fields as $field) {
			if (!is_array($field) or empty($field['name'])) {
				continue;
			}

			$seen_field_keys[$field['key']] = true;
			$field_def = twcp_format_acf_field($field);

			// The value of the field, or its meta when ACF has none
			$value = function_exists('get_field') ? get_field($field['name'], $acf_target) : null;
			if ($value === null or $value === false) {
				$stored_meta = ($object_type === 'term') ? get_term_meta($object_id, $field['name'], true) : get_post_meta($object_id, $field['name'], true);
				if ($stored_meta !== '' and $stored_meta !== false and $stored_meta !== null) {
					$value = $stored_meta;
				}
			}

			// Block sets in flexible content, which use the set layout
			if ($field_def['type'] === 'flexible_content' and is_array($value)) {
				foreach ($value as $b_idx => &$block_item) {
					if (!is_array($block_item)) {
						continue;
					}
					if (!empty($block_item['acf_fc_layout']) and $block_item['acf_fc_layout'] === 'set' and !empty($block_item['set'])) {
						$block_set_ids = is_array($block_item['set']) ? $block_item['set'] : [$block_item['set']];
						$resolved_in_block = [];

						foreach ($block_set_ids as $sid) {
							$sid_int = absint($sid);
							if ($sid_int > 0) {
								$referenced_set_ids[$sid_int] = [
									'source' => "layout 'set' in block index {$b_idx}",
									'set_id' => $sid_int,
								];
								if ($expand_block_sets) {
									$visited = [];
									$res = twcp_resolve_block_set($sid_int, $visited);
									if ($res) {
										$resolved_in_block[] = $res;
									}
								}
							}
						}

						if ($expand_block_sets and !empty($resolved_in_block)) {
							$block_item['resolved_block_sets'] = $resolved_in_block;
						}
					}
				}
				unset($block_item);
			}

			// Special handling for standalone post_object fields referencing block_set
			if ($field_def['type'] === 'post_object' and !empty($field['post_type'])) {
				$pt_list = (array) $field['post_type'];
				if (array_intersect($pt_list, twcp_block_set_types())) {
					if (!empty($value)) {
						$val_ids = is_array($value) ? $value : [$value];
						foreach ($val_ids as $val_id) {
							$vid_int = is_array($val_id) && isset($val_id['ID']) ? absint($val_id['ID']) : absint($val_id);
							if ($vid_int > 0) {
								$referenced_set_ids[$vid_int] = [
									'source' => "field '{$field['name']}'",
									'set_id' => $vid_int,
								];
							}
						}
					}
				}
			}

			$is_empty = ($value === null or $value === '' or (is_array($value) and empty($value)));

			$field_data = array_merge($field_def, [
				'group_title' => $group['title'] ?? '',
				'group_key'   => $group['key'] ?? '',
				'value'       => $value,
				'is_empty'    => $is_empty,
			]);

			$formatted_group_fields[] = $field_data;
			$fields_flat[] = $field_data;
		}

		$groups_formatted[] = [
			'key'         => $group['key'] ?? '',
			'title'       => $group['title'] ?? '',
			'description' => $group['description'] ?? '',
			'fields'      => $formatted_group_fields,
		];
	}

	// Fields in _acf_map that the groups above left out
	$acf_map = ($object_type === 'term') ? get_term_meta($object_id, '_acf_map', true) : get_post_meta($object_id, '_acf_map', true);

	if (is_array($acf_map) and function_exists('acf_get_field')) {
		foreach ($acf_map as $fname => $fkey) {
			$full_key = (strpos((string) $fkey, 'field_') === 0) ? $fkey : 'field_' . $fkey;
			if (empty($seen_field_keys[$full_key])) {
				$f = acf_get_field($full_key);
				if ($f and is_array($f)) {
					$seen_field_keys[$full_key] = true;
					$field_def = twcp_format_acf_field($f);
					$value = function_exists('get_field') ? get_field($fname, $acf_target) : null;
					if ($value === null) {
						$value = ($object_type === 'term') ? get_term_meta($object_id, $fname, true) : get_post_meta($object_id, $fname, true);
					}
					$field_data = array_merge($field_def, [
						'group_title' => 'Mapped Custom Fields',
						'group_key'   => 'acf_map',
						'value'       => $value,
						'is_empty'    => ($value === null or $value === '' or (is_array($value) and empty($value))),
					]);
					$fields_flat[] = $field_data;
				}
			}
		}
	}

	// Block sets of the object
	$block_sets_response = [
		'is_block_set'         => !empty($entity_info['is_block_set']),
		'referenced_sets'      => [],
		'theme_defaults'       => [],
		'shared_blocks'        => null,
		'used_by'              => null,
		'available_block_sets' => twcp_block_sets_catalog(),
	];

	// Block sets its layouts or fields refer to
	if (!empty($referenced_set_ids)) {
		foreach ($referenced_set_ids as $set_info) {
			$sid = $set_info['set_id'];
			$visited = [];
			$res = $expand_block_sets ? twcp_resolve_block_set($sid, $visited) : null;
			$set_post = get_post($sid);
			$block_sets_response['referenced_sets'][] = [
				'id'           => $sid,
				'title'        => $set_post ? $set_post->post_title : '',
				'slug'         => $set_post ? $set_post->post_name : '',
				'source'       => $set_info['source'],
				'status'       => $set_post ? $set_post->post_status : '',
				'total_blocks' => $res ? $res['total_blocks'] : 0,
				'blocks'       => $res ? $res['blocks'] : [],
			];
		}
	}

	// Default block sets the theme sets for this post type or taxonomy
	$opt_key = 'options_set_' . $subtype;
	$theme_default_ids = get_option($opt_key);
	if (is_array($theme_default_ids) and !empty($theme_default_ids)) {
		foreach ($theme_default_ids as $td_id) {
			$td_id_int = absint($td_id);
			if ($td_id_int > 0) {
				$visited = [];
				$res = $expand_block_sets ? twcp_resolve_block_set($td_id_int, $visited) : null;
				$td_post = get_post($td_id_int);
				$block_sets_response['theme_defaults'][] = [
					'id'           => $td_id_int,
					'title'        => $td_post ? $td_post->post_title : '',
					'slug'         => $td_post ? $td_post->post_name : '',
					'option_name'  => $opt_key,
					'status'       => $td_post ? $td_post->post_status : '',
					'total_blocks' => $res ? $res['total_blocks'] : 0,
					'blocks'       => $res ? $res['blocks'] : [],
				];
			}
		}
	}

	// The blocks of the object when it is a block set itself
	if (!empty($entity_info['is_block_set'])) {
		$visited = [];
		$res = twcp_resolve_block_set($object_id, $visited);
		$block_sets_response['shared_blocks'] = $res ? $res['blocks'] : [];
		$block_sets_response['used_by'] = twcp_block_set_usages($object_id);
	}

	return [
		'object'       => $entity_info,
		'total_fields' => count($fields_flat),
		'fields'       => $fields_flat,
		'field_groups' => $groups_formatted,
		'block_sets'   => $block_sets_response,
	];
}
