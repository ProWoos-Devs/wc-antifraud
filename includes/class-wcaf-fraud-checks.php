<?php
/**
 * Fraud Detection Checks
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Fraud_Checks {

	private $options;

	public function __construct() {
		$this->options = WCAF_Helpers::get_options();
		add_action( 'woocommerce_check_cart_items', [ $this, 'check_cart_total' ] );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'check_checkout' ], 20, 2 );
		add_action( 'woocommerce_thankyou', [ $this, 'analyze_order_after_payment' ], 10, 1 );

		// Server-side post-payment analysis. Carding bots check out via the Store
		// API + PayPal and only ever receive a JSON response, so they never render
		// the thankyou page and woocommerce_thankyou never fires for them. When the
		// stolen card works, the order goes straight to a paid status server-side
		// and would otherwise escape detection. These hooks fire regardless of any
		// browser page load. analyze_order_after_payment() is idempotent (skips
		// orders already marked fraud-auto-cancelled).
		add_action( 'woocommerce_payment_complete', [ $this, 'analyze_order_after_payment' ], 10, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'analyze_order_after_payment' ], 10, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'analyze_order_after_payment' ], 10, 1 );
		add_action( 'woocommerce_order_status_on-hold', [ $this, 'analyze_order_after_payment' ], 10, 1 );

		// Catch failed/cancelled orders too (bot card-testing orders always fail)
		add_action( 'woocommerce_order_status_failed', [ $this, 'analyze_failed_order' ], 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'analyze_failed_order' ], 10, 1 );
	}

	/**
	 * Block suspicious cart totals
	 */
	public function check_cart_total() {
		if ( ! WC()->cart ) {
			return;
		}
		$totals     = WC()->cart->get_totals();
		$cart_total = isset( $totals['total'] ) ? floatval( $totals['total'] ) : 0.0;
		if ( WCAF_Helpers::is_amount_suspicious( $cart_total, floatval( $this->options['target_amount'] ), floatval( $this->options['amount_tolerance'] ) ) ) {
			wc_add_notice( __( 'Your order has been restricted due to security concerns. Please contact support.', 'wc-antifraud' ), 'error' );
		}
	}

	/**
	 * Checkout-time validation
	 *
	 * @param array    $data
	 * @param WP_Error $errors
	 */
	public function check_checkout( $data, $errors ) {
		$block_msg = apply_filters(
			'wcaf_checkout_block_message',
			__( 'We cannot process your order due to security concerns. Please contact us if you believe this is a mistake.', 'wc-antifraud' )
		);

		// Note: Unknown origin is checked post-payment only (in analyze_order_after_payment).
		// Blocking at checkout caused false positives for customers with cookie blockers,
		// Safari ITP, or when WC attribution JS didn't load.

		$email = isset( $data['billing_email'] ) ? $data['billing_email'] : '';
		$phone = isset( $data['billing_phone'] ) ? $data['billing_phone'] : '';
		$ip    = WCAF_Helpers::get_client_ip();

		if ( ! empty( $email ) && WCAF_Helpers::is_email_address_blocked( $email, $this->options ) ) {
			$errors->add( 'wcaf_blocked_email', $block_msg );
			return;
		}
		if ( ! empty( $this->options['enable_disposable'] ) && ! empty( $email ) && WCAF_Helpers::is_email_blocked( $email, $this->options ) ) {
			$errors->add( 'wcaf_blocked_domain', $block_msg );
			return;
		}
		if ( $ip && WCAF_Helpers::is_ip_blocked( $ip, $this->options ) ) {
			$errors->add( 'wcaf_blocked_ip', $block_msg );
			return;
		}
		if ( ! empty( $phone ) && WCAF_Helpers::is_phone_blocked( $phone, $this->options ) ) {
			$errors->add( 'wcaf_blocked_phone', $block_msg );
			return;
		}
	}

	/**
	 * Post-payment analysis
	 *
	 * @param int $order_id
	 */
	public function analyze_order_after_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Skip if already marked as fraud (prevents re-processing when several of
		// the post-payment hooks fire for the same order).
		if ( 'fraud-auto-cancelled' === $order->get_status() ) {
			return;
		}
		$reasons = $this->detect_fraud_indicators( $order );
		if ( ! empty( $reasons ) ) {
			$this->handle_suspicious_order( $order, $reasons );
		}
	}

	/**
	 * Analyze failed/cancelled orders for fraud indicators
	 *
	 * @param int $order_id
	 */
	public function analyze_failed_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'fraud-auto-cancelled' === $order->get_status() ) {
			return;
		}

		// Store API failed orders are always bots — run the full check set.
		if ( 'store-api' === $order->get_created_via() ) {
			$reasons = $this->detect_fraud_indicators( $order );
			if ( ! empty( $reasons ) ) {
				$this->handle_suspicious_order( $order, $reasons );
			}
			return;
		}

		// Classic-checkout failed orders: a legit customer whose card declines (and
		// who may retry) must NEVER be flagged. Their order always carries WC
		// attribution data, so for classic checkout we act ONLY on the unknown-origin
		// signal — an order with no attribution at all, an unambiguous bot. The
		// amount and IP-repeat heuristics are deliberately NOT run here, so a genuine
		// decline+retry can't false-positive.
		if ( $this->is_unknown_origin_check_enabled() && $this->is_unknown_origin_order( $order ) ) {
			$this->handle_suspicious_order(
				$order,
				[ __( 'Unknown Origin (no attribution / no checkout session)', 'wc-antifraud' ) ]
			);
		}
	}

	/**
	 * Run all fraud indicator checks
	 *
	 * @param WC_Order $order
	 * @return array Fraud reasons
	 */
	private function detect_fraud_indicators( $order ) {
		$reasons = [];
		$opts    = $this->options;

		// Store API bot detection (always on).
		// Orders created via store-api with no WC attribution data are bots
		// posting directly to the API, bypassing the actual checkout page.
		$created_via = $order->get_created_via();
		if ( 'store-api' === $created_via && empty( $order->get_meta( '_wc_order_attribution_source_type' ) ) ) {
			$reasons[] = __( 'Store API Bot Order (no checkout session)', 'wc-antifraud' );
		} elseif ( $this->is_unknown_origin_check_enabled() && $this->is_unknown_origin_order( $order ) ) {
			// Unknown origin (optional toggle) — ANY customer-facing order with no
			// WC attribution data, classic checkout included. Real orders always
			// carry attribution (WC's sourcebuster runs on every checkout page load
			// and JS-requiring gateways force it), so empty attribution means the
			// order never loaded the checkout page. elseif avoids double-counting a
			// store-api bot, which the check above already covers.
			$reasons[] = __( 'Unknown Origin (no attribution / no checkout session)', 'wc-antifraud' );
		}

		// Suspicious amount
		if ( WCAF_Helpers::is_amount_suspicious( floatval( $order->get_total() ), floatval( $opts['target_amount'] ), floatval( $opts['amount_tolerance'] ) ) ) {
			$reasons[] = sprintf( __( 'Suspicious Amount (%s)', 'wc-antifraud' ), wc_price( $opts['target_amount'] ) );
		}

		// Blacklisted email address
		if ( WCAF_Helpers::is_email_address_blocked( $order->get_billing_email(), $opts ) ) {
			$reasons[] = __( 'Blacklisted Email Address', 'wc-antifraud' );
		}

		// Blocked email domain
		if ( ! empty( $opts['enable_disposable'] ) && WCAF_Helpers::is_email_blocked( $order->get_billing_email(), $opts ) ) {
			$reasons[] = __( 'Blocked Email Domain', 'wc-antifraud' );
		}

		// Blocked IP
		$ip = WCAF_Helpers::get_client_ip();
		if ( $ip && WCAF_Helpers::is_ip_blocked( $ip, $opts ) ) {
			$reasons[] = __( 'Blacklisted IP Address', 'wc-antifraud' );
		}

		// Blocked phone
		if ( WCAF_Helpers::is_phone_blocked( $order->get_billing_phone(), $opts ) ) {
			$reasons[] = __( 'Blacklisted Phone Number', 'wc-antifraud' );
		}

		// Proxy/VPN
		if ( ! empty( $opts['enable_proxy_check'] ) && WCAF_Helpers::is_proxy_detected() ) {
			$reasons[] = __( 'Proxy/VPN Detected', 'wc-antifraud' );
		}

		// IP repeat
		if ( ! empty( $opts['enable_ip_repeat'] ) && $ip ) {
			if ( WCAF_IP_Tracker::track_and_check( $ip, $order->get_id(), $opts ) ) {
				$reasons[] = __( 'Multiple Orders from Same IP', 'wc-antifraud' );
			}
		}

		return $reasons;
	}

	/**
	 * Whether the "flag all unknown-origin orders as fraud" rule is enabled.
	 *
	 * @return bool
	 */
	private function is_unknown_origin_check_enabled() {
		return ! empty( $this->options['enable_unknown_origin'] );
	}

	/**
	 * Check if an order has no WC attribution data ("unknown origin").
	 *
	 * Only the two customer-facing order paths are subject to this rule. Orders
	 * created programmatically — admin/manual (phone) orders, subscription
	 * renewals, REST/ERP/POS integrations — legitimately have no attribution and
	 * must never be flagged. Bots only ever use the classic checkout or the Store
	 * API, so restricting to these loses nothing.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function is_unknown_origin_order( $order ) {
		$created_via = $order->get_created_via();
		if ( ! in_array( $created_via, [ 'checkout', 'store-api' ], true ) ) {
			return false;
		}
		return empty( $order->get_meta( '_wc_order_attribution_source_type' ) );
	}

	/**
	 * Mark an order as fraud, alert, and fire the extension hook.
	 *
	 * @param WC_Order $order
	 * @param array    $reasons
	 */
	private function handle_suspicious_order( $order, $reasons ) {
		WCAF_Order_Status::mark_as_fraud( $order, $reasons );
		WCAF_Email_Alerts::send_alert( $order, $reasons, $this->options );
		do_action( 'wcaf_suspicious_order_detected', $order, $reasons );
	}
}
