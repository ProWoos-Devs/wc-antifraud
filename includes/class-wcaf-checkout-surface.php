<?php
/**
 * Checkout surface lock
 *
 * A store whose checkout page renders the Block Checkout submits every order
 * through the Store API (/wc/store/v1/checkout). The classic checkout's AJAX
 * endpoints (wc-ajax=checkout, wc-ajax=update_order_review, and their
 * admin-ajax twins) stay registered by WooCommerce anyway, and card-testing
 * toolkits drive exactly that legacy flow: find the checkout page, post the
 * order-review form, run the card through the gateway's classic card fields,
 * post the checkout. No customer of a Block Checkout store ever sends those
 * requests, so refusing them costs nothing and closes the whole path.
 *
 * The lock engages only while the checkout page contains the Block Checkout;
 * a store on the shortcode checkout is never affected.
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Checkout_Surface {

	/** Classic-checkout AJAX events used only by the shortcode checkout form. */
	const EVENTS = [ 'checkout', 'update_order_review' ];

	/** Reason slug (stats key refused:<slug>, error code wcaf_<slug>). */
	const REASON = 'classic_checkout';

	/** Alert email throttle state. */
	const ALERT_TRANSIENT = 'wcaf_classic_lock_alert';

	/** Minimum seconds between two alert emails. */
	const ALERT_INTERVAL = HOUR_IN_SECONDS;

	/** @var bool|null Memoized surface detection. */
	private static $block_checkout = null;

	public static function init() {
		$opts = WCAF_Helpers::get_options();
		if ( empty( $opts['enable_classic_checkout_lock'] ) ) {
			return;
		}
		// WooCommerce registers its handlers on init priority 0; the surface
		// check needs wc_get_page_id(), so wait for init ourselves. The actions
		// themselves fire on template_redirect / admin-ajax, well after this.
		add_action( 'init', [ __CLASS__, 'register' ], 5 );
	}

	/**
	 * Hook the refusal in front of WooCommerce's handlers (priority 10).
	 */
	public static function register() {
		if ( ! self::is_block_checkout() ) {
			return;
		}
		foreach ( self::EVENTS as $event ) {
			add_action( 'wc_ajax_' . $event, [ __CLASS__, 'refuse' ], 1 );
			add_action( 'wp_ajax_woocommerce_' . $event, [ __CLASS__, 'refuse' ], 1 );
			add_action( 'wp_ajax_nopriv_woocommerce_' . $event, [ __CLASS__, 'refuse' ], 1 );
		}
	}

	/**
	 * True when the store's checkout page renders the Block Checkout.
	 *
	 * Uses WooCommerce's own detection when available (it also covers block
	 * themes, where the checkout lives in a template rather than a page).
	 *
	 * @return bool
	 */
	public static function is_block_checkout() {
		if ( null !== self::$block_checkout ) {
			return self::$block_checkout;
		}
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false; // Not memoized: WooCommerce may load later in the request.
		}
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' )
			&& method_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils', 'is_checkout_block_default' ) ) {
			self::$block_checkout = (bool) \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
		} else {
			$page_id              = (int) wc_get_page_id( 'checkout' );
			self::$block_checkout = $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
		}
		return self::$block_checkout;
	}

	/**
	 * Whether the lock is currently enforcing (option on AND Block Checkout detected).
	 *
	 * @return bool
	 */
	public static function is_active() {
		$opts = WCAF_Helpers::get_options();
		return ! empty( $opts['enable_classic_checkout_lock'] ) && self::is_block_checkout();
	}

	/**
	 * Forget the memoized detection (tests, or after the checkout page changes).
	 */
	public static function reset() {
		self::$block_checkout = null;
	}

	/**
	 * Refuse the request with the JSON shape the classic checkout JS expects, then stop.
	 */
	public static function refuse() {
		$opts = WCAF_Helpers::get_options();
		$ip   = WCAF_Helpers::get_client_ip();

		// Allowlisted IPs bypass every check.
		if ( $ip && WCAF_Helpers::is_ip_allowed( $ip, $opts ) ) {
			return;
		}

		$event = preg_replace( '/^(wc_ajax_|wp_ajax_nopriv_woocommerce_|wp_ajax_woocommerce_)/', '', (string) current_action() );

		WCAF_Stats::bump( 'refused:' . self::REASON );
		error_log( sprintf( 'WC Antifraud: Refused classic checkout request "%s" (Block Checkout store). IP: %s', $event, $ip ? $ip : 'unknown' ) );
		self::maybe_alert( $event, $ip ? $ip : 'unknown', $opts );

		wp_send_json(
			[
				'result'   => 'failure',
				'messages' => '<div class="woocommerce-error">' . esc_html( self::message() ) . '</div>',
				'refresh'  => false,
				'reload'   => false,
			],
			403
		);
	}

	/**
	 * Customer-facing message. Neutral on purpose: a real person can only land
	 * here through a stale page, and a reload gives them the live checkout.
	 *
	 * @return string
	 */
	public static function message() {
		return apply_filters(
			'wcaf_classic_lock_message',
			__( 'This checkout form is no longer in use. Please reload the checkout page to continue.', 'wc-antifraud' )
		);
	}

	/**
	 * One alert email per hour at most, carrying the number of refusals since
	 * the previous one. A bot fires dozens of these in minutes; a mail per hit
	 * would bury the inbox and teach the merchant to ignore the sender.
	 *
	 * @param string $event
	 * @param string $ip
	 * @param array  $opts
	 */
	private static function maybe_alert( $event, $ip, $opts ) {
		$state = get_transient( self::ALERT_TRANSIENT );
		if ( ! is_array( $state ) ) {
			$state = [ 'count' => 0, 'mailed' => 0, 'first' => time() ];
		}
		$state['count']++;
		$now = time();

		if ( $now - (int) $state['mailed'] < self::ALERT_INTERVAL ) {
			set_transient( self::ALERT_TRANSIENT, $state, DAY_IN_SECONDS );
			return;
		}

		$count = (int) $state['count'];
		$since = (int) $state['first'];
		$state = [ 'count' => 0, 'mailed' => $now, 'first' => $now ];
		set_transient( self::ALERT_TRANSIENT, $state, DAY_IN_SECONDS );

		$recipients = self::recipients( $opts );
		if ( empty( $recipients ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: number of refused requests */
			__( '[%1$s] WC Antifraud: %2$d classic checkout request(s) refused', 'wc-antifraud' ),
			wp_strip_all_tags( get_bloginfo( 'name' ) ),
			$count
		);
		$lines = [
			sprintf( __( 'Store: %s', 'wc-antifraud' ), home_url() ),
			sprintf( __( 'Refused requests: %d', 'wc-antifraud' ), $count ),
			sprintf( __( 'Since: %s (UTC)', 'wc-antifraud' ), gmdate( 'Y-m-d H:i:s', $since ) ),
			sprintf( __( 'Latest: %s from %s', 'wc-antifraud' ), $event, $ip ),
			'',
			__( 'This store uses the Block Checkout, so genuine customers never submit the classic checkout form. Requests to it come from bots working through the legacy flow (card testing) and were refused before any order or payment attempt was created.', 'wc-antifraud' ),
			'',
			sprintf( __( 'Next alert of this kind at the earliest in %d minutes. Counts appear on the Reports tab.', 'wc-antifraud' ), (int) ( self::ALERT_INTERVAL / MINUTE_IN_SECONDS ) ),
		];
		wp_mail( $recipients, $subject, implode( "\n", $lines ), [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	/**
	 * @param array $opts
	 * @return string[]
	 */
	private static function recipients( $opts ) {
		$raw = isset( $opts['email_recipients'] ) ? (string) $opts['email_recipients'] : '';
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $addr ) {
			$addr = sanitize_email( $addr );
			if ( $addr && is_email( $addr ) ) {
				$out[] = $addr;
			}
		}
		return array_unique( $out );
	}
}
