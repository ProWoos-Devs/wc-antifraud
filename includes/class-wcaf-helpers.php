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
	 * Bundled disposable-domain list, relative to the plugin root.
	 */
	const DISPOSABLE_LIST_FILE = 'assets/data/disposable-domains.txt';

	/**
	 * Bundled disposable domains as a lookup map, loaded once per request.
	 *
	 * @var array|null
	 */
	private static $disposable_map = null;

	/**
	 * Check email domain against the merchant's blocked-domain list and the
	 * bundled disposable-domain list.
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
		$domain = substr( strrchr( $email, '@' ), 1 );
		if ( '' === $domain ) {
			return false;
		}

		// Merchant list first: exact matches and * wildcards.
		$blocked_raw = $opts['disposable_domains'] ?? '';
		if ( ! empty( $blocked_raw ) ) {
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
		}

		return self::is_bundled_disposable_domain( $domain );
	}

	/**
	 * Whether a domain (or one of its parent domains) is on the bundled
	 * disposable-domain list.
	 *
	 * @param string $domain Lowercase domain.
	 * @return bool
	 */
	public static function is_bundled_disposable_domain( $domain ) {
		$map = self::bundled_disposable_map();
		if ( empty( $map ) ) {
			return false;
		}
		// Walk up: mail.example.tld -> example.tld. Stop at two labels.
		$labels = explode( '.', $domain );
		while ( count( $labels ) >= 2 ) {
			if ( isset( $map[ implode( '.', $labels ) ] ) ) {
				return true;
			}
			array_shift( $labels );
		}
		return false;
	}

	/**
	 * Number of domains on the bundled list (for the settings screen).
	 *
	 * @return int
	 */
	public static function bundled_disposable_count() {
		return count( self::bundled_disposable_map() );
	}

	/**
	 * Load the bundled list into a hash map, once per request. Fails open: a
	 * missing or unreadable file means nothing is treated as disposable.
	 *
	 * @return array domain => true
	 */
	private static function bundled_disposable_map() {
		if ( null !== self::$disposable_map ) {
			return self::$disposable_map;
		}
		self::$disposable_map = [];
		$path = WCAF_PLUGIN_DIR . self::DISPOSABLE_LIST_FILE;
		if ( ! is_readable( $path ) ) {
			return self::$disposable_map;
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return self::$disposable_map;
		}
		foreach ( $lines as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '.' ) ) {
				continue;
			}
			self::$disposable_map[ $line ] = true;
		}
		return self::$disposable_map;
	}

	/**
	 * Whether the plugin is in monitor mode (flag and alert, never cancel).
	 *
	 * @param array $opts
	 * @return bool
	 */
	public static function is_monitor_mode( $opts ) {
		return 'monitor' === ( $opts['detection_mode'] ?? 'block' );
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
	 * Check an IP against a newline-separated list of IPs and IPv4 CIDR ranges.
	 *
	 * @param string $ip
	 * @param string $raw_list One entry per line.
	 * @return bool
	 */
	public static function ip_in_list( $ip, $raw_list ) {
		if ( empty( $ip ) || empty( $raw_list ) ) {
			return false;
		}
		$entries = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw_list ) ) );
		$ip_long = ip2long( $ip );
		foreach ( $entries as $entry ) {
			if ( false !== strpos( $entry, '/' ) ) {
				if ( false === $ip_long ) {
					continue; // IPv6 address against an IPv4 range.
				}
				list( $subnet, $bits ) = explode( '/', $entry, 2 );
				$bits        = intval( $bits );
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
	 * Check IP against blacklist (supports CIDR)
	 *
	 * @param string $ip
	 * @param array  $opts
	 * @return bool
	 */
	public static function is_ip_blocked( $ip, $opts ) {
		return self::ip_in_list( $ip, $opts['blocked_ips'] ?? '' );
	}

	/**
	 * Check IP against the allowlist (supports CIDR). Allowlisted IPs bypass
	 * every check and are never banned.
	 *
	 * @param string $ip
	 * @param array  $opts
	 * @return bool
	 */
	public static function is_ip_allowed( $ip, $opts ) {
		return self::ip_in_list( $ip, $opts['allowed_ips'] ?? '' );
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
	/**
	 * Whether WooCommerce's Order Attribution feature is on for this store.
	 *
	 * Every attribution-based rule (unknown origin, Store API bot) reads
	 * _wc_order_attribution_source_type, which only exists when the feature is
	 * enabled (WooCommerce > Settings > Advanced > Features). With it off, no order
	 * carries attribution, so those rules would flag every genuine order.
	 *
	 * @return bool
	 */
	public static function order_attribution_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'feature_is_enabled' ) ) {
			return (bool) \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'order_attribution' );
		}
		return 'yes' === get_option( 'woocommerce_feature_order_attribution_enabled', 'yes' );
	}

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
