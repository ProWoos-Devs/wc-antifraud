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

	const STATUS_SLUG = 'wc-fraud-auto-cancelled';
	const STATUS_KEY  = 'fraud-auto-cancelled';

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_status_to_list' ] );
		add_filter( 'woocommerce_reports_order_statuses', [ __CLASS__, 'exclude_from_reports' ] );
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
		$statuses[ self::STATUS_SLUG ] = _x( 'Fraud — Auto Cancelled', 'Order status', 'wc-antifraud' );
		return $statuses;
	}

	public static function exclude_from_reports( $statuses ) {
		return $statuses;
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
		$order->update_status( self::STATUS_KEY, $note );
		return true;
	}
}
