<?php

/**
 * Shared helpers for Twee MCP integration tests
 */
abstract class TWCP_TestCase extends WP_UnitTestCase {

	/**
	 * Administrator used as the connected MCP user
	 *
	 * @var int
	 */
	protected static int $admin_id;

	public static function wpSetUpBeforeClass(WP_UnitTest_Factory $factory): void
	{
		self::$admin_id = $factory->user->create(['role' => 'administrator']);
	}

	public function set_up(): void
	{
		parent::set_up();
		wp_set_current_user(self::$admin_id);
	}

	/**
	 * Call a tool through the registry with validation and access checks
	 *
	 * @param string $name
	 * @param array  $args
	 * @param bool   $read_only
	 *
	 * @return mixed
	 */
	protected function call(string $name, array $args = [], bool $read_only = false)
	{
		return twcp_tools_call($name, $args, $read_only);
	}

	/**
	 * Dispatch a JSON-RPC message the way the REST endpoint does
	 *
	 * @param string $method
	 * @param array  $params
	 *
	 * @return array|null
	 */
	protected function dispatch(string $method, array $params = []): ?array
	{
		return twcp_tools_dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
	}

	/**
	 * Skip the test when WooCommerce is not loaded
	 */
	protected function require_woocommerce(): void
	{
		if (!class_exists('WooCommerce')) {
			$this->markTestSkipped('WooCommerce is not available. Set WOOCOMMERCE_DIR to run store tests.');
		}
	}

	/**
	 * Create an image attachment with generated intermediate sizes
	 *
	 * @param int    $width
	 * @param int    $height
	 * @param int    $parent
	 * @param string $name
	 *
	 * @return int Attachment ID
	 */
	protected function create_image(int $width = 1600, int $height = 900, int $parent = 0, string $name = 'twcp-test'): int
	{
		$file = get_temp_dir() . $name . '-' . wp_generate_password(6, false) . '.jpg';
		$image = imagecreatetruecolor($width, $height);
		imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
		imagejpeg($image, $file, 90);
		imagedestroy($image);

		$id = self::factory()->attachment->create_upload_object($file, $parent);
		wp_delete_file($file);

		return $id;
	}

	/**
	 * Capture outgoing model requests and reply with a canned response
	 *
	 * @param array $reply Decoded provider response body
	 *
	 * @return ArrayObject Captured request bodies
	 */
	protected function mock_model(array $reply): ArrayObject
	{
		$requests = new ArrayObject();

		add_filter('pre_http_request', function($response, $args, $url) use ($requests, $reply) {
			$requests[] = ['url' => $url, 'body' => json_decode($args['body'], true)];

			return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode($reply), 'cookies' => []];
		}, 10, 3);

		return $requests;
	}
}
