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

	/**
	 * Legacy option used through 1.9.0. It held every tracked IP in one row.
	 */
	const IP_STORE_KEY = 'wcaf_ip_store';

	/**
	 * Each IP now gets an independently expiring, hashed transient.
	 */
	const TRANSIENT_PREFIX = 'wcaf_ip_repeat_';

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
		$window    = min( DAY_IN_SECONDS, max( MINUTE_IN_SECONDS, absint( $opts['ip_repeat_window'] ?? HOUR_IN_SECONDS ) ) );
		$threshold = min( 100, max( 1, absint( $opts['ip_repeat_threshold'] ?? 3 ) ) );
		$now       = time();
		$key       = self::transient_key( $ip );
		$stored    = get_transient( $key );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		// Filter to recent entries
		$entries = [];
		foreach ( $stored as $entry ) {
			$timestamp = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			if ( $timestamp >= ( $now - $window ) && $timestamp <= $now && ! empty( $entry['order_id'] ) ) {
				$entries[] = [
					'order_id' => (int) $entry['order_id'],
					'time'     => $timestamp,
				];
			}
		}

		// Add this order if not already tracked
		$exists = false;
		foreach ( $entries as $entry ) {
			if ( (int) $entry['order_id'] === (int) $order_id ) {
				$exists = true;
				break;
			}
		}
		if ( ! $exists ) {
			$entries[] = [ 'order_id' => $order_id, 'time' => $now ];
		}

		set_transient( $key, $entries, $window );

		// Drop the old all-IPs option on first use after upgrading. Retaining its
		// aggregate history would keep the original unbounded row alive. Starting
		// fresh with this order is the safe, fail-open migration.
		if ( false !== get_option( self::IP_STORE_KEY, false ) ) {
			delete_option( self::IP_STORE_KEY );
		}

		return count( $entries ) >= $threshold;
	}

	/**
	 * @param string $ip Customer IP.
	 * @return string
	 */
	private static function transient_key( $ip ) {
		return self::TRANSIENT_PREFIX . hash( 'sha256', (string) $ip );
	}

	public static function initialize() {
		delete_option( self::IP_STORE_KEY );
	}

	/**
	 * Clean up old data
	 *
	 * Per-IP transients expire on their own. This only removes the legacy global
	 * option left by versions through 1.9.0.
	 *
	 * @param int $max_age Unused; retained for backward compatibility.
	 * @return int Whether a legacy store was removed.
	 */
	public static function cleanup_old_data( $max_age = 604800 ) {
		unset( $max_age );
		$legacy = get_option( self::IP_STORE_KEY, false );
		delete_option( self::IP_STORE_KEY );
		return is_array( $legacy ) ? count( $legacy ) : 0;
	}
}
