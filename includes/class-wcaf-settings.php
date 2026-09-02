<?php
/**
 * Admin Settings
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Settings {

	/**
	 * Nonce action for the small admin links (unban, clear bans, reset the
	 * decline alert) and the self-test AJAX call.
	 */
	const ACTION_NONCE = 'wcaf_admin_action';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_admin_actions' ] );
		add_action( 'admin_notices', [ __CLASS__, 'action_admin_notice' ] );
		add_action( 'admin_notices', [ __CLASS__, 'proxy_suspect_notice' ] );
		add_filter( 'plugin_action_links_' . WCAF_PLUGIN_BASENAME, [ __CLASS__, 'add_action_links' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_wcaf_self_test', [ __CLASS__, 'ajax_self_test' ] );
	}

	public static function add_menu_page() {
		add_menu_page(
			__( 'WC Antifraud', 'wc-antifraud' ),
			__( 'Antifraud', 'wc-antifraud' ),
			'manage_woocommerce',
			'wc-antifraud',
			[ __CLASS__, 'render_page' ],
			'dashicons-shield-alt',
			56
		);
	}

	public static function add_action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=wc-antifraud' ) ), __( 'Settings', 'wc-antifraud' ) ) );
		return $links;
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_wc-antifraud' !== $hook ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', self::get_css() );
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery-core', self::get_js() );
	}

	// ── Tabs ──────────────────────────────────────────────────────────

	private static function get_current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'detection';
	}

	private static function get_tabs() {
		return [
			'detection'     => __( 'Detection Rules', 'wc-antifraud' ),
			'blacklists'    => __( 'Lists', 'wc-antifraud' ),
			'notifications' => __( 'Notifications', 'wc-antifraud' ),
			'activity'      => __( 'Activity Log', 'wc-antifraud' ),
			'reports'       => __( 'Reports', 'wc-antifraud' ),
		];
	}

	// ── Registration ──────────────────────────────────────────────────

	public static function register_settings() {
		register_setting( 'wcaf_group', WC_Antifraud::OPTION_KEY, [ __CLASS__, 'sanitize' ] );
		$tab = self::get_current_tab();

		if ( 'detection' === $tab ) {
			self::register_detection_fields();
		} elseif ( 'blacklists' === $tab ) {
			self::register_blacklist_fields();
		} elseif ( 'notifications' === $tab ) {
			self::register_notification_fields();
		} elseif ( 'reports' === $tab ) {
			self::register_reports_fields();
		}
	}

	private static function register_detection_fields() {
		add_settings_section( 'wcaf_mode', __( 'Detection Mode', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'What happens to an order that matches a post-payment fraud rule.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'detection_mode', __( 'Mode', 'wc-antifraud' ), [ __CLASS__, 'field_detection_mode' ], 'wc-antifraud', 'wcaf_mode' );

		add_settings_section( 'wcaf_origin', __( 'Origin Verification', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Block orders that bypass the normal checkout flow (bots, API abuse).', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_unknown_origin', __( 'Unknown origin blocking', 'wc-antifraud' ), [ __CLASS__, 'field_unknown_origin' ], 'wc-antifraud', 'wcaf_origin' );

		add_settings_section( 'wcaf_gateway', __( 'Gateway Fraud Signals', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Act on fraud signals reported by the payment gateway itself.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_stripe_decline', __( 'Stripe fraud-decline tagging', 'wc-antifraud' ), [ __CLASS__, 'field_stripe_decline' ], 'wc-antifraud', 'wcaf_gateway' );

		add_settings_section( 'wcaf_declines', __( 'Repeated Payment Failures', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Card testing is defined by declines: a shopper fails once or twice, a bot working through stolen card numbers fails over and over from one checkout session or one IP. Failed payments are counted per visitor over a rolling 24 hours. From 5 failures an admin notice appears. Optionally, further checkouts from that visitor are refused before they reach the gateway.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'decline_block_threshold', __( 'Refuse checkout after', 'wc-antifraud' ), [ __CLASS__, 'field_decline_threshold' ], 'wc-antifraud', 'wcaf_declines' );
		add_settings_field( 'enable_auto_ban', __( 'Auto-ban repeat offenders', 'wc-antifraud' ), [ __CLASS__, 'field_auto_ban' ], 'wc-antifraud', 'wcaf_declines' );
		add_settings_field( 'auto_ban_minutes', __( 'Auto-ban duration', 'wc-antifraud' ), [ __CLASS__, 'field_auto_ban_minutes' ], 'wc-antifraud', 'wcaf_declines' );

		add_settings_section( 'wcaf_amount', __( 'Suspicious Amount', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Flag orders matching a known fraudulent amount pattern.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'target_amount', __( 'Target fraud amount', 'wc-antifraud' ), [ __CLASS__, 'field_target_amount' ], 'wc-antifraud', 'wcaf_amount' );
		add_settings_field( 'amount_tolerance', __( 'Amount tolerance', 'wc-antifraud' ), [ __CLASS__, 'field_tolerance' ], 'wc-antifraud', 'wcaf_amount' );

		add_settings_section( 'wcaf_rate', __( 'Rate Limiting', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Detect rapid-fire order attempts from the same source.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_ip_repeat', __( 'IP repeat-attempt blocking', 'wc-antifraud' ), [ __CLASS__, 'field_ip_repeat' ], 'wc-antifraud', 'wcaf_rate' );
		add_settings_field( 'ip_repeat_threshold', __( 'IP repeat threshold', 'wc-antifraud' ), [ __CLASS__, 'field_ip_threshold' ], 'wc-antifraud', 'wcaf_rate' );
		add_settings_field( 'ip_repeat_window', __( 'IP repeat window', 'wc-antifraud' ), [ __CLASS__, 'field_ip_window' ], 'wc-antifraud', 'wcaf_rate' );

		add_settings_section( 'wcaf_registration', __( 'Registration Protection', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Fake accounts tend to come before fraud. Applies to the WordPress and WooCommerce registration forms.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_registration_limit', __( 'Protect registration', 'wc-antifraud' ), [ __CLASS__, 'field_registration_limit' ], 'wc-antifraud', 'wcaf_registration' );
		add_settings_field( 'registration_max_per_hour', __( 'Max sign-ups per IP per hour', 'wc-antifraud' ), [ __CLASS__, 'field_registration_max' ], 'wc-antifraud', 'wcaf_registration' );

		add_settings_section( 'wcaf_heuristics', __( 'Heuristics', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Advanced detection that may produce false positives. Test on staging first.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_proxy_check', __( 'VPN/Proxy detection', 'wc-antifraud' ), [ __CLASS__, 'field_proxy' ], 'wc-antifraud', 'wcaf_heuristics' );

		// REST API section
		add_settings_section( 'wcaf_rest', __( 'REST API Protection', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Block bots from creating orders via the WooCommerce REST/Store API without a valid checkout session.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_rest_hardening', __( 'REST API hardening', 'wc-antifraud' ), [ __CLASS__, 'field_rest_hardening' ], 'wc-antifraud', 'wcaf_rest' );
		add_settings_field( 'rest_self_test', __( 'Test the protection', 'wc-antifraud' ), [ __CLASS__, 'field_self_test' ], 'wc-antifraud', 'wcaf_rest' );
	}

	private static function register_blacklist_fields() {
		add_settings_section( 'wcaf_allow', __( 'Allowlist', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'IP addresses that bypass every check, are never flagged, and are never banned. For staging servers, headless front ends, and your own testing.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'allowed_ips', __( 'Allowed IP addresses', 'wc-antifraud' ), [ __CLASS__, 'field_allowed_ips' ], 'wc-antifraud', 'wcaf_allow' );

		add_settings_section( 'wcaf_proxies', __( 'Trusted Proxies', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'How the plugin decides which address is the customer. The connecting address is the customer unless it belongs to a proxy the plugin trusts: Cloudflare (ranges refreshed daily), a proxy on this host (private address, detected automatically), or a proxy you declare below. Forwarding headers from anyone else are ignored, because any client can type them.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'trusted_proxies', __( 'Trusted proxy addresses', 'wc-antifraud' ), [ __CLASS__, 'field_trusted_proxies' ], 'wc-antifraud', 'wcaf_proxies' );
		add_settings_field( 'trust_all_proxy_headers', __( 'Legacy mode', 'wc-antifraud' ), [ __CLASS__, 'field_trust_all_proxy_headers' ], 'wc-antifraud', 'wcaf_proxies' );
		add_settings_field( 'proxy_diagnostic', __( 'This request', 'wc-antifraud' ), [ __CLASS__, 'field_proxy_diagnostic' ], 'wc-antifraud', 'wcaf_proxies' );

		add_settings_section( 'wcaf_bl', __( 'Blacklists', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Manually block specific emails, IPs, or phone numbers. One entry per line. On the order screen, the Actions dropdown has "Block this customer", which adds the order\'s email and IP here.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'blocked_emails', __( 'Blocked email addresses', 'wc-antifraud' ), [ __CLASS__, 'field_blocked_emails' ], 'wc-antifraud', 'wcaf_bl' );
		add_settings_field( 'enable_disposable', __( 'Disposable email blocking', 'wc-antifraud' ), [ __CLASS__, 'field_disposable' ], 'wc-antifraud', 'wcaf_bl' );
		add_settings_field( 'disposable_domains', __( 'Blocked email domains', 'wc-antifraud' ), [ __CLASS__, 'field_domains' ], 'wc-antifraud', 'wcaf_bl' );
		add_settings_field( 'blocked_ips', __( 'Blocked IP addresses', 'wc-antifraud' ), [ __CLASS__, 'field_blocked_ips' ], 'wc-antifraud', 'wcaf_bl' );
		add_settings_field( 'blocked_phones', __( 'Blocked phone patterns', 'wc-antifraud' ), [ __CLASS__, 'field_blocked_phones' ], 'wc-antifraud', 'wcaf_bl' );
	}

	private static function register_notification_fields() {
		add_settings_section( 'wcaf_notif', '', function () {
			echo '<p>' . esc_html__( 'Configure who gets notified when fraud is detected.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'email_recipients', __( 'Alert email recipients', 'wc-antifraud' ), [ __CLASS__, 'field_recipients' ], 'wc-antifraud', 'wcaf_notif' );
	}

	/**
	 * Reports-tab fields (AbuseIPDB community reporting), registered on a
	 * dedicated page slug so the form at the bottom of the Reports tab
	 * renders only this section.
	 */
	private static function register_reports_fields() {
		add_settings_section( 'wcaf_abuseipdb', __( 'AbuseIPDB Reporting', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Report the IPs behind fraud orders to the AbuseIPDB community database (abuseipdb.com), helping other stores and firewalls block the same attackers. Reports contain only the IP, the detection reasons, and the order timestamp — never customer data.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud-reports' );
		add_settings_field( 'enable_abuseipdb', __( 'Report fraud IPs to AbuseIPDB', 'wc-antifraud' ), [ __CLASS__, 'field_enable_abuseipdb' ], 'wc-antifraud-reports', 'wcaf_abuseipdb' );
		add_settings_field( 'abuseipdb_api_key', __( 'AbuseIPDB API key', 'wc-antifraud' ), [ __CLASS__, 'field_abuseipdb_api_key' ], 'wc-antifraud-reports', 'wcaf_abuseipdb' );
	}

	// ── Sanitize ──────────────────────────────────────────────────────

	public static function sanitize( $input ) {
		$existing = WCAF_Helpers::get_options();
		$output   = $existing;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tab = isset( $_POST['wcaf_current_tab'] ) ? sanitize_key( $_POST['wcaf_current_tab'] ) : '';

		// Not a settings-form submission: a programmatic update_option() call
		// (the "Block this customer" order action, activation defaults, WP-CLI).
		// register_setting() routes those through this callback too, and the
		// tab-scoped merging below would discard the new value entirely.
		if ( '' === $tab ) {
			return is_array( $input ) ? $input : $existing;
		}

		if ( 'detection' === $tab ) {
			$output['detection_mode']            = ( isset( $input['detection_mode'] ) && 'monitor' === $input['detection_mode'] ) ? 'monitor' : 'block';
			$output['enable_unknown_origin']     = ! empty( $input['enable_unknown_origin'] ) ? 1 : 0;
			$output['enable_stripe_decline']     = ! empty( $input['enable_stripe_decline'] ) ? 1 : 0;
			$output['enable_proxy_check']        = ! empty( $input['enable_proxy_check'] ) ? 1 : 0;
			$output['enable_ip_repeat']          = ! empty( $input['enable_ip_repeat'] ) ? 1 : 0;
			$output['enable_rest_hardening']     = ! empty( $input['enable_rest_hardening'] ) ? 1 : 0;
			$output['enable_auto_ban']           = ! empty( $input['enable_auto_ban'] ) ? 1 : 0;
			$output['enable_registration_limit'] = ! empty( $input['enable_registration_limit'] ) ? 1 : 0;
			if ( isset( $input['target_amount'] ) )    { $output['target_amount']    = floatval( $input['target_amount'] ); }
			if ( isset( $input['amount_tolerance'] ) )  { $output['amount_tolerance']  = floatval( $input['amount_tolerance'] ); }
			if ( isset( $input['ip_repeat_threshold'] ) ) { $output['ip_repeat_threshold'] = absint( $input['ip_repeat_threshold'] ); }
			if ( isset( $input['ip_repeat_window'] ) )    { $output['ip_repeat_window']    = absint( $input['ip_repeat_window'] ); }
			if ( isset( $input['decline_block_threshold'] ) ) {
				$threshold = absint( $input['decline_block_threshold'] );
				$output['decline_block_threshold'] = $threshold > 0 ? max( WCAF_Decline_Clusters::MIN_BLOCK_THRESHOLD, $threshold ) : 0;
			}
			if ( isset( $input['auto_ban_minutes'] ) )          { $output['auto_ban_minutes']          = max( 5, absint( $input['auto_ban_minutes'] ) ); }
			if ( isset( $input['registration_max_per_hour'] ) ) { $output['registration_max_per_hour'] = max( 1, absint( $input['registration_max_per_hour'] ) ); }
			if ( $output['enable_auto_ban'] && 0 === (int) $output['decline_block_threshold'] ) {
				add_settings_error( 'enable_auto_ban', 'auto_ban_no_limit', __( 'Auto-ban is enabled but no failure limit is set, so it will never trigger. Set "Refuse checkout after" to a number of failures.', 'wc-antifraud' ), 'warning' );
			}
		}

		if ( 'blacklists' === $tab ) {
			$output['enable_disposable']  = ! empty( $input['enable_disposable'] ) ? 1 : 0;
			$output['disposable_domains'] = isset( $input['disposable_domains'] ) ? sanitize_textarea_field( $input['disposable_domains'] ) : '';
			$output['allowed_ips']        = isset( $input['allowed_ips'] ) ? sanitize_textarea_field( $input['allowed_ips'] ) : '';
			$output['trusted_proxies']    = isset( $input['trusted_proxies'] ) ? sanitize_textarea_field( $input['trusted_proxies'] ) : '';
			$output['trust_all_proxy_headers'] = ! empty( $input['trust_all_proxy_headers'] ) ? 1 : 0;
			if ( $output['trust_all_proxy_headers'] ) {
				add_settings_error( 'trust_all_proxy_headers', 'legacy_proxy_mode', __( 'Legacy mode is on: forwarding headers from any client are trusted, so IP-based rules can be evaded or misdirected by a bot that forges them. Declare your proxy instead and turn this off.', 'wc-antifraud' ), 'warning' );
			}
			$output['blocked_emails']     = isset( $input['blocked_emails'] ) ? sanitize_textarea_field( $input['blocked_emails'] ) : '';
			$output['blocked_ips']        = isset( $input['blocked_ips'] ) ? sanitize_textarea_field( $input['blocked_ips'] ) : '';
			$output['blocked_phones']     = isset( $input['blocked_phones'] ) ? sanitize_textarea_field( $input['blocked_phones'] ) : '';
		}

		if ( 'notifications' === $tab ) {
			$output['email_recipients'] = isset( $input['email_recipients'] ) ? sanitize_text_field( $input['email_recipients'] ) : '';
			if ( ! empty( $output['email_recipients'] ) && empty( WCAF_Helpers::sanitize_email_list( $output['email_recipients'] ) ) ) {
				add_settings_error( 'email_recipients', 'invalid_emails', __( 'Please provide valid email addresses.', 'wc-antifraud' ), 'error' );
			}
		}

		if ( 'reports' === $tab ) {
			$output['enable_abuseipdb']  = ! empty( $input['enable_abuseipdb'] ) ? 1 : 0;
			$output['abuseipdb_api_key'] = isset( $input['abuseipdb_api_key'] ) ? sanitize_text_field( $input['abuseipdb_api_key'] ) : '';
			if ( $output['enable_abuseipdb'] && empty( $output['abuseipdb_api_key'] ) ) {
				add_settings_error( 'abuseipdb_api_key', 'missing_abuseipdb_key', __( 'AbuseIPDB reporting is enabled but no API key is set — no reports will be sent until you add one.', 'wc-antifraud' ), 'warning' );
			}
		}

		return $output;
	}

	// ── Admin link actions (unban, clear bans, reset decline alert) ───

	public static function handle_admin_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! isset( $_GET['wcaf_action'] ) || ! isset( $_GET['page'] ) || 'wc-antifraud' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( self::ACTION_NONCE );

		$action = sanitize_key( wp_unslash( $_GET['wcaf_action'] ) );
		$notice = '';

		if ( 'unban' === $action && ! empty( $_GET['ip'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_GET['ip'] ) );
			WCAF_IP_Bans::unban( $ip );
			$notice = 'unbanned';
		} elseif ( 'clear_bans' === $action ) {
			WCAF_IP_Bans::clear_all();
			$notice = 'bans_cleared';
		} elseif ( 'reset_declines' === $action ) {
			WCAF_Decline_Clusters::reset();
			$notice = 'declines_reset';
		} elseif ( 'trust_proxy' === $action ) {
			WCAF_Client_IP::trust_suspect();
			$notice = 'proxy_trusted';
		} elseif ( 'dismiss_proxy' === $action ) {
			WCAF_Client_IP::dismiss_suspect();
			$notice = 'proxy_dismissed';
		} elseif ( 'refresh_cf' === $action ) {
			$notice = WCAF_Client_IP::refresh_cloudflare_ranges() ? 'cf_refreshed' : 'cf_refresh_failed';
		}

		$redirect = remove_query_arg( [ 'wcaf_action', 'ip', '_wpnonce' ] );
		wp_safe_redirect( add_query_arg( 'wcaf_notice', $notice, $redirect ) );
		exit;
	}

	public static function action_admin_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['wcaf_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = sanitize_key( wp_unslash( $_GET['wcaf_notice'] ) );
		$messages = [
			'unbanned'       => __( 'The ban was lifted.', 'wc-antifraud' ),
			'bans_cleared'   => __( 'All temporary bans were lifted.', 'wc-antifraud' ),
			'declines_reset' => __( 'The repeated-failure counters were cleared.', 'wc-antifraud' ),
			'proxy_trusted'  => __( 'The proxy was added to Trusted proxy addresses. IP-based rules now see your customers\' real addresses.', 'wc-antifraud' ),
			'proxy_dismissed' => __( 'Noted. That address will not be reported as a proxy for 30 days.', 'wc-antifraud' ),
			'cf_refreshed'   => __( 'Cloudflare IP ranges refreshed.', 'wc-antifraud' ),
			'cf_refresh_failed' => __( 'Cloudflare IP ranges could not be fetched; the previous set is still in use.', 'wc-antifraud' ),
		];
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		$type = 'cf_refresh_failed' === $key ? 'warning' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $messages[ $key ] ) );
	}

	/**
	 * Persistent notice while an undeclared public-address proxy is suspected
	 * and the automatic IP rules are suspended because of it.
	 */
	public static function proxy_suspect_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! WCAF_Client_IP::ip_rules_suspended() ) {
			return;
		}
		$s = WCAF_Client_IP::suspect();
		if ( null === $s ) {
			return;
		}
		$trust   = wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=blacklists&wcaf_action=trust_proxy' ), self::ACTION_NONCE );
		$dismiss = wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=blacklists&wcaf_action=dismiss_proxy' ), self::ACTION_NONCE );
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>',
			esc_html__( 'WC Antifraud: a proxy seems to sit in front of this site.', 'wc-antifraud' ),
			esc_html( sprintf(
				/* translators: 1: proxy address, 2: forwarded address */
				__( 'Requests keep arriving from %1$s with a forwarding header naming another address (last seen: %2$s). Until you confirm that %1$s is your proxy, the automatic IP rules (auto-ban, repeated-failure IP counting, registration rate limit, IP repeat) are paused, because every customer would otherwise look like that one address. Everything else keeps working.', 'wc-antifraud' ),
				$s['ip'],
				$s['forwarded']
			) ),
			esc_url( $trust ),
			esc_html( sprintf( __( 'Yes, %s is my proxy', 'wc-antifraud' ), $s['ip'] ) ),
			esc_url( $dismiss ),
			esc_html__( 'No, that is not a proxy', 'wc-antifraud' )
		);
	}

	// ── Self-test (REST hardening) ────────────────────────────────────

	/**
	 * Fire the request a card-testing bot sends, a Store API checkout POST with
	 * no nonce, at this store, and report who stopped it. No order is created:
	 * the request is rejected at the gate, and even if it were not, the empty
	 * cart of a fresh server-side session cannot become an order.
	 */
	public static function ajax_self_test() {
		check_ajax_referer( self::ACTION_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wc-antifraud' ) ], 403 );
		}

		$opts     = WCAF_Helpers::get_options();
		$response = wp_remote_post(
			rest_url( 'wc/store/v1/checkout' ),
			[
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => wp_json_encode( [
					'billing_address' => [
						'first_name' => 'Antifraud',
						'last_name'  => 'Selftest',
						'email'      => 'selftest@example.com',
						'country'    => 'US',
					],
					'payment_method'  => 'wcaf-selftest',
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: transport error */
					__( 'The test request could not reach the store from the server (%s). That is usually the host blocking loopback requests, not a protection problem.', 'wc-antifraud' ),
					$response->get_error_message()
				),
			] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$slug = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';

		if ( 403 === $code && WCAF_REST_Hardening::ERROR_CODE === $slug ) {
			wp_send_json_success( [
				'message' => __( 'Blocked by WC Antifraud REST hardening (HTTP 403). A checkout POST with no session nonce was refused before WooCommerce processed it.', 'wc-antifraud' ),
			] );
		}

		if ( $code >= 400 ) {
			$hint = empty( $opts['enable_rest_hardening'] )
				? __( 'REST API hardening is switched off in this plugin.', 'wc-antifraud' )
				: __( 'WooCommerce\'s own check ran before this plugin\'s. The request was still stopped.', 'wc-antifraud' );
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: 1: HTTP status, 2: error code, 3: hint */
					__( 'Rejected with HTTP %1$d (%2$s), but not by WC Antifraud. %3$s', 'wc-antifraud' ),
					$code,
					$slug ?: __( 'no error code', 'wc-antifraud' ),
					$hint
				),
			] );
		}

		wp_send_json_error( [
			'message' => sprintf(
				/* translators: %d: HTTP status */
				__( 'Not blocked (HTTP %d). Check that REST API hardening is enabled and saved, then run the test again.', 'wc-antifraud' ),
				$code
			),
		] );
	}

	// ── Page render ───────────────────────────────────────────────────

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-antifraud' ) );
		}
		$tab  = self::get_current_tab();
		$tabs = self::get_tabs();
		?>
		<div class="wrap">
			<div class="wcaf-header">
				<span class="dashicons dashicons-shield-alt wcaf-header-icon"></span>
				<div>
					<h1><?php esc_html_e( 'WC Antifraud', 'wc-antifraud' ); ?></h1>
					<span class="wcaf-version"><?php printf( esc_html__( 'Version %s', 'wc-antifraud' ), esc_html( WCAF_VERSION ) ); ?></span>
				</div>
			</div>

			<nav class="nav-tab-wrapper wcaf-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-antifraud&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php settings_errors(); ?>

			<?php if ( 'activity' === $tab ) : ?>
				<?php self::render_activity_log(); ?>
			<?php elseif ( 'reports' === $tab ) : ?>
				<?php self::render_reports(); ?>
			<?php else : ?>
				<?php if ( 'detection' === $tab && WCAF_Helpers::is_monitor_mode( WCAF_Helpers::get_options() ) ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'Monitor mode is on: suspicious orders are flagged and reported but never cancelled.', 'wc-antifraud' ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="options.php">
					<input type="hidden" name="wcaf_current_tab" value="<?php echo esc_attr( $tab ); ?>" />
					<?php settings_fields( 'wcaf_group' ); ?>
					<?php do_settings_sections( 'wc-antifraud' ); ?>
					<?php submit_button( __( 'Save Settings', 'wc-antifraud' ) ); ?>
				</form>
				<?php if ( 'blacklists' === $tab ) : ?>
					<?php self::render_bans(); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Temporary bans ────────────────────────────────────────────────

	private static function render_bans() {
		$bans = WCAF_IP_Bans::active();
		?>
		<div class="wcaf-card">
			<h3><?php esc_html_e( 'Temporary bans', 'wc-antifraud' ); ?></h3>
			<p class="description"><?php esc_html_e( 'IPs banned automatically after repeated payment failures. Each ban lifts itself when it expires.', 'wc-antifraud' ); ?></p>
			<?php if ( empty( $bans ) ) : ?>
				<p><?php esc_html_e( 'No active bans.', 'wc-antifraud' ); ?></p>
			<?php else : ?>
				<table class="wcaf-table">
					<thead><tr>
						<th><?php esc_html_e( 'IP', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Banned', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'wc-antifraud' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $bans as $ip => $ban ) : ?>
						<tr>
							<td><code><?php echo esc_html( $ip ); ?></code></td>
							<td><?php echo esc_html( $ban['reason'] ?: '—' ); ?></td>
							<td><?php echo esc_html( date_i18n( 'M j, Y g:i a', $ban['since'] ) ); ?></td>
							<td><?php echo esc_html( date_i18n( 'M j, Y g:i a', $ban['expires'] ) ); ?></td>
							<td><a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=blacklists&wcaf_action=unban&ip=' . rawurlencode( $ip ) ), self::ACTION_NONCE ) ); ?>"><?php esc_html_e( 'Unban', 'wc-antifraud' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=blacklists&wcaf_action=clear_bans' ), self::ACTION_NONCE ) ); ?>"><?php esc_html_e( 'Lift all bans', 'wc-antifraud' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Activity Log ──────────────────────────────────────────────────

	private static function render_activity_log() {
		// Use direct SQL to avoid WC object cache returning stale/wrong status orders.
		global $wpdb;
		$fraud_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order'
			 AND post_status IN ('fraud-auto-cancelled','fraud-stripe')
			 ORDER BY post_date DESC
			 LIMIT 50"
		);
		// Orders flagged in monitor mode keep their normal status, so they are
		// found through the flag meta instead.
		$flagged_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'yes'
			 WHERE p.post_type = 'shop_order'
			 AND p.post_status NOT IN ('trash','fraud-auto-cancelled','fraud-stripe')
			 ORDER BY p.post_date DESC
			 LIMIT 50",
			WCAF_Order_Status::MONITOR_FLAG_META
		) );
		$orders = array_filter( array_map( 'wc_get_order', array_unique( array_merge( $fraud_ids, $flagged_ids ) ) ) );
		usort( $orders, function ( $a, $b ) {
			$da = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
			$db = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
			return $db <=> $da;
		} );
		$orders = array_slice( $orders, 0, 50 );
		?>
		<div class="wcaf-card">
			<h3><?php esc_html_e( 'Recent Fraud Detections', 'wc-antifraud' ); ?></h3>
			<?php if ( empty( $orders ) ) : ?>
				<p><?php esc_html_e( 'No fraud detections recorded yet.', 'wc-antifraud' ); ?></p>
			<?php else : ?>
				<table class="wcaf-table">
					<thead><tr>
						<th><?php esc_html_e( 'Order', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Date', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Email', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Total', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'IP', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Action', 'wc-antifraud' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'wc-antifraud' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $orders as $order ) :
						$reason = '';
						foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id(), 'type' => 'internal' ] ) as $note ) {
							if ( preg_match( '/Reasons:\s*(.+)$/i', $note->content, $m ) ) { $reason = $m[1]; break; }
						}
						$is_fraud = WCAF_Order_Status::is_fraud_order( $order );
					?>
						<tr>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_id() ); ?></a></td>
							<td><?php $d = $order->get_date_created(); echo $d ? esc_html( $d->date_i18n( 'M j, Y g:i a' ) ) : '—'; ?></td>
							<td><?php echo esc_html( $order->get_billing_email() ); ?></td>
							<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
							<td><?php echo esc_html( $order->get_customer_ip_address() ?: '—' ); ?></td>
							<td><?php echo $is_fraud ? esc_html__( 'Cancelled', 'wc-antifraud' ) : '<span class="wcaf-monitor">' . esc_html__( 'Flagged (monitor)', 'wc-antifraud' ) . '</span>'; ?></td>
							<td><span class="wcaf-fraud"><?php echo esc_html( $reason ?: '—' ); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Reports ───────────────────────────────────────────────────────

	private static function render_reports() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$period     = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '30';
		$date_after = gmdate( 'Y-m-d', strtotime( "-{$period} days" ) );

		$periods = [ '7' => __( 'Last 7 days', 'wc-antifraud' ), '30' => __( 'Last 30 days', 'wc-antifraud' ), '90' => __( 'Last 90 days', 'wc-antifraud' ), '365' => __( 'Last year', 'wc-antifraud' ) ];

		global $wpdb;
		$date_sql = $wpdb->prepare( '%s', $date_after . ' 00:00:00' );

		$fraud_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('fraud-auto-cancelled','fraud-stripe') AND post_date >= {$date_sql}" );
		$legit_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('wc-processing','wc-completed','wc-on-hold') AND post_date >= {$date_sql}" );
		$failed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('wc-failed','wc-cancelled') AND post_date >= {$date_sql}" );
		$total_count  = $fraud_count + $legit_count + $failed_count;

		// Get fraud order details via direct SQL (avoids WC object cache returning wrong orders)
		$fraud_order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order'
			 AND post_status IN ('fraud-auto-cancelled','fraud-stripe')
			 AND post_date >= %s
			 ORDER BY post_date DESC
			 LIMIT 100",
			$date_after . ' 00:00:00'
		) );
		$fraud_orders = array_filter( array_map( 'wc_get_order', $fraud_order_ids ) );
		$reason_counts = $fraud_emails = $fraud_ips = [];
		foreach ( $fraud_orders as $order ) {
			$e = $order->get_billing_email();
			if ( $e ) { $fraud_emails[ $e ] = ( $fraud_emails[ $e ] ?? 0 ) + 1; }
			$ip = $order->get_customer_ip_address();
			if ( $ip ) { $fraud_ips[ $ip ] = ( $fraud_ips[ $ip ] ?? 0 ) + 1; }
			foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id(), 'type' => 'internal' ] ) as $note ) {
				if ( preg_match( '/Reasons:\s*(.+)$/i', $note->content, $m ) ) {
					foreach ( array_map( 'trim', explode( ',', $m[1] ) ) as $r ) { $reason_counts[ $r ] = ( $reason_counts[ $r ] ?? 0 ) + 1; }
					break;
				}
			}
		}
		arsort( $reason_counts ); arsort( $fraud_emails ); arsort( $fraud_ips );
		?>
		<div style="margin-bottom:16px;">
			<?php foreach ( $periods as $v => $l ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-antifraud&tab=reports&period=' . $v ) ); ?>" class="button <?php echo $period === $v ? 'button-primary' : ''; ?>"><?php echo esc_html( $l ); ?></a>
			<?php endforeach; ?>
		</div>

		<div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
			<?php
			$cards = [
				[ $legit_count, __( 'Legitimate Orders', 'wc-antifraud' ), '#00a32a' ],
				[ $fraud_count, __( 'Fraud Blocked', 'wc-antifraud' ), '#d63638' ],
				[ $failed_count, __( 'Failed / Cancelled', 'wc-antifraud' ), '#dba617' ],
				[ $total_count > 0 ? round( ( $fraud_count / $total_count ) * 100, 1 ) . '%' : '0%', __( 'Fraud Rate', 'wc-antifraud' ), '#1d2327' ],
			];
			foreach ( $cards as $c ) :
			?>
				<div class="wcaf-card" style="flex:1;min-width:150px;text-align:center;">
					<h3 style="margin:0;font-size:36px;color:<?php echo esc_attr( $c[2] ); ?>;"><?php echo esc_html( $c[0] ); ?></h3>
					<p style="margin:4px 0 0;color:#646970;"><?php echo esc_html( $c[1] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div style="display:flex;gap:20px;flex-wrap:wrap;">
			<?php self::render_report_table( __( 'Fraud Reasons Breakdown', 'wc-antifraud' ), __( 'Reason', 'wc-antifraud' ), $reason_counts ); ?>
			<?php self::render_report_table( __( 'Top Fraud Emails', 'wc-antifraud' ), __( 'Email', 'wc-antifraud' ), array_slice( $fraud_emails, 0, 10, true ) ); ?>
			<?php self::render_report_table( __( 'Top Fraud IPs', 'wc-antifraud' ), __( 'IP Address', 'wc-antifraud' ), array_slice( $fraud_ips, 0, 10, true ) ); ?>
		</div>

		<!-- AbuseIPDB community reporting settings -->
		<div class="wcaf-card" style="margin-top:20px;">
			<form method="post" action="options.php">
				<input type="hidden" name="wcaf_current_tab" value="reports" />
				<?php settings_fields( 'wcaf_group' ); ?>
				<?php do_settings_sections( 'wc-antifraud-reports' ); ?>
				<?php submit_button( __( 'Save Settings', 'wc-antifraud' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_report_table( $title, $col_label, $data ) {
		?>
		<div class="wcaf-card" style="flex:1;min-width:300px;">
			<h3><?php echo esc_html( $title ); ?></h3>
			<?php if ( empty( $data ) ) : ?>
				<p><?php esc_html_e( 'No data in this period.', 'wc-antifraud' ); ?></p>
			<?php else : ?>
				<table class="wcaf-table"><thead><tr><th><?php echo esc_html( $col_label ); ?></th><th><?php esc_html_e( 'Count', 'wc-antifraud' ); ?></th></tr></thead><tbody>
				<?php foreach ( $data as $key => $count ) : ?>
					<tr><td><?php echo esc_html( $key ); ?></td><td><strong><?php echo esc_html( $count ); ?></strong></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Field renderers ───────────────────────────────────────────────

	private static function opt() { return WCAF_Helpers::get_options(); }
	private static function key() { return WC_Antifraud::OPTION_KEY; }

	public static function field_detection_mode() {
		$o    = self::opt();
		$mode = $o['detection_mode'] ?? 'block';
		printf(
			'<fieldset>
				<label><input name="%1$s[detection_mode]" type="radio" value="block" %2$s /> %3$s</label><br />
				<label><input name="%1$s[detection_mode]" type="radio" value="monitor" %4$s /> %5$s</label>
			</fieldset><p class="description">%6$s</p>',
			esc_attr( self::key() ),
			checked( 'block', $mode, false ),
			esc_html__( 'Block: cancel suspicious orders (Auto Cancelled status), email an alert, report the IP to AbuseIPDB if enabled', 'wc-antifraud' ),
			checked( 'monitor', $mode, false ),
			esc_html__( 'Monitor: flag suspicious orders and email an alert, but leave the order status alone and never report the IP', 'wc-antifraud' ),
			esc_html__( 'Use Monitor when installing on a new store or after changing rules: it shows what would be cancelled without touching a single order. Flagged orders get a gray "Flagged" badge on the Orders list and appear in the Activity Log. Pre-payment checks (blacklists, bans, repeated-failure limit, REST hardening) are not affected by this setting.', 'wc-antifraud' )
		);
	}

	public static function field_unknown_origin() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_unknown_origin]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_unknown_origin'], false ),
			esc_html__( 'Flag all unknown-origin orders as fraud (classic checkout included)', 'wc-antifraud' ),
			esc_html__( 'Marks any order with no WooCommerce attribution data as fraud, whether placed through the classic checkout or the Store API. Store API bot orders are always caught regardless of this setting; enabling this extends the same rule to classic-checkout orders. Only customer-facing paths are affected — admin/manual, subscription, and API-integration orders are never flagged. Recommended: on.', 'wc-antifraud' )
		);
		if ( ! WCAF_Helpers::order_attribution_enabled() ) {
			printf(
				'<p class="description" style="color:#b32d2e;font-weight:600;">%s</p>',
				esc_html__( 'Inactive: WooCommerce\'s Order Attribution feature is turned off on this store (WooCommerce > Settings > Advanced > Features), so no order carries attribution data and this rule, together with the Store API bot check, is skipped. Enable Order Attribution to use them.', 'wc-antifraud' )
			);
		}
	}

	public static function field_stripe_decline() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_stripe_decline]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_stripe_decline'], false ),
			esc_html__( 'Mark orders as "Cancelled by Stripe" when Stripe reports the decline as fraudulent', 'wc-antifraud' ),
			esc_html__( 'Applies when Stripe Radar blocks a payment as too risky, or the card issuer returns a fraud decline code (fraudulent, stolen/lost/pickup card, merchant blacklist). Only ever affects orders whose payment already failed — the customer is never charged. A detailed decline note and a decline panel on the order screen (risk level, decline code, card details, Stripe Dashboard link) are recorded on every failed Stripe order regardless of this setting. These orders are NOT reported to AbuseIPDB, because a gateway fraud signal can occasionally belong to a real customer.', 'wc-antifraud' )
		);
	}

	public static function field_decline_threshold() {
		$o = self::opt();
		printf( '<input name="%s[decline_block_threshold]" type="number" min="0" max="100" value="%s" class="small-text" /> <span class="description">%s</span><p class="description">%s</p>',
			esc_attr( self::key() ), esc_attr( $o['decline_block_threshold'] ),
			esc_html__( 'failed payments in 24 hours', 'wc-antifraud' ),
			sprintf(
				/* translators: 1: alert threshold, 2: minimum block threshold */
				esc_html__( '0 = report only (the admin notice still appears from %1$d failures). Minimum when set: %2$d. Only you know whether a trade counter, a phone-order desk, or a strict gateway on this store could produce the same pattern from genuine customers.', 'wc-antifraud' ),
				WCAF_Decline_Clusters::ALERT_FROM,
				WCAF_Decline_Clusters::MIN_BLOCK_THRESHOLD
			)
		);
	}

	public static function field_auto_ban() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_auto_ban]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_auto_ban'], false ),
			esc_html__( 'Temporarily ban the IP once the failure limit above refuses a checkout', 'wc-antifraud' ),
			esc_html__( 'Stops the same source from simply starting a fresh session and carrying on. The ban lifts itself after the duration below, so a wrong guess never becomes a permanent lock-out. Allowlisted IPs are never banned. Active bans are listed on the Lists tab.', 'wc-antifraud' )
		);
		if ( WCAF_Client_IP::ip_rules_suspended() ) {
			printf( '<p class="description" style="color:#b32d2e;font-weight:600;">%s</p>', esc_html__( 'Paused: an undeclared proxy is suspected (see the notice at the top of the screen, or Lists > Trusted Proxies).', 'wc-antifraud' ) );
		}
	}

	public static function field_auto_ban_minutes() {
		$o = self::opt();
		printf( '<input name="%s[auto_ban_minutes]" type="number" min="5" max="10080" value="%s" class="small-text" /> <span class="description">%s</span>',
			esc_attr( self::key() ), esc_attr( $o['auto_ban_minutes'] ),
			esc_html__( 'minutes (60 = 1 hour, 1440 = 1 day)', 'wc-antifraud' )
		);
	}

	public static function field_target_amount() {
		$o = self::opt();
		printf( '<input name="%s[target_amount]" type="number" step="0.01" min="0" value="%s" class="regular-text" /><p class="description">%s</p>',
			esc_attr( self::key() ), esc_attr( $o['target_amount'] ),
			esc_html__( 'Flag orders matching this amount. Set to 0 to disable.', 'wc-antifraud' )
		);
	}

	public static function field_tolerance() {
		$o = self::opt();
		printf( '<input name="%s[amount_tolerance]" type="number" step="0.01" min="0" value="%s" class="small-text" /><p class="description">%s</p>',
			esc_attr( self::key() ), esc_attr( $o['amount_tolerance'] ),
			esc_html__( 'Tolerance range around the target (e.g. 0.55 = +/- $0.55).', 'wc-antifraud' )
		);
	}

	public static function field_ip_repeat() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_ip_repeat]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_ip_repeat'], false ),
			esc_html__( 'Flag repeat orders from the same IP', 'wc-antifraud' ),
			esc_html__( 'Counts every order from an IP, successful ones included, so a trade counter or phone-order desk placing many orders from one connection will trip it. For card testing, the Repeated Payment Failures rule above is the better signal.', 'wc-antifraud' )
		);
		if ( WCAF_Client_IP::ip_rules_suspended() ) {
			printf( '<p class="description" style="color:#b32d2e;font-weight:600;">%s</p>', esc_html__( 'Paused: an undeclared proxy is suspected (see the notice at the top of the screen, or Lists > Trusted Proxies).', 'wc-antifraud' ) );
		}
	}

	public static function field_ip_threshold() {
		$o = self::opt();
		printf( '<input name="%s[ip_repeat_threshold]" type="number" min="1" max="100" value="%s" class="small-text" /><p class="description">%s</p>',
			esc_attr( self::key() ), esc_attr( $o['ip_repeat_threshold'] ),
			esc_html__( 'Orders from the same IP before flagging.', 'wc-antifraud' )
		);
	}

	public static function field_ip_window() {
		$o = self::opt();
		printf( '<input name="%s[ip_repeat_window]" type="number" min="60" max="86400" value="%s" class="small-text" /> <span class="description">%s</span><p class="description">%s</p>',
			esc_attr( self::key() ), esc_attr( $o['ip_repeat_window'] ),
			esc_html__( 'seconds', 'wc-antifraud' ),
			esc_html__( '3600 = 1 hour, 86400 = 1 day.', 'wc-antifraud' )
		);
	}

	public static function field_registration_limit() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_registration_limit]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_registration_limit'], false ),
			esc_html__( 'Refuse sign-ups from banned or blacklisted IPs, from blacklisted or disposable email addresses, and beyond a per-IP hourly limit', 'wc-antifraud' ),
			esc_html__( 'Disposable addresses are refused only when disposable email blocking is on (Lists tab). The hourly limit is deliberately generous so a shared office, campus, or carrier-grade NAT address is not tripped by ordinary use. Allowlisted IPs skip every check.', 'wc-antifraud' )
		);
		if ( WCAF_Client_IP::ip_rules_suspended() ) {
			printf( '<p class="description" style="color:#b32d2e;font-weight:600;">%s</p>', esc_html__( 'Paused: an undeclared proxy is suspected (see the notice at the top of the screen, or Lists > Trusted Proxies).', 'wc-antifraud' ) );
		}
	}

	public static function field_registration_max() {
		$o = self::opt();
		printf( '<input name="%s[registration_max_per_hour]" type="number" min="1" max="1000" value="%s" class="small-text" />',
			esc_attr( self::key() ), esc_attr( $o['registration_max_per_hour'] )
		);
	}

	public static function field_proxy() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_proxy_check]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_proxy_check'], false ),
			esc_html__( 'Enable VPN/Proxy heuristic detection', 'wc-antifraud' ),
			esc_html__( 'May produce false positives behind CDNs.', 'wc-antifraud' )
		);
	}

	public static function field_rest_hardening() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_rest_hardening]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_rest_hardening'], false ),
			esc_html__( 'Block unauthenticated order creation via REST API', 'wc-antifraud' ),
			esc_html__( 'Prevents bots from POSTing directly to WooCommerce order endpoints. Only allows requests with valid checkout session nonces, API keys, or admin authentication. Allowlisted IPs always pass, temporarily banned IPs never do. Recommended: always on.', 'wc-antifraud' )
		);
	}

	public static function field_self_test() {
		printf(
			'<button type="button" class="button" id="wcaf-self-test" data-nonce="%s">%s</button> <span id="wcaf-self-test-result"></span><p class="description">%s</p>',
			esc_attr( wp_create_nonce( self::ACTION_NONCE ) ),
			esc_html__( 'Run the test', 'wc-antifraud' ),
			esc_html__( 'Sends the same request a card-testing bot sends, a Store API checkout POST that never loaded your checkout page, at this store from the server, and reports who stopped it. Nothing is charged and no order is created. Uses the saved settings, so save first after changing the toggle above.', 'wc-antifraud' )
		);
	}

	public static function field_trusted_proxies() {
		$o = self::opt();
		printf( '<textarea name="%s[trusted_proxies]" rows="3" cols="60" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( self::key() ), esc_textarea( $o['trusted_proxies'] ),
			esc_html__( 'Only needed for a proxy with a public address that is not Cloudflare (an external load balancer, another CDN). One IP or CIDR per line, IPv4 or IPv6. Proxies on this host (private addresses) are trusted automatically.', 'wc-antifraud' )
		);
		$fetched = WCAF_Client_IP::cloudflare_ranges_fetched_at();
		$count   = count( WCAF_Client_IP::cloudflare_ranges() );
		$status  = $fetched
			? sprintf( /* translators: 1: number of ranges, 2: date */ __( 'Cloudflare ranges: %1$d, fetched %2$s.', 'wc-antifraud' ), $count, date_i18n( 'M j, Y g:i a', $fetched ) )
			: sprintf( /* translators: %d: number of ranges */ __( 'Cloudflare ranges: %d from the bundled copy (no fetch has succeeded yet).', 'wc-antifraud' ), $count );
		printf(
			'<p class="description">%s <a href="%s">%s</a></p>',
			esc_html( $status ),
			esc_url( wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=blacklists&wcaf_action=refresh_cf' ), self::ACTION_NONCE ) ),
			esc_html__( 'Refresh now', 'wc-antifraud' )
		);
	}

	public static function field_trust_all_proxy_headers() {
		$o = self::opt();
		printf( '<label><input name="%s[trust_all_proxy_headers]" type="checkbox" value="1" %s /> %s</label><p class="description" style="color:#b32d2e;">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['trust_all_proxy_headers'], false ),
			esc_html__( 'Trust forwarding headers from any client (insecure, behavior before 1.7.0)', 'wc-antifraud' ),
			esc_html__( 'Lets a bot forge its address, which evades the IP blacklist and bans and can get innocent addresses banned. Use only while you identify a proxy you cannot yet declare, then turn it off.', 'wc-antifraud' )
		);
	}

	public static function field_proxy_diagnostic() {
		$remote   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$resolved = WCAF_Helpers::get_client_ip();
		$labels   = [
			'direct'               => __( 'direct connection, no proxy', 'wc-antifraud' ),
			'cloudflare'           => __( 'behind Cloudflare, client taken from CF-Connecting-IP', 'wc-antifraud' ),
			'cloudflare-forwarded' => __( 'behind Cloudflare, client taken from X-Forwarded-For', 'wc-antifraud' ),
			'local-proxy'          => __( 'proxy on this host, client taken from X-Forwarded-For', 'wc-antifraud' ),
			'trusted-proxy'        => __( 'declared proxy, client taken from X-Forwarded-For', 'wc-antifraud' ),
			'legacy'               => __( 'legacy mode, first forwarding header trusted', 'wc-antifraud' ),
			'none'                 => __( 'no address available', 'wc-antifraud' ),
		];
		$source = WCAF_Client_IP::source();
		printf(
			'<p><code>%s</code> &rarr; <code>%s</code><br /><span class="description">%s</span></p>',
			esc_html( $remote ?: '-' ),
			esc_html( $resolved ?: '-' ),
			esc_html( $labels[ $source ] ?? $source )
		);
		if ( WCAF_Client_IP::ip_rules_suspended() ) {
			printf( '<p class="description" style="color:#b32d2e;font-weight:600;">%s</p>', esc_html__( 'Automatic IP rules are paused: an undeclared proxy is suspected. See the notice at the top of the screen.', 'wc-antifraud' ) );
		}
	}

	public static function field_allowed_ips() {
		$o = self::opt();
		printf( '<textarea name="%s[allowed_ips]" rows="4" cols="60" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( self::key() ), esc_textarea( $o['allowed_ips'] ),
			esc_html__( 'One IP per line. CIDR supported (e.g. 203.0.113.0/24).', 'wc-antifraud' )
		);
	}

	public static function field_blocked_emails() {
		$o = self::opt();
		printf( '<textarea name="%s[blocked_emails]" rows="5" cols="60" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( self::key() ), esc_textarea( $o['blocked_emails'] ),
			esc_html__( 'One email per line (e.g. spammer@example.com).', 'wc-antifraud' )
		);
	}

	public static function field_disposable() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_disposable]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_disposable'], false ),
			esc_html__( 'Block disposable/temporary email domains', 'wc-antifraud' ),
			sprintf(
				/* translators: %s: number of bundled domains */
				esc_html__( 'Uses a bundled list of %s known throwaway domains (from the public-domain disposable-email-domains project, updated with each plugin release) plus any domains you add below. Checked at checkout, on registration when registration protection is on, and in post-payment analysis.', 'wc-antifraud' ),
				number_format_i18n( WCAF_Helpers::bundled_disposable_count() )
			)
		);
	}

	public static function field_domains() {
		$o = self::opt();
		printf( '<textarea name="%s[disposable_domains]" rows="5" cols="60" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( self::key() ), esc_textarea( $o['disposable_domains'] ),
			esc_html__( 'Your own additions, one domain per line. Wildcards supported (e.g. *.tempmail.com). Applied together with the bundled list when disposable blocking is on.', 'wc-antifraud' )
		);
	}

	public static function field_blocked_ips() {
		$o = self::opt();
		printf( '<textarea name="%s[blocked_ips]" rows="5" cols="60" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( self::key() ), esc_textarea( $o['blocked_ips'] ),
			esc_html__( 'One IP per line. CIDR supported (e.g. 192.168.1.0/24).', 'wc-antifraud' )
		);
	}

	public static function field_blocked_phones() {
		$o = self::opt();
		printf( '<textarea name="%s[blocked_phones]" rows="5" cols="60" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( self::key() ), esc_textarea( $o['blocked_phones'] ),
			esc_html__( 'One pattern per line. Use * as wildcard (e.g. +1555*).', 'wc-antifraud' )
		);
	}

	public static function field_recipients() {
		$o = self::opt();
		printf( '<input name="%s[email_recipients]" type="text" value="%s" class="large-text" /><p class="description">%s</p>',
			esc_attr( self::key() ), esc_attr( $o['email_recipients'] ),
			esc_html__( 'Comma-separated emails that receive fraud alerts.', 'wc-antifraud' )
		);
	}

	public static function field_enable_abuseipdb() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_abuseipdb]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_abuseipdb'], false ),
			esc_html__( 'Report the IP of every order marked as fraud (automatic or manual) to AbuseIPDB', 'wc-antifraud' ),
			esc_html__( 'Categories: Fraud Orders + Web App Attack. Each IP is reported at most once per 15 minutes (API rule) and orders older than two months are skipped (the API rejects older timestamps). A note with the result is added to the order. Orders flagged in Monitor mode are never reported.', 'wc-antifraud' )
		);
	}

	public static function field_abuseipdb_api_key() {
		$o           = self::opt();
		$description = sprintf(
			/* translators: %s: AbuseIPDB URL */
			__( 'Free API key from <a href="%s" target="_blank" rel="noopener noreferrer">abuseipdb.com</a> (Account → API). The free tier allows 1,000 reports per day.', 'wc-antifraud' ),
			esc_url( 'https://www.abuseipdb.com/' )
		);
		printf( '<input name="%s[abuseipdb_api_key]" type="text" value="%s" class="large-text" autocomplete="off" /><p class="description">%s</p>',
			esc_attr( self::key() ), esc_attr( $o['abuseipdb_api_key'] ),
			wp_kses( $description, [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] )
		);
	}

	// ── CSS / JS ──────────────────────────────────────────────────────

	private static function get_css() {
		return '
			.wcaf-header{display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;}
			.wcaf-header h1{margin:0;padding:0;font-size:23px;font-weight:400;line-height:1.3;}
			.wcaf-header-icon{font-size:40px;color:#2271b1;width:40px;height:40px;}
			.wcaf-version{color:#787c82;font-size:13px;}
			.wcaf-tabs{margin:0 0 20px;}.wcaf-tabs .nav-tab{font-size:14px;}
			.wcaf-card{background:#fff;border:1px solid #c3c4c7;padding:12px 20px;margin-bottom:20px;}
			.wcaf-card h3{margin-top:.5em;}
			.wcaf-table{width:100%;border-collapse:collapse;}
			.wcaf-table th,.wcaf-table td{padding:8px 10px;text-align:left;border-bottom:1px solid #e0e0e0;}
			.wcaf-table tr:nth-child(even){background:#f6f7f7;}
			.wcaf-fraud{color:#d63638;font-weight:600;}
			.wcaf-monitor{color:#646970;font-weight:600;}
			.form-table td p.description{margin-top:4px;color:#646970;}
			#wcaf-self-test-result{margin-left:8px;font-weight:600;}
		';
	}

	private static function get_js() {
		$running = esc_js( __( 'Running…', 'wc-antifraud' ) );
		$failed  = esc_js( __( 'The test request failed to run.', 'wc-antifraud' ) );
		return "
			jQuery(function($){
				$('#wcaf-self-test').on('click', function(){
					var btn = $(this), out = $('#wcaf-self-test-result');
					btn.prop('disabled', true);
					out.css('color', '#646970').text('{$running}');
					$.post(ajaxurl, { action: 'wcaf_self_test', nonce: btn.data('nonce') })
						.done(function(res){
							var msg = (res && res.data && res.data.message) ? res.data.message : '{$failed}';
							out.css('color', res && res.success ? '#00a32a' : '#d63638').text(msg);
						})
						.fail(function(){ out.css('color', '#d63638').text('{$failed}'); })
						.always(function(){ btn.prop('disabled', false); });
				});
			});
		";
	}
}
