<?php
/**
 * Custom Order Status
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Order_Status {

	/**
	 * Post status slug as stored in the database.
	 *
	 * WooCommerce's update_status() stores custom statuses without the wc- prefix.
	 */
	const STATUS_SLUG = 'fraud-auto-cancelled';

	/**
	 * Key used for wc_order_statuses filter (requires wc- prefix by WC convention)
	 */
	const STATUS_WC_KEY = 'wc-fraud-auto-cancelled';

	/**
	 * Post status slug for orders whose fraud verdict came from Stripe itself
	 * (Radar block or issuer fraud decline code), as stored in the database.
	 * NOTE: post_status is varchar(20) — slugs longer than 20 chars fail to
	 * save silently (MySQL rejects the write while WC carries on).
	 */
	const STRIPE_STATUS_SLUG = 'fraud-stripe';

	/**
	 * wc_order_statuses key for the Stripe fraud status
	 */
	const STRIPE_STATUS_WC_KEY = 'wc-fraud-stripe';

	/**
	 * Bulk action key on the Orders list table.
	 */
	const BULK_ACTION = 'wcaf_mark_as_fraud';

	/**
	 * Query var backing the combined "Fraud" view on the Orders list.
	 *
	 * Replaces the two per-status filter links. Neither of those showed the whole
	 * picture on its own, and a fraud order that is later refunded moves to the
	 * Refunded status and drops out of both.
	 */
	const VIEW_QUERY_VAR = 'wcaf_view';

	/**
	 * Value of self::VIEW_QUERY_VAR that selects the combined fraud view.
	 */
	const VIEW_FRAUD = 'fraud';

	/**
	 * Order meta key holding the persistent fraud flag.
	 *
	 * Set whenever an order is marked as fraud and never cleared automatically,
	 * so the fraud designation survives a later refund (which changes the order
	 * status to Refunded).
	 */
	const FRAUD_FLAG_META = '_wcaf_is_fraud';

	/**
	 * Order meta set in monitor mode: the order matched a fraud rule and was
	 * flagged for review, but its status was left alone.
	 */
	const MONITOR_FLAG_META = '_wcaf_monitor_flag';

	/**
	 * Key of the "Block this customer" entry in the order-screen Actions dropdown.
	 */
	const BLOCK_ACTION = 'wcaf_block_customer';

	public static function init() {
		self::register_status();
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_status_to_list' ] );
		add_filter( 'woocommerce_reports_order_statuses', [ __CLASS__, 'exclude_from_reports' ] );
		add_filter( 'views_edit-shop_order', [ __CLASS__, 'add_status_filter_link' ] );
		add_filter( 'woocommerce_shop_order_list_table_views', [ __CLASS__, 'add_status_filter_link' ] );

		// Combined "Fraud" view (classic Orders screen). Spans both fraud
		// statuses plus any order still carrying the persistent fraud flag.
		add_action( 'pre_get_posts', [ __CLASS__, 'apply_fraud_view_query' ] );

		// "Change status to Fraud" bulk action on the Orders list (classic + HPOS).
		add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'bulk_action_admin_notice' ] );

		// Persistent "Fraud" badge column — keeps the fraud label visible after a
		// refund moves the order to the Refunded status (classic + HPOS).
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_fraud_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_fraud_column_classic' ], 10, 2 );
		add_filter( 'woocommerce_shop_order_list_table_columns', [ __CLASS__, 'add_fraud_column' ] );
		add_action( 'woocommerce_shop_order_list_table_custom_column', [ __CLASS__, 'render_fraud_column_hpos' ], 10, 2 );

		// "Block this customer" in the order-screen Actions dropdown.
		add_filter( 'woocommerce_order_actions', [ __CLASS__, 'register_order_action' ], 10, 2 );
		add_action( 'woocommerce_order_action_' . self::BLOCK_ACTION, [ __CLASS__, 'handle_block_customer' ] );

		// Count fraud orders an admin moves back to a normal status (the
		// false-positive signal in the usage counters).
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'track_unmark' ], 10, 3 );
	}

	/**
	 * @param int    $order_id
	 * @param string $from
	 * @param string $to
	 */
	public static function track_unmark( $order_id, $from, $to ) {
		if ( ! in_array( $from, self::fraud_statuses(), true ) || in_array( $to, self::fraud_statuses(), true ) ) {
			return;
		}
		// A refund keeps the fraud designation (persistent flag); it is not an un-mark.
		if ( 'refunded' === $to || ! is_admin() || wp_doing_cron() ) {
			return;
		}
		WCAF_Stats::bump( 'unmarked' );
	}

	/**
	 * Flag an order for review without changing its status (monitor mode).
	 *
	 * @param WC_Order $order
	 * @param array    $reasons
	 * @return bool
	 */
	public static function flag_for_review( $order, $reasons ) {
		if ( ! $order ) {
			return false;
		}
		$order->update_meta_data( self::MONITOR_FLAG_META, 'yes' );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: %s: comma-separated reasons */
				__( 'Flagged for review by WC Antifraud (monitor mode, status unchanged). Reasons: %s', 'wc-antifraud' ),
				implode( ', ', $reasons )
			)
		);
		return true;
	}

	/**
	 * Whether an order was flagged in monitor mode.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function is_monitor_flagged( $order ) {
		return $order && 'yes' === $order->get_meta( self::MONITOR_FLAG_META );
	}

	/**
	 * Add "Block this customer" to the order Actions dropdown.
	 *
	 * @param array         $actions
	 * @param WC_Order|null $order
	 * @return array
	 */
	public static function register_order_action( $actions, $order = null ) {
		$actions[ self::BLOCK_ACTION ] = __( 'Block this customer (Antifraud)', 'wc-antifraud' );
		return $actions;
	}

	/**
	 * Append the order's billing email and customer IP to the blacklists.
	 *
	 * WooCommerce verifies the order-actions nonce and capability before this
	 * runs. The IP is only added when it is a public address, so a store owner
	 * testing from a private network cannot lock out their own LAN.
	 *
	 * @param WC_Order $order
	 */
	public static function handle_block_customer( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$opts  = WCAF_Helpers::get_options();
		$added = [];

		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( $email && is_email( $email ) && ! WCAF_Helpers::is_email_address_blocked( $email, $opts ) ) {
			$opts['blocked_emails'] = trim( (string) $opts['blocked_emails'] . "\n" . $email );
			$added[]                = $email;
		}

		$ip = (string) $order->get_customer_ip_address();
		if ( $ip
			&& false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
			&& ! WCAF_Helpers::is_ip_blocked( $ip, $opts )
			&& ! WCAF_Helpers::is_ip_allowed( $ip, $opts ) ) {
			$opts['blocked_ips'] = trim( (string) $opts['blocked_ips'] . "\n" . $ip );
			$added[]             = $ip;
		}

		if ( empty( $added ) ) {
			$order->add_order_note( __( 'WC Antifraud: this customer\'s email and IP were already on the blacklists (or not blockable).', 'wc-antifraud' ) );
			return;
		}

		update_option( WC_Antifraud::OPTION_KEY, $opts );
		WCAF_Stats::bump( 'block_customer' );
		$order->add_order_note(
			sprintf(
				/* translators: %s: comma-separated list of what was added */
				__( 'WC Antifraud: customer blocked. Added to the blacklists: %s', 'wc-antifraud' ),
				implode( ', ', $added )
			)
		);
	}

	/**
	 * The plugin's fraud status slugs (own detections + Stripe verdicts).
	 *
	 * Use this wherever "is the order already marked fraud" is checked so both
	 * statuses are treated alike.
	 *
	 * @return array
	 */
	public static function fraud_statuses() {
		return [ self::STATUS_SLUG, self::STRIPE_STATUS_SLUG ];
	}

	/**
	 * Recent fraud orders, with the few fields the linked-fraud rule compares.
	 *
	 * Direct SQL on purpose: WC_Order_Query maps a status argument to its
	 * "wc-"-prefixed form, which never matches the unprefixed custom statuses,
	 * and loading hundreds of order objects on every status transition is too
	 * heavy. Reads the posts store or the HPOS tables, whichever is authoritative.
	 * An order counts as fraud when it is in a fraud status OR carries the
	 * persistent flag (a refunded fraud order has left the status).
	 *
	 * @param string $since_gmt  MySQL datetime (GMT); only orders created at or after it.
	 * @param int    $exclude_id Order to leave out (the one being analyzed).
	 * @param int    $limit      Row cap, newest first.
	 * @return array[] Rows: id, created_gmt, email, ip, ship_address_1, ship_postcode, bill_address_1, bill_postcode, status.
	 */
	public static function recent_fraud_anchors( $since_gmt, $exclude_id = 0, $limit = 500 ) {
		global $wpdb;

		$statuses = self::fraud_statuses();
		foreach ( self::fraud_statuses() as $slug ) {
			$statuses[] = 'wc-' . $slug;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$limit        = max( 1, (int) $limit );

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$addr   = $wpdb->prefix . 'wc_order_addresses';
			$meta   = $wpdb->prefix . 'wc_orders_meta';
			$sql    = $wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and placeholders built above.
				"SELECT o.id AS id, o.status AS status, o.date_created_gmt AS created_gmt, o.billing_email AS email, o.ip_address AS ip,
					s.address_1 AS ship_address_1, s.postcode AS ship_postcode,
					b.address_1 AS bill_address_1, b.postcode AS bill_postcode
				FROM {$orders} o
				LEFT JOIN {$addr} s ON s.order_id = o.id AND s.address_type = 'shipping'
				LEFT JOIN {$addr} b ON b.order_id = o.id AND b.address_type = 'billing'
				WHERE o.type = 'shop_order'
					AND o.status <> 'trash'
					AND o.date_created_gmt >= %s
					AND o.id <> %d
					AND ( o.status IN ( {$placeholders} )
						OR EXISTS ( SELECT 1 FROM {$meta} m WHERE m.order_id = o.id AND m.meta_key = %s AND m.meta_value IN ( 'yes', '1' ) ) )
				ORDER BY o.id DESC
				LIMIT %d",
				// phpcs:enable
				array_merge( [ $since_gmt, (int) $exclude_id ], $statuses, [ self::FRAUD_FLAG_META, $limit ] )
			);
		} else {
			$sql = $wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
				"SELECT p.ID AS id, p.post_status AS status, p.post_date_gmt AS created_gmt,
					em.meta_value AS email, ip.meta_value AS ip,
					sa.meta_value AS ship_address_1, sp.meta_value AS ship_postcode,
					ba.meta_value AS bill_address_1, bp.meta_value AS bill_postcode
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
				LEFT JOIN {$wpdb->postmeta} ip ON ip.post_id = p.ID AND ip.meta_key = '_customer_ip_address'
				LEFT JOIN {$wpdb->postmeta} sa ON sa.post_id = p.ID AND sa.meta_key = '_shipping_address_1'
				LEFT JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = '_shipping_postcode'
				LEFT JOIN {$wpdb->postmeta} ba ON ba.post_id = p.ID AND ba.meta_key = '_billing_address_1'
				LEFT JOIN {$wpdb->postmeta} bp ON bp.post_id = p.ID AND bp.meta_key = '_billing_postcode'
				WHERE p.post_type = 'shop_order'
					AND p.post_status <> 'trash'
					AND p.post_date_gmt >= %s
					AND p.ID <> %d
					AND ( p.post_status IN ( {$placeholders} )
						OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = p.ID AND f.meta_key = %s AND f.meta_value IN ( 'yes', '1' ) ) )
				ORDER BY p.ID DESC
				LIMIT %d",
				// phpcs:enable
				array_merge( [ $since_gmt, (int) $exclude_id ], $statuses, [ self::FRAUD_FLAG_META, $limit ] )
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return is_array( $rows ) ? $rows : [];
	}

	public static function register_status() {
		register_post_status(
			self::STATUS_SLUG,
			[
				'label'                     => _x( 'Auto Cancelled', 'Order status', 'wc-antifraud' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'Auto Cancelled <span class="count">(%s)</span>',
					'Auto Cancelled <span class="count">(%s)</span>',
					'wc-antifraud'
				),
			]
		);

		register_post_status(
			self::STRIPE_STATUS_SLUG,
			[
				'label'                     => _x( 'Cancelled by Stripe', 'Order status', 'wc-antifraud' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'Cancelled by Stripe <span class="count">(%s)</span>',
					'Cancelled by Stripe <span class="count">(%s)</span>',
					'wc-antifraud'
				),
			]
		);
	}

	public static function add_status_to_list( $statuses ) {
		$statuses[ self::STATUS_WC_KEY ]        = _x( 'Auto Cancelled', 'Order status', 'wc-antifraud' );
		$statuses[ self::STRIPE_STATUS_WC_KEY ] = _x( 'Cancelled by Stripe', 'Order status', 'wc-antifraud' );
		return $statuses;
	}

	public static function exclude_from_reports( $statuses ) {
		return $statuses;
	}

	/**
	 * Add "Fraud" filter link to orders list table
	 *
	 * @param array $views Existing view links
	 * @return array Modified views
	 */
	public static function add_status_filter_link( $views ) {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// The combined view is a posts_where clause, which the HPOS orders table
		// never runs through. Leave WordPress's own per-status views in place
		// there rather than removing the only way to filter fraud orders.
		if ( $hpos ) {
			return $views;
		}

		// WordPress builds a view for each custom status automatically (both are
		// registered with show_in_admin_status_list).
		unset( $views[ self::STATUS_SLUG ], $views[ self::STRIPE_STATUS_SLUG ] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$is_current = isset( $_GET[ self::VIEW_QUERY_VAR ] ) && self::VIEW_FRAUD === $_GET[ self::VIEW_QUERY_VAR ];

		$views['wcaf_fraud_all'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( admin_url( 'edit.php?post_type=shop_order&' . self::VIEW_QUERY_VAR . '=' . self::VIEW_FRAUD ) ),
			$is_current ? ' class="current"' : '',
			sprintf(
				/* translators: %s: number of orders */
				__( 'Fraud', 'wc-antifraud' ) . ' <span class="count">(%s)</span>',
				number_format_i18n( self::get_all_fraud_order_count() )
			)
		);

		return $views;
	}

	/**
	 * SQL predicate matching every order the plugin considers fraud.
	 *
	 * Either of the two fraud statuses, or the persistent flag on an order whose
	 * status has since moved on (a refund relabels a fraud order Refunded). The
	 * flag is matched loosely so flags written directly to the database as '1'
	 * rather than 'yes' are still picked up.
	 *
	 * @param string $posts_table Posts table name to qualify columns with.
	 * @return string SQL fragment, already escaped, wrapped in parentheses.
	 */
	private static function fraud_sql_predicate( $posts_table ) {
		global $wpdb;

		$statuses     = self::fraud_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		return $wpdb->prepare(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/placeholders built above.
			"( {$posts_table}.post_status IN ( {$placeholders} )
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} wcaf_flag
					WHERE wcaf_flag.post_id = {$posts_table}.ID
						AND wcaf_flag.meta_key = %s
						AND wcaf_flag.meta_value IN ( 'yes', '1' )
				) )",
			// phpcs:enable
			array_merge( $statuses, [ self::FRAUD_FLAG_META ] )
		);
	}

	/**
	 * Count every order matching the combined fraud view.
	 *
	 * @return int
	 */
	private static function get_all_fraud_order_count() {
		global $wpdb;

		$count = wp_cache_get( 'wcaf_all_fraud_count', 'wc-antifraud' );
		if ( false !== $count ) {
			return (int) $count;
		}

		$count = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- predicate is prepared.
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'shop_order'
				AND post_status != 'trash'
				AND " . self::fraud_sql_predicate( $wpdb->posts )
		);

		wp_cache_set( 'wcaf_all_fraud_count', $count, 'wc-antifraud', 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Point the main Orders query at the combined fraud view when it is selected.
	 *
	 * @param WP_Query $query Query being prepared.
	 */
	public static function apply_fraud_view_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( ! isset( $_GET[ self::VIEW_QUERY_VAR ] ) || self::VIEW_FRAUD !== $_GET[ self::VIEW_QUERY_VAR ] ) {
			return;
		}

		// 'any' resolves to every status with exclude_from_search false, which
		// covers the two fraud statuses and leaves trash out. The status/flag OR
		// itself cannot be expressed through WP_Query, hence the where filter.
		$query->set( 'post_status', 'any' );
		add_filter( 'posts_where', [ __CLASS__, 'fraud_view_where' ], 10, 2 );
	}

	/**
	 * Restrict the fraud view to fraud orders.
	 *
	 * Detaches itself immediately so it can only ever affect the one query it
	 * was attached for.
	 *
	 * @param string   $where Current WHERE clause.
	 * @param WP_Query $query Query being run.
	 * @return string
	 */
	public static function fraud_view_where( $where, $query ) {
		remove_filter( 'posts_where', [ __CLASS__, 'fraud_view_where' ], 10 );

		global $wpdb;

		return $where . ' AND ' . self::fraud_sql_predicate( $wpdb->posts );
	}

	/**
	 * Mark order as fraud
	 *
	 * @param WC_Order $order     Order object
	 * @param array    $reasons   Array of fraud reasons
	 * @param bool     $report_ip Whether to report the customer IP to AbuseIPDB.
	 *                            Pass false for detections that can false-positive
	 *                            on a real customer (e.g. Stripe Radar blocks) —
	 *                            AbuseIPDB reports are public and not reversible,
	 *                            unlike the fraud status itself.
	 * @param string   $status    Fraud status slug to set. Defaults to the classic
	 *                            "Auto Cancelled"; the Stripe decline handler passes
	 *                            STRIPE_STATUS_SLUG ("Cancelled by Stripe") so
	 *                            gateway verdicts are distinguishable.
	 * @return bool
	 */
	public static function mark_as_fraud( $order, $reasons, $report_ip = true, $status = self::STATUS_SLUG ) {
		if ( ! $order ) {
			return false;
		}

		if ( ! in_array( $status, self::fraud_statuses(), true ) ) {
			$status = self::STATUS_SLUG;
		}

		$note = sprintf( __( 'Order automatically marked as fraud. Reasons: %s', 'wc-antifraud' ), implode( ', ', $reasons ) );
		// Persistent fraud flag — survives a later refund that would otherwise
		// relabel the order as Refunded and hide the fraud designation.
		// update_status() calls save(), which persists this pending meta.
		$order->update_meta_data( self::FRAUD_FLAG_META, 'yes' );
		$order->update_status( $status, $note );
		WCAF_Stats::bump( 'marked:' . $status );

		// Report the attacking IP to AbuseIPDB (no-op unless enabled and an
		// API key is configured). Runs here so both automatic detections and
		// the manual bulk action feed the community database.
		if ( $report_ip ) {
			WCAF_AbuseIPDB::report_order( $order, $reasons );
		}

		return true;
	}

	/**
	 * Whether an order should be treated as fraud for display purposes.
	 *
	 * True if currently in the fraud status OR carrying the persistent fraud flag
	 * (i.e. it was fraud and has since been refunded).
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function is_fraud_order( $order ) {
		if ( ! $order ) {
			return false;
		}
		return in_array( $order->get_status(), self::fraud_statuses(), true )
			|| 'yes' === $order->get_meta( self::FRAUD_FLAG_META );
	}

	/**
	 * Add the "Change status to Fraud" option to the Orders bulk-actions dropdown.
	 *
	 * @param array $actions
	 * @return array
	 */
	public static function register_bulk_action( $actions ) {
		$actions[ self::BULK_ACTION ] = __( 'Change status to Fraud', 'wc-antifraud' );
		return $actions;
	}

	/**
	 * Handle the "Change status to Fraud" bulk action.
	 *
	 * WordPress verifies the bulk-action nonce before this filter runs, so only a
	 * capability check is needed here.
	 *
	 * @param string $redirect_to
	 * @param string $action
	 * @param array  $ids
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_to;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect_to;
		}

		$count = 0;
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			if ( in_array( $order->get_status(), self::fraud_statuses(), true ) ) {
				continue;
			}
			if ( self::mark_as_fraud( $order, [ __( 'Manually marked as fraud by admin', 'wc-antifraud' ) ] ) ) {
				WCAF_Stats::bump( 'manual_mark' );
				$count++;
			}
		}

		return add_query_arg( 'wcaf_marked_fraud', $count, $redirect_to );
	}

	/**
	 * Admin notice after the bulk "mark as fraud" action runs.
	 */
	public static function bulk_action_admin_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['wcaf_marked_fraud'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count = absint( $_GET['wcaf_marked_fraud'] );

		$message = sprintf(
			/* translators: %d: number of orders */
			_n(
				'%d order changed to Fraud status.',
				'%d orders changed to Fraud status.',
				$count,
				'wc-antifraud'
			),
			$count
		);

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Add the "Fraud" column right after the status column.
	 *
	 * @param array $columns
	 * @return array
	 */
	public static function add_fraud_column( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['wcaf_fraud'] = __( 'Fraud', 'wc-antifraud' );
			}
		}
		if ( ! isset( $new['wcaf_fraud'] ) ) {
			$new['wcaf_fraud'] = __( 'Fraud', 'wc-antifraud' );
		}
		return $new;
	}

	/**
	 * Render the fraud column on the classic (post-based) Orders screen.
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public static function render_fraud_column_classic( $column, $post_id ) {
		if ( 'wcaf_fraud' !== $column ) {
			return;
		}
		self::output_fraud_badge( wc_get_order( $post_id ) );
	}

	/**
	 * Render the fraud column on the HPOS Orders screen.
	 *
	 * @param string   $column
	 * @param WC_Order $order
	 */
	public static function render_fraud_column_hpos( $column, $order ) {
		if ( 'wcaf_fraud' !== $column ) {
			return;
		}
		self::output_fraud_badge( $order );
	}

	/**
	 * Output a "Fraud" status pill for a flagged order (nothing otherwise).
	 *
	 * Red for the plugin's own detections; Stripe blurple when the verdict came
	 * from Stripe (Radar block / issuer fraud decline), so the source is visible
	 * at a glance on the Orders list.
	 *
	 * @param WC_Order|false $order
	 */
	private static function output_fraud_badge( $order ) {
		if ( ! self::is_fraud_order( $order ) ) {
			// Monitor-mode flag: gray, so it reads as "look at this", not "fraud".
			if ( self::is_monitor_flagged( $order ) ) {
				printf(
					'<mark class="order-status" style="background:#646970;color:#fff;" title="%s"><span>%s</span></mark>',
					esc_attr__( 'Matched a fraud rule in monitor mode. Status unchanged.', 'wc-antifraud' ),
					esc_html__( 'Flagged', 'wc-antifraud' )
				);
			}
			return;
		}

		$stripe_verdict = self::STRIPE_STATUS_SLUG === $order->get_status()
			|| '' !== (string) $order->get_meta( '_wcaf_stripe_decline' );

		printf(
			'<mark class="order-status" style="background:%s;color:#fff;"><span>%s</span></mark>',
			$stripe_verdict ? '#635bff' : '#d63638',
			$stripe_verdict ? esc_html__( 'Fraud (Stripe)', 'wc-antifraud' ) : esc_html__( 'Fraud', 'wc-antifraud' )
		);
	}
}
