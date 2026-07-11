<?php
/**
 * Stripe Decline Intelligence
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe Decline class - records WHY a Stripe payment failed on the order itself
 *
 * The Stripe gateway's own failure note is a generic "Stripe SCA authentication
 * failed. Reason: Your card was declined." — the real cause (Radar block,
 * issuer decline code, risk level) is only visible in the Stripe Dashboard.
 * This class listens to the gateway's payment-error webhook action and writes
 * a detailed order note plus structured order meta with the decline code and,
 * when available, the charge outcome (blocked vs declined, risk level, Radar
 * rule, whether the charge ever reached the card network).
 *
 * When the decline itself is an explicit fraud signal — Stripe Radar blocked
 * the payment, or the issuer returned a fraud decline code (fraudulent,
 * stolen_card, lost_card, pickup_card, merchant_blacklist) — the order is
 * additionally marked with the plugin's Fraud status. AbuseIPDB reporting is
 * deliberately SKIPPED for these markings: a Radar block can false-positive on
 * a real customer, and AbuseIPDB reports are public and effectively permanent,
 * while the Fraud status and note are internal and reversible.
 */
class WCAF_Stripe_Decline {

	/**
	 * Order meta key holding the structured decline detail (JSON)
	 */
	const DECLINE_META = '_wcaf_stripe_decline';

	/**
	 * Issuer decline codes that are explicit fraud signals.
	 * https://docs.stripe.com/declines/codes
	 */
	const FRAUD_DECLINE_CODES = [ 'fraudulent', 'stolen_card', 'lost_card', 'pickup_card', 'merchant_blacklist' ];

	/**
	 * Initialize hooks
	 */
	public static function init() {
		// Fired by the Stripe gateway's webhook handler AFTER it has set the
		// order to Failed, with the raw webhook event — the only point where
		// the real decline detail passes through WordPress. Because the order
		// is already Failed when this runs, marking it Fraud here is never
		// overwritten by the gateway.
		add_action( 'wc_gateway_stripe_process_webhook_payment_error', [ __CLASS__, 'handle_payment_error' ], 10, 2 );

		// Prominent decline panel on the order edit screen (order notes are
		// easy to miss in the notes stack).
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ], 10, 2 );
	}

	/**
	 * Record decline detail from a Stripe payment-failure webhook and mark the
	 * order as fraud when the decline is an explicit fraud signal.
	 *
	 * @param WC_Order $order        Order object
	 * @param object   $notification Raw Stripe webhook event (json_decode'd)
	 */
	public static function handle_payment_error( $order, $notification = null ) {
		if ( ! $order instanceof WC_Order || ! is_object( $notification ) || empty( $notification->type ) || empty( $notification->data->object ) ) {
			return;
		}

		// The same action fires for several webhook types (sources, vouchers,
		// exceptions); only card-payment failures carry decline detail.
		if ( ! in_array( $notification->type, [ 'payment_intent.payment_failed', 'charge.failed' ], true ) ) {
			return;
		}

		// Both webhook types can arrive for one failed payment — record once.
		if ( $order->get_meta( self::DECLINE_META ) ) {
			return;
		}

		$detail = ( 'charge.failed' === $notification->type )
			? self::extract_from_charge( $notification->data->object )
			: self::extract_from_intent( $notification->data->object );

		if ( empty( $detail['code'] ) && empty( $detail['decline_code'] ) && empty( $detail['outcome_type'] ) ) {
			return;
		}

		$order->update_meta_data( self::DECLINE_META, wp_json_encode( $detail ) );
		$order->save();
		$order->add_order_note( self::build_note( $detail ) );

		self::maybe_mark_fraud( $order, $detail );
	}

	/**
	 * Extract decline detail from a payment_intent.payment_failed event object.
	 *
	 * The intent carries last_payment_error (code, decline_code, message, card)
	 * but NOT the charge outcome; that lives on the charge object, so when the
	 * intent references a charge it is fetched with one read-only API call.
	 *
	 * @param object $intent Stripe PaymentIntent
	 * @return array Decline detail
	 */
	private static function extract_from_intent( $intent ) {
		$lpe = isset( $intent->last_payment_error ) && is_object( $intent->last_payment_error ) ? $intent->last_payment_error : null;

		$detail = [
			'source'         => 'payment_intent.payment_failed',
			'payment_intent' => $intent->id ?? '',
			'code'           => $lpe->code ?? '',
			'decline_code'   => $lpe->decline_code ?? '',
			'message'        => $lpe->message ?? '',
		];

		if ( isset( $lpe->payment_method->card ) ) {
			$detail = array_merge( $detail, self::card_fields( $lpe->payment_method->card ) );
		}

		// latest_charge is an ID string (or an expanded object on some API versions).
		$charge_id = '';
		if ( ! empty( $intent->latest_charge ) ) {
			$charge_id = is_object( $intent->latest_charge ) ? ( $intent->latest_charge->id ?? '' ) : $intent->latest_charge;
		} elseif ( ! empty( $lpe->charge ) ) {
			$charge_id = $lpe->charge;
		}

		if ( $charge_id ) {
			$detail['charge'] = $charge_id;
			$detail           = array_merge( $detail, self::retrieve_charge_outcome( $charge_id ) );
		}

		return $detail;
	}

	/**
	 * Extract decline detail from a charge.failed event object.
	 *
	 * @param object $charge Stripe Charge
	 * @return array Decline detail
	 */
	private static function extract_from_charge( $charge ) {
		$detail = [
			'source'         => 'charge.failed',
			'charge'         => $charge->id ?? '',
			'payment_intent' => is_string( $charge->payment_intent ?? null ) ? $charge->payment_intent : '',
			'code'           => $charge->failure_code ?? '',
			'decline_code'   => '',
			'message'        => $charge->failure_message ?? '',
		];

		if ( isset( $charge->payment_method_details->card ) ) {
			$detail = array_merge( $detail, self::card_fields( $charge->payment_method_details->card ) );
		}

		if ( isset( $charge->outcome ) && is_object( $charge->outcome ) ) {
			$detail = array_merge( $detail, self::outcome_fields( $charge->outcome ) );
		}

		return $detail;
	}

	/**
	 * Fetch a charge's outcome via the Stripe gateway's API wrapper (read-only).
	 *
	 * @param string $charge_id Stripe charge ID
	 * @return array Outcome fields (empty on any failure — never blocks the flow)
	 */
	private static function retrieve_charge_outcome( $charge_id ) {
		if ( ! class_exists( 'WC_Stripe_API' ) ) {
			return [];
		}

		try {
			$charge = WC_Stripe_API::retrieve( 'charges/' . $charge_id );
		} catch ( Exception $e ) {
			return [];
		}

		if ( empty( $charge ) || ! empty( $charge->error ) || empty( $charge->outcome ) || ! is_object( $charge->outcome ) ) {
			return [];
		}

		return self::outcome_fields( $charge->outcome );
	}

	/**
	 * Normalize a charge outcome object into flat fields.
	 *
	 * outcome.type 'blocked' means Stripe Radar stopped the payment itself;
	 * outcome.reason holds either the Radar reason (highest_risk_level) or the
	 * issuer decline code (insufficient_funds, fraudulent, ...).
	 *
	 * @param object $outcome Stripe charge outcome
	 * @return array
	 */
	private static function outcome_fields( $outcome ) {
		$rule = '';
		if ( ! empty( $outcome->rule ) ) {
			$rule = is_object( $outcome->rule ) ? ( $outcome->rule->id ?? '' ) : $outcome->rule;
		}

		return [
			'outcome_type'   => $outcome->type ?? '',
			'outcome_reason' => $outcome->reason ?? '',
			'risk_level'     => $outcome->risk_level ?? '',
			'risk_rule'      => $rule,
			'seller_message' => $outcome->seller_message ?? '',
			'network_status' => $outcome->network_status ?? '',
		];
	}

	/**
	 * Normalize card fields.
	 *
	 * @param object $card Stripe card object
	 * @return array
	 */
	private static function card_fields( $card ) {
		return [
			'card_brand'   => $card->brand ?? '',
			'card_last4'   => $card->last4 ?? '',
			'card_funding' => $card->funding ?? '',
			'card_country' => $card->country ?? '',
		];
	}

	/**
	 * One-sentence explanation of the failure, shared by the order note and
	 * the order-screen panel.
	 *
	 * @param array $detail Decline detail
	 * @return string
	 */
	private static function build_headline( $detail ) {
		if ( 'blocked' === ( $detail['outcome_type'] ?? '' ) ) {
			$specifics = [];
			if ( ! empty( $detail['risk_rule'] ) ) {
				/* translators: %s: Radar rule ID */
				$specifics[] = sprintf( __( 'Radar rule %s', 'wc-antifraud' ), $detail['risk_rule'] );
			}
			if ( ! empty( $detail['risk_level'] ) ) {
				/* translators: %s: Radar risk level */
				$specifics[] = sprintf( __( 'risk level: %s', 'wc-antifraud' ), $detail['risk_level'] );
			}
			if ( ! empty( $detail['decline_code'] ) ) {
				/* translators: %s: Stripe decline code */
				$specifics[] = sprintf( __( 'decline code: %s', 'wc-antifraud' ), $detail['decline_code'] );
			}

			return sprintf(
				/* translators: %s: comma-separated decline specifics */
				__( 'Stripe blocked this payment before it reached the card network (%s).', 'wc-antifraud' ),
				$specifics ? implode( ', ', $specifics ) : '?'
			);
		}

		if ( ! empty( $detail['decline_code'] ) ) {
			return sprintf(
				/* translators: %s: Stripe decline code */
				__( 'The card issuer declined this payment (decline code: %s).', 'wc-antifraud' ),
				$detail['decline_code']
			);
		}

		if ( ! empty( $detail['outcome_reason'] ) ) {
			return sprintf(
				/* translators: %s: Stripe outcome reason */
				__( 'Stripe reported the payment as failed (reason: %s).', 'wc-antifraud' ),
				$detail['outcome_reason']
			);
		}

		return sprintf(
			/* translators: %s: Stripe error code */
			__( 'Stripe reported the payment as failed (code: %s).', 'wc-antifraud' ),
			! empty( $detail['code'] ) ? $detail['code'] : '?'
		);
	}

	/**
	 * Stripe Dashboard URL for the failed payment (empty if no ID is known).
	 *
	 * @param array $detail Decline detail
	 * @return string
	 */
	private static function dashboard_url( $detail ) {
		$id = ! empty( $detail['payment_intent'] ) ? $detail['payment_intent'] : ( $detail['charge'] ?? '' );
		return $id ? 'https://dashboard.stripe.com/payments/' . rawurlencode( $id ) : '';
	}

	/**
	 * Build the human-readable order note from the decline detail.
	 *
	 * @param array $detail Decline detail
	 * @return string
	 */
	private static function build_note( $detail ) {
		$parts = [ self::build_headline( $detail ) ];

		if ( ! empty( $detail['card_brand'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: card brand, 2: funding type, 3: last 4 digits, 4: issuing country */
				__( 'Card: %1$s %2$s ending %3$s (%4$s).', 'wc-antifraud' ),
				$detail['card_brand'],
				$detail['card_funding'],
				$detail['card_last4'] ? $detail['card_last4'] : '????',
				$detail['card_country'] ? $detail['card_country'] : '?'
			);
		}

		$url = self::dashboard_url( $detail );
		if ( $url ) {
			$parts[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $url ),
				__( 'Review it in the Stripe Dashboard', 'wc-antifraud' )
			) . '.';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Whether the decline detail is an explicit fraud signal.
	 *
	 * @param array $detail Decline detail
	 * @return bool
	 */
	private static function is_fraud_signal( $detail ) {
		if ( 'blocked' === ( $detail['outcome_type'] ?? '' ) ) {
			return true;
		}

		if ( in_array( $detail['decline_code'] ?? '', self::FRAUD_DECLINE_CODES, true ) ) {
			return true;
		}

		// On charge.failed events the issuer decline code arrives as outcome.reason.
		return in_array( $detail['outcome_reason'] ?? '', self::FRAUD_DECLINE_CODES, true );
	}

	/**
	 * Mark the order as fraud when the decline is an explicit fraud signal.
	 *
	 * AbuseIPDB reporting is skipped (third mark_as_fraud argument): unlike the
	 * plugin's own attribution-based detections, a Stripe fraud signal can
	 * belong to a real customer whose card scored badly, and public IP reports
	 * are not reversible.
	 *
	 * @param WC_Order $order  Order object
	 * @param array    $detail Decline detail
	 */
	private static function maybe_mark_fraud( $order, $detail ) {
		if ( ! self::is_fraud_signal( $detail ) ) {
			return;
		}

		$opts = WCAF_Helpers::get_options();
		if ( empty( $opts['enable_stripe_decline'] ) ) {
			return;
		}

		if ( in_array( $order->get_status(), WCAF_Order_Status::fraud_statuses(), true ) ) {
			return;
		}

		if ( 'blocked' === ( $detail['outcome_type'] ?? '' ) ) {
			$reason = sprintf(
				/* translators: %s: Radar risk level */
				__( 'Stripe Radar blocked the payment as too risky (risk level: %s)', 'wc-antifraud' ),
				$detail['risk_level'] ? $detail['risk_level'] : '?'
			);
		} else {
			$code   = ! empty( $detail['decline_code'] ) ? $detail['decline_code'] : ( $detail['outcome_reason'] ?? '' );
			$reason = sprintf(
				/* translators: %s: Stripe decline code */
				__( 'Card issuer fraud decline (code: %s)', 'wc-antifraud' ),
				$code
			);
		}

		WCAF_Order_Status::mark_as_fraud(
			$order,
			[ $reason ],
			false,
			WCAF_Order_Status::STRIPE_STATUS_SLUG
		);
		WCAF_Email_Alerts::send_alert( $order, [ $reason ], $opts );
		do_action( 'wcaf_suspicious_order_detected', $order, [ $reason ] );
	}

	/**
	 * Register the decline panel on the order edit screen (classic and HPOS),
	 * only for orders that carry decline detail.
	 *
	 * @param string                $screen_or_type Screen ID (HPOS) or post type (classic)
	 * @param WP_Post|WC_Order|null $object         The object being edited
	 */
	public static function register_meta_box( $screen_or_type, $object = null ) {
		$order = null;
		if ( $object instanceof WC_Order ) {
			$order = $object;
		} elseif ( $object instanceof WP_Post && 'shop_order' === $object->post_type ) {
			$order = wc_get_order( $object->ID );
		}

		if ( ! $order || ! $order->get_meta( self::DECLINE_META ) ) {
			return;
		}

		// null screen = current screen; 'side'/'high' puts the panel at the top
		// of the right-hand column, above Order notes.
		add_meta_box(
			'wcaf_stripe_decline',
			__( 'Stripe decline', 'wc-antifraud' ),
			[ __CLASS__, 'render_meta_box' ],
			null,
			'side',
			'high'
		);
	}

	/**
	 * Render the decline panel.
	 *
	 * @param WP_Post|WC_Order $object The object being edited
	 */
	public static function render_meta_box( $object ) {
		$order = ( $object instanceof WC_Order ) ? $object : wc_get_order( $object->ID );
		if ( ! $order ) {
			return;
		}

		$detail = json_decode( (string) $order->get_meta( self::DECLINE_META ), true );
		if ( ! is_array( $detail ) ) {
			return;
		}

		$is_fraud = self::is_fraud_signal( $detail );
		$accent   = $is_fraud ? '#635bff' : '#996800';

		printf(
			'<div style="border-left:4px solid %s;padding:2px 2px 2px 10px;margin:4px 0 8px;"><p style="margin:0;font-size:13px;font-weight:600;">%s</p></div>',
			esc_attr( $accent ),
			esc_html( self::build_headline( $detail ) )
		);

		$rows = array_filter( [
			__( 'Verdict', 'wc-antifraud' )        => $is_fraud ? __( 'Fraud signal from Stripe', 'wc-antifraud' ) : __( 'Ordinary decline', 'wc-antifraud' ),
			__( 'Risk level', 'wc-antifraud' )     => $detail['risk_level'] ?? '',
			__( 'Radar rule', 'wc-antifraud' )     => $detail['risk_rule'] ?? '',
			__( 'Decline code', 'wc-antifraud' )   => ! empty( $detail['decline_code'] ) ? $detail['decline_code'] : ( $detail['outcome_reason'] ?? '' ),
			__( 'Card', 'wc-antifraud' )           => ! empty( $detail['card_brand'] )
				? trim( sprintf( '%s %s •••• %s (%s)', $detail['card_brand'], $detail['card_funding'] ?? '', $detail['card_last4'] ?? '', $detail['card_country'] ?? '' ) )
				: '',
			__( 'Card network', 'wc-antifraud' )   => ( 'not_sent_to_network' === ( $detail['network_status'] ?? '' ) )
				? __( 'never contacted', 'wc-antifraud' )
				: ( $detail['network_status'] ?? '' ),
		] );

		echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><td style="padding:3px 8px 3px 0;color:#646970;white-space:nowrap;vertical-align:top;">%s</td><td style="padding:3px 0;"><code style="font-size:11px;">%s</code></td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}
		echo '</table>';

		$url = self::dashboard_url( $detail );
		if ( $url ) {
			printf(
				'<p style="margin:10px 0 4px;"><a href="%s" target="_blank" rel="noopener" class="button">%s</a></p>',
				esc_url( $url ),
				esc_html__( 'Review it in the Stripe Dashboard', 'wc-antifraud' )
			);
		}
	}
}
