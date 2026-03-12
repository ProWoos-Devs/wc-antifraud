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

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . WCAF_PLUGIN_BASENAME, [ __CLASS__, 'add_action_links' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
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
	}

	// ── Tabs ──────────────────────────────────────────────────────────

	private static function get_current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'detection';
	}

	private static function get_tabs() {
		return [
			'detection'     => __( 'Detection Rules', 'wc-antifraud' ),
			'blacklists'    => __( 'Blacklists', 'wc-antifraud' ),
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
		}
	}

	private static function register_detection_fields() {
		add_settings_section( 'wcaf_origin', __( 'Origin Verification', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Block orders that bypass the normal checkout flow (bots, API abuse).', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_unknown_origin', __( 'Unknown origin blocking', 'wc-antifraud' ), [ __CLASS__, 'field_unknown_origin' ], 'wc-antifraud', 'wcaf_origin' );

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

		add_settings_section( 'wcaf_heuristics', __( 'Heuristics', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Advanced detection that may produce false positives. Test on staging first.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_proxy_check', __( 'VPN/Proxy detection', 'wc-antifraud' ), [ __CLASS__, 'field_proxy' ], 'wc-antifraud', 'wcaf_heuristics' );

		// REST API section
		add_settings_section( 'wcaf_rest', __( 'REST API Protection', 'wc-antifraud' ), function () {
			echo '<p>' . esc_html__( 'Block bots from creating orders via the WooCommerce REST/Store API without a valid checkout session.', 'wc-antifraud' ) . '</p>';
		}, 'wc-antifraud' );
		add_settings_field( 'enable_rest_hardening', __( 'REST API hardening', 'wc-antifraud' ), [ __CLASS__, 'field_rest_hardening' ], 'wc-antifraud', 'wcaf_rest' );
	}

	private static function register_blacklist_fields() {
		add_settings_section( 'wcaf_bl', '', function () {
			echo '<p>' . esc_html__( 'Manually block specific emails, IPs, or phone numbers. One entry per line.', 'wc-antifraud' ) . '</p>';
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

	// ── Sanitize ──────────────────────────────────────────────────────

	public static function sanitize( $input ) {
		$existing = WCAF_Helpers::get_options();
		$output   = $existing;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tab = isset( $_POST['wcaf_current_tab'] ) ? sanitize_key( $_POST['wcaf_current_tab'] ) : '';

		if ( 'detection' === $tab ) {
			$output['enable_unknown_origin'] = ! empty( $input['enable_unknown_origin'] ) ? 1 : 0;
			$output['enable_proxy_check']     = ! empty( $input['enable_proxy_check'] ) ? 1 : 0;
			$output['enable_ip_repeat']       = ! empty( $input['enable_ip_repeat'] ) ? 1 : 0;
			$output['enable_rest_hardening']  = ! empty( $input['enable_rest_hardening'] ) ? 1 : 0;
			if ( isset( $input['target_amount'] ) )    { $output['target_amount']    = floatval( $input['target_amount'] ); }
			if ( isset( $input['amount_tolerance'] ) )  { $output['amount_tolerance']  = floatval( $input['amount_tolerance'] ); }
			if ( isset( $input['ip_repeat_threshold'] ) ) { $output['ip_repeat_threshold'] = absint( $input['ip_repeat_threshold'] ); }
			if ( isset( $input['ip_repeat_window'] ) )    { $output['ip_repeat_window']    = absint( $input['ip_repeat_window'] ); }
		}

		if ( 'blacklists' === $tab ) {
			$output['enable_disposable']  = ! empty( $input['enable_disposable'] ) ? 1 : 0;
			$output['disposable_domains'] = isset( $input['disposable_domains'] ) ? sanitize_textarea_field( $input['disposable_domains'] ) : '';
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

		return $output;
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
				<form method="post" action="options.php">
					<input type="hidden" name="wcaf_current_tab" value="<?php echo esc_attr( $tab ); ?>" />
					<?php settings_fields( 'wcaf_group' ); ?>
					<?php do_settings_sections( 'wc-antifraud' ); ?>
					<?php submit_button( __( 'Save Settings', 'wc-antifraud' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Activity Log ──────────────────────────────────────────────────

	private static function render_activity_log() {
		// Use direct SQL to avoid WC object cache returning stale/wrong status orders.
		global $wpdb;
		$order_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order'
			 AND post_status = 'fraud-auto-cancelled'
			 ORDER BY post_date DESC
			 LIMIT 50"
		);
		$orders = array_filter( array_map( 'wc_get_order', $order_ids ) );
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
						<th><?php esc_html_e( 'Reason', 'wc-antifraud' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $orders as $order ) :
						$reason = '';
						foreach ( wc_get_order_notes( [ 'order_id' => $order->get_id(), 'type' => 'internal' ] ) as $note ) {
							if ( preg_match( '/Reasons:\s*(.+)$/i', $note->content, $m ) ) { $reason = $m[1]; break; }
						}
					?>
						<tr>
							<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_id() ); ?></a></td>
							<td><?php $d = $order->get_date_created(); echo $d ? esc_html( $d->date_i18n( 'M j, Y g:i a' ) ) : '—'; ?></td>
							<td><?php echo esc_html( $order->get_billing_email() ); ?></td>
							<td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
							<td><?php echo esc_html( $order->get_customer_ip_address() ?: '—' ); ?></td>
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

		$fraud_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status='fraud-auto-cancelled' AND post_date >= {$date_sql}" );
		$legit_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('wc-processing','wc-completed','wc-on-hold') AND post_date >= {$date_sql}" );
		$failed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_status IN ('wc-failed','wc-cancelled') AND post_date >= {$date_sql}" );
		$total_count  = $fraud_count + $legit_count + $failed_count;

		// Get fraud order details via direct SQL (avoids WC object cache returning wrong orders)
		$fraud_order_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order'
			 AND post_status = 'fraud-auto-cancelled'
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

	public static function field_unknown_origin() {
		$o = self::opt();
		printf( '<label><input name="%s[enable_unknown_origin]" type="checkbox" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( self::key() ), checked( 1, $o['enable_unknown_origin'], false ),
			esc_html__( 'Block orders without proper attribution data', 'wc-antifraud' ),
			esc_html__( 'Orders placed by bots lack browser session/attribution cookies. This is the primary defense.', 'wc-antifraud' )
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
		printf( '<label><input name="%s[enable_ip_repeat]" type="checkbox" value="1" %s /> %s</label>',
			esc_attr( self::key() ), checked( 1, $o['enable_ip_repeat'], false ),
			esc_html__( 'Block repeat order attempts from the same IP', 'wc-antifraud' )
		);
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
			esc_html__( 'Prevents bots from POSTing directly to WooCommerce order endpoints. Only allows requests with valid checkout session nonces, API keys, or admin authentication. Recommended: always on.', 'wc-antifraud' )
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
		printf( '<label><input name="%s[enable_disposable]" type="checkbox" value="1" %s /> %s</label>',
			esc_attr( self::key() ), checked( 1, $o['enable_disposable'], false ),
			esc_html__( 'Block disposable/temporary email domains', 'wc-antifraud' )
		);
	}

	public static function field_domains() {
		$o = self::opt();
		printf( '<textarea name="%s[disposable_domains]" rows="5" cols="60" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( self::key() ), esc_textarea( $o['disposable_domains'] ),
			esc_html__( 'One domain per line. Wildcards supported (e.g. *.tempmail.com).', 'wc-antifraud' )
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

	// ── CSS ───────────────────────────────────────────────────────────

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
			.form-table td p.description{margin-top:4px;color:#646970;}
		';
	}
}
