<?php

/**
 * WP Activity Log summary and brand activity tool tests
 */
class ActivityTest extends TWCP_TestCase {

	/**
	 * 2026-08-03 10:00 UTC, the start of a logged work session
	 */
	private const START = 1785751200;

	public function set_up(): void
	{
		parent::set_up();
		add_filter('twcp_activity_active', '__return_true');

		global $wpdb;
		$wpdb->query("CREATE TABLE {$wpdb->base_prefix}wsal_occurrences (
			id bigint(20) NOT NULL AUTO_INCREMENT, site_id bigint(20) NOT NULL DEFAULT 1, alert_id bigint(20) NOT NULL, created_on double NOT NULL,
			client_ip varchar(255) NOT NULL DEFAULT '', severity varchar(255) NOT NULL DEFAULT '', object varchar(255) NOT NULL DEFAULT '',
			event_type varchar(255) NOT NULL DEFAULT '', user_agent varchar(255) NOT NULL DEFAULT '', user_roles varchar(255) NOT NULL DEFAULT '',
			username varchar(60) DEFAULT NULL, user_id bigint(20) DEFAULT NULL, session_id varchar(255) NOT NULL DEFAULT '',
			post_status varchar(255) NOT NULL DEFAULT '', post_type varchar(255) NOT NULL DEFAULT '', post_id bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY (id))");
		$wpdb->query("CREATE TABLE {$wpdb->base_prefix}wsal_metadata (
			id bigint(20) NOT NULL AUTO_INCREMENT, occurrence_id bigint(20) NOT NULL, name varchar(60) NOT NULL, value longtext NOT NULL, PRIMARY KEY (id))");
	}

	public function tear_down(): void
	{
		global $wpdb;
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}wsal_occurrences");
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}wsal_metadata");
		parent::tear_down();
	}

	/**
	 * Work is counted per post type, time adds the gaps between actions, and noise and other roles are left out.
	 */
	public function test_summary(): void
	{
		$manager = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Walter']);
		$editor = self::factory()->user->create(['role' => 'editor']);
		$post = self::factory()->post->create();
		$page = self::factory()->post->create(['post_type' => 'page']);

		$this->log($manager, 1000, -10);
		$this->log($manager, 2100, 0, $post);
		$this->log($manager, 2065, 120, $post);
		$this->log($manager, 2002, 130, $post);
		$this->log($manager, 4015, 140);
		$this->log($manager, 2101, 150, $page);
		$this->log($manager, 2001, 3600, $post);
		$this->log($manager, 2010, 3610);
		$this->log($manager, 2023, 3660);
		$this->log($manager, 2002, -86400 * 40, $post);
		$this->log($editor, 2002, 200, $page, 'editor');

		$summary = $this->call('get_activity_summary', ['date_from' => '2026-08-01', 'date_to' => '2026-08-05']);
		$this->assertCount(1, $summary['users']);

		$row = $summary['users'][0];
		$this->assertSame('Walter', $row['name']);
		$this->assertSame(6, $row['actions']);
		$this->assertSame(1, $row['active_days']);
		$this->assertSame(1, $row['sessions']);
		$this->assertSame(0, $row['parallel_sessions']);
		// One minute for the first and the isolated publish, plus the gaps of 120, 10, 10 and 50 seconds
		$this->assertSame(5, $row['active_minutes']);
		$this->assertSame(1.0, $row['span_hours']);
		$this->assertSame(['opened' => 1, 'edited' => 1, 'published' => 1, 'published_new' => 0, 'saves' => 2], $row['items']['post']);
		$this->assertSame([1, 0, 0], [$row['published'], $row['published_new'], $row['price_updates']]);
		$this->assertSame(0, $row['tracked_minutes'], 'Time is not tracked');
		$this->assertArrayNotHasKey('page', $row['items']);
		$this->assertSame(['created' => 1, 'edited' => 0, 'deleted' => 0], $row['terms']);
		$this->assertSame(1, $row['media']['uploaded']);
		$this->assertSame(1, $row['logins']);

		$all = $this->call('get_activity_summary', ['date_from' => '2026-08-01', 'date_to' => '2026-08-05', 'role' => 'any']);
		$this->assertCount(2, $all['users']);
		$this->assertSame(1, $all['users'][1]['items']['page']['edited']);

		$single = $this->call('get_activity_summary', ['date_from' => '2026-08-01', 'date_to' => '2026-08-05', 'user' => (string) $editor]);
		$this->assertSame($editor, $single['users'][0]['user_id']);

		$named = $this->call('get_activity_summary', ['date_from' => '2026-08-01', 'date_to' => '2026-08-05', 'user' => 'Walter']);
		$this->assertSame($manager, $named['users'][0]['user_id']);
	}

	/**
	 * Two logins of one account working at the same time are reported as parallel sessions.
	 */
	public function test_summary_flags_parallel_sessions(): void
	{
		$shared = self::factory()->user->create(['role' => 'editor']);
		$post = self::factory()->post->create();

		$this->log($shared, 2002, 0, $post, 'shop_manager', 'a');
		$this->log($shared, 2002, 60, $post, 'shop_manager', 'b');
		$this->log($shared, 2002, 120, $post, 'shop_manager', 'a');

		$row = $this->call('get_activity_summary', ['date_from' => '2026-08-03', 'date_to' => '2026-08-03'])['users'][0];
		$this->assertSame(2, $row['sessions']);
		$this->assertSame(2, $row['parallel_sessions']);
	}

	/**
	 * Users who published most come first; first publications, price updates and term changes are counted apart.
	 */
	public function test_published_and_prices(): void
	{
		$publisher = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Sofía']);
		$busy = self::factory()->user->create(['role' => 'editor']);
		$new = self::factory()->post->create(['post_date' => gmdate('Y-m-d H:i:s', self::START + 60), 'post_date_gmt' => gmdate('Y-m-d H:i:s', self::START + 60)]);
		$old = self::factory()->post->create(['post_date' => '2025-01-01 10:00:00', 'post_date_gmt' => '2025-01-01 10:00:00']);

		$this->log($publisher, 9001, 60, $new);
		$this->log($publisher, 9001, 120, $old);
		$this->log($publisher, 9016, 180, $new, 'shop_manager', 'a', ['PriceType' => 'regular', 'OldPrice' => '€100', 'NewPrice' => '€120']);
		$this->log($publisher, 9016, 181, $new);
		$this->log($publisher, TWCP_TERMS_EVENT, 200, $old, 'shop_manager', 'a', ['OldTerms' => 'None', 'NewTerms' => 'Bocci']);
		$this->log($publisher, TWCP_PRICES_EVENT, 300, $old);
		foreach (range(1, 20) as $step) {
			$this->log($busy, 2002, $step * 60, $old);
		}

		$range = ['date_from' => '2026-08-03', 'date_to' => '2026-08-03'];
		$users = $this->call('get_activity_summary', $range)['users'];
		$this->assertSame([$publisher, $busy], array_column($users, 'user_id'));
		// The regular and sale prices of one save count once
		$this->assertSame([2, 1, 2], [$users[0]['published'], $users[0]['published_new'], $users[0]['price_updates']]);
		$this->assertSame(['opened' => 0, 'edited' => 2, 'published' => 2, 'price_changed' => 2, 'terms_changed' => 1, 'published_new' => 1, 'saves' => 5], $users[0]['items']['post']);

		$events = $this->call('get_activity_events', $range + ['action' => 'price_changed'])['events'];
		$this->assertSame([TWCP_PRICES_EVENT, 9016, 9016], array_column($events, 'code'));
		$this->assertSame(['Price' => ['old' => '€100', 'new' => '€120']], $events[2]['changes']);
		$this->assertSame([TWCP_TERMS_EVENT], array_column($this->call('get_activity_events', $range + ['action' => 'terms_changed'])['events'], 'code'));
	}

	/**
	 * Tracked editing time is logged as events whose seconds are added to the reports per user, day, item and brand.
	 */
	public function test_tracked_time(): void
	{
		register_taxonomy('twcp_brand', 'post');
		$brand = self::factory()->term->create(['taxonomy' => 'twcp_brand', 'name' => 'Bocci']);
		$manager = self::factory()->user->create(['role' => 'editor', 'user_login' => 'juliana']);
		$lamp = self::factory()->post->create(['post_title' => 'Lamp']);
		wp_set_object_terms($lamp, [$brand], 'twcp_brand');

		$this->log($manager, 2002, 60, $lamp);
		$this->log($manager, TWCP_TIME_EVENT, 120, $lamp, 'shop_manager', 'a', ['Seconds' => 90]);
		$this->log($manager, TWCP_TIME_EVENT, 600, $lamp, 'shop_manager', 'a', ['Seconds' => 60]);
		$this->log($manager, TWCP_TIME_EVENT, 7200, 0, 'shop_manager', 'a', ['Seconds' => 150, 'Screen' => 'dashboard']);

		$range = ['date_from' => '2026-08-03', 'date_to' => '2026-08-03', 'user' => 'juliana'];
		$row = $this->call('get_activity_summary', $range)['users'][0];
		$this->assertSame([5, 1], [$row['tracked_minutes'], $row['actions']], 'Time events are not actions');

		$report = $this->call('get_user_activity', $range + ['taxonomy' => 'twcp_brand']);
		$this->assertSame([5, 3], [$report['days'][0]['tracked_minutes'], $report['items'][0]['tracked_minutes']]);

		$group = $this->call('get_brand_activity', $range + ['taxonomy' => 'twcp_brand'])['groups'][0];
		$this->assertSame([3, 3], [$group['tracked_minutes'], $group['users'][0]['tracked_minutes']]);

		$this->assertSame(3, $this->call('get_item_history', ['id' => $lamp])['contributors'][0]['tracked_minutes']);

		$this->assertSame([2002], array_column($this->call('get_activity_events', $range)['events'], 'code'), 'Time is left out like views');
		$this->assertSame(4, $this->call('get_activity_events', $range + ['include_noise' => true])['total']);
	}

	/**
	 * Items are grouped by their terms, other types by a top-level term of the same name, and the rest by none or deleted.
	 */
	public function test_brand_activity(): void
	{
		register_post_type('twcp_material', ['public' => true]);
		register_taxonomy('twcp_brand', 'post');
		register_taxonomy('twcp_collection', 'twcp_material', ['hierarchical' => true]);

		$brand = self::factory()->term->create(['taxonomy' => 'twcp_brand', 'name' => 'Bocci']);
		$top = self::factory()->term->create(['taxonomy' => 'twcp_collection', 'name' => 'bocci']);
		$glass = self::factory()->term->create(['taxonomy' => 'twcp_collection', 'name' => 'Glass', 'parent' => $top]);

		$manager = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Ernesto']);
		$lamp = self::factory()->post->create();
		$material = self::factory()->post->create(['post_type' => 'twcp_material']);
		$plain = self::factory()->post->create();
		wp_set_object_terms($lamp, [$brand], 'twcp_brand');
		wp_set_object_terms($material, [$glass], 'twcp_collection');

		$this->log($manager, 2002, 0, $lamp);
		$this->log($manager, 2002, 10, $lamp);
		$this->log($manager, 2001, 100, $material);
		$this->log($manager, 2100, 200, $plain);
		$this->log($manager, 2002, 300, 999999);

		$result = $this->call('get_brand_activity', ['date_from' => '2026-08-03', 'date_to' => '2026-08-03', 'taxonomy' => 'twcp_brand']);
		$groups = array_column($result['groups'], null, 'name');
		$this->assertSame(4, $result['items']);

		$bocci = $groups['Bocci'];
		$this->assertSame($brand, $bocci['term_id']);
		$this->assertSame(['post' => 1, 'twcp_material' => 1], $bocci['types']);
		$this->assertSame([2, 2, 1, 2], [$bocci['items'], $bocci['edited'], $bocci['published'], $bocci['saves']]);
		$this->assertSame(['user_id' => $manager, 'name' => 'Ernesto', 'items' => 2, 'edited' => 2, 'published' => 1, 'price_changed' => 0, 'saves' => 2, 'tracked_minutes' => 0], $bocci['users'][0]);

		$this->assertSame([1, 0], [$groups['(none)']['items'], $groups['(none)']['edited']]);
		$this->assertSame(1, $groups['(deleted items)']['items']);

		$this->assertStringContainsString('Unknown taxonomy', $this->call('get_brand_activity', ['taxonomy' => 'nope'])['error']);
	}

	/**
	 * One user's days, sessions, and items are reported with the items' terms.
	 */
	public function test_user_activity(): void
	{
		register_taxonomy('twcp_brand', 'post');
		$brand = self::factory()->term->create(['taxonomy' => 'twcp_brand', 'name' => 'Euroluce']);
		$manager = self::factory()->user->create(['role' => 'editor', 'user_login' => 'walter']);
		$lamp = self::factory()->post->create(['post_title' => 'Lamp']);
		$chair = self::factory()->post->create(['post_title' => 'Chair']);
		wp_set_object_terms($lamp, [$brand], 'twcp_brand');

		$this->log($manager, 2100, 0, $lamp);
		$this->log($manager, 2002, 60, $lamp);
		$this->log($manager, 2002, 180, $lamp);
		$this->log($manager, 2002, 86400, $chair, 'shop_manager', 'b');

		$report = $this->call('get_user_activity', ['user' => 'walter', 'date_from' => '2026-08-03', 'date_to' => '2026-08-04', 'taxonomy' => 'twcp_brand']);
		$this->assertSame($manager, $report['summary']['user_id']);
		$this->assertSame(2, $report['summary']['active_days']);
		$this->assertSame(['date' => '2026-08-03', 'start' => '10:00', 'end' => '10:03', 'active_minutes' => 4, 'actions' => 3, 'items' => 1, 'saves' => 2, 'tracked_minutes' => 0], $report['days'][0]);
		$this->assertSame('2026-08-04', $report['days'][1]['date']);
		$this->assertSame(['a', 'b'], array_column($report['sessions'], 'session'));

		$this->assertSame([$lamp, $chair], array_column($report['items'], 'id'));
		$this->assertSame(['Lamp', ['Euroluce'], ['opened' => 1, 'edited' => 2], 2], [$report['items'][0]['title'], $report['items'][0]['terms'], $report['items'][0]['actions'], $report['items'][0]['saves']]);

		$empty = $this->call('get_user_activity', ['user' => 'walter', 'date_from' => '2026-07-01', 'date_to' => '2026-07-02']);
		$this->assertStringContainsString('No activity', $empty['notes']);
		$this->assertSame('User not found.', $this->call('get_user_activity', ['user' => 'nobody'])['error']);
	}

	/**
	 * An item's history lists everyone who changed it with messages, details, and old and new values.
	 */
	public function test_item_history(): void
	{
		add_filter('twcp_activity_catalogue', [self::class, 'catalogue']);
		register_taxonomy('twcp_brand', 'post', ['show_ui' => true]);
		$brand = self::factory()->term->create(['taxonomy' => 'twcp_brand', 'name' => 'Bocci']);
		$karina = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Karina']);
		$diego = self::factory()->user->create(['role' => 'editor', 'display_name' => 'Diego']);
		$lamp = self::factory()->post->create(['post_title' => 'Lamp']);
		wp_set_object_terms($lamp, [$brand], 'twcp_brand');

		$this->log($karina, 9001, 0, $lamp, 'shop_manager', 'a', ['ProductTitle' => 'Lamp', 'OldStatus' => 'draft', 'ProductStatus' => 'publish']);
		$this->log($karina, 9017, 120, $lamp, 'shop_manager', 'a', ['ProductTitle' => 'Lamp', 'PostID' => $lamp, 'OldSku' => 'old-1', 'NewSku' => 'new-1']);
		$this->log($diego, 9073, 200, $lamp, 'editor');
		$this->log(0, 9105, 300, $lamp, '');
		$this->log($diego, 2002, 400, $lamp, 'editor');

		$history = $this->call('get_item_history', ['id' => $lamp]);
		$this->assertSame([$lamp, 'post', 'Lamp', 'publish', ['Bocci']], [$history['item']['id'], $history['item']['type'], $history['item']['title'], $history['item']['status'], $history['item']['terms']['twcp_brand']]);
		$this->assertSame(3, $history['total']);
		$this->assertSame(['Karina', 'Diego'], array_column($history['contributors'], 'name'));
		$this->assertSame(['edited' => 1, 'published' => 1], $history['contributors'][0]['actions']);
		$this->assertSame(2, $history['contributors'][0]['saves']);

		$sku = $history['events'][1];
		$this->assertSame([9017, 'Karina', 'User changed the SKU of a product'], [$sku['code'], $sku['user'], $sku['description']]);
		$this->assertSame('Changed the SKU of the product Lamp to new-1.', $sku['message']);
		$this->assertSame(['Product ID' => (string) $lamp, 'Previous SKU' => 'old-1'], $sku['details']);
		$this->assertSame(['Sku' => ['old' => 'old-1', 'new' => 'new-1']], $sku['changes']);
		$this->assertSame(['Status' => ['old' => 'draft', 'new' => 'publish']], $history['events'][2]['changes']);

		$this->log($karina, 9001, 500, 999999, 'shop_manager', 'a', ['ProductTitle' => 'Gone Lamp']);
		$gone = $this->call('get_item_history', ['id' => 999999]);
		$this->assertSame(['deleted', 'Gone Lamp'], [$gone['item']['status'], $gone['events'][0]['item']['title']]);
		$this->assertStringContainsString('No item', $this->call('get_item_history', ['id' => 999998])['error']);

		// A copied product is logged without an item and names the new product in its details only
		$copy = self::factory()->post->create(['post_title' => 'Lamp (Copy)']);
		$this->log($karina, 9138, 600, 0, 'shop_manager', 'a', ['ProductID' => $copy], ['object' => 'woocommerce-product']);
		$this->assertSame(['created'], array_column($this->call('get_item_history', ['id' => $copy])['events'], 'action'));
		$this->assertSame([9138], array_column($this->call('get_activity_events', ['date_from' => '2026-08-03', 'item_id' => $copy])['events'], 'code'));
		$this->assertSame(1, $this->call('get_activity_summary', ['date_from' => '2026-08-03', 'user' => (string) $karina])['users'][0]['items']['post']['created']);
	}

	/**
	 * Events are listed newest first and filtered by action, event ID, item, and role, without noise by default.
	 */
	public function test_activity_events(): void
	{
		$manager = self::factory()->user->create(['role' => 'editor']);
		$post = self::factory()->post->create();

		$this->log($manager, 9001, 0, $post);
		$this->log($manager, 9017, 60, $post);
		$this->log($manager, 2101, 120, $post);
		$this->log($manager, 2010, 180);

		$range = ['date_from' => '2026-08-03', 'date_to' => '2026-08-03'];
		$codes = function(array $args) use ($range): array {
			return array_column($this->call('get_activity_events', $range + $args)['events'], 'code');
		};

		$this->assertSame([2010, 9017, 9001], $codes([]));
		$this->assertSame(4, $this->call('get_activity_events', $range + ['include_noise' => true])['total']);
		$this->assertSame([9001], $codes(['action' => 'published']));
		$this->assertSame([9017], $codes(['action' => 'edited']));
		$this->assertSame([2010, 9017], $codes(['event_ids' => [9017, 2010]]));
		$this->assertSame([], $codes(['event_ids' => [9017], 'action' => 'published']));
		$this->assertSame([9017, 9001], $codes(['item_id' => $post]));
		$this->assertSame([9017], $codes(['limit' => 1, 'offset' => 1]));
		$this->assertSame([], $codes(['role' => 'customer']));
		$this->assertSame('uploaded', $this->call('get_activity_events', $range)['events'][0]['action']);

		// Details stored as objects fill placeholders of their properties
		add_filter('twcp_activity_catalogue', [self::class, 'catalogue']);
		$this->log($manager, 5000, 240, 0, 'shop_manager', 'a', ['PluginData' => (object) ['Name' => 'WP Activity Log', 'Version' => '5.6.8']]);
		$plugin = $this->call('get_activity_events', $range + ['action' => 'other'])['events'][0];
		$this->assertSame(['Installed the plugin WP Activity Log.', ['Version' => '5.6.8']], [$plugin['message'], $plugin['details']]);
	}

	/**
	 * Logins are reported per user with IPs and devices, and failed logins of unknown users separately.
	 */
	public function test_login_activity(): void
	{
		$manager = self::factory()->user->create(['role' => 'editor', 'user_login' => 'karina']);
		$chrome = ['user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36'];

		$this->log($manager, 1000, 0, 0, 'shop_manager', 'a', [], ['client_ip' => '10.0.0.1'] + $chrome);
		$this->log($manager, 1000, 3600, 0, 'shop_manager', 'b', [], ['client_ip' => '10.0.0.2'] + $chrome);
		$this->log($manager, 1002, 7200);
		$this->log($manager, 1001, 7300);
		$this->log(0, 1003, 100, 0, '', '', [], ['client_ip' => '9.9.9.9']);
		$this->log(0, 1003, 200, 0, '', '', [], ['client_ip' => '9.9.9.9']);

		$result = $this->call('get_login_activity', ['date_from' => '2026-08-03', 'date_to' => '2026-08-03']);
		$row = $result['users'][0];
		$this->assertSame([2, 1, 1, '2026-08-03 11:00', 0], [$row['logins'], $row['logouts'], $row['failed_logins'], $row['last_login'], $row['signed_in_now']]);
		$this->assertSame(['10.0.0.1' => 1, '10.0.0.2' => 1], $row['ips']);
		$this->assertSame(['Chrome on Windows' => 2], $row['devices']);
		$this->assertSame(['failed_logins' => 2, 'top_ips' => [['ip' => '9.9.9.9', 'attempts' => 2]]], $result['unknown_users']);

		$this->assertArrayNotHasKey('unknown_users', $this->call('get_login_activity', ['date_from' => '2026-08-03', 'user' => 'karina']));
	}

	/**
	 * Event types are listed with their counts, and searching or "all" includes types never logged.
	 */
	public function test_event_types(): void
	{
		add_filter('twcp_activity_catalogue', [self::class, 'catalogue']);
		$manager = self::factory()->user->create(['role' => 'editor']);
		$post = self::factory()->post->create();

		$this->log($manager, 9017, 0, $post);
		$this->log($manager, 9017, 60, $post);
		$this->log($manager, 9001, 120, $post);

		$range = ['date_from' => '2026-08-03', 'date_to' => '2026-08-03'];
		$types = $this->call('get_activity_event_types', $range)['event_types'];
		$rows = [];
		foreach ($types as $type) {
			$rows[] = [$type['code'], $type['logged'], $type['counted_as'], $type['enabled']];
		}
		$this->assertSame([[9017, 2, 'edited', true], [9001, 1, 'published', true]], $rows);

		$this->assertSame([1000], array_column($this->call('get_activity_event_types', $range + ['search' => 'logged in'])['event_types'], 'code'));
		$this->assertSame([1000], array_column($this->call('get_activity_event_types', $range + ['category' => 'logins'])['event_types'], 'code'));
		$this->assertSame(4, $this->call('get_activity_event_types', $range + ['all' => true])['total']);
		$this->assertSame('other', $this->call('get_activity_event_types', $range + ['search' => '5000'])['event_types'][0]['counted_as']);
	}

	/**
	 * The tools need WP Activity Log and an administrator, and stay out of read-only model lookups.
	 */
	public function test_activity_access(): void
	{
		$this->assertStringContainsString('YYYY-MM-DD', $this->call('get_activity_summary', ['date_from' => '03/08/2026'])['error']);
		$this->assertSame('User not found.', $this->call('get_activity_summary', ['user' => 'nobody'])['error']);
		$this->assertStringContainsString('unavailable', $this->call('get_activity_summary', [], true)['error']);

		wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
		$this->assertStringContainsString('cannot use', $this->call('get_brand_activity')['error']);

		add_filter('twcp_activity_active', '__return_false', 20);
		$this->assertSame('WP Activity Log', twcp_tools_missing('get_activity_summary'));
		$this->assertArrayNotHasKey('get_brand_activity', twcp_tools_enabled());
	}

	/**
	 * Log an event the way WP Activity Log stores it
	 *
	 * @param int    $user
	 * @param int    $code    Event ID
	 * @param int    $offset  Seconds after the session start
	 * @param int    $post
	 * @param string $roles
	 * @param string $session
	 * @param array  $meta    Logged details
	 * @param array  $columns Other columns, such as client_ip and user_agent
	 *
	 * @return int Event ID
	 */
	private function log(int $user, int $code, int $offset, int $post = 0, string $roles = 'shop_manager', string $session = 'a', array $meta = [], array $columns = []): int
	{
		global $wpdb;
		$wpdb->insert($wpdb->base_prefix . 'wsal_occurrences', $columns + [
			'alert_id'   => $code,
			'created_on' => self::START + $offset,
			'user_roles' => $roles,
			'username'   => $user ? get_userdata($user)->user_login : 'System',
			'user_id'    => $user,
			'session_id' => $session,
			'post_id'    => $post,
		]);
		$id = (int) $wpdb->insert_id;

		foreach ($meta as $name => $value) {
			$wpdb->insert($wpdb->base_prefix . 'wsal_metadata', ['occurrence_id' => $id, 'name' => $name, 'value' => maybe_serialize($value)]);
		}

		return $id;
	}

	/**
	 * Event types the way WP Activity Log describes them
	 *
	 * @return array
	 */
	public static function catalogue(): array
	{
		$type = function(string $description, string $message, array $metadata, string $subcategory): array {
			return compact('description', 'message', 'metadata', 'subcategory') + ['category' => 'WooCommerce', 'object' => 'woocommerce-product', 'event_type' => 'modified', 'severity' => 'medium'];
		};

		return [
			9017 => $type('User changed the SKU of a product', 'Changed the SKU of the product <strong>%ProductTitle%</strong> to %NewSku%.', ['Product ID' => '%PostID%', 'Previous SKU' => '%OldSku%'], 'Products'),
			9001 => $type('User published a product', 'Published the product %ProductTitle%.', [], 'Products'),
			1000 => $type('User logged in', 'User logged in.', [], 'Users Logins & Sessions Events'),
			5000 => ['object' => 'plugin'] + $type('User installed a plugin', 'Installed the plugin %PluginData->Name%.', ['Version' => '%PluginData->Version%'], 'Plugins'),
		];
	}
}
