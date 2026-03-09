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

	public static function init() {
		self::register_status();
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_status_to_list' ] );
		add_filter( 'woocommerce_reports_order_statuses', [ __CLASS__, 'exclude_from_reports' ] );
		add_filter( 'views_edit-shop_order', [ __CLASS__, 'add_status_filter_link' ] );
		add_filter( 'woocommerce_shop_order_list_table_views', [ __CLASS__, 'add_status_filter_link' ] );
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
		$order->update_status( self::STATUS_SLUG, $note );
		return true;
	}
}
