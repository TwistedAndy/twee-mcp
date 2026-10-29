<?php

/**
 * Stand-in for the WP Activity Log class the tracker logs its events with. It knows no event types, as if WP Activity Log
 * were not running.
 */

namespace WSAL\Controllers;

class Alert_Manager {

	public static array $events = [];

	public static function get_alerts(): array
	{
		return [];
	}

	public static function trigger_event(int $code, array $data): void
	{
		self::$events[] = [$code, $data];
	}
}
