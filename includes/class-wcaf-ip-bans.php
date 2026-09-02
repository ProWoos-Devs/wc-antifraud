<?php
/**
 * Temporary IP bans.
 *
 * A self-expiring ban list, separate from the merchant's permanent IP
 * blacklist. Entries are added automatically when a visitor crosses the
 * repeated-payment-failure limit (if auto-ban is enabled) and release
 * themselves after the configured duration, so a wrong guess never becomes a
 * permanent lock-out. Allowlisted, private, and invalid IPs are never banned.
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_IP_Bans {

	/**
	 * Option holding active bans: ip => [ 'expires' => ts, 'since' => ts, 'reason' => string ].
	 * Not autoloaded.
	 */
	const OPTION_KEY = 'wcaf_ip_bans';

	/**
	 * Whether an IP is currently banned.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public static function is_banned( $ip ) {
		if ( empty( $ip ) ) {
			return false;
		}
		$bans = self::active();
		return isset( $bans[ $ip ] );
	}

	/**
	 * Ban an IP for a number of minutes.
	 *
	 * @param string $ip
	 * @param int    $minutes
	 * @param string $reason
	 * @return bool True when a ban was written.
	 */
	public static function ban( $ip, $minutes, $reason = '' ) {
		if ( ! self::is_bannable( $ip ) ) {
			return false;
		}
		$minutes = max( 1, (int) $minutes );
		$bans    = self::active();
		$now     = time();

		$bans[ $ip ] = [
			'since'   => isset( $bans[ $ip ]['since'] ) ? (int) $bans[ $ip ]['since'] : $now,
			'expires' => $now + ( $minutes * MINUTE_IN_SECONDS ),
			'reason'  => sanitize_text_field( $reason ),
		];
		update_option( self::OPTION_KEY, $bans, false );
		return true;
	}

	/**
	 * Ban an IP if auto-ban is enabled in settings.
	 *
	 * @param string $ip
	 * @param array  $opts
	 * @param string $reason
	 * @return bool
	 */
	public static function maybe_auto_ban( $ip, $opts, $reason = '' ) {
		if ( empty( $opts['enable_auto_ban'] ) ) {
			return false;
		}
		if ( self::is_banned( $ip ) ) {
			return false;
		}
		return self::ban( $ip, (int) ( $opts['auto_ban_minutes'] ?? 60 ), $reason );
	}

	/**
	 * Lift a ban.
	 *
	 * @param string $ip
	 */
	public static function unban( $ip ) {
		$bans = self::active();
		unset( $bans[ $ip ] );
		update_option( self::OPTION_KEY, $bans, false );
	}

	/**
	 * Lift every ban.
	 */
	public static function clear_all() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Active bans, expired ones dropped.
	 *
	 * @return array ip => [ 'since', 'expires', 'reason' ]
	 */
	public static function active() {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$now  = time();
		$live = [];
		foreach ( $stored as $ip => $row ) {
			if ( ! is_array( $row ) || empty( $row['expires'] ) || (int) $row['expires'] <= $now ) {
				continue;
			}
			$live[ (string) $ip ] = [
				'since'   => (int) ( $row['since'] ?? $now ),
				'expires' => (int) $row['expires'],
				'reason'  => (string) ( $row['reason'] ?? '' ),
			];
		}
		if ( count( $live ) !== count( $stored ) ) {
			update_option( self::OPTION_KEY, $live, false );
		}
		return $live;
	}

	/**
	 * Public, valid, and not allowlisted.
	 *
	 * @param string $ip
	 * @return bool
	 */
	private static function is_bannable( $ip ) {
		if ( empty( $ip ) ) {
			return false;
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return ! WCAF_Helpers::is_ip_allowed( $ip, WCAF_Helpers::get_options() );
	}
}
