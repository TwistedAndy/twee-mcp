<?php

/**
 * Comment moderation and reply tool tests
 */
class CommentTest extends TWCP_TestCase {

	/**
	 * The moderation queue is filtered by status, post, and keyword.
	 */
	public function test_get_comments(): void
	{
		$post_id = self::factory()->post->create(['post_title' => 'Commented']);
		$held = self::factory()->comment->create(['comment_post_ID' => $post_id, 'comment_approved' => '0', 'comment_content' => 'Is this waterproof?']);
		self::factory()->comment->create(['comment_post_ID' => $post_id, 'comment_approved' => '1', 'comment_content' => 'Great strap']);
		self::factory()->comment->create(['comment_post_ID' => self::factory()->post->create(), 'comment_approved' => '1']);

		$queue = $this->call('get_comments', ['status' => 'hold']);
		$this->assertSame(1, $queue['total']);
		$this->assertSame($held, $queue['comments'][0]['id']);
		$this->assertSame('hold', $queue['comments'][0]['status']);
		$this->assertSame('Commented', $queue['comments'][0]['post_title']);

		$this->assertSame(2, $this->call('get_comments', ['post_id' => $post_id])['total']);
		$this->assertSame(1, $this->call('get_comments', ['search' => 'waterproof'])['total']);
	}

	/**
	 * Moderation actions change the comment status.
	 */
	public function test_moderate_comment(): void
	{
		$comment_id = self::factory()->comment->create(['comment_post_ID' => self::factory()->post->create(), 'comment_approved' => '0']);

		$this->assertSame('approved', $this->call('moderate_comment', ['comment_id' => $comment_id, 'action' => 'approve'])['comment']['status']);
		$this->assertSame('spam', $this->call('moderate_comment', ['comment_id' => $comment_id, 'action' => 'spam'])['comment']['status']);
		$this->assertSame('approved', $this->call('moderate_comment', ['comment_id' => $comment_id, 'action' => 'unspam'])['comment']['status']);
		$this->assertSame('trash', $this->call('moderate_comment', ['comment_id' => $comment_id, 'action' => 'trash'])['comment']['status']);

		$this->assertArrayHasKey('error', $this->call('moderate_comment', ['comment_id' => $comment_id, 'action' => 'delete']));
		$this->assertSame('Comment not found.', $this->call('moderate_comment', ['comment_id' => 999999, 'action' => 'approve'])['error']);
	}

	/**
	 * Replies are approved, threaded, and approve a pending parent.
	 */
	public function test_reply_comment(): void
	{
		$post_id = self::factory()->post->create();
		$parent = self::factory()->comment->create(['comment_post_ID' => $post_id, 'comment_approved' => '0']);

		$result = $this->call('reply_comment', ['comment_id' => $parent, 'content' => 'Yes, it is. <script>x</script>']);
		$reply = get_comment($result['comment']['id']);

		$this->assertSame('1', get_comment($parent)->comment_approved);
		$this->assertSame((string) $parent, $reply->comment_parent);
		$this->assertSame((string) self::$admin_id, $reply->user_id);
		$this->assertStringNotContainsString('<script>', $reply->comment_content);

		$this->assertArrayHasKey('error', $this->call('reply_comment', ['comment_id' => $parent, 'content' => ' ']));
	}
}
