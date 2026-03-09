<?php
/**
 * Helper utilities
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Helpers {

	/**
	 * Get client IP (best-effort, Cloudflare-aware)
	 *
	 * @return string|false
	 */
	public static function get_client_ip() {
		$keys = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_CLIENT_IP',
			'REMOTE_ADDR',
		];

		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
				if ( 'HTTP_X_FORWARDED_FOR' === $k ) {
					$parts = explode( ',', $val );
					$ip    = trim( $parts[0] );
					return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : false;
				}
				$ip = trim( $val );
				return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : false;
			}
		}
		return false;
	}

	/**
	 * Proxy/VPN heuristic (Cloudflare-safe)
	 *
	 * @return bool
	 */
	public static function is_proxy_detected() {
		$suspect = [ 'HTTP_VIA', 'HTTP_X_PROXY_USER', 'HTTP_FORWARDED' ];
		foreach ( $suspect as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				return true;
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			if ( count( explode( ',', $val ) ) > 2 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check email domain against blocked domains list
	 *
	 * @param string $email
	 * @param array  $opts
	 * @return bool
	 */
	public static function is_email_blocked( $email, $opts ) {
		$email = strtolower( trim( $email ) );
		if ( empty( $email ) || false === strpos( $email, '@' ) ) {
			return false;
		}
		$domain    = substr( strrchr( $email, '@' ), 1 );
		$blocked_raw = $opts['disposable_domains'] ?? '';
		if ( empty( $blocked_raw ) ) {
			return false;
		}
		$blocked = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $blocked_raw ) ) );
		foreach ( $blocked as $d ) {
			if ( 0 === strcasecmp( $domain, $d ) ) {
				return true;
			}
			if ( false !== stripos( $d, '*' ) ) {
				$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $d, '/' ) ) . '$/i';
				if ( preg_match( $pattern, $domain ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Check full email address against blacklist
	 *
	 * @param string $email
	 * @param array  $opts
	 * @return bool
	 */
	public static function is_email_address_blocked( $email, $opts ) {
		$email = strtolower( trim( $email ) );
		if ( empty( $email ) ) {
			return false;
		}
		$blocked_raw = $opts['blocked_emails'] ?? '';
		if ( empty( $blocked_raw ) ) {
			return false;
		}
		$blocked = array_filter( array_map( 'strtolower', array_map( 'trim', preg_split( '/\r\n|\r|\n/', $blocked_raw ) ) ) );
		return in_array( $email, $blocked, true );
	}

	/**
	 * Check IP against blacklist (supports CIDR)
	 *
	 * @param string $ip
	 * @param array  $opts
	 * @return bool
	 */
	public static function is_ip_blocked( $ip, $opts ) {
		if ( empty( $ip ) ) {
			return false;
		}
		$blocked_raw = $opts['blocked_ips'] ?? '';
		if ( empty( $blocked_raw ) ) {
			return false;
		}
		$blocked = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $blocked_raw ) ) );
		$ip_long = ip2long( $ip );
		if ( false === $ip_long ) {
			return false;
		}
		foreach ( $blocked as $entry ) {
			if ( false !== strpos( $entry, '/' ) ) {
				list( $subnet, $bits ) = explode( '/', $entry, 2 );
				$bits       = intval( $bits );
				$subnet_long = ip2long( $subnet );
				if ( false !== $subnet_long && $bits >= 0 && $bits <= 32 ) {
					$mask = -1 << ( 32 - $bits );
					if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
						return true;
					}
				}
			} elseif ( $ip === $entry ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check phone against blocked patterns (supports wildcards)
	 *
	 * @param string $phone
	 * @param array  $opts
	 * @return bool
	 */
	public static function is_phone_blocked( $phone, $opts ) {
		$phone = preg_replace( '/[^\d+]/', '', $phone );
		if ( empty( $phone ) ) {
			return false;
		}
		$blocked_raw = $opts['blocked_phones'] ?? '';
		if ( empty( $blocked_raw ) ) {
			return false;
		}
		$patterns = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $blocked_raw ) ) );
		foreach ( $patterns as $p ) {
			$p_clean = preg_replace( '/[^\d+*]/', '', $p );
			if ( empty( $p_clean ) ) {
				continue;
			}
			if ( false !== strpos( $p_clean, '*' ) ) {
				$regex = '/^' . str_replace( '\*', '.*', preg_quote( $p_clean, '/' ) ) . '$/';
				if ( preg_match( $regex, $phone ) ) {
					return true;
				}
			} elseif ( $phone === $p_clean ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get plugin options with defaults
	 *
	 * @return array
	 */
	public static function get_options() {
		$defaults = WC_Antifraud::get_default_options();
		return wp_parse_args( get_option( WC_Antifraud::OPTION_KEY, [] ), $defaults );
	}

	/**
	 * Check if amount matches suspicious target
	 *
	 * @param float $amount
	 * @param float $target
	 * @param float $tolerance
	 * @return bool
	 */
	public static function is_amount_suspicious( $amount, $target, $tolerance ) {
		if ( floatval( $target ) <= 0 ) {
			return false;
		}
		return abs( floatval( $amount ) - floatval( $target ) ) < floatval( $tolerance );
	}

	/**
	 * Sanitize comma-separated email list
	 *
	 * @param string $emails
	 * @return array Valid emails
	 */
	public static function sanitize_email_list( $emails ) {
		return array_filter( array_map( 'trim', explode( ',', $emails ) ), 'is_email' );
	}
}
