<?php
/**
 * AbuseIPDB Reporting Handler
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AbuseIPDB class - reports fraud-order IPs to the AbuseIPDB community database
 *
 * When an order is marked as fraud, the attacking IP is reported to
 * https://www.abuseipdb.com/ so other stores and firewalls consuming AbuseIPDB
 * blocklists (Fail2Ban, CrowdSec, WAF rulesets) benefit from the detection.
 * Reporting is opt-in: it only runs when enabled in settings and an API key
 * is configured. Reports never include customer PII — only the IP, the
 * detection reasons, and the order timestamp.
 */
class WCAF_AbuseIPDB {

	/**
	 * AbuseIPDB v2 report endpoint
	 */
	const API_URL = 'https://api.abuseipdb.com/api/v2/report';

	/**
	 * AbuseIPDB report categories: 3 = Fraud Orders, 21 = Web App Attack
	 */
	const CATEGORIES = '3,21';

	/**
	 * Order meta key recording a successful report (holds the report UTC datetime)
	 */
	const REPORTED_META = '_wcaf_abuseipdb_reported';

	/**
	 * AbuseIPDB rejects report timestamps older than two months. Orders older
	 * than this (e.g. bulk-marked historical orders) are skipped rather than
	 * reported with a false current timestamp.
	 */
	const MAX_ORDER_AGE = 60 * DAY_IN_SECONDS;

	/**
	 * AbuseIPDB only accepts one report per IP per 15 minutes per reporter.
	 */
	const PER_IP_COOLDOWN = 15 * MINUTE_IN_SECONDS;

	/**
	 * Report the IP behind a fraud order to AbuseIPDB.
	 *
	 * Fire-and-forget: any failure is logged and never interrupts the order
	 * flow that triggered it.
	 *
	 * @param WC_Order $order   Order object
	 * @param array    $reasons Fraud reasons (as produced by the detection checks)
	 */
	public static function report_order( $order, $reasons ) {
		if ( ! $order ) {
			return;
		}

		$opts = WCAF_Helpers::get_options();
		if ( empty( $opts['enable_abuseipdb'] ) || empty( $opts['abuseipdb_api_key'] ) ) {
			return;
		}

		// Already reported (e.g. re-marked after a manual status change).
		if ( $order->get_meta( self::REPORTED_META ) ) {
			return;
		}

		// Use the IP stored ON THE ORDER, never the current request IP — a
		// bulk action runs in the admin's request, whose IP is the admin's.
		$ip = $order->get_customer_ip_address();
		if ( ! self::is_reportable_ip( $ip ) ) {
			return;
		}

		$date_created = $order->get_date_created();
		if ( ! $date_created || ( time() - $date_created->getTimestamp() ) > self::MAX_ORDER_AGE ) {
			return;
		}

		// Per-IP cooldown: the API 429s a second report of the same IP within
		// 15 minutes (relevant when bulk-marking several orders from one IP).
		$cooldown_key = 'wcaf_abuseipdb_' . md5( $ip );
		if ( get_transient( $cooldown_key ) ) {
			return;
		}
		set_transient( $cooldown_key, 1, self::PER_IP_COOLDOWN );

		// No PII in the comment — AbuseIPDB reports are public.
		$comment = sprintf(
			'Fraudulent WooCommerce order attempt (automated card-testing bot). Detection: %s.',
			html_entity_decode( wp_strip_all_tags( implode( ', ', $reasons ) ), ENT_QUOTES )
		);

		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Key'    => $opts['abuseipdb_api_key'],
					'Accept' => 'application/json',
				],
				'body'    => [
					'ip'         => $ip,
					'categories' => self::CATEGORIES,
					'comment'    => $comment,
					'timestamp'  => $date_created->format( DATE_ATOM ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log(
				sprintf(
					'WC Antifraud: AbuseIPDB report failed for order #%d (IP %s): %s',
					$order->get_id(),
					$ip,
					$response->get_error_message()
				)
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			$score = isset( $body['data']['abuseConfidenceScore'] ) ? $body['data']['abuseConfidenceScore'] : '?';
			$order->update_meta_data( self::REPORTED_META, gmdate( 'Y-m-d H:i:s' ) );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: IP address, 2: AbuseIPDB abuse confidence score */
					__( 'IP %1$s reported to AbuseIPDB (Fraud Orders). Abuse confidence score: %2$s.', 'wc-antifraud' ),
					$ip,
					$score
				)
			);
		} else {
			$detail = isset( $body['errors'][0]['detail'] ) ? $body['errors'][0]['detail'] : wp_remote_retrieve_body( $response );
			error_log(
				sprintf(
					'WC Antifraud: AbuseIPDB rejected report for order #%d (IP %s, HTTP %d): %s',
					$order->get_id(),
					$ip,
					$code,
					$detail
				)
			);
		}
	}

	/**
	 * Whether an IP is valid and public (private/reserved ranges are never reported).
	 *
	 * @param string $ip IP address
	 * @return bool
	 */
	private static function is_reportable_ip( $ip ) {
		return ! empty( $ip )
			&& false !== filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
	}
}
