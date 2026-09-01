<?php
/**
 * REST API client for Razorpay.
 *
 * Wraps calls to the Razorpay REST API (https://api.razorpay.com/v1) using the
 * WordPress HTTP API. No external SDK is required.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMProGateway_Razorpay_API {

	/**
	 * Base URL for the Razorpay REST API.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.razorpay.com/v1';

	/**
	 * Default currency used when the site currency is unavailable.
	 *
	 * @var string
	 */
	const CURRENCY = 'INR';

	/**
	 * Razorpay key id.
	 *
	 * @var string
	 */
	private $key_id;

	/**
	 * Razorpay key secret.
	 *
	 * @var string
	 */
	private $key_secret;

	/**
	 * Singleton instance.
	 *
	 * @var PMProGateway_Razorpay_API|null
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_id     Razorpay key id. Defaults to the option value for the active environment.
	 * @param string|null $key_secret Razorpay key secret. Defaults to the option value for the active environment.
	 */
	public function __construct( $key_id = null, $key_secret = null ) {
		$environment = self::get_environment();

		if ( empty( $key_id ) ) {
			$key_id = ( 'sandbox' === $environment )
				? get_option( 'pmpro_razorpay_sandbox_key_id' )
				: get_option( 'pmpro_razorpay_key_id' );
		}
		if ( empty( $key_secret ) ) {
			$key_secret = ( 'sandbox' === $environment )
				? get_option( 'pmpro_razorpay_sandbox_key_secret' )
				: get_option( 'pmpro_razorpay_key_secret' );
		}

		$this->key_id     = $key_id;
		$this->key_secret = $key_secret;
	}

	/**
	 * Get the active gateway environment ('live' or 'sandbox').
	 *
	 * @return string
	 */
	public static function get_environment() {
		$environment = get_option( 'pmpro_gateway_environment', 'live' );
		return ( 'sandbox' === $environment ) ? 'sandbox' : 'live';
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return PMProGateway_Razorpay_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get the site currency code (e.g. 'INR', 'USD').
	 *
	 * @return string
	 */
	public static function get_currency() {
		global $pmpro_currency;
		if ( ! empty( $pmpro_currency ) ) {
			return $pmpro_currency;
		}

		$currency = get_option( 'pmpro_currency' );
		return ! empty( $currency ) ? $currency : self::CURRENCY;
	}

	/**
	 * Get the configured key id.
	 *
	 * @return string
	 */
	public function get_key_id() {
		return $this->key_id;
	}

	/**
	 * Get the configured key secret.
	 *
	 * @return string
	 */
	public function get_key_secret() {
		return $this->key_secret;
	}

	/**
	 * Get the webhook secret for the active environment.
	 *
	 * @return string
	 */
	public static function get_webhook_secret() {
		$environment = self::get_environment();

		return ( 'sandbox' === $environment )
			? get_option( 'pmpro_razorpay_sandbox_webhook_secret' )
			: get_option( 'pmpro_razorpay_webhook_secret' );
	}

	/**
	 * Whether the configured keys are test keys (sandbox mode).
	 *
	 * @return bool
	 */
	public function is_sandbox() {
		return ( 0 === strpos( (string) $this->key_id, 'rzp_test_' ) );
	}

	/**
	 * Log a debug message when Razorpay debugging is enabled.
	 *
	 * @param string $message The message to log.
	 * @return void
	 * @since 1.0.0
	 */
	public static function debug_log( $message ) {
		if ( defined( 'PMPRO_RAZORPAY_DEBUG' ) && PMPRO_RAZORPAY_DEBUG ) {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Perform a request against the Razorpay API.
	 *
	 * @param string $method HTTP method ('POST', 'GET', etc).
	 * @param string $path   API path relative to the base URL (e.g. '/orders').
	 * @param array  $args   Optional. Request body arguments for POST requests.
	 * @return array|WP_Error Decoded JSON response on success, WP_Error on failure.
	 */
	private function request( $method, $path, $args = array() ) {
		$url = self::API_BASE . $path;

		$headers = array(
			'Authorization' => 'Basic ' . base64_encode( $this->key_id . ':' . $this->key_secret ),
			'Content-Type'  => 'application/json',
		);

		$request_args = array(
			'timeout' => 30,
			'headers' => $headers,
		);

		if ( 'GET' === strtoupper( $method ) ) {
			$response = wp_remote_get( $url, $request_args );
		} else {
			$request_args['body'] = empty( $args ) ? '{}' : wp_json_encode( $args );
			$response             = wp_remote_post( $url, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );

		if ( $response_code < 200 || $response_code >= 300 ) {
			$error_message = __( 'Razorpay API request failed.', 'pmpro-razorpay' );

			if ( ! empty( $data['error']['description'] ) ) {
				$error_message = $data['error']['description'];
			} elseif ( ! empty( $data['error']['code'] ) ) {
				$error_message = $data['error']['code'];
			}

			$error_data = array(
				'status' => $response_code,
				'path'   => $path,
			);

			if ( ! empty( $data['error']['code'] ) ) {
				$error_data['razorpay_code'] = $data['error']['code'];
			}
			if ( ! empty( $data['error']['reason'] ) ) {
				$error_data['razorpay_reason'] = $data['error']['reason'];
			}

			self::debug_log( sprintf(
				'[PMPro Razorpay] API error %1$s %2$s: %3$s %4$s',
				$method,
				$path,
				$response_code,
				$body
			) );

			return new WP_Error( 'razorpay_api_error', $error_message, $error_data );
		}

		return $data;
	}

	/**
	 * Create a customer.
	 *
	 * @param array $args Customer args (name, email, contact, notes).
	 * @return array|WP_Error
	 */
	public function create_customer( $args ) {
		return $this->request( 'POST', '/customers', $args );
	}

	/**
	 * Create an order for a one-time payment.
	 *
	 * @param int   $amount_paise Amount in paise.
	 * @param string $receipt     Receipt id (e.g. PM Pro order code).
	 * @param array  $notes       Optional. Key/value notes.
	 * @return array|WP_Error
	 */
	public function create_order( $amount_paise, $receipt, $notes = array(), $currency = null ) {
		if ( empty( $currency ) ) {
			$currency = self::get_currency();
		}

		$args = array(
			'amount'   => (int) $amount_paise,
			'currency' => $currency,
			'receipt'  => (string) $receipt,
		);

		if ( ! empty( $notes ) ) {
			$args['notes'] = $notes;
		}

		return $this->request( 'POST', '/orders', $args );
	}

	/**
	 * Create a plan.
	 *
	 * @param array $args Plan args (period, interval, item, notes).
	 * @return array|WP_Error
	 */
	public function create_plan( $args ) {
		return $this->request( 'POST', '/plans', $args );
	}

	/**
	 * Fetch all plans.
	 *
	 * @param array $args Optional. Query args (from, to, count, skip).
	 * @return array|WP_Error
	 */
	public function fetch_plans( $args = array() ) {
		$path = '/plans';
		if ( ! empty( $args ) ) {
			$path .= '?' . http_build_query( $args );
		}

		return $this->request( 'GET', $path );
	}

	/**
	 * Fetch a single plan by id.
	 *
	 * @param string $plan_id Plan id.
	 * @return array|WP_Error
	 */
	public function fetch_plan( $plan_id ) {
		return $this->request( 'GET', '/plans/' . $plan_id );
	}

	/**
	 * Create a subscription.
	 *
	 * @param array $args Subscription args (plan_id, total_count, start_at, addons, notes, etc).
	 * @return array|WP_Error
	 */
	public function create_subscription( $args ) {
		return $this->request( 'POST', '/subscriptions', $args );
	}

	/**
	 * Fetch a subscription.
	 *
	 * @param string $subscription_id Subscription id.
	 * @return array|WP_Error
	 */
	public function fetch_subscription( $subscription_id ) {
		return $this->request( 'GET', '/subscriptions/' . $subscription_id );
	}

	/**
	 * Cancel a subscription.
	 *
	 * @param string $subscription_id     Subscription id.
	 * @param bool   $cancel_at_cycle_end Whether to cancel at the end of the current billing cycle.
	 * @return array|WP_Error
	 */
	public function cancel_subscription( $subscription_id, $cancel_at_cycle_end = false ) {
		$path = '/subscriptions/' . $subscription_id . '/cancel';
		$args = array();

		if ( $cancel_at_cycle_end ) {
			$args['cancel_at_cycle_end'] = 1;
		}

		return $this->request( 'POST', $path, $args );
	}

	/**
	 * Fetch a payment.
	 *
	 * @param string $payment_id Payment id.
	 * @return array|WP_Error
	 */
	public function fetch_payment( $payment_id ) {
		return $this->request( 'GET', '/payments/' . $payment_id );
	}

	/**
	 * Refund a payment.
	 *
	 * @param string   $payment_id   Payment id.
	 * @param int|null $amount_paise Optional. Refund amount in paise. Defaults to full refund.
	 * @return array|WP_Error
	 */
	public function refund_payment( $payment_id, $amount_paise = null ) {
		$path = '/payments/' . $payment_id . '/refund';
		$args = array();

		if ( ! empty( $amount_paise ) ) {
			$args['amount'] = (int) $amount_paise;
		}

		return $this->request( 'POST', $path, $args );
	}

	/**
	 * Convert an amount to paise.
	 *
	 * @param float|int|string $amount Amount in the base currency unit.
	 * @return int
	 */
	public static function amount_to_paise( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Verify a Razorpay webhook signature.
	 *
	 * @param string $payload   Raw webhook body.
	 * @param string $signature Signature from the X-Razorpay-Signature header.
	 * @param string $secret    Webhook secret.
	 * @return bool
	 */
	public static function verify_webhook_signature( $payload, $signature, $secret ) {
		$expected = hash_hmac( 'sha256', $payload, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Verify a Razorpay webhook signature against any configured webhook secret.
	 *
	 * Razorpay sends webhooks for both the live and sandbox environments to the
	 * same URL, each signed with its own secret. Accept the payload if it
	 * validates against either secret.
	 *
	 * @param string $payload   Raw webhook body.
	 * @param string $signature Signature from the X-Razorpay-Signature header.
	 * @return bool
	 */
	public static function verify_webhook_signature_any( $payload, $signature ) {
		$secrets = array(
			get_option( 'pmpro_razorpay_webhook_secret' ),
			get_option( 'pmpro_razorpay_sandbox_webhook_secret' ),
		);

		foreach ( $secrets as $secret ) {
			if ( ! empty( $secret ) && self::verify_webhook_signature( $payload, $signature, $secret ) ) {
				return true;
			}
		}

		return false;
	}
}
