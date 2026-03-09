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

		// Unknown origin
		if ( ! empty( $this->options['enable_unknown_origin'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$cookie = isset( $_COOKIE['wc_order_attribution_source_type'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wc_order_attribution_source_type'] ) ) : '';
			$session = WC()->session ? WC()->session->get( 'wc_order_attribution_source_type' ) : '';
			if ( empty( $cookie ) && empty( $session ) ) {
				$errors->add( 'wcaf_unknown_origin', $block_msg );
				return;
			}
		}

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
		$reasons = $this->detect_fraud_indicators( $order );
		if ( ! empty( $reasons ) ) {
			WCAF_Order_Status::mark_as_fraud( $order, $reasons );
			WCAF_Email_Alerts::send_alert( $order, $reasons, $this->options );
			do_action( 'wcaf_suspicious_order_detected', $order, $reasons );
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

		// Unknown origin
		$origin = $order->get_meta( '_wc_order_attribution_source_type' );
		if ( empty( $origin ) || 'unknown' === strtolower( $origin ) ) {
			$reasons[] = __( 'Unknown Origin', 'wc-antifraud' );
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
}
