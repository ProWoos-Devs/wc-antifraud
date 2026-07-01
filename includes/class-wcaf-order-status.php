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
	 * Bulk action key on the Orders list table.
	 */
	const BULK_ACTION = 'wcaf_mark_as_fraud';

	/**
	 * Order meta key holding the persistent fraud flag.
	 *
	 * Set whenever an order is marked as fraud and never cleared automatically,
	 * so the fraud designation survives a later refund (which changes the order
	 * status to Refunded).
	 */
	const FRAUD_FLAG_META = '_wcaf_is_fraud';

	public static function init() {
		self::register_status();
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_status_to_list' ] );
		add_filter( 'woocommerce_reports_order_statuses', [ __CLASS__, 'exclude_from_reports' ] );
		add_filter( 'views_edit-shop_order', [ __CLASS__, 'add_status_filter_link' ] );
		add_filter( 'woocommerce_shop_order_list_table_views', [ __CLASS__, 'add_status_filter_link' ] );

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
	}

	public static function register_status() {
		register_post_status(
			self::STATUS_SLUG,
			[
				'label'                     => _x( 'Fraud — Auto Cancelled', 'Order status', 'wc-antifraud' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop(
					'Fraud — Auto Cancelled <span class="count">(%s)</span>',
					'Fraud — Auto Cancelled <span class="count">(%s)</span>',
					'wc-antifraud'
				),
			]
		);
	}

	public static function add_status_to_list( $statuses ) {
		$statuses[ self::STATUS_WC_KEY ] = _x( 'Fraud — Auto Cancelled', 'Order status', 'wc-antifraud' );
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
		$count = self::get_fraud_order_count();

		$current = '';
		if ( isset( $_GET['status'] ) && self::STATUS_SLUG === $_GET['status'] ) {
			$current = ' class="current"';
		} elseif ( isset( $_GET['post_status'] ) && self::STATUS_SLUG === $_GET['post_status'] ) {
			$current = ' class="current"';
		}

		$url = admin_url( 'edit.php?post_type=shop_order&post_status=' . self::STATUS_SLUG );
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$url = admin_url( 'admin.php?page=wc-orders&status=' . self::STATUS_SLUG );
		}

		$label = sprintf(
			__( 'Fraud', 'wc-antifraud' )
			. ' <span class="count">(%s)</span>',
			number_format_i18n( $count )
		);

		$views[ self::STATUS_SLUG ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $current, $label );

		return $views;
	}

	/**
	 * Count orders with fraud status
	 *
	 * @return int
	 */
	private static function get_fraud_order_count() {
		$counts = wp_count_posts( 'shop_order' );
		$slug   = self::STATUS_SLUG;
		return isset( $counts->$slug ) ? (int) $counts->$slug : 0;
	}

	/**
	 * Mark order as fraud
	 *
	 * @param WC_Order $order
	 * @param array    $reasons
	 * @return bool
	 */
	public static function mark_as_fraud( $order, $reasons ) {
		if ( ! $order ) {
			return false;
		}
		$note = sprintf( __( 'Order automatically marked as fraud. Reasons: %s', 'wc-antifraud' ), implode( ', ', $reasons ) );
		// Persistent fraud flag — survives a later refund that would otherwise
		// relabel the order as Refunded and hide the fraud designation.
		// update_status() calls save(), which persists this pending meta.
		$order->update_meta_data( self::FRAUD_FLAG_META, 'yes' );
		$order->update_status( self::STATUS_SLUG, $note );
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
		return self::STATUS_SLUG === $order->get_status()
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
			if ( self::STATUS_SLUG === $order->get_status() ) {
				continue;
			}
			if ( self::mark_as_fraud( $order, [ __( 'Manually marked as fraud by admin', 'wc-antifraud' ) ] ) ) {
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
	 * @param WC_Order|false $order
	 */
	private static function output_fraud_badge( $order ) {
		if ( ! self::is_fraud_order( $order ) ) {
			return;
		}
		printf(
			'<mark class="order-status" style="background:#d63638;color:#fff;"><span>%s</span></mark>',
			esc_html__( 'Fraud', 'wc-antifraud' )
		);
	}
}
