<?php
/**
 * Opt-in anonymous usage reports.
 *
 * Nothing is sent until an administrator has said yes. The report is keyed by
 * a random install ID generated at consent time, never by the site URL, and
 * carries versions, feature flags, environment facts, and the previous day's
 * event counters. Never emails, IPs, order IDs, URLs, or user data. Sent once
 * a day from WP cron, never from a customer request. Withdrawing consent
 * stops the reports and deletes the install ID; "delete my data" also asks the
 * receiver to remove everything it holds for that ID.
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Telemetry {

	const ENDPOINT      = 'https://prowoos.com/wp-json/prowoos-telemetry/v1/report';
	const CRON_HOOK     = 'wcaf_send_telemetry';
	const PLUGIN_SLUG   = 'wc-antifraud';
	const STATUS_OPTION = 'wcaf_telemetry_status';

	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'send' ] );
		if ( is_admin() ) {
			add_action( 'admin_init', [ __CLASS__, 'ensure_cron' ], 4 );
			add_action( 'admin_notices', [ __CLASS__, 'consent_notice' ] );
		}
	}

	/**
	 * @return string
	 */
	public static function endpoint() {
		return (string) apply_filters( 'wcaf_telemetry_endpoint', self::ENDPOINT );
	}

	/**
	 * '' (never asked), 'yes', or 'no'.
	 *
	 * @return string
	 */
	public static function consent() {
		$opts = WCAF_Helpers::get_options();
		$c    = (string) ( $opts['telemetry_consent'] ?? '' );
		return in_array( $c, [ 'yes', 'no' ], true ) ? $c : '';
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		return 'yes' === self::consent();
	}

	/**
	 * Record the administrator's answer. Consent creates the install ID;
	 * withdrawal deletes it, so re-consenting gets a fresh one.
	 *
	 * @param bool $yes
	 */
	public static function set_consent( $yes ) {
		$opts                      = WCAF_Helpers::get_options();
		$opts['telemetry_consent'] = $yes ? 'yes' : 'no';
		if ( $yes && empty( $opts['telemetry_install_id'] ) ) {
			$opts['telemetry_install_id'] = bin2hex( random_bytes( 16 ) );
		}
		if ( ! $yes ) {
			$opts['telemetry_install_id'] = '';
		}
		update_option( WC_Antifraud::OPTION_KEY, $opts );
	}

	/**
	 * @return string 32 hex chars, or '' when no consent.
	 */
	public static function install_id() {
		$opts = WCAF_Helpers::get_options();
		$id   = (string) ( $opts['telemetry_install_id'] ?? '' );
		return preg_match( '/^[a-f0-9]{32}$/', $id ) ? $id : '';
	}

	/**
	 * Plain-language list of what a report contains (settings screen, README).
	 *
	 * @return string[]
	 */
	public static function fields() {
		return [
			__( 'A random install ID (never your site address)', 'wc-antifraud' ),
			__( 'Plugin, WordPress, WooCommerce, and PHP versions, and the site locale', 'wc-antifraud' ),
			__( 'Whether HPOS, the Block Checkout, and Order Attribution are in use', 'wc-antifraud' ),
			__( 'Which rules are switched on, and the detection mode', 'wc-antifraud' ),
			__( 'Whether Cloudflare or a proxy was detected, and whether the legacy proxy mode is on', 'wc-antifraud' ),
			__( 'Yesterday\'s event counts: orders marked as fraud by reason, monitor flags, checkouts refused by reason, REST blocks, repeated-failure alerts, auto-bans, bans lifted by hand, "Block this customer" uses, and fraud orders un-marked by an admin', 'wc-antifraud' ),
		];
	}

	/**
	 * Build the report. Keys are fixed and reviewed; add nothing here that
	 * could identify a site, an order, or a person.
	 *
	 * @return array
	 */
	public static function payload() {
		$opts      = WCAF_Helpers::get_options();
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$block_checkout = false;
		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'has_block' ) ) {
			$checkout_id    = (int) wc_get_page_id( 'checkout' );
			$block_checkout = $checkout_id > 0 && has_block( 'woocommerce/checkout', $checkout_id );
		}

		$rules = [];
		foreach ( [ 'enable_unknown_origin', 'enable_stripe_decline', 'enable_linked_fraud', 'enable_disposable', 'enable_proxy_check', 'enable_ip_repeat', 'enable_auto_ban', 'enable_registration_limit', 'enable_rest_hardening', 'enable_abuseipdb' ] as $k ) {
			$rules[ substr( $k, 7 ) ] = ! empty( $opts[ $k ] );
		}
		$rules['decline_limit']  = (int) ( $opts['decline_block_threshold'] ?? 0 ) > 0;
		$rules['allowlist']      = '' !== trim( (string) ( $opts['allowed_ips'] ?? '' ) );
		$rules['blacklists']     = '' !== trim( (string) ( $opts['blocked_emails'] ?? '' ) . ( $opts['blocked_ips'] ?? '' ) . ( $opts['blocked_phones'] ?? '' ) . ( $opts['disposable_domains'] ?? '' ) );

		return [
			'wp_version'          => (string) get_bloginfo( 'version' ),
			'wc_version'          => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'php_version'         => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'              => (string) get_locale(),
			'hpos'                => (bool) $hpos,
			'block_checkout'      => (bool) $block_checkout,
			'attribution'         => (bool) WCAF_Helpers::order_attribution_enabled(),
			'detection_mode'      => (string) ( $opts['detection_mode'] ?? 'block' ),
			'rules'               => $rules,
			'cloudflare_ranges'   => WCAF_Client_IP::cloudflare_ranges_fetched_at() > 0,
			'trusted_proxies'     => '' !== trim( (string) ( $opts['trusted_proxies'] ?? '' ) ),
			'legacy_proxy_mode'   => ! empty( $opts['trust_all_proxy_headers'] ),
			'proxy_suspect'       => WCAF_Client_IP::ip_rules_suspended(),
			'counts_date'         => $yesterday,
			'counts'              => WCAF_Stats::day( $yesterday ),
		];
	}

	/**
	 * Send today's report. Cron only, or the "Send now" link.
	 *
	 * @return bool|null Null when consent is absent, else success.
	 */
	public static function send() {
		if ( ! self::enabled() ) {
			return null;
		}
		$id = self::install_id();
		if ( '' === $id ) {
			return false;
		}
		$response = wp_remote_post( self::endpoint(), [
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'install_id' => $id,
				'plugin'     => self::PLUGIN_SLUG,
				'version'    => WCAF_VERSION,
				'payload'    => self::payload(),
			] ),
		] );
		$status = [ 'last_sent' => time(), 'last_code' => 0, 'last_error' => '' ];
		if ( is_wp_error( $response ) ) {
			$status['last_error'] = $response->get_error_message();
			update_option( self::STATUS_OPTION, $status, false );
			return false;
		}
		$status['last_code'] = (int) wp_remote_retrieve_response_code( $response );
		update_option( self::STATUS_OPTION, $status, false );
		return 200 === $status['last_code'];
	}

	/**
	 * Ask the receiver to forget this install, then withdraw consent locally.
	 *
	 * @return bool Whether the receiver confirmed the deletion.
	 */
	public static function delete_remote() {
		$id = self::install_id();
		$ok = false;
		if ( '' !== $id ) {
			$response = wp_remote_post( self::endpoint(), [
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'install_id' => $id, 'plugin' => self::PLUGIN_SLUG, 'delete' => true ] ),
			] );
			$ok = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
		}
		self::set_consent( false );
		delete_option( self::STATUS_OPTION );
		return $ok;
	}

	/**
	 * @return array last_sent, last_code, last_error
	 */
	public static function status() {
		$s = get_option( self::STATUS_OPTION, [] );
		return is_array( $s ) ? wp_parse_args( $s, [ 'last_sent' => 0, 'last_code' => 0, 'last_error' => '' ] ) : [ 'last_sent' => 0, 'last_code' => 0, 'last_error' => '' ];
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * One-time question, shown until answered either way.
	 */
	public static function consent_notice() {
		if ( '' !== self::consent() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$yes = wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=notifications&wcaf_action=telemetry_yes' ), WCAF_Settings::ACTION_NONCE );
		$no  = wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=notifications&wcaf_action=telemetry_no' ), WCAF_Settings::ACTION_NONCE );
		$items = '';
		foreach ( self::fields() as $f ) {
			$items .= '<li>' . esc_html( $f ) . '</li>';
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s</p><ul style="list-style:disc;margin-left:20px;">%s</ul><p>%s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
			esc_html__( 'WC Antifraud: help improve the plugin?', 'wc-antifraud' ),
			esc_html__( 'Once a day the plugin can send an anonymous report to prowoos.com. It contains only:', 'wc-antifraud' ),
			$items, // already escaped
			esc_html__( 'Never emails, IP addresses, order details, your site address, or any user data. You can change your mind or delete the data at any time under Antifraud > Notifications > Privacy.', 'wc-antifraud' ),
			esc_url( $yes ),
			esc_html__( 'Allow anonymous usage data', 'wc-antifraud' ),
			esc_url( $no ),
			esc_html__( 'No thanks', 'wc-antifraud' )
		);
	}
}
