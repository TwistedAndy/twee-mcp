<?php
/**
 * Twee MCP — Discovery & Search
 *
 * Public search and fetch tools, compatible with ChatGPT connectors.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Fast unified search across posts, terms, and media
 *
 * @param array $args
 *
 * @return array
 */
function twcp_search_query(array $args): array
{
	if (empty($args['query'])) {
		return ['error' => 'query parameter is required'];
	}

	$term = sanitize_text_field($args['query']);
	$results = [];

	$posts = get_posts([
		's'              => $term,
		'post_type'      => array_values(array_filter(['post', 'page', 'product'], 'post_type_exists')),
		'post_status'    => 'publish',
		'has_password'   => false,
		'posts_per_page' => 10,
	]);

	foreach ($posts as $p) {
		$results[] = [
			'id'    => 'post:' . $p->ID,
			'title' => $p->post_title,
			'type'  => $p->post_type,
			'link'  => get_permalink($p->ID),
		];
	}

	$terms = get_terms([
		'search'     => $term,
		'number'     => 10,
		'hide_empty' => false,
		'taxonomy'   => get_taxonomies(['public' => true]),
	]);

	if (!is_wp_error($terms)) {
		foreach ($terms as $t) {
			$results[] = [
				'id'       => 'term:' . $t->term_id,
				'title'    => $t->name,
				'type'     => $t->taxonomy,
				'taxonomy' => $t->taxonomy,
				'link'     => get_term_link($t),
			];
		}
	}

	return [
		'query'   => $term,
		'count'   => count($results),
		'results' => $results,
	];
}


/**
 * Fetch entity details by search ID (e.g. post:123, term:45, image:67)
 *
 * @param array $args
 *
 * @return array
 */
function twcp_search_fetch(array $args): array
{
	if (empty($args['id'])) {
		return ['error' => 'id parameter is required'];
	}

	$parts = explode(':', (string) $args['id'], 2);
	if (count($parts) !== 2) {
		return ['error' => 'Invalid ID format. Use type:id, e.g. post:123 or term:45'];
	}

	[$type, $id] = $parts;
	$numeric_id = absint($id);

	switch ($type) {
		case 'post':
		case 'page':
		case 'product':
			$post = get_post($numeric_id);
			$public = $post && is_post_publicly_viewable($post) && $post->post_password === '';
			if (!$post or (!current_user_can(TWCP_CAPABILITY) and !$public)) {
				return ['error' => 'Post not found: ' . $numeric_id];
			}

			return twcp_post_query(['id' => $numeric_id]);

		case 'term':
			$t = get_term($numeric_id);
			if (!$t or is_wp_error($t)) {
				return ['error' => 'Term not found: ' . $numeric_id];
			}

			$taxonomy = get_taxonomy($t->taxonomy);
			if (!$taxonomy->public and !current_user_can('edit_term', $numeric_id)) {
				return ['error' => 'Term not found: ' . $numeric_id];
			}

			return [
				'id'          => $t->term_id,
				'name'        => $t->name,
				'slug'        => $t->slug,
				'taxonomy'    => $t->taxonomy,
				'description' => $t->description,
				'count'       => $t->count,
				'link'        => get_term_link($t),
			];

		case 'image':
		case 'attachment':
			if (!current_user_can(TWCP_CAPABILITY)) {
				return ['error' => 'Authentication is required to fetch media.'];
			}

			return twcp_media_images(['id' => $numeric_id]);

		default:
			return ['error' => 'Unsupported type: ' . $type];
	}
}


