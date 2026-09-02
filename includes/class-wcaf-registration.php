<?php
/**
 * Registration protection.
 *
 * Fake accounts tend to come before fraud, and the registration form is a
 * scriptable endpoint like checkout. When enabled, sign-ups through the
 * WordPress and WooCommerce registration forms are refused for banned IPs,
 * for disposable email domains (when disposable blocking is on), and once an
 * IP exceeds the per-hour attempt limit. Allowlisted IPs skip every check.
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Registration {

	/**
	 * Rate-limit window.
	 */
	const WINDOW = HOUR_IN_SECONDS;

	public static function init() {
		$opts = WCAF_Helpers::get_options();
		if ( empty( $opts['enable_registration_limit'] ) ) {
			return;
		}
		add_filter( 'registration_errors', [ __CLASS__, 'validate_wp' ], 10, 3 );
		add_filter( 'woocommerce_process_registration_errors', [ __CLASS__, 'validate_woo' ], 10, 4 );
	}

	/**
	 * @param WP_Error $errors
	 * @param string   $login
	 * @param string   $email
	 * @return WP_Error
	 */
	public static function validate_wp( $errors, $login, $email ) {
		return self::validate( $errors, (string) $email );
	}

	/**
	 * @param WP_Error $errors
	 * @param string   $username
	 * @param string   $password
	 * @param string   $email
	 * @return WP_Error
	 */
	public static function validate_woo( $errors, $username, $password, $email ) {
		return self::validate( $errors, (string) $email );
	}

	/**
	 * @param WP_Error $errors
	 * @param string   $email
	 * @return WP_Error
	 */
	private static function validate( $errors, $email ) {
		if ( ! $errors instanceof WP_Error ) {
			$errors = new WP_Error();
		}
		$opts = WCAF_Helpers::get_options();
		$ip   = WCAF_Helpers::get_client_ip();

		if ( $ip && WCAF_Helpers::is_ip_allowed( $ip, $opts ) ) {
			return $errors;
		}

		$message = apply_filters(
			'wcaf_registration_block_message',
			__( 'Registration could not be completed. Please contact us if you believe this is a mistake.', 'wc-antifraud' )
		);

		if ( $ip && ( WCAF_IP_Bans::is_banned( $ip ) || WCAF_Helpers::is_ip_blocked( $ip, $opts ) ) ) {
			$errors->add( 'wcaf_registration_ip', $message );
			return $errors;
		}

		if ( '' !== $email && ! empty( $opts['enable_disposable'] ) && WCAF_Helpers::is_email_blocked( $email, $opts ) ) {
			$errors->add( 'wcaf_registration_email', __( 'This email domain is not accepted for registration.', 'wc-antifraud' ) );
			return $errors;
		}

		if ( '' !== $email && WCAF_Helpers::is_email_address_blocked( $email, $opts ) ) {
			$errors->add( 'wcaf_registration_email', $message );
			return $errors;
		}

		if ( $ip && ! WCAF_Client_IP::ip_rules_suspended() && self::over_rate_limit( $ip, (int) ( $opts['registration_max_per_hour'] ?? 10 ) ) ) {
			$errors->add( 'wcaf_registration_rate', $message );
		}

		return $errors;
	}

	/**
	 * Increment and test the per-IP attempt counter.
	 *
	 * @param string $ip
	 * @param int    $max
	 * @return bool True when over the limit for this window.
	 */
	private static function over_rate_limit( $ip, $max ) {
		$max      = max( 1, $max );
		$key      = 'wcaf_reg_' . md5( $ip );
		$attempts = (int) get_transient( $key );
		if ( $attempts >= $max ) {
			return true;
		}
		set_transient( $key, $attempts + 1, self::WINDOW );
		return false;
	}
}
