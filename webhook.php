<?php
/**
 * Razorpay webhook handler.
 *
 * Processes webhook events sent by Razorpay to
 * admin-ajax.php?action=razorpay-webhook.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Set this in your wp-config.php for debugging.
// define( 'PMPRO_RAZORPAY_DEBUG', true );

/**
 * Process an incoming Razorpay webhook request.
 *
 * @since 1.0.0
 */
function pmpro_razorpay_process_webhook() {
	global $pmpro_razorpay_logstr;

	$pmpro_razorpay_logstr = ''; // Will put debug info here and write to razorpay_webhook.txt.

	if ( ! function_exists( 'pmpro_getParam' ) ) {
		return;
	}

	// Read the raw JSON body. Razorpay sends the event as raw JSON, not as $_POST.
	$payload = file_get_contents( 'php://input' );

	// Read the signature from the X-Razorpay-Signature header.
	$signature = '';
	if ( isset( $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ) ) {
		$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ) );
	}

	// Get the configured webhook secrets (live and/or sandbox).
	$secret         = get_option( 'pmpro_razorpay_webhook_secret' );
	$sandbox_secret = get_option( 'pmpro_razorpay_sandbox_webhook_secret' );

	if ( empty( $secret ) && empty( $sandbox_secret ) ) {
		pmpro_razorpay_webhook_log( __( 'Razorpay webhook secret not configured.', 'pmpro-razorpay' ) );
		pmpro_razorpay_Exit();
	}

	// Verify the webhook signature against any configured webhook secret
	// (live or sandbox). Razorpay sends both environments to the same URL.
	if ( false === $payload || empty( $signature ) || ! PMProGateway_Razorpay_API::verify_webhook_signature_any( $payload, $signature ) ) {
		pmpro_razorpay_webhook_log( __( 'Razorpay webhook signature verification failed.', 'pmpro-razorpay' ) );
		status_header( 400 );
		pmpro_razorpay_Exit();
	}

	// Decode the event.
	$event = json_decode( $payload, true );

	if ( empty( $event ) || empty( $event['event'] ) ) {
		pmpro_razorpay_webhook_log( __( 'Razorpay webhook contained no event data.', 'pmpro-razorpay' ) );
		status_header( 400 );
		pmpro_razorpay_Exit();
	}

	$event_type = sanitize_text_field( $event['event'] );

	// Full reference of event types and responses:
	// https://razorpay.com/docs/webhooks/
	switch ( $event_type ) {

		case 'subscription.authenticated':
			pmpro_razorpay_handle_subscription_authenticated( $event );
			pmpro_razorpay_Exit();
			break;

		case 'subscription.activated':
			pmpro_razorpay_handle_subscription_activated( $event );
			pmpro_razorpay_Exit();
			break;

		case 'subscription.charged':
			pmpro_razorpay_handle_subscription_charged( $event );
			pmpro_razorpay_Exit();
			break;

		case 'subscription.charge.failed':
		case 'payment.failed':
			pmpro_razorpay_handle_payment_failed( $event );
			pmpro_razorpay_Exit();
			break;

		case 'subscription.cancelled':
		case 'subscription.halted':
		case 'subscription.completed':
			$subscription    = pmpro_razorpay_get_entity( $event, 'subscription' );
			$subscription_id = isset( $subscription['id'] ) ? sanitize_text_field( $subscription['id'] ) : '';

			pmpro_razorpay_log_transition( '', $subscription_id, $event_type, '', '', '', 'cancellation', 'terminal subscription state' );
			pmpro_razorpay_webhook_log( pmpro_handle_subscription_cancellation_at_gateway( $subscription_id, 'razorpay', get_option( 'pmpro_gateway_environment' ) ) );
			pmpro_razorpay_Exit();
			break;

		case 'subscription.expired':
		case 'subscription.paused':
		case 'subscription.resumed':
		case 'subscription.pending':
			$subscription    = pmpro_razorpay_get_entity( $event, 'subscription' );
			$subscription_id = isset( $subscription['id'] ) ? sanitize_text_field( $subscription['id'] ) : '';

			pmpro_razorpay_webhook_log( sprintf(
				/* translators: %1$s: Razorpay event type, %2$s: Razorpay subscription ID. */
				__( 'Subscription %1$s for subscription # (%2$s). No PMPro state change.', 'pmpro-razorpay' ),
				$event_type,
				$subscription_id
			) );
			pmpro_razorpay_Exit();
			break;

		case 'payment.authorized':
			pmpro_razorpay_handle_payment_authorized( $event );
			pmpro_razorpay_Exit();
			break;

		case 'refund.created':
		case 'refund.processed':
			pmpro_razorpay_handle_refund( $event );
			pmpro_razorpay_Exit();
			break;

		case 'payment.captured':
			pmpro_razorpay_handle_payment_captured( $event );
			pmpro_razorpay_Exit();
			break;

		default:
			do_action( 'pmpro_razorpay_other_webhook_events', $event_type, $event['payload'] );
			pmpro_razorpay_Exit();
			break;
	}
}
pmpro_razorpay_process_webhook();

/**
 * Extract an entity from a Razorpay webhook payload.
 *
 * Razorpay nests each entity under its type name followed by an "entity"
 * key, e.g. $event['payload']['subscription']['entity'].
 *
 * @param array  $event Decoded webhook event.
 * @param string $type  Entity type (e.g. 'subscription', 'payment', 'refund').
 * @return array The entity data, or an empty array if not found.
 * @since 1.0.0
 */
function pmpro_razorpay_get_entity( $event, $type ) {
	if ( isset( $event['payload'][ $type ]['entity'] ) && is_array( $event['payload'][ $type ]['entity'] ) ) {
		return $event['payload'][ $type ]['entity'];
	}

	if ( isset( $event['payload'][ $type ] ) && is_array( $event['payload'][ $type ] ) ) {
		return $event['payload'][ $type ];
	}

	return array();
}

/**
 * Complete checkout for a MemberOrder.
 *
 * @param MemberOrder $morder The order to complete checkout for.
 * @return bool
 * @since 1.0.0
 */
function pmpro_razorpay_complete_checkout( $morder ) {
	pmpro_pull_checkout_data_from_order( $morder );
	return pmpro_complete_async_checkout( $morder );
}

/**
 * Log a structured PMPro/Razorpay state transition.
 *
 * Answers: order id, subscription id, event, razorpay status, payment id,
 * PMPro order status, action taken, and reason. Never logs secrets.
 *
 * @param string $order_id         PMPro order ID (or empty).
 * @param string $subscription_id  Razorpay subscription ID (or empty).
 * @param string $event            Razorpay webhook event.
 * @param string $razorpay_status  Razorpay subscription/payment status.
 * @param string $payment_id       Razorpay payment ID (or empty).
 * @param string $pmpro_status     PMPro order status (or empty).
 * @param string $action           Action taken (or empty).
 * @param string $reason           Reason (or empty).
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_log_transition( $order_id, $subscription_id, $event, $razorpay_status, $payment_id, $pmpro_status, $action, $reason = '' ) {
	pmpro_razorpay_webhook_log( sprintf(
		'[PMPro Razorpay] order_id=%s event=%s razorpay_subscription=%s razorpay_status=%s payment_id=%s pmpro_status=%s action=%s reason=%s',
		$order_id,
		$event,
		$subscription_id,
		$razorpay_status,
		$payment_id,
		$pmpro_status,
		$action,
		$reason
	) );
}

/**
 * Handle a subscription.authenticated event.
 *
 * Authentication means the customer completed the mandate/payment-method
 * authentication transaction. It is NOT proof that the membership amount was
 * charged (for a future-start subscription it is only the auto-refunded token).
 * Record the subscription id only; never complete a paid checkout here.
 *
 * @param array $event Decoded webhook event.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_handle_subscription_authenticated( $event ) {
	$subscription = pmpro_razorpay_get_entity( $event, 'subscription' );

	$subscription_id = ( ! empty( $subscription['id'] ) ) ? sanitize_text_field( $subscription['id'] ) : '';

	if ( empty( $subscription_id ) ) {
		pmpro_razorpay_webhook_log( __( 'No subscription id found in subscription.authenticated event.', 'pmpro-razorpay' ) );
		return;
	}

	$morder = pmpro_razorpay_get_checkout_order( $subscription );

	if ( empty( $morder->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay subscription ID. */
			__( "Couldn't find the order for subscription # (%s).", 'pmpro-razorpay' ),
			$subscription_id
		) );
		return;
	}

	if ( empty( $morder->subscription_transaction_id ) ) {
		$morder->subscription_transaction_id = $subscription_id;
		$morder->saveOrder();
	}

	pmpro_razorpay_log_transition(
		$morder->id,
		$subscription_id,
		'subscription.authenticated',
		isset( $subscription['status'] ) ? sanitize_text_field( $subscription['status'] ) : '',
		'',
		$morder->status,
		'record_subscription_id',
		'authentication is not proof of payment; awaiting first charge'
	);
}

/**
 * Handle a subscription.activated event.
 *
 * Activation means the billing cycle started and Razorpay ATTEMPTED a charge.
 * It does not guarantee the charge succeeded, so do not complete checkout here.
 * The canonical confirmation is subscription.charged (backup: payment.captured).
 *
 * @param array $event Decoded webhook event.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_handle_subscription_activated( $event ) {
	$subscription = pmpro_razorpay_get_entity( $event, 'subscription' );

	$subscription_id = ( ! empty( $subscription['id'] ) ) ? sanitize_text_field( $subscription['id'] ) : '';

	if ( empty( $subscription_id ) ) {
		pmpro_razorpay_webhook_log( __( 'No subscription id found in subscription.activated event.', 'pmpro-razorpay' ) );
		return;
	}

	$morder = pmpro_razorpay_get_checkout_order( $subscription );

	if ( empty( $morder->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay subscription ID. */
			__( "Couldn't find the order for subscription # (%s).", 'pmpro-razorpay' ),
			$subscription_id
		) );
		return;
	}

	if ( empty( $morder->subscription_transaction_id ) ) {
		$morder->subscription_transaction_id = $subscription_id;
		$morder->saveOrder();
	}

	pmpro_razorpay_log_transition(
		$morder->id,
		$subscription_id,
		'subscription.activated',
		isset( $subscription['status'] ) ? sanitize_text_field( $subscription['status'] ) : '',
		'',
		$morder->status,
		'no_action',
		'activation attempts a charge but does not confirm it; awaiting subscription.charged'
	);
}

/**
 * Handle a payment.authorized event (the ₹1/₹5 mandate token, auto-refunded).
 *
 * This is never the membership payment. Log only; take no PMPro action.
 *
 * @param array $event Decoded webhook event.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_handle_payment_authorized( $event ) {
	$payment = pmpro_razorpay_get_entity( $event, 'payment' );

	$payment_id = ( ! empty( $payment['id'] ) ) ? sanitize_text_field( $payment['id'] ) : '';

	pmpro_razorpay_log_transition(
		'',
		( ! empty( $payment['subscription_id'] ) ) ? sanitize_text_field( $payment['subscription_id'] ) : '',
		'payment.authorized',
		isset( $payment['status'] ) ? sanitize_text_field( $payment['status'] ) : '',
		$payment_id,
		'',
		'no_action',
		'mandate token authorization is not a membership payment'
	);
}

/**
 * Find the checkout order for a subscription entity.
 *
 * First tries the order id stored in the subscription notes
 * (pmpro_order_id or pmpro_orderid), then falls back to looking up the
 * subscription id stored in the razorpay_subscription_id order meta.
 *
 * @param array $subscription The Razorpay subscription entity.
 * @return MemberOrder
 * @since 1.0.0
 */
function pmpro_razorpay_get_checkout_order( $subscription ) {
	$notes = ( ! empty( $subscription['notes'] ) && is_array( $subscription['notes'] ) ) ? $subscription['notes'] : array();

	$order_id = 0;
	if ( ! empty( $notes['pmpro_order_id'] ) ) {
		$order_id = intval( $notes['pmpro_order_id'] );
	} elseif ( ! empty( $notes['pmpro_orderid'] ) ) {
		$order_id = intval( $notes['pmpro_orderid'] );
	}

	if ( ! empty( $order_id ) ) {
		$morder = new MemberOrder( $order_id );
		if ( ! empty( $morder->id ) ) {
			return $morder;
		}
	}

	$subscription_id = ( ! empty( $subscription['id'] ) ) ? sanitize_text_field( $subscription['id'] ) : '';
	if ( ! empty( $subscription_id ) ) {
		return pmpro_razorpay_get_order_by_subscription( $subscription_id );
	}

	return new MemberOrder();
}

/**
 * Find an order by its Razorpay subscription id.
 *
 * @param string $subscription_id The Razorpay subscription id.
 * @return MemberOrder
 * @since 1.0.0
 */
function pmpro_razorpay_get_order_by_subscription( $subscription_id ) {
	$morder = new MemberOrder();
	$morder->getLastMemberOrderBySubscriptionTransactionID( $subscription_id );

	if ( ! empty( $morder->id ) ) {
		return $morder;
	}

	return pmpro_razorpay_get_order_by_meta( 'razorpay_subscription_id', $subscription_id );
}

/**
 * Find an order by a value stored in its meta.
 *
 * @param string $meta_key   Meta key to search.
 * @param string $meta_value Meta value to search for.
 * @return MemberOrder
 * @since 1.0.0
 */
function pmpro_razorpay_get_order_by_meta( $meta_key, $meta_value ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared, indexed reverse meta lookup with no PMPro helper available.
	$order_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT pmpro_membership_order_id FROM {$wpdb->pmpro_membership_ordermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY meta_id DESC LIMIT 1",
			$meta_key,
			$meta_value
		)
	);

	if ( ! empty( $order_id ) ) {
		return new MemberOrder( intval( $order_id ) );
	}

	return new MemberOrder();
}

/**
 * Handle a subscription.charged event.
 *
 * Completes checkout for the first charge, or creates a renewal order for
 * subsequent charges.
 *
 * @param array $event Decoded webhook event.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_handle_subscription_charged( $event ) {
	$subscription = pmpro_razorpay_get_entity( $event, 'subscription' );
	$payment      = pmpro_razorpay_get_entity( $event, 'payment' );

	$subscription_id = ( ! empty( $subscription['id'] ) ) ? sanitize_text_field( $subscription['id'] ) : '';
	$payment_id      = ( ! empty( $payment['id'] ) ) ? sanitize_text_field( $payment['id'] ) : '';
	$payment_status  = ( ! empty( $payment['status'] ) ) ? sanitize_text_field( $payment['status'] ) : '';

	if ( empty( $subscription_id ) ) {
		pmpro_razorpay_webhook_log( __( 'No subscription id found in subscription.charged event.', 'pmpro-razorpay' ) );
		return;
	}

	// The payment must actually be captured before we treat it as money received.
	if ( empty( $payment_id ) || 'captured' !== $payment_status ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %1$s: Razorpay subscription ID. */
			__( 'subscription.charged for subscription # (%1$s) did not carry a captured payment. Ignoring.', 'pmpro-razorpay' ),
			$subscription_id
		) );
		return;
	}

	// Idempotency: bail if this payment has already been processed.
	$existing = new MemberOrder();
	$existing->getMemberOrderByPaymentTransactionID( $payment_id );
	if ( ! empty( $existing->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay payment ID. */
			__( 'An order with that payment ID (%s) already exists.', 'pmpro-razorpay' ),
			$payment_id
		) );
		return;
	}

	// Find the order created at checkout.
	$morder = pmpro_razorpay_get_checkout_order( $subscription );

	if ( empty( $morder->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay subscription ID. */
			__( "Couldn't find the original subscription: (%s).", 'pmpro-razorpay' ),
			$subscription_id
		) );
		return;
	}

	// If the order has not been completed yet, this is the first charge.
	if ( 'success' !== $morder->status ) {
		if ( empty( $morder->subscription_transaction_id ) ) {
			$morder->subscription_transaction_id = $subscription_id;
		}
		if ( empty( $morder->payment_transaction_id ) ) {
			$morder->payment_transaction_id = $payment_id;
		}
		$morder->saveOrder();

		pmpro_razorpay_complete_checkout( $morder );
		pmpro_razorpay_log_transition( $morder->id, $subscription_id, 'subscription.charged', $payment_status, $payment_id, $morder->status, 'complete_checkout', 'first successful charge' );
		return;
	}

	// The order is already complete. If it has no payment_transaction_id yet,
	// record the first payment and stop.
	if ( empty( $morder->payment_transaction_id ) ) {
		$morder->payment_transaction_id = $payment_id;
		$morder->saveOrder();
		pmpro_razorpay_log_transition( $morder->id, $subscription_id, 'subscription.charged', $payment_status, $payment_id, $morder->status, 'record_payment', 'first payment recorded' );
		return;
	}

	// Otherwise, this is a recurring renewal.
	pmpro_razorpay_add_renewal( $subscription, $payment );
}

/**
 * Add a renewal order for a recurring subscription charge.
 *
 * @param array $subscription The Razorpay subscription entity.
 * @param array $payment      The Razorpay payment entity.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_add_renewal( $subscription, $payment ) {
	$subscription_id = ( ! empty( $subscription['id'] ) ) ? sanitize_text_field( $subscription['id'] ) : '';
	$payment_id      = ( ! empty( $payment['id'] ) ) ? sanitize_text_field( $payment['id'] ) : '';

	if ( empty( $subscription_id ) || empty( $payment_id ) ) {
		pmpro_razorpay_webhook_log( __( 'Missing subscription or payment id for renewal.', 'pmpro-razorpay' ) );
		return;
	}

	// Idempotency: bail if we already have an order for this payment.
	$morder = new MemberOrder();
	$morder->getMemberOrderByPaymentTransactionID( $payment_id );
	if ( ! empty( $morder->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay payment ID. */
			__( 'An order with that payment ID (%s) already exists.', 'pmpro-razorpay' ),
			$payment_id
		) );
		return;
	}

	// Find the original subscription order.
	$old_order = new MemberOrder();
	$old_order->getLastMemberOrderBySubscriptionTransactionID( $subscription_id );

	if ( empty( $old_order ) || empty( $old_order->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay subscription ID. */
			__( "Couldn't find the original subscription: (%s).", 'pmpro-razorpay' ),
			$subscription_id
		) );
		return;
	}

	$user_id = $old_order->user_id;
	$user    = get_userdata( $user_id );

	// No user found for this order anymore.
	if ( empty( $user ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: PMPro order ID. */
			__( "Couldn't find the old order's user. Order ID (%s).", 'pmpro-razorpay' ),
			$old_order->id
		) );
		return;
	}

	$user->membership_level = pmpro_getMembershipLevelForUser( $user_id );

	// Amounts arrive in paise; convert to the base currency unit.
	$amount_paise = ( ! empty( $payment['amount'] ) ) ? intval( $payment['amount'] ) : 0;
	$total        = $amount_paise / 100;

	$timestamp = ( ! empty( $payment['created_at'] ) ) ? intval( $payment['created_at'] ) : time();

	// Create the renewal order.
	$order = new MemberOrder();
	$order->user_id                     = $user_id;
	$order->status                      = 'success';
	$order->membership_id               = $user->membership_level->id;
	$order->payment_transaction_id      = $payment_id;
	$order->subscription_transaction_id = $subscription_id;
	$order->gateway                     = get_option( 'pmpro_gateway' );
	$order->gateway_environment         = get_option( 'pmpro_gateway_environment' );
	$order->timestamp                   = $timestamp;
	$order->payment_type                = ( ! empty( $payment['method'] ) ) ? sanitize_text_field( $payment['method'] ) : '';
	$order->subtotal                    = $total;
	$order->total                       = $total;

	$order->find_billing_address();
	$order->saveOrder();

	if ( $order->id ) {
		$order->getMemberOrderByID( $order->id );

		// Send the customer invoice email.
		$email = new PMProEmail();
		$email->sendInvoiceEmail( $user, $order );

		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %1$s: PMPro order ID, %2$s: Razorpay subscription ID. */
			__( 'Order created (%1$s) for subscription # (%2$s).', 'pmpro-razorpay' ),
			$order->id,
			$subscription_id
		) );

		do_action( 'pmpro_subscription_payment_completed', $order, $payment );
	}
}

/**
 * Handle a failed payment (subscription.charge.failed or payment.failed).
 *
 * @param array $event Decoded webhook event.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_handle_payment_failed( $event ) {
	$subscription = pmpro_razorpay_get_entity( $event, 'subscription' );
	$payment      = pmpro_razorpay_get_entity( $event, 'payment' );

	$subscription_id = ( ! empty( $subscription['id'] ) ) ? sanitize_text_field( $subscription['id'] ) : '';
	if ( empty( $subscription_id ) && ! empty( $payment['subscription_id'] ) ) {
		$subscription_id = sanitize_text_field( $payment['subscription_id'] );
	}

	$payment_id = ( ! empty( $payment['id'] ) ) ? sanitize_text_field( $payment['id'] ) : '';

	// Find the original order.
	$old_order = new MemberOrder();
	if ( ! empty( $subscription_id ) ) {
		$old_order->getLastMemberOrderBySubscriptionTransactionID( $subscription_id );
	} elseif ( ! empty( $payment_id ) ) {
		$old_order->getMemberOrderByPaymentTransactionID( $payment_id );
	}

	if ( empty( $old_order->id ) ) {
		pmpro_razorpay_webhook_log( __( "Couldn't find the original order for this failed payment.", 'pmpro-razorpay' ) );
		return;
	}

	$user_id = $old_order->user_id;
	$user    = get_userdata( $user_id );

	if ( empty( $user ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: PMPro order ID. */
			__( "Couldn't find the old order's user. Order ID (%s).", 'pmpro-razorpay' ),
			$old_order->id
		) );
		return;
	}

	do_action( 'pmpro_subscription_payment_failed', $old_order );

	$reason = ( ! empty( $payment['error_description'] ) ) ? sanitize_text_field( $payment['error_description'] ) : '';
	if ( empty( $reason ) && ! empty( $payment['error_code'] ) ) {
		$reason = sanitize_text_field( $payment['error_code'] );
	}

	$order = new MemberOrder();
	$order->user_id                     = $user_id;
	$order->status                      = 'error';
	$order->membership_id               = $old_order->membership_id;
	$order->payment_transaction_id      = $payment_id;
	$order->subscription_transaction_id = $subscription_id;
	$order->gateway                     = get_option( 'pmpro_gateway' );
	$order->gateway_environment         = get_option( 'pmpro_gateway_environment' );
	$order->timestamp                   = time();

	if ( ! empty( $reason ) ) {
		/* translators: %s: Razorpay failure reason. */
		$order->notes = sprintf( __( 'Payment failed: %s.', 'pmpro-razorpay' ), $reason );
	} else {
		$order->notes = __( 'Payment failed.', 'pmpro-razorpay' );
	}

	$order->saveOrder();

	// Email the customer about this failure.
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureEmail( $user, $order );

	// Email admin so they are aware of the failure.
	$pmproemail = new PMProEmail();
	$pmproemail->sendBillingFailureAdminEmail( get_bloginfo( 'admin_email' ), $order );

	pmpro_razorpay_webhook_log( sprintf(
		/* translators: %s: Razorpay subscription ID. */
		__( 'Payment failed for subscription # (%s).', 'pmpro-razorpay' ),
		$subscription_id
	) );
}

/**
 * Handle a refund event.
 *
 * @param array $event Decoded webhook event.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_handle_refund( $event ) {
	$refund = pmpro_razorpay_get_entity( $event, 'refund' );

	$payment_id = ( ! empty( $refund['payment_id'] ) ) ? sanitize_text_field( $refund['payment_id'] ) : '';

	if ( empty( $payment_id ) ) {
		pmpro_razorpay_webhook_log( __( 'No payment id found in refund event.', 'pmpro-razorpay' ) );
		return;
	}

	$order = new MemberOrder();
	$order->getMemberOrderByPaymentTransactionID( $payment_id );

	if ( empty( $order->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay payment ID. */
			__( "Couldn't find an order with payment id (%s) to refund.", 'pmpro-razorpay' ),
			$payment_id
		) );
		return;
	}

	// Amounts arrive in paise; convert to the base currency unit.
	$refund_amount_paise = ( ! empty( $refund['amount'] ) ) ? intval( $refund['amount'] ) : 0;
	$refund_total        = $refund_amount_paise / 100;

	if ( $refund_total >= floatval( $order->total ) ) {
		$order->status = 'refunded';
		$order->saveOrder();
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %1$s: PMPro order ID, %2$s: Razorpay payment ID. */
			__( 'Order # (%1$s) marked refunded for payment (%2$s).', 'pmpro-razorpay' ),
			$order->id,
			$payment_id
		) );
	} else {
		/* translators: %1$s: Refund amount, %2$s: Razorpay payment ID. */
		$order->notes = trim( $order->notes . ' ' . sprintf( __( 'Partial refund of %1$s processed for payment (%2$s).', 'pmpro-razorpay' ), $refund_total, $payment_id ) );
		$order->saveOrder();
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %1$s: Refund amount, %2$s: PMPro order ID. */
			__( 'Partial refund of %1$s added as a note to order # (%2$s).', 'pmpro-razorpay' ),
			$refund_total,
			$order->id
		) );
	}
}

/**
 * Handle a payment.captured event for one-time (non-subscription) orders.
 *
 * @param array $event Decoded webhook event.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_handle_payment_captured( $event ) {
	$payment = pmpro_razorpay_get_entity( $event, 'payment' );

	$payment_id     = ( ! empty( $payment['id'] ) ) ? sanitize_text_field( $payment['id'] ) : '';
	$payment_status = ( ! empty( $payment['status'] ) ) ? sanitize_text_field( $payment['status'] ) : '';

	if ( empty( $payment_id ) ) {
		pmpro_razorpay_webhook_log( __( 'No payment id found in payment.captured event.', 'pmpro-razorpay' ) );
		return;
	}

	// Defensive: this is a payment.captured event, but only act on captured payments.
	if ( 'captured' !== $payment_status ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %1$s: Razorpay payment ID, %2$s: Razorpay payment status. */
			__( 'payment.captured for payment (%1$s) has status %2$s. Ignoring.', 'pmpro-razorpay' ),
			$payment_id,
			$payment_status
		) );
		return;
	}

	// Idempotency: bail if this payment has already been processed.
	$existing = new MemberOrder();
	$existing->getMemberOrderByPaymentTransactionID( $payment_id );
	if ( ! empty( $existing->id ) ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: Razorpay payment ID. */
			__( 'An order with that payment ID (%s) already exists.', 'pmpro-razorpay' ),
			$payment_id
		) );
		return;
	}

	// Subscription payment: record the first payment's transaction id on the
	// checkout order. Renewals are handled by subscription.charged.
	if ( ! empty( $payment['subscription_id'] ) ) {
		$subscription_id = sanitize_text_field( $payment['subscription_id'] );
		$morder          = pmpro_razorpay_get_order_by_subscription( $subscription_id );

		if ( empty( $morder->id ) ) {
			pmpro_razorpay_webhook_log( sprintf(
				/* translators: %s: Razorpay payment ID. */
				__( "Couldn't find the order for subscription payment (%s).", 'pmpro-razorpay' ),
				$payment_id
			) );
			return;
		}

		if ( 'success' !== $morder->status ) {
			if ( empty( $morder->subscription_transaction_id ) ) {
				$morder->subscription_transaction_id = $subscription_id;
			}
			if ( empty( $morder->payment_transaction_id ) ) {
				$morder->payment_transaction_id = $payment_id;
			}
			$morder->saveOrder();
			pmpro_razorpay_complete_checkout( $morder );
			pmpro_razorpay_log_transition( $morder->id, $subscription_id, 'payment.captured', $payment_status, $payment_id, $morder->status, 'complete_checkout', 'first captured subscription payment' );
		} elseif ( empty( $morder->payment_transaction_id ) ) {
			$morder->payment_transaction_id = $payment_id;
			$morder->saveOrder();
			pmpro_razorpay_log_transition( $morder->id, $subscription_id, 'payment.captured', $payment_status, $payment_id, $morder->status, 'record_payment', 'first payment recorded' );
		}

		return;
	}

	// One-time order: find it by the notes or the razorpay_order_id meta.
	$notes = ( ! empty( $payment['notes'] ) && is_array( $payment['notes'] ) ) ? $payment['notes'] : array();

	$morder = new MemberOrder();
	if ( ! empty( $notes['pmpro_order_id'] ) ) {
		$morder = new MemberOrder( intval( $notes['pmpro_order_id'] ) );
	} elseif ( ! empty( $notes['pmpro_orderid'] ) ) {
		$morder = new MemberOrder( intval( $notes['pmpro_orderid'] ) );
	}

	if ( empty( $morder->id ) && ! empty( $payment['order_id'] ) ) {
		$morder = pmpro_razorpay_get_order_by_meta( 'razorpay_order_id', sanitize_text_field( $payment['order_id'] ) );
	}

	if ( empty( $morder->id ) ) {
		pmpro_razorpay_webhook_log( __( "Couldn't find the order for this captured payment.", 'pmpro-razorpay' ) );
		return;
	}

	if ( 'success' === $morder->status ) {
		pmpro_razorpay_webhook_log( sprintf(
			/* translators: %s: PMPro order ID. */
			__( 'Order # (%s) is already complete.', 'pmpro-razorpay' ),
			$morder->id
		) );
		return;
	}

	if ( empty( $morder->payment_transaction_id ) ) {
		$morder->payment_transaction_id = $payment_id;
	}

	$morder->saveOrder();

	pmpro_razorpay_complete_checkout( $morder );
	pmpro_razorpay_log_transition( $morder->id, '', 'payment.captured', $payment_status, $payment_id, $morder->status, 'complete_checkout', 'one-time payment captured' );
}

/**
 * Add a message to the webhook log string.
 *
 * @param string $s The message to log.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_webhook_log( $s ) {
	global $pmpro_razorpay_logstr;
	$pmpro_razorpay_logstr .= "\t" . $s . "\n";
}

/**
 * Output the webhook log and exit.
 *
 * @param bool|string $redirect Optional. A URL to redirect to.
 * @return void
 * @since 1.0.0
 */
function pmpro_razorpay_Exit( $redirect = false ) {
	global $pmpro_razorpay_logstr;
	$pmpro_razorpay_logstr = sprintf(
		/* translators: %s: Date/time the webhook was logged. */
		__( 'Logged On: %s', 'pmpro-razorpay' ),
		date_i18n( 'm/d/Y H:i:s' )
	) . "\n" . $pmpro_razorpay_logstr . "\n-------------\n";

	// Log in file or email?
	if ( defined( 'PMPRO_RAZORPAY_DEBUG' ) && PMPRO_RAZORPAY_DEBUG === 'log' ) {
		// File.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! empty( $wp_filesystem ) ) {
			$log_path = PMPRO_RAZORPAY_DIR . '/logs/razorpay_webhook.txt';
			$existing = $wp_filesystem->exists( $log_path ) ? $wp_filesystem->get_contents( $log_path ) : '';
			$wp_filesystem->put_contents( $log_path, $existing . $pmpro_razorpay_logstr, FS_CHMOD_FILE );
		}
	} elseif ( defined( 'PMPRO_RAZORPAY_DEBUG' ) && false !== PMPRO_RAZORPAY_DEBUG ) {
		// Email.
		if ( strpos( PMPRO_RAZORPAY_DEBUG, '@' ) ) {
			$log_email = PMPRO_RAZORPAY_DEBUG; // Constant defines a specific email address.
		} else {
			$log_email = get_option( 'admin_email' );
		}

		wp_mail( $log_email, get_option( 'blogname' ) . ' ' . __( 'Razorpay Webhook Log', 'pmpro-razorpay' ), nl2br( $pmpro_razorpay_logstr ) );
	}

	if ( ! empty( $redirect ) ) {
		wp_safe_redirect( $redirect );
	}

	exit;
}
