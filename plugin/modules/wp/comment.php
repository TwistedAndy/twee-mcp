<?php
/**
 * Twee MCP — Comments Moderation
 *
 * Implements MCP tools for reading, moderating, and replying to post comments.
 *
 * @package Twee_MCP
 */

defined('ABSPATH') or exit;


/**
 * Format a comment for MCP responses
 *
 * @param WP_Comment $comment
 *
 * @return array
 */
function twcp_comment_format(WP_Comment $comment): array
{
	$statuses = ['1' => 'approved', '0' => 'hold', 'spam' => 'spam', 'trash' => 'trash'];

	return [
		'id'         => (int) $comment->comment_ID,
		'post_id'    => (int) $comment->comment_post_ID,
		'post_title' => get_the_title((int) $comment->comment_post_ID),
		'parent'     => (int) $comment->comment_parent,
		'author'     => $comment->comment_author,
		'author_url' => $comment->comment_author_url,
		'user_id'    => (int) $comment->user_id,
		'date'       => $comment->comment_date,
		'status'     => $statuses[$comment->comment_approved] ?? $comment->comment_approved,
		'type'       => $comment->comment_type,
		'content'    => $comment->comment_content,
		'link'       => get_comment_link($comment),
	];
}


/**
 * Action/Tool: Query comments
 *
 * @param array $args
 *
 * @return array
 */
function twcp_comment_query(array $args): array
{
	$limit = isset($args['limit']) ? min(50, max(1, (int) $args['limit'])) : 20;
	$offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
	$status = $args['status'] ?? 'all';

	$query_args = [
		'status'  => in_array($status, ['all', 'hold', 'approve', 'spam', 'trash'], true) ? $status : 'all',
		'type'    => 'comment',
		'number'  => $limit,
		'offset'  => $offset,
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	];

	if (!empty($args['post_id'])) {
		$query_args['post_id'] = absint($args['post_id']);
	}
	if (!empty($args['search'])) {
		$query_args['search'] = sanitize_text_field($args['search']);
	}

	$comments = get_comments($query_args);
	$total = get_comments(['count' => true, 'number' => 0, 'offset' => 0] + $query_args);

	return [
		'total'    => (int) $total,
		'limit'    => $limit,
		'offset'   => $offset,
		'comments' => array_map('twcp_comment_format', $comments),
	];
}


/**
 * Action/Tool: Change the moderation status of a comment
 *
 * @param array $args
 *
 * @return array
 */
function twcp_comment_moderate(array $args): array
{
	$comment = get_comment(absint($args['comment_id'] ?? 0));
	if (!$comment) {
		return ['error' => 'Comment not found.'];
	}
	if (!current_user_can('edit_comment', $comment->comment_ID)) {
		return ['error' => 'You cannot moderate this comment.'];
	}

	$id = (int) $comment->comment_ID;
	$result = match ($args['action'] ?? '') {
		'approve' => wp_set_comment_status($id, 'approve'),
		'hold' => wp_set_comment_status($id, 'hold'),
		'spam' => wp_spam_comment($id),
		'unspam' => wp_unspam_comment($id),
		'trash' => wp_trash_comment($id),
		'untrash' => wp_untrash_comment($id),
		default => null,
	};

	if ($result === null) {
		return ['error' => 'Unknown moderation action.'];
	}
	if (!$result or is_wp_error($result)) {
		return ['error' => 'Could not update the comment status.'];
	}

	return ['success' => true, 'comment' => twcp_comment_format(get_comment($id))];
}


/**
 * Action/Tool: Reply to a comment as the current user
 *
 * @param array $args
 *
 * @return array
 */
function twcp_comment_reply(array $args): array
{
	$parent = get_comment(absint($args['comment_id'] ?? 0));
	if (!$parent) {
		return ['error' => 'Comment not found.'];
	}

	$content = trim(wp_kses_post((string) ($args['content'] ?? '')));
	if ($content === '') {
		return ['error' => 'Reply content is required.'];
	}

	if ($parent->comment_approved === '0') {
		wp_set_comment_status((int) $parent->comment_ID, 'approve');
	}

	$user = wp_get_current_user();
	$reply_id = wp_insert_comment([
		'comment_post_ID'      => (int) $parent->comment_post_ID,
		'comment_parent'       => (int) $parent->comment_ID,
		'comment_content'      => $content,
		'comment_approved'     => 1,
		'user_id'              => $user->ID,
		'comment_author'       => $user->display_name,
		'comment_author_email' => $user->user_email,
		'comment_author_url'   => $user->user_url,
	]);

	if (!$reply_id) {
		return ['error' => 'Could not save the reply.'];
	}

	return ['success' => true, 'comment' => twcp_comment_format(get_comment($reply_id))];
}
