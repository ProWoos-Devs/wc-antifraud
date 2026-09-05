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

	/**
	 * Linked-fraud rule: an IP shared with a fraud order only counts within this
	 * many seconds of it. Carrier-grade NAT (mobile networks) puts thousands of
	 * customers behind one address, so an IP match is only meaningful when the
	 * two orders are minutes apart, a retry after a block.
	 */
	const LINKED_IP_WINDOW = HOUR_IN_SECONDS;

	/** Shortest normalized ship-to key (street + postcode) worth comparing. */
	const LINKED_ADDRESS_MIN_LENGTH = 6;

	/** Most recent fraud orders considered as anchors. */
	const LINKED_ANCHOR_LIMIT = 500;

	private $options;

	public function __construct() {
		$this->options = WCAF_Helpers::get_options();
		add_action( 'woocommerce_check_cart_items', [ $this, 'check_cart_total' ] );

		// Pre-payment checks, classic checkout.
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'check_checkout' ], 20, 2 );

		// Pre-payment checks, Block Checkout / Store API. This action fires
		// against the persisted draft order after billing data is applied and
		// before payment; throwing a RouteException here refuses the checkout
		// with a proper Store API error and no payment attempt is made.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'check_store_api_checkout' ], 5, 2 );

		add_action( 'woocommerce_thankyou', [ $this, 'analyze_order_after_payment' ], 10, 1 );

		// Server-side post-payment analysis. Carding bots check out via the Store
		// API + PayPal and only ever receive a JSON response, so they never render
		// the thankyou page and woocommerce_thankyou never fires for them. When the
		// stolen card works, the order goes straight to a paid status server-side
		// and would otherwise escape detection. These hooks fire regardless of any
		// browser page load. analyze_order_after_payment() is idempotent (skips
		// orders already marked fraud or already flagged in monitor mode).
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
	 * Customer-facing message for a refused checkout.
	 *
	 * @return string
	 */
	private function block_message() {
		return apply_filters(
			'wcaf_checkout_block_message',
			__( 'We cannot process your order due to security concerns. Please contact us if you believe this is a mistake.', 'wc-antifraud' )
		);
	}

	/**
	 * Checkout-time validation (classic checkout)
	 *
	 * @param array    $data
	 * @param WP_Error $errors
	 */
	public function check_checkout( $data, $errors ) {
		// Note: Unknown origin is checked post-payment only (in analyze_order_after_payment).
		// Blocking at checkout caused false positives for customers with cookie blockers,
		// Safari ITP, or when WC attribution JS didn't load.
		$email = isset( $data['billing_email'] ) ? $data['billing_email'] : '';
		$phone = isset( $data['billing_phone'] ) ? $data['billing_phone'] : '';

		$reason = $this->pre_payment_block_reason( $email, $phone, WCAF_Helpers::get_client_ip() );
		if ( '' !== $reason ) {
			WCAF_Stats::bump( 'refused:' . $reason );
			$errors->add( 'wcaf_' . $reason, $this->block_message() );
		}
	}

	/**
	 * Checkout-time validation (Block Checkout / Store API)
	 *
	 * @param WC_Order        $order
	 * @param WP_REST_Request $request
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the checkout is refused.
	 */
	public function check_store_api_checkout( $order, $request ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$reason = $this->pre_payment_block_reason( $order->get_billing_email(), $order->get_billing_phone(), WCAF_Helpers::get_client_ip() );
		if ( '' === $reason ) {
			return;
		}
		WCAF_Stats::bump( 'refused:' . $reason );
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wcaf_' . $reason, $this->block_message(), 403 );
		}
	}

	/**
	 * The shared pre-payment check set, for both checkout surfaces.
	 *
	 * Order matters: the allowlist exempts everything, a temporary ban comes
	 * before the merchant's lists, and the repeated-failure limit runs last
	 * because it is the only check that can add a ban of its own.
	 *
	 * @param string       $email
	 * @param string       $phone
	 * @param string|false $ip
	 * @return string Reason slug, or '' when nothing blocks.
	 */
	private function pre_payment_block_reason( $email, $phone, $ip ) {
		$opts = $this->options;

		if ( $ip && WCAF_Helpers::is_ip_allowed( $ip, $opts ) ) {
			return '';
		}
		if ( $ip && WCAF_IP_Bans::is_banned( $ip ) ) {
			return 'banned_ip';
		}
		if ( ! empty( $email ) && WCAF_Helpers::is_email_address_blocked( $email, $opts ) ) {
			return 'blocked_email';
		}
		if ( ! empty( $opts['enable_disposable'] ) && ! empty( $email ) && WCAF_Helpers::is_email_blocked( $email, $opts ) ) {
			return 'blocked_domain';
		}
		if ( $ip && WCAF_Helpers::is_ip_blocked( $ip, $opts ) ) {
			return 'blocked_ip';
		}
		if ( ! empty( $phone ) && WCAF_Helpers::is_phone_blocked( $phone, $opts ) ) {
			return 'blocked_phone';
		}
		if ( WCAF_Decline_Clusters::is_over_block_threshold( $ip ) ) {
			if ( $ip && WCAF_IP_Bans::maybe_auto_ban( $ip, $opts, __( 'Repeated payment failures', 'wc-antifraud' ) ) ) {
				do_action( 'wcaf_ip_auto_banned', $ip, 'decline_limit' );
			}
			return 'decline_limit';
		}
		return '';
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
		// Skip if already handled (prevents re-processing when several of the
		// post-payment hooks fire for the same order).
		if ( $this->already_handled( $order ) ) {
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
		if ( $this->already_handled( $order ) ) {
			return;
		}

		// Store API failed orders are always bots — run the full check set.
		if ( 'store-api' === $order->get_created_via() ) {
			$reasons = $this->detect_fraud_indicators( $order, true );
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
		$ip = self::order_ip( $order );
		if ( $ip && WCAF_Helpers::is_ip_allowed( $ip, $this->options ) ) {
			return;
		}
		if ( $this->is_unknown_origin_check_enabled() && $this->is_unknown_origin_order( $order ) ) {
			$this->handle_suspicious_order(
				$order,
				[ 'unknown_origin' => __( 'Unknown Origin (no attribution / no checkout session)', 'wc-antifraud' ) ]
			);
			return;
		}

		// A failed order that shares its email, ship-to address, or (minutes
		// apart) its IP with an order already marked fraud is the same actor
		// retrying: a second card, a second gateway, a second name to the same
		// drop address. Nothing was charged, so relabelling it costs a real
		// customer nothing. Gateway verdicts count as anchors here, unlike on
		// the paid path (see detect_fraud_indicators()).
		$linked = $this->linked_fraud_reason( $order, true );
		if ( $linked ) {
			$this->handle_suspicious_order( $order, $linked );
		}
	}

	/**
	 * Whether the order was already marked fraud, or already flagged in monitor mode.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private function already_handled( $order ) {
		return in_array( $order->get_status(), WCAF_Order_Status::fraud_statuses(), true )
			|| WCAF_Order_Status::is_monitor_flagged( $order );
	}

	/**
	 * The IP to judge an order by.
	 *
	 * The IP stored on the order, never the current request's. The post-payment
	 * hooks frequently run inside a gateway webhook (Stripe, PayPal IPN) or an
	 * admin status change, where the request IP is the gateway's server or the
	 * admin's own address.
	 *
	 * @param WC_Order $order
	 * @return string|false
	 */
	public static function order_ip( $order ) {
		$ip = (string) $order->get_customer_ip_address();
		if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		// Fail open when the order has no trustworthy address. Falling back to the
		// current request can attribute a gateway webhook or an admin action to the
		// gateway/admin and poison IP-linked rules and third-party reports.
		return false;
	}

	/**
	 * Run all fraud indicator checks
	 *
	 * @param WC_Order $order
	 * @param bool     $failed Whether the order is being analyzed as a failed/cancelled order (nothing charged).
	 * @return array Fraud reasons, slug => label
	 */
	private function detect_fraud_indicators( $order, $failed = false ) {
		$reasons = [];
		$opts    = $this->options;
		$ip      = self::order_ip( $order );

		// Allowlisted IPs bypass every rule (staging, headless front ends,
		// the merchant's own testing).
		if ( $ip && WCAF_Helpers::is_ip_allowed( $ip, $opts ) ) {
			return [];
		}

		// Store API bot detection (always on).
		// Orders created via store-api with no WC attribution data are bots
		// posting directly to the API, bypassing the actual checkout page.
		// Both attribution rules are skipped entirely when the store has WooCommerce's
		// Order Attribution feature turned off: then NO order carries attribution and
		// the rules would cancel every genuine order (seen on a live store, 1.5.1).
		$created_via = $order->get_created_via();
		if ( ! WCAF_Helpers::order_attribution_enabled() ) {
			// no attribution-based signal available on this store
		} elseif ( 'store-api' === $created_via && empty( $order->get_meta( '_wc_order_attribution_source_type' ) ) ) {
			$reasons['store_api_bot'] = __( 'Store API Bot Order (no checkout session)', 'wc-antifraud' );
		} elseif ( $this->is_unknown_origin_check_enabled() && $this->is_unknown_origin_order( $order ) ) {
			// Unknown origin (optional toggle) — ANY customer-facing order with no
			// WC attribution data, classic checkout included. Real orders always
			// carry attribution (WC's sourcebuster runs on every checkout page load
			// and JS-requiring gateways force it), so empty attribution means the
			// order never loaded the checkout page. elseif avoids double-counting a
			// store-api bot, which the check above already covers.
			$reasons['unknown_origin'] = __( 'Unknown Origin (no attribution / no checkout session)', 'wc-antifraud' );
		}

		// Suspicious amount
		if ( WCAF_Helpers::is_amount_suspicious( floatval( $order->get_total() ), floatval( $opts['target_amount'] ), floatval( $opts['amount_tolerance'] ) ) ) {
			$reasons['amount'] = sprintf( __( 'Suspicious Amount (%s)', 'wc-antifraud' ), wc_price( $opts['target_amount'] ) );
		}

		// Blacklisted email address
		if ( WCAF_Helpers::is_email_address_blocked( $order->get_billing_email(), $opts ) ) {
			$reasons['blacklist_email'] = __( 'Blacklisted Email Address', 'wc-antifraud' );
		}

		// Blocked email domain (merchant list + bundled disposable list)
		if ( ! empty( $opts['enable_disposable'] ) && WCAF_Helpers::is_email_blocked( $order->get_billing_email(), $opts ) ) {
			$reasons['blocked_domain'] = __( 'Blocked Email Domain', 'wc-antifraud' );
		}

		// Blocked IP
		if ( $ip && WCAF_Helpers::is_ip_blocked( $ip, $opts ) ) {
			$reasons['blacklist_ip'] = __( 'Blacklisted IP Address', 'wc-antifraud' );
		}

		// Blocked phone
		if ( WCAF_Helpers::is_phone_blocked( $order->get_billing_phone(), $opts ) ) {
			$reasons['blacklist_phone'] = __( 'Blacklisted Phone Number', 'wc-antifraud' );
		}

		// Proxy/VPN (request headers, so only meaningful when the customer's own
		// request is the one running this analysis)
		if ( ! empty( $opts['enable_proxy_check'] ) && WCAF_Helpers::is_proxy_detected() ) {
			$reasons['proxy'] = __( 'Proxy/VPN Detected', 'wc-antifraud' );
		}

		// IP repeat (suspended while an undeclared proxy hides the real addresses)
		if ( ! empty( $opts['enable_ip_repeat'] ) && $ip && ! WCAF_Client_IP::ip_rules_suspended() ) {
			if ( WCAF_IP_Tracker::track_and_check( $ip, $order->get_id(), $opts ) ) {
				$reasons['ip_repeat'] = __( 'Multiple Orders from Same IP', 'wc-antifraud' );
			}
		}

		// Linked to known fraud. On a PAID order the anchors are the plugin's own
		// detections and manual marks only, never a Stripe verdict: Radar does
		// block real customers now and then, and that customer paying again by
		// another route must not lose the sale.
		$reasons += $this->linked_fraud_reason( $order, $failed );

		return $reasons;
	}

	/**
	 * Linked-to-known-fraud rule.
	 *
	 * Looks for an order marked fraud within the configured window that shares
	 * with this one, in order of strength: the billing email, the ship-to street
	 * and postcode (shipping address, or billing when the order has no shipping
	 * address), or the customer IP within LINKED_IP_WINDOW of the anchor's
	 * creation. Returns a one-element reasons array (slug => label naming the
	 * anchor order and the shared datum) or an empty array.
	 *
	 * @param WC_Order $order                  Order under analysis.
	 * @param bool     $include_stripe_anchors Whether "Cancelled by Stripe" orders may anchor the match.
	 * @return array
	 */
	private function linked_fraud_reason( $order, $include_stripe_anchors ) {
		if ( empty( $this->options['enable_linked_fraud'] ) ) {
			return [];
		}
		// Customer-facing paths only. An order the merchant keys in by hand, a
		// subscription renewal, or an ERP/POS import is the merchant's decision.
		if ( ! in_array( $order->get_created_via(), [ 'checkout', 'store-api' ], true ) ) {
			return [];
		}
		$days = max( 1, (int) ( $this->options['linked_fraud_days'] ?? 30 ) );

		$created = $order->get_date_created();
		$now_ts  = $created ? $created->getTimestamp() : time();
		$since   = gmdate( 'Y-m-d H:i:s', $now_ts - $days * DAY_IN_SECONDS );

		$anchors = WCAF_Order_Status::recent_fraud_anchors( $since, $order->get_id(), self::LINKED_ANCHOR_LIMIT );
		if ( empty( $anchors ) ) {
			return [];
		}

		$email   = self::normalize_email( $order->get_billing_email() );
		$address = self::ship_to_key( $order->get_shipping_address_1(), $order->get_shipping_postcode(), $order->get_billing_address_1(), $order->get_billing_postcode() );
		$ip      = self::order_ip( $order );
		$use_ip  = $ip && WCAF_Helpers::is_public_ip( $ip ) && ! WCAF_Client_IP::ip_rules_suspended();

		$stripe_status = [ WCAF_Order_Status::STRIPE_STATUS_SLUG, 'wc-' . WCAF_Order_Status::STRIPE_STATUS_SLUG ];

		foreach ( $anchors as $row ) {
			if ( ! $include_stripe_anchors && in_array( $row['status'], $stripe_status, true ) ) {
				continue;
			}
			$anchor_id = (int) $row['id'];
			$match     = '';

			if ( '' !== $email && $email === self::normalize_email( $row['email'] ) ) {
				$match = __( 'same email address', 'wc-antifraud' );
			} elseif ( '' !== $address && $address === self::ship_to_key( $row['ship_address_1'], $row['ship_postcode'], $row['bill_address_1'], $row['bill_postcode'] ) ) {
				$match = __( 'same ship-to address', 'wc-antifraud' );
			} elseif ( $use_ip && $ip === (string) $row['ip'] ) {
				$anchor_ts = strtotime( (string) $row['created_gmt'] . ' UTC' );
				if ( $anchor_ts && abs( $now_ts - $anchor_ts ) <= self::LINKED_IP_WINDOW ) {
					$match = __( 'same IP address minutes apart', 'wc-antifraud' );
				}
			}

			if ( '' !== $match ) {
				return [
					'linked_fraud' => sprintf(
						/* translators: 1: order number of the earlier fraud order, 2: what the two orders share */
						__( 'Linked to known fraud order #%1$d (%2$s)', 'wc-antifraud' ),
						$anchor_id,
						$match
					),
				];
			}
		}

		return [];
	}

	/**
	 * Lowercased, trimmed email for comparison.
	 *
	 * @param string|null $email
	 * @return string
	 */
	private static function normalize_email( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Comparable ship-to key: street line plus postcode, lowercased, letters and
	 * digits only, so "4250 Pittman Grove Church Rd." and "4250 pittman grove
	 * church rd" compare equal. Falls back to the billing address when the order
	 * has no shipping street. Empty when too short to mean anything.
	 *
	 * @param string|null $ship_address_1
	 * @param string|null $ship_postcode
	 * @param string|null $bill_address_1
	 * @param string|null $bill_postcode
	 * @return string
	 */
	private static function ship_to_key( $ship_address_1, $ship_postcode, $bill_address_1, $bill_postcode ) {
		$street   = trim( (string) $ship_address_1 );
		$postcode = (string) $ship_postcode;
		if ( '' === $street ) {
			$street   = trim( (string) $bill_address_1 );
			$postcode = (string) $bill_postcode;
		}
		if ( '' === $street ) {
			return '';
		}
		$key = strtolower( preg_replace( '/[^a-z0-9]/i', '', $street . $postcode ) );
		return strlen( $key ) >= self::LINKED_ADDRESS_MIN_LENGTH ? $key : '';
	}

	/**
	 * Whether the "flag all unknown-origin orders as fraud" rule is enabled.
	 *
	 * @return bool
	 */
	private function is_unknown_origin_check_enabled() {
		return ! empty( $this->options['enable_unknown_origin'] ) && WCAF_Helpers::order_attribution_enabled();
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
	 * Act on a suspicious order.
	 *
	 * Block mode (default): mark as fraud (status change, persistent flag,
	 * AbuseIPDB report), alert, fire the extension hook.
	 * Monitor mode: flag the order and note the reasons WITHOUT changing its
	 * status or reporting the IP, alert, fire the extension hook.
	 *
	 * @param WC_Order $order
	 * @param array    $reasons slug => label
	 */
	private function handle_suspicious_order( $order, $reasons ) {
		$monitor = WCAF_Helpers::is_monitor_mode( $this->options );
		$labels  = array_values( $reasons );
		// A link to an earlier fraud order is circumstantial (a shared drop
		// address, a shared mobile-carrier IP). Like gateway verdicts it never
		// reports the IP to AbuseIPDB on its own; the anchor already did if it
		// deserved it, and public reports cannot be taken back.
		$report_ip = ! empty( array_diff_key( $reasons, [ 'linked_fraud' => true ] ) );
		if ( $monitor ) {
			WCAF_Order_Status::flag_for_review( $order, $labels );
			WCAF_Stats::bump( 'monitor_flagged' );
		} else {
			WCAF_Order_Status::mark_as_fraud( $order, $labels, $report_ip );
		}
		foreach ( array_keys( $reasons ) as $slug ) {
			WCAF_Stats::bump( 'reason:' . $slug );
		}
		WCAF_Email_Alerts::send_alert( $order, $labels, $this->options, $monitor );
		do_action( 'wcaf_suspicious_order_detected', $order, $labels, $monitor );
	}
}
