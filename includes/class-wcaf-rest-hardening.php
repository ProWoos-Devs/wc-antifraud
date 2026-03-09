<?php
/**
 * REST API Hardening
 *
 * Blocks unauthenticated order creation via WooCommerce REST/Store API.
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCAF_REST_Hardening {

	public static function init() {
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'block_unauthenticated_order_creation' ], 10, 3 );
	}

	/**
	 * Block unauthenticated POST/PUT to order-creation endpoints
	 *
	 * @param mixed           $result
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 * @return mixed|WP_Error
	 */
	public static function block_unauthenticated_order_creation( $result, $server, $request ) {
		$method = $request->get_method();
		if ( ! in_array( $method, [ 'POST', 'PUT' ], true ) ) {
			return $result;
		}

		$route          = $request->get_route();
		$blocked_routes = [
			'#^/wc/v[1-3]/orders/?$#',
			'#^/wc/store/v1/checkout/?$#',
			'#^/wc/store/checkout/?$#',
		];

		$is_blocked = false;
		foreach ( $blocked_routes as $pattern ) {
			if ( preg_match( $pattern, $route ) ) {
				$is_blocked = true;
				break;
			}
		}

		if ( ! $is_blocked ) {
			return $result;
		}

		// Allow authenticated admin users
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return $result;
		}

		// Allow WC API key auth
		if ( ! empty( $request->get_param( 'consumer_key' ) ) ) {
			return $result;
		}

		// Allow OAuth / Bearer auth
		if ( ! empty( $request->get_header( 'authorization' ) ) ) {
			return $result;
		}

		// Allow WooCommerce Store API requests (Block Checkout).
		// The Store API has its own nonce verification layer that runs later,
		// so we just need to confirm a nonce header is present (proves it came
		// from a browser with WC session, not a raw bot POST).
		$wc_nonce = $request->get_header( 'x-wc-store-api-nonce' );
		$nonce    = $request->get_header( 'nonce' );
		if ( ! empty( $wc_nonce ) || ! empty( $nonce ) ) {
			return $result;
		}

		$ip = WCAF_Helpers::get_client_ip();
		error_log( sprintf( 'WC Antifraud: Blocked REST API order creation. Route: %s, IP: %s', $route, $ip ?: 'unknown' ) );

		return new WP_Error( 'rest_forbidden', __( 'Order creation via REST API is not permitted.', 'wc-antifraud' ), [ 'status' => 403 ] );
	}
}
