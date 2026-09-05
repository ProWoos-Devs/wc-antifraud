<?php
/**
 * Decline clustering: repeated payment failures from one visitor.
 *
 * Card testing is defined by declines. A shopper's card fails once, maybe
 * twice, then they succeed or leave. Several failed payments from ONE visitor
 * inside a day is a different population, and it is the population this class
 * exists to surface and, when the merchant sets a limit, to stop before the
 * next attempt reaches the gateway.
 *
 * Failures are counted, never orders. Counting orders would point at the one
 * legitimate high-volume source, a trade counter or phone-order desk placing
 * many SUCCESSFUL orders from one browser. Only orders WooCommerce itself moved
 * to Failed are counted; Pending is the normal resting state for offline
 * payment methods and is never treated as a failure.
 *
 * Each failure is counted under two keys, so both shapes of attack are seen:
 * the WooCommerce session the order was placed from (one visit working through
 * many cards) and the customer IP stored on the order (many fresh visits from
 * one address). Both keys are read from the ORDER, never from the request that
 * happens to move it to Failed, because that request is frequently a gateway
 * webhook whose IP is the gateway's own server.
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_Decline_Clusters {

	/**
	 * Option holding the rolling counts. Not autoloaded: read on checkout
	 * submissions only when a limit is set, on failed orders, and in the admin.
	 */
	const OPTION_KEY = 'wcaf_decline_clusters';

	/**
	 * Order meta stamped at checkout with a one-way label of the WooCommerce
	 * session, so failures can be grouped per visit without storing the session
	 * ID itself.
	 */
	const VISITOR_META = '_wcaf_visitor';

	/**
	 * How long a failure stays counted. The signal is a rate, not a total.
	 */
	const WINDOW = DAY_IN_SECONDS;

	/**
	 * Failures from one visitor before the store owner is told. Below this the
	 * ordinary explanations still hold (mistyped card, expired card, one bank
	 * decline before switching cards).
	 */
	const ALERT_FROM = 5;

	/**
	 * Lowest block limit a merchant may set. Blocking on one or two declines
	 * would reject shoppers over a pattern that is still ordinary.
	 */
	const MIN_BLOCK_THRESHOLD = 3;

	/**
	 * Most visitors tracked at once. An attack concentrates failures on few
	 * keys; the cap is for the opposite shape, a store whose gateway is failing
	 * every genuine checkout.
	 */
	const MAX_TRACKED = 500;

	/**
	 * Bound one visitor's timestamp list. The settings UI caps the blocking
	 * threshold at 100, so retaining twice that is sufficient for enforcement
	 * and keeps a sustained attack from growing the option without limit.
	 */
	const MAX_EVENTS_PER_KEY = 200;

	public static function init() {
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'record_failed_order' ], 10, 2 );

		// Stamp the visitor label on orders from both checkout surfaces, before
		// WooCommerce saves the order.
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'stamp_visitor_classic' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'stamp_visitor_store_api' ], 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
		}
	}

	// ── Visitor label ─────────────────────────────────────────────────

	/**
	 * One-way label for the current WooCommerce session, or '' when there is none.
	 *
	 * @return string
	 */
	public static function current_visitor_key() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		$customer_id = (string) WC()->session->get_customer_id();
		if ( '' === $customer_id ) {
			return '';
		}
		return 'v:' . substr( hash( 'sha256', $customer_id ), 0, 16 );
	}

	/**
	 * Key under which failures from an IP are counted.
	 *
	 * @param string $ip
	 * @return string
	 */
	private static function ip_key( $ip ) {
		return $ip ? 'ip:' . $ip : '';
	}

	/**
	 * @param WC_Order $order
	 * @param array    $data  Posted checkout data (unused).
	 */
	public static function stamp_visitor_classic( $order, $data = [] ) {
		self::stamp_visitor( $order );
	}

	/**
	 * @param WC_Order        $order
	 * @param WP_REST_Request $request (unused)
	 */
	public static function stamp_visitor_store_api( $order, $request = null ) {
		self::stamp_visitor( $order );
	}

	/**
	 * @param WC_Order $order
	 */
	private static function stamp_visitor( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$key = self::current_visitor_key();
		if ( '' === $key ) {
			return;
		}
		$order->update_meta_data( self::VISITOR_META, $key );
	}

	// ── Recording ─────────────────────────────────────────────────────

	/**
	 * Count a failed payment against the visitor and the IP that placed the order.
	 *
	 * @param int           $order_id
	 * @param WC_Order|null $order
	 */
	public static function record_failed_order( $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$ip = (string) $order->get_customer_ip_address();
		if ( $ip && WCAF_Helpers::is_ip_allowed( $ip, WCAF_Helpers::get_options() ) ) {
			// The merchant's own test declines must not raise the alarm.
			return;
		}

		// While an undeclared proxy hides the real addresses, the IP key would
		// count every customer's failures under the proxy's address; keep only
		// the session key.
		$ip_key = WCAF_Client_IP::ip_rules_suspended() ? '' : self::ip_key( $ip );
		$keys   = array_filter( [ (string) $order->get_meta( self::VISITOR_META ), $ip_key ] );
		if ( empty( $keys ) ) {
			return;
		}

		$now         = time();
		$clusters    = self::load( $now );
		$event_limit = max( self::MAX_EVENTS_PER_KEY, self::block_threshold() );
		foreach ( $keys as $key ) {
			$existing = $clusters[ $key ] ?? [ 'events' => [] ];
			$events   = isset( $existing['events'] ) && is_array( $existing['events'] ) ? $existing['events'] : [];
			$events[] = $now;
			if ( count( $events ) > $event_limit ) {
				$events = array_slice( $events, -$event_limit );
			}
			$clusters[ $key ] = [
				'events'   => $events,
				'failures' => count( $events ),
				'first'    => (int) reset( $events ),
				'last'     => $now,
			];
			if ( self::ALERT_FROM === $clusters[ $key ]['failures'] ) {
				WCAF_Stats::bump( 'decline_alert' );
			}
		}
		self::save( $clusters );
	}

	// ── Reading ───────────────────────────────────────────────────────

	/**
	 * Failures recorded against one key inside the window.
	 *
	 * @param string $key
	 * @return int
	 */
	public static function failures_for( $key ) {
		if ( '' === $key ) {
			return 0;
		}
		$clusters = self::load( time() );
		return (int) ( $clusters[ $key ]['failures'] ?? 0 );
	}

	/**
	 * The merchant's block limit, or 0 when blocking is off.
	 *
	 * @return int
	 */
	public static function block_threshold() {
		$opts       = WCAF_Helpers::get_options();
		$configured = (int) ( $opts['decline_block_threshold'] ?? 0 );
		if ( $configured < 1 ) {
			return 0;
		}
		return max( $configured, self::MIN_BLOCK_THRESHOLD );
	}

	/**
	 * Whether the visitor behind the current request (session or IP) has failed
	 * payment enough times for the merchant's limit.
	 *
	 * @param string $ip Client IP of the current request.
	 * @return bool
	 */
	public static function is_over_block_threshold( $ip ) {
		$threshold = self::block_threshold();
		if ( 0 === $threshold ) {
			return false;
		}
		$ip_key = WCAF_Client_IP::ip_rules_suspended() ? '' : self::ip_key( $ip );
		foreach ( [ self::current_visitor_key(), $ip_key ] as $key ) {
			if ( self::failures_for( $key ) >= $threshold ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keys at or above the alert threshold, worst first.
	 *
	 * @return array[] Each: [ 'key' => string, 'failures' => int, 'first' => int, 'last' => int ]
	 */
	public static function alerting_clusters() {
		$out = [];
		foreach ( self::load( time() ) as $key => $row ) {
			if ( (int) $row['failures'] < self::ALERT_FROM ) {
				continue;
			}
			$out[] = [
				'key'      => (string) $key,
				'failures' => (int) $row['failures'],
				'first'    => (int) $row['first'],
				'last'     => (int) $row['last'],
			];
		}
		usort( $out, function ( $a, $b ) {
			return $b['failures'] <=> $a['failures'];
		} );
		return $out;
	}

	/**
	 * Forget everything recorded (merchant resolves the alert).
	 */
	public static function reset() {
		delete_option( self::OPTION_KEY );
	}

	// ── Admin notice ──────────────────────────────────────────────────

	/**
	 * Non-dismissible notice while a visitor is above the alert threshold. It
	 * clears itself once the failures age out of the window, or on Clear.
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$clusters = self::alerting_clusters();
		if ( empty( $clusters ) ) {
			return;
		}

		$worst = (int) $clusters[0]['failures'];
		$what  = 0 === strpos( $clusters[0]['key'], 'ip:' )
			? sprintf( __( 'IP %s', 'wc-antifraud' ), substr( $clusters[0]['key'], 3 ) )
			: __( 'a single checkout session', 'wc-antifraud' );

		$headline = sprintf(
			/* translators: 1: number of failed payments, 2: "IP x.x.x.x" or "a single checkout session" */
			_n( '%1$d payment has failed from %2$s in the last 24 hours.', '%1$d payments have failed from %2$s in the last 24 hours.', $worst, 'wc-antifraud' ),
			$worst,
			$what
		);

		$settings_url = admin_url( 'admin.php?page=wc-antifraud&tab=detection' );
		$action       = 0 === self::block_threshold()
			? sprintf(
				/* translators: %s: settings URL */
				__( 'WC Antifraud is reporting this, not blocking it. If this is not your own testing, set a limit under <a href="%s">Detection Rules, Repeated Payment Failures</a> and further checkouts from that visitor will be refused before they reach the gateway.', 'wc-antifraud' ),
				esc_url( $settings_url )
			)
			: sprintf(
				/* translators: %d: block limit */
				__( 'Checkouts from that visitor are being refused (limit: %d failures).', 'wc-antifraud' ),
				self::block_threshold()
			);

		$clear_url = wp_nonce_url( admin_url( 'admin.php?page=wc-antifraud&tab=detection&wcaf_action=reset_declines' ), 'wcaf_admin_action' );

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $headline ),
			esc_html__( 'Shoppers fail once or twice and then succeed or leave. Repeated failures from one visitor inside a day is what card testing looks like.', 'wc-antifraud' ),
			wp_kses( $action, [ 'a' => [ 'href' => [] ] ] ),
			esc_url( $clear_url ),
			esc_html__( 'Clear this alert', 'wc-antifraud' )
		);
	}

	// ── Storage ───────────────────────────────────────────────────────

	/**
	 * Read the store, dropping individual failures that have aged out of the
	 * window. Older plugin versions stored only an aggregate count and cannot
	 * prove when each failure occurred; treat every failure as if it happened
	 * at the last one, so an upgrade never resets a cluster mid-attack.
	 *
	 * @param int $now
	 * @return array
	 */
	private static function load( $now ) {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$live        = [];
		$cutoff      = $now - self::WINDOW;
		$event_limit = max( self::MAX_EVENTS_PER_KEY, self::block_threshold() );
		foreach ( $stored as $key => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['last'] ) ) {
				continue;
			}

			if ( isset( $row['events'] ) && is_array( $row['events'] ) ) {
				$events = array_values( array_filter( array_map( 'intval', $row['events'] ), function ( $timestamp ) use ( $cutoff, $now ) {
					return $timestamp >= $cutoff && $timestamp <= $now;
				} ) );
				sort( $events, SORT_NUMERIC );
			} else {
				// Legacy aggregate row (through 1.9.0): keep its count alive for
				// one more window from the last failure, then let it decay.
				$last   = (int) $row['last'];
				$count  = max( 1, (int) ( $row['failures'] ?? 1 ) );
				$events = $last >= $cutoff && $last <= $now ? array_fill( 0, $count, $last ) : [];
			}

			if ( empty( $events ) ) {
				continue;
			}
			if ( count( $events ) > $event_limit ) {
				$events = array_slice( $events, -$event_limit );
			}
			$live[ (string) $key ] = [
				'events'   => $events,
				'failures' => count( $events ),
				'first'    => (int) reset( $events ),
				'last'     => (int) end( $events ),
			];
		}
		return $live;
	}

	/**
	 * Write the store back, keeping the most recent entries when over the cap.
	 *
	 * @param array $clusters
	 */
	private static function save( $clusters ) {
		if ( count( $clusters ) > self::MAX_TRACKED ) {
			uasort( $clusters, function ( $a, $b ) {
				return $b['last'] <=> $a['last'];
			} );
			$clusters = array_slice( $clusters, 0, self::MAX_TRACKED, true );
		}
		update_option( self::OPTION_KEY, $clusters, false );
	}
}
