<?php
/**
 * Daily event counters.
 *
 * One option, keyed by UTC date, holding named counters: fraud marked (and
 * by reason), monitor flags, pre-payment refusals by reason, REST blocks,
 * decline alerts, auto-bans, manual unbans, block-customer uses, orders an
 * admin un-marked. Thirty days retained. Feeds the opt-in telemetry report
 * (yesterday's bucket) and is available to the Reports tab without scanning
 * orders. Events are rare (fraud detections, admin actions), so the option
 * write per event is acceptable; never bump this on ordinary page views.
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Stats {

	const OPTION_KEY     = 'wcaf_daily_stats';
	const RETENTION_DAYS = 30;

	/**
	 * Increment a counter for today (UTC).
	 *
	 * @param string $key
	 * @param int    $n
	 */
	public static function bump( $key, $n = 1 ) {
		$key = substr( preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( (string) $key ) ), 0, 40 );
		if ( '' === $key ) {
			return;
		}
		$stats = self::load();
		$today = gmdate( 'Y-m-d' );
		if ( ! isset( $stats[ $today ] ) || ! is_array( $stats[ $today ] ) ) {
			$stats[ $today ] = [];
		}
		$stats[ $today ][ $key ] = (int) ( $stats[ $today ][ $key ] ?? 0 ) + (int) $n;
		update_option( self::OPTION_KEY, self::prune( $stats ), false );
	}

	/**
	 * Counters for one UTC date.
	 *
	 * @param string $date Y-m-d
	 * @return array key => int
	 */
	public static function day( $date ) {
		$stats = self::load();
		return isset( $stats[ $date ] ) && is_array( $stats[ $date ] ) ? array_map( 'intval', $stats[ $date ] ) : [];
	}

	/**
	 * Counters summed over the last N days including today.
	 *
	 * @param int $days
	 * @return array key => int
	 */
	public static function totals( $days ) {
		$stats  = self::load();
		$cutoff = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
		$out    = [];
		foreach ( $stats as $date => $row ) {
			if ( $date < $cutoff || ! is_array( $row ) ) {
				continue;
			}
			foreach ( $row as $k => $n ) {
				$out[ $k ] = (int) ( $out[ $k ] ?? 0 ) + (int) $n;
			}
		}
		arsort( $out );
		return $out;
	}

	public static function reset() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * @return array
	 */
	private static function load() {
		$stats = get_option( self::OPTION_KEY, [] );
		return is_array( $stats ) ? $stats : [];
	}

	/**
	 * @param array $stats
	 * @return array
	 */
	private static function prune( $stats ) {
		$cutoff = gmdate( 'Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $stats ) as $date ) {
			if ( $date < $cutoff ) {
				unset( $stats[ $date ] );
			}
		}
		return $stats;
	}
}
