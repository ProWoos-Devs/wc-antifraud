<?php
/**
 * IP Tracking for repeat-order detection
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_IP_Tracker {

	const IP_STORE_KEY = 'wcaf_ip_store';

	/**
	 * Track order and check threshold
	 *
	 * @param string $ip
	 * @param int    $order_id
	 * @param array  $opts
	 * @return bool True if threshold exceeded
	 */
	public static function track_and_check( $ip, $order_id, $opts ) {
		if ( empty( $ip ) || empty( $order_id ) ) {
			return false;
		}
		$store     = get_option( self::IP_STORE_KEY, [] );
		$window    = intval( $opts['ip_repeat_window'] ?? 3600 );
		$threshold = intval( $opts['ip_repeat_threshold'] ?? 3 );
		$now       = time();

		if ( ! isset( $store[ $ip ] ) || ! is_array( $store[ $ip ] ) ) {
			$store[ $ip ] = [];
		}

		// Filter to recent entries
		$entries = [];
		foreach ( $store[ $ip ] as $e ) {
			if ( isset( $e['time'] ) && ( $now - intval( $e['time'] ) ) <= $window ) {
				$entries[] = $e;
			}
		}

		// Add this order if not already tracked
		$exists = false;
		foreach ( $entries as $e ) {
			if ( isset( $e['order_id'] ) && $e['order_id'] == $order_id ) {
				$exists = true;
				break;
			}
		}
		if ( ! $exists ) {
			$entries[] = [ 'order_id' => $order_id, 'time' => $now ];
		}

		$store[ $ip ] = $entries;
		update_option( self::IP_STORE_KEY, $store );

		return count( $entries ) >= $threshold;
	}

	public static function initialize() {
		if ( false === get_option( self::IP_STORE_KEY ) ) {
			update_option( self::IP_STORE_KEY, [] );
		}
	}

	/**
	 * Clean up old data
	 *
	 * @param int $max_age Seconds (default 7 days)
	 * @return int IPs cleaned
	 */
	public static function cleanup_old_data( $max_age = 604800 ) {
		$store   = get_option( self::IP_STORE_KEY, [] );
		$now     = time();
		$cleaned = 0;
		foreach ( $store as $ip => $entries ) {
			$recent = [];
			foreach ( $entries as $e ) {
				if ( isset( $e['time'] ) && ( $now - intval( $e['time'] ) ) <= $max_age ) {
					$recent[] = $e;
				}
			}
			if ( empty( $recent ) ) {
				unset( $store[ $ip ] );
				$cleaned++;
			} else {
				$store[ $ip ] = $recent;
			}
		}
		update_option( self::IP_STORE_KEY, $store );
		return $cleaned;
	}
}
