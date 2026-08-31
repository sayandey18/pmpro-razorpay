<?php
/**
 * PMPro Razorpay Gateway Class
 * 
 * @package PMPro Razorpay
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', array( 'PMProGateway_Razorpay', 'init' ) );
add_filter( 'pmpro_is_ready', array( 'PMProGateway_Razorpay', 'pmpro_is_razorpay_ready' ), 999, 1 );

class PMProGateway_Razorpay extends PMProGateway {

	function __construct( $gateway = null ) {

		$this->gateway = $gateway;
		return $this->gateway;
	}

	/**
	 * Run on WP init
	 *
	 * @since 1.0.0
	 */
	static function init() {

		//make sure Razorpay is a gateway option
		add_filter( 'pmpro_gateways', array( 'PMProGateway_Razorpay', 'pmpro_gateways' ) );
		add_filter( 'pmpro_gateways_with_pending_status', array( 'PMProGateway_Razorpay', 'pmpro_gateways_with_pending_status' ) );

		//add fields to payment settings
		add_filter( 'pmpro_payment_options', array( 'PMProGateway_Razorpay', 'pmpro_payment_options' ) );
		add_filter( 'pmpro_payment_option_fields', array( 'PMProGateway_Razorpay', 'pmpro_payment_option_fields' ), 10, 2 );
		//code to add at checkout
		$gateway = pmpro_getGateway();

		if ( $gateway == "razorpay" ) {

			add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_Razorpay', 'pmpro_required_billing_fields' ) );
			add_filter( 'pmpro_checkout_default_submit_button', array( 'PMProGateway_Razorpay', 'pmpro_checkout_default_submit_button' ) );
		}

		add_filter( 'pmpro_allowed_refunds_gateways', array( 'PMProGateway_Razorpay', 'allow_refunds' ), 10, 1 );
		add_filter( 'pmpro_process_refund_razorpay', array( 'PMProGateway_Razorpay', 'process_refund' ), 10, 2 );

		add_action( 'wp', array( 'PMProGateway_Razorpay', 'maybe_render_checkout_modal' ) );

	}

	static function pmpro_gateways_with_pending_status( $gateways ) {
		$gateways[] = 'razorpay';
		return $gateways;
	}


	/**
	 * Make sure this gateway is in the gateways list
	 *
	 * @since 1.0.0
	 */
	static function pmpro_gateways( $gateways ) {

		if ( empty( $gateways['razorpay'] ) ) {
			$gateways['razorpay'] = __( 'Razorpay', 'pmpro-razorpay' );
		}

		return $gateways;
	}

	static function allow_refunds( $gateways ) {
		$gateways[] = 'razorpay';
		return $gateways;
	}

	/**
	 * Get a list of payment options that the this gateway needs/supports.
	 *
	 * @since 1.0.0
	 */
	static function getGatewayOptions() {

		$options = array(
			'razorpay_key_id',
			'razorpay_key_secret',
			'razorpay_webhook_secret',
			'razorpay_sandbox_key_id',
			'razorpay_sandbox_key_secret',
			'razorpay_sandbox_webhook_secret',
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate'
		);

		return $options;
	}

	/**
	 * Set payment options for payment settings page.
	 *
	 * @since 1.0.0
	 */
	static function pmpro_payment_options( $options ) {
		//get razorpay options
		$razorpay_options = PMProGateway_Razorpay::getGatewayOptions();

		//merge with others.
		$options = array_merge( $razorpay_options, $options );

		return $options;
	}

	/**
	 * Check if all fields are complete
	 */
	static function pmpro_is_razorpay_ready( $ready ){

		$api = PMProGateway_Razorpay_API::get_instance();

		if ( empty( $api->get_key_id() ) ||
			empty( $api->get_key_secret() ) ||
			empty( PMProGateway_Razorpay_API::get_webhook_secret() ) ){
			$ready = false;
		} else {
			$ready = true;
		}

		return $ready;

	}

	/**
	 * Check whether or not a gateway supports a specific feature.
	 *
	 * @param string $feature The feature to check.
	 * @return bool True if the gateway supports the feature, false if not.
	 * @since 1.0.0
	 */
	public static function supports( $feature ) {
		$supports = array(
			'subscription_sync' => true,
		);

		if ( empty( $supports[$feature] ) ) {
			return false;
		}

		return $supports[$feature];
	}

	/**
	 * Display fields for this gateway's options
	 *
	 * @since 1.0.0
	 */
	static function show_settings_fields() {
		?>
		<div id="pmpro_razorpay_live" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Razorpay (Live)', 'pmpro-razorpay' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class='form-table'>
					<tbody>
						<?php self::render_razorpay_key_fields( 'live' ); ?>
					</tbody>
				</table>
			</div>
		</div>

		<div id="pmpro_razorpay_sandbox" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Razorpay (Test)', 'pmpro-razorpay' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class='form-table'>
					<tbody>
						<?php self::render_razorpay_key_fields( 'sandbox' ); ?>
					</tbody>
				</table>
			</div>
		</div>

		<div id="pmpro_razorpay" class="pmpro_section" data-visibility="shown" data-activated="true">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Razorpay Webhook', 'pmpro-razorpay' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class='form-table'>
					<tbody>
						<tr class="gateway gateway_razorpay">
							<th scope="row" valign="top">
								<label><?php esc_html_e( 'Webhook URL', 'pmpro-razorpay' ); ?>:</label>
							</th>
							<td>
								<p><?php esc_html_e( 'Integrate with Razorpay, be sure to use the following for your Webhook URL', 'pmpro-razorpay' ); ?> <pre><?php echo esc_url( admin_url( 'admin-ajax.php' ) . '?action=razorpay-webhook' ); ?></pre></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the key fields (Key ID, Key Secret, Webhook Secret) for an environment.
	 *
	 * @param string $mode 'live' or 'sandbox'.
	 * @since 1.0.0
	 */
	private static function render_razorpay_key_fields( $mode ) {
		$is_sandbox = ( 'sandbox' === $mode );
		$key_name   = $is_sandbox ? 'razorpay_sandbox' : 'razorpay';

		$fields = array(
			'key_id'         => __( 'Key ID', 'pmpro-razorpay' ),
			'key_secret'     => __( 'Key Secret', 'pmpro-razorpay' ),
			'webhook_secret' => __( 'Webhook Secret', 'pmpro-razorpay' ),
		);

		foreach ( $fields as $suffix => $label ) {
			$field_name = $key_name . '_' . $suffix;
			$type       = ( 'key_id' === $suffix ) ? 'text' : 'password';
			?>
			<tr class="gateway gateway_razorpay">
				<th scope="row" valign="top">
					<label for="<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $label ); ?>:</label>
				</th>
				<td>
					<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $field_name ); ?>" name="<?php echo esc_attr( $field_name ); ?>" size="60" value="<?php echo esc_attr( get_option( 'pmpro_' . $field_name ) ); ?>" class="regular-text code" />
					<br /><small><?php echo esc_html( sprintf( __( 'Enter the %1$s from your Razorpay Dashboard.', 'pmpro-razorpay' ), $label ) ); ?></small>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Save the payment gateway settings fields for PMPro V3.5+.
	 *
	 * @since 1.0.0
	 */
	public static function save_settings_fields() {
		$settings_to_save = array(
			'razorpay_key_id',
			'razorpay_key_secret',
			'razorpay_webhook_secret',
			'razorpay_sandbox_key_id',
			'razorpay_sandbox_key_secret',
			'razorpay_sandbox_webhook_secret'
		);

		foreach ( $settings_to_save as $setting ) {
			if ( isset( $_REQUEST[ $setting ] ) ) {
				update_option( 'pmpro_' . $setting, sanitize_text_field( $_REQUEST[ $setting ] ) );
			}
		}
	}

	/**
	 * Get a description for this gateway.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function get_description_for_gateway_settings() {
		return esc_html__( 'Razorpay is a leading Indian payment gateway that lets you accept UPI Autopay and credit/debit card subscription payments on your membership site.', 'pmpro-razorpay' );
	}

	/**
	 * Display fields for this gateway's options.
	 *
	 * @since 1.0.0
	 */
	static function pmpro_payment_option_fields( $values, $gateway ) {
		_deprecated_function( __METHOD__, '3.5' );
	?>
	<tr class="pmpro_settings_divider gateway gateway_razorpay" <?php if( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<td colspan="2">
			<h2><?php esc_html_e('Razorpay Settings', 'pmpro-razorpay' ); ?></h2>
		</td>
	</tr>

	<tr class="gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="razorpay_key_id"><?php esc_html_e( 'Key ID', 'pmpro-razorpay' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="razorpay_key_id" name="razorpay_key_id" size="60" value="<?php echo esc_attr( $values['razorpay_key_id'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Key ID from your Razorpay Dashboard.', 'pmpro-razorpay' ); ?></small>
		</td>
	</tr>

	<tr class="gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="razorpay_key_secret"><?php esc_html_e( 'Key Secret', 'pmpro-razorpay' ); ?>:</label>
		</th>
		<td>
			<input type="password" id="razorpay_key_secret" name="razorpay_key_secret" size="60" value="<?php echo esc_attr( $values['razorpay_key_secret'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Key Secret from your Razorpay Dashboard.', 'pmpro-razorpay' ); ?></small>
		</td>
	</tr>

	<tr class="gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="razorpay_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'pmpro-razorpay' ); ?>:</label>
		</th>
		<td>
			<input type="password" id="razorpay_webhook_secret" name="razorpay_webhook_secret" size="60" value="<?php echo esc_attr( $values['razorpay_webhook_secret'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Webhook Secret from your Razorpay Dashboard.', 'pmpro-razorpay' ); ?></small>
		</td>
	</tr>

	<tr class="pmpro_settings_divider gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<td colspan="2">
			<h2><?php esc_html_e( 'Sandbox/Testing Keys', 'pmpro-razorpay' ); ?></h2>
		</td>
	</tr>

	<tr class="gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="razorpay_sandbox_key_id"><?php esc_html_e( 'Key ID', 'pmpro-razorpay' ); ?>:</label>
		</th>
		<td>
			<input type="text" id="razorpay_sandbox_key_id" name="razorpay_sandbox_key_id" size="60" value="<?php echo esc_attr( $values['razorpay_sandbox_key_id'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Key ID from your Razorpay Dashboard.', 'pmpro-razorpay' ); ?></small>
		</td>
	</tr>

	<tr class="gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="razorpay_sandbox_key_secret"><?php esc_html_e( 'Key Secret', 'pmpro-razorpay' ); ?>:</label>
		</th>
		<td>
			<input type="password" id="razorpay_sandbox_key_secret" name="razorpay_sandbox_key_secret" size="60" value="<?php echo esc_attr( $values['razorpay_sandbox_key_secret'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Key Secret from your Razorpay Dashboard.', 'pmpro-razorpay' ); ?></small>
		</td>
	</tr>

	<tr class="gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?> >
		<th scope="row" valign="top">
			<label for="razorpay_sandbox_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'pmpro-razorpay' ); ?>:</label>
		</th>
		<td>
			<input type="password" id="razorpay_sandbox_webhook_secret" name="razorpay_sandbox_webhook_secret" size="60" value="<?php echo esc_attr( $values['razorpay_sandbox_webhook_secret'] ); ?>" />
			<br /><small><?php esc_html_e( 'Enter the Webhook Secret from your Razorpay Dashboard.', 'pmpro-razorpay' ); ?></small>
		</td>
	</tr>

	<tr class="gateway gateway_razorpay" <?php if ( $gateway != "razorpay" ) { ?>style="display: none;"<?php } ?>>
		<th scope="row" valign="top">
			<label><?php esc_html_e( 'Razorpay Webhook URL', 'pmpro-razorpay' ); ?>:</label>
		</th>
		<td>
			<p><?php esc_html_e( 'To fully integrate with Razorpay, be sure to use the following for your Webhook URL', 'pmpro-razorpay' ); ?> <pre><?php echo esc_url( admin_url("admin-ajax.php") . "?action=razorpay-webhook"); ?></pre></p>
		</td>
	</tr>
	<?php
	}

	/**
	 * Remove required billing fields
	 *
	 * @since 1.0.0
	 */
	static function pmpro_required_billing_fields( $fields ) {

		unset($fields['CardType']);
		unset($fields['AccountNumber']);
		unset($fields['ExpirationMonth']);
		unset($fields['ExpirationYear']);
		unset($fields['CVV']);

		return $fields;
	}

	/**
	 * Swap in our submit buttons.
	 *
	 * @since 1.0.0
	 */
	static function pmpro_checkout_default_submit_button( $show ) {

		global $pmpro_requirebilling;

		//show our submit buttons
		?>
		<span id="pmpro_submit_span">
			<input type="hidden" name="submit-checkout" value="1" />
			<input type="submit" id="pmpro_btn-submit" class="<?php echo esc_attr( pmpro_get_element_class(  'pmpro_btn pmpro_btn-submit-checkout'  ) ); ?>" value="<?php if( $pmpro_requirebilling ) { esc_attr_e( 'Pay with Razorpay', 'pmpro-razorpay' ); } else { esc_attr_e( 'Submit and Confirm', 'pmpro-razorpay' ); } ?>" /></span>
		<?php

		//don't show the default
		return false;
	}

	/**
	 * Process checkout.
	 *
	 * @since 1.0.0
	 */
	function process( &$order ) {

		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}
		//clean up a couple values
		$order->payment_type = "Razorpay";
		$order->CardType = "";
		$order->cardtype = "";
		$order->status = "token";
		$order->saveOrder();

		self::pmpro_checkout_before_change_membership_level( $order->user_id, $order );

		return true;
	}

	/**
	 * Instead of change membership levels, send users to Razorpay to pay.
	 *
	 * @since 1.0.0
	 */
	static function pmpro_checkout_before_change_membership_level( $user_id, $morder ) {

		//if no order, no need to pay
		if ( empty( $morder ) ) {
			return;
		}

		// Bail for free checkouts.
		if ( $morder->gateway != 'razorpay' ) {
			return;
		}

		$morder->user_id = $user_id;
		$morder->saveOrder();

		//Save checkout data in order meta before sending user to Razorpay.
		pmpro_save_checkout_data_to_order( $morder );

		do_action( "pmpro_before_send_to_razorpay", $user_id, $morder );

		$morder->Gateway->sendToRazorpay( $morder );

	}

	/**
	 * Map a PM Pro billing cycle period to a Razorpay plan period.
	 *
	 * @param string $cycle_period The PM Pro cycle period (Day, Week, Month, Year).
	 * @return string The Razorpay plan period (daily, weekly, monthly, yearly).
	 */
	private static function get_razorpay_period( $cycle_period ) {
		switch ( $cycle_period ) {
			case 'Week':
				return 'weekly';
			case 'Year':
				return 'yearly';
			case 'Day':
			case 'day':
				return 'daily';
			case 'Month':
			default:
				return 'monthly';
		}
	}

	/**
	 * Get the maximum total_count Razorpay allows for a plan period and interval.
	 *
	 * Razorpay limits subscriptions to a maximum duration of 100 years.
	 *
	 * @param string $period   Razorpay plan period (daily, weekly, monthly, yearly).
	 * @param int    $interval Razorpay plan interval (cycle multiplier).
	 * @return int
	 */
	private static function get_max_total_count( $period, $interval ) {
		$interval = max( 1, (int) $interval );

		$cycles_per_100_years = array(
			'daily'   => 36500,
			'weekly'  => 5200,
			'monthly' => 1200,
			'yearly'  => 100,
		);

		$max = isset( $cycles_per_100_years[ $period ] ) ? $cycles_per_100_years[ $period ] : 1200;

		return (int) floor( $max / $interval );
	}

	/**
	 * Get (or create) a reusable Razorpay plan for a membership level's billing
	 * configuration, mirroring how PMPro's Stripe gateway reuses Prices.
	 *
	 * A plan is keyed by period, interval, amount, and currency so that repeated
	 * checkouts on the same level reuse a single plan instead of creating a new
	 * one each time. The plan id is cached in membership level meta.
	 *
	 * @since 1.0.0
	 *
	 * @param object $level              The checkout level object.
	 * @param string $period             Razorpay plan period (daily, weekly, monthly, yearly).
	 * @param int    $interval           Razorpay plan interval (cycle multiplier).
	 * @param int    $billing_amount_paise The recurring amount in paise.
	 * @param string $currency           Currency code.
	 * @return string|WP_Error Plan id, or WP_Error on failure.
	 */
	private static function get_plan_for_level( $level, $period, $interval, $billing_amount_paise, $currency ) {
		$api       = PMProGateway_Razorpay_API::get_instance();
		$env       = $api->is_sandbox() ? 'sandbox' : 'live';
		$signature = $period . '|' . $interval . '|' . (int) $billing_amount_paise . '|' . strtoupper( $currency );
		$meta_key  = 'pmpro_razorpay_plan_id_' . $env . '_' . md5( $signature );

		$plan_id = get_pmpro_membership_level_meta( $level->id, $meta_key, true );
		if ( ! empty( $plan_id ) ) {
			$existing = $api->fetch_plan( $plan_id );
			if ( ! is_wp_error( $existing ) && ! empty( $existing['id'] ) ) {
				return $plan_id;
			}

			// The cached plan no longer exists; clear it and create a fresh one.
			delete_pmpro_membership_level_meta( $level->id, $meta_key );
		}

		$plan = $api->create_plan( array(
			'period'   => $period,
			'interval' => $interval,
			'item'     => array(
				'name'     => $level->name,
				'amount'   => (int) $billing_amount_paise,
				'currency' => $currency,
			),
		) );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		update_pmpro_membership_level_meta( $level->id, $meta_key, $plan['id'] );

		return $plan['id'];
	}

	/**
	 * Calculate the future start date for a Razorpay subscription.
	 *
	 * Only used when the first charge must be deferred (a genuine free trial, a
	 * free start, or an explicit future profile start date). Mirrors PMPro's
	 * Stripe gateway: for a free trial, the trial cycles are accounted for
	 * before calculating the profile start date.
	 *
	 * @param MemberOrder $order The order being processed.
	 * @param object      $level The checkout level object.
	 * @return int|false Unix timestamp of the first charge, or false if none.
	 */
	private static function get_razorpay_start_date( $order, $level ) {
		// For a free trial, account for the trial cycles before the first charge.
		if ( ! empty( $level->trial_limit ) && pmpro_round_price( $level->trial_amount ) == 0 ) {
			$original_cycle_number = $level->cycle_number;
			$level->cycle_number   = $level->cycle_number * ( (int) $level->trial_limit + 1 );
		}

		$start_date = pmpro_calculate_profile_start_date( $order, 'U', true );

		// Restore the original billing frequency.
		if ( ! empty( $original_cycle_number ) ) {
			$level->cycle_number = $original_cycle_number;
		}

		return $start_date;
	}

	/**
	 * Get (or create) the Razorpay customer for the order's user.
	 *
	 * The customer id is stored in user meta per environment and reused on
	 * subsequent checkouts, mirroring PMPro's Stripe customer handling.
	 *
	 * @param MemberOrder $order The order being processed.
	 * @return string|WP_Error Razorpay customer id, or WP_Error on failure.
	 */
	private static function get_or_create_razorpay_customer( $order ) {
		$api      = PMProGateway_Razorpay_API::get_instance();
		$user_id  = (int) $order->user_id;
		$meta_key = $api->is_sandbox() ? 'pmpro_razorpay_sandbox_customer_id' : 'pmpro_razorpay_customer_id';

		// Reuse an existing customer if we already stored one.
		$customer_id = get_user_meta( $user_id, $meta_key, true );
		if ( ! empty( $customer_id ) ) {
			return $customer_id;
		}

		$user = get_userdata( $user_id );
		if ( empty( $user ) ) {
			return new WP_Error( 'razorpay_customer_error', __( 'Could not find the user for this checkout.', 'pmpro-razorpay' ) );
		}

		$name = trim( $user->first_name . ' ' . $user->last_name );
		if ( empty( $name ) ) {
			$name = $user->display_name;
		}

		// Gather the customer's contact number (phone), if available and valid.
		$contact = pmpro_getParam( 'bphone', 'REQUEST' );
		if ( empty( $contact ) ) {
			$contact = get_user_meta( $user_id, 'pmpro_bphone', true );
		}
		if ( ! empty( $contact ) ) {
			$contact = trim( $contact );
			$digits  = preg_replace( '/[^0-9]/', '', $contact );
			if ( strlen( $digits ) < 8 ) {
				$contact = ''; // Invalid for Razorpay; omit to avoid an API error.
			}
		}

		$args = array(
			'name'          => $name,
			'email'         => $user->user_email,
			'fail_existing' => '0',
			'notes'         => array(
				'pmpro_user_id' => $user_id,
			),
		);

		if ( ! empty( $contact ) ) {
			$args['contact'] = $contact;
		}

		$customer = $api->create_customer( $args );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		if ( empty( $customer['id'] ) ) {
			return new WP_Error( 'razorpay_customer_error', __( 'No customer id returned from Razorpay.', 'pmpro-razorpay' ) );
		}

		update_user_meta( $user_id, $meta_key, sanitize_text_field( $customer['id'] ) );

		return $customer['id'];
	}

	/**
	 * Send the order to Razorpay to pay.
	 *
	 * @param MemberOrder $order  MemberOrder object for this checkout.
	 * @since 1.0.0
	 */
	function sendToRazorpay( &$order ) {
		$api = PMProGateway_Razorpay_API::get_instance();

		// Ensure a Razorpay customer exists for this user (create + store + reuse).
		$customer_id = self::get_or_create_razorpay_customer( $order );
		if ( is_wp_error( $customer_id ) ) {
			$order->notes = trim( $order->notes . ' ' . sprintf( __( 'Error creating Razorpay customer: %s', 'pmpro-razorpay' ), $customer_id->get_error_message() ) );
			$order->saveOrder();
			pmpro_setMessage( $customer_id->get_error_message(), 'pmpro_error' );
			wp_redirect( pmpro_url( 'checkout' ) );
			exit;
		}

		//taxes on initial amount
		$initial_subtotal = $order->subtotal;
		$initial_tax = $order->getTaxForPrice( $initial_subtotal );
		$initial_payment_amount = pmpro_round_price( (float) $initial_subtotal  + (float) $initial_tax );
		$initial_amount_paise = PMProGateway_Razorpay_API::amount_to_paise( $initial_payment_amount );

		// Now, let's handle the recurring payments.
		$level = $order->getMembershipLevelAtCheckout();
		if ( pmpro_isLevelRecurring( $level ) ) {

			// Map the PM Pro cycle to a Razorpay plan period. Razorpay's interval is the cycle multiplier.
			$period = self::get_razorpay_period( $level->cycle_period );
			$interval = ! empty( $level->cycle_number ) ? (int) $level->cycle_number : 1;

			$billing_amount_paise = PMProGateway_Razorpay_API::amount_to_paise( $level->billing_amount );

			if ( defined( 'PMPRO_RAZORPAY_DEBUG' ) && PMPRO_RAZORPAY_DEBUG ) {
				error_log( sprintf(
					'[PMPro Razorpay] billing_amount=%s initial_payment=%s subtotal=%s billing_amount_paise=%s currency=%s',
					$level->billing_amount,
					$level->initial_payment,
					$initial_subtotal,
					$billing_amount_paise,
					PMProGateway_Razorpay_API::get_currency()
				) );
			}

			$plan_id = self::get_plan_for_level( $level, $period, $interval, $billing_amount_paise, PMProGateway_Razorpay_API::get_currency() );

			if ( is_wp_error( $plan_id ) ) {
				$order->notes = trim( $order->notes . ' ' . sprintf( __( 'Error creating Razorpay plan: %s', 'pmpro-razorpay' ), $plan_id->get_error_message() ) );
				$order->saveOrder();
				pmpro_setMessage( $plan_id->get_error_message(), 'pmpro_error' );
				wp_redirect( pmpro_url( 'checkout' ) );
				exit;
			}

			if ( defined( 'PMPRO_RAZORPAY_DEBUG' ) && PMPRO_RAZORPAY_DEBUG ) {
				error_log( sprintf( '[PMPro Razorpay] using plan id=%s', $plan_id ) );
			}

			// PM Pro's billing_limit is the number of payments after the initial, so
			// add one to get the total cycles Razorpay should charge. Cap at the
			// 100-year maximum for the period/interval.
			$max_total_count = self::get_max_total_count( $period, $interval );
			$total_count     = ! empty( $level->billing_limit )
				? min( (int) $level->billing_limit + 1, $max_total_count )
				: $max_total_count;

			$subscription_args = array(
				'plan_id' => $plan_id,
				'total_count' => $total_count,
				'customer_notify' => true,
				'notes' => array(
					'pmpro_order_id' => $order->id,
					'pmpro_user_id' => $order->user_id,
				),
			);

			/*
			 * Determine whether PMPro's initial payment is combined with the first
			 * recurring cycle. This mirrors PMPro's Stripe gateway. When the initial
			 * payment equals the recurring amount and there is no trial or explicit
			 * future start date, the first cycle IS the initial payment and must be
			 * charged immediately (no start_at).
			 */
			$combine_initial_and_recurring = (
				empty( $level->trial_limit ) &&
				empty( $level->profile_start_date ) &&
				! empty( $level->initial_payment ) &&
				(float) $level->initial_payment === (float) $level->billing_amount
			);

			/*
			 * The amount due today (including tax) above the recurring billing
			 * amount. Razorpay charges the plan amount as the first cycle, so the
			 * addon covers this difference on that first cycle.
			 */
			$setup_fee_paise = PMProGateway_Razorpay_API::amount_to_paise( $initial_payment_amount ) - $billing_amount_paise;

			if ( $setup_fee_paise > 0 ) {
				/*
				 * Initial payment is greater than the recurring amount (setup fee
				 * and/or tax). Charge plan + addon on the first cycle immediately.
				 */
				$subscription_args['addons'] = array(
					array(
						'item' => array(
							'name' => __( 'Setup Fee', 'pmpro-razorpay' ),
							'amount' => $setup_fee_paise,
							'currency' => PMProGateway_Razorpay_API::get_currency(),
						),
					),
				);
			} elseif ( ! $combine_initial_and_recurring ) {
				/*
				 * Genuine free trial / free start / explicit future start date:
				 * defer the first charge to the future recurring start date.
				 */
				$start_date = self::get_razorpay_start_date( $order, $level );
				if ( ! empty( $start_date ) && (int) $start_date > time() ) {
					$subscription_args['start_at'] = (int) $start_date;
				}
			}

			if ( defined( 'PMPRO_RAZORPAY_DEBUG' ) && PMPRO_RAZORPAY_DEBUG ) {
				error_log( sprintf(
					'[PMPro Razorpay] subscription_args order_id=%s combine=%s setup_fee_paise=%s start_at=%s total_count=%s',
					$order->id,
					$combine_initial_and_recurring ? 'true' : 'false',
					$setup_fee_paise,
					isset( $subscription_args['start_at'] ) ? $subscription_args['start_at'] : '',
					$total_count
				) );
			}

			$subscription = $api->create_subscription( $subscription_args );

			if ( is_wp_error( $subscription ) ) {
				$order->notes = trim( $order->notes . ' ' . sprintf( __( 'Error creating Razorpay subscription: %s', 'pmpro-razorpay' ), $subscription->get_error_message() ) );
				$order->saveOrder();
				pmpro_setMessage( $subscription->get_error_message(), 'pmpro_error' );
				wp_redirect( pmpro_url( 'checkout' ) );
				exit;
			}

			$order->subscription_transaction_id = $subscription['id'];
			update_pmpro_membership_order_meta( $order->id, 'razorpay_subscription_id', $subscription['id'], false );

		} else {

			// Non-recurring membership: create a one-time order.
			$razorpay_order = $api->create_order( $initial_amount_paise, $order->code, array( 'pmpro_order_id' => $order->id ) );

			if ( is_wp_error( $razorpay_order ) ) {
				$order->notes = trim( $order->notes . ' ' . sprintf( __( 'Error creating Razorpay order: %s', 'pmpro-razorpay' ), $razorpay_order->get_error_message() ) );
				$order->saveOrder();
				pmpro_setMessage( $razorpay_order->get_error_message(), 'pmpro_error' );
				wp_redirect( pmpro_url( 'checkout' ) );
				exit;
			}

			update_pmpro_membership_order_meta( $order->id, 'razorpay_order_id', $razorpay_order['id'], false );
		}

		$order->saveOrder();

		// Redirect back to checkout so the embedded modal can render.
		wp_redirect( pmpro_url( 'checkout', '?pmpro_level=' . $order->membership_id . '&pmpro_razorpay_order=' . $order->code ) );
		exit;
	}

	/**
	 * Enqueue the Razorpay Checkout.js modal when an order code is present.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_render_checkout_modal() {
		$code = pmpro_getParam( 'pmpro_razorpay_order', 'REQUEST' );
		if ( empty( $code ) ) {
			return;
		}

		$morder = new MemberOrder();
		$morder->getMemberOrderByCode( $code );

		if ( empty( $morder->id ) || 'razorpay' !== $morder->gateway ) {
			return;
		}

		$razorpay_order_id = get_pmpro_membership_order_meta( $morder->id, 'razorpay_order_id', true );
		$razorpay_subscription_id = get_pmpro_membership_order_meta( $morder->id, 'razorpay_subscription_id', true );

		if ( empty( $razorpay_order_id ) && empty( $razorpay_subscription_id ) ) {
			return;
		}

		$api = PMProGateway_Razorpay_API::get_instance();

		$user = get_userdata( $morder->user_id );
		$prefill_name = '';
		$prefill_email = '';
		$prefill_contact = '';
		if ( ! empty( $user ) ) {
			$prefill_name = trim( $user->first_name . ' ' . $user->last_name );
			if ( empty( $prefill_name ) ) {
				$prefill_name = $user->display_name;
			}
			$prefill_email = $user->user_email;
			$prefill_contact = get_user_meta( $morder->user_id, 'pmpro_bphone', true );
		}

		$description = '';
		$level = $morder->getMembershipLevel();
		if ( ! empty( $level ) ) {
			$description = $level->name;
		}

		$config = array(
			'key' => $api->get_key_id(),
			'currency' => PMProGateway_Razorpay_API::get_currency(),
			'name' => get_bloginfo( 'name' ),
			'description' => $description,
			'prefill' => array(
				'name' => $prefill_name,
				'email' => $prefill_email,
				'contact' => $prefill_contact,
			),
			'theme_color' => '',
			'order_id' => '',
			'subscription_id' => '',
		);

		if ( ! empty( $razorpay_order_id ) ) {
			$config['order_id'] = $razorpay_order_id;
			$config['amount'] = PMProGateway_Razorpay_API::amount_to_paise( $morder->total );
		}

		if ( ! empty( $razorpay_subscription_id ) ) {
			$config['subscription_id'] = $razorpay_subscription_id;
		}

		$config['callback_url'] = pmpro_url( 'confirmation', '?level=' . $morder->membership_id );

		wp_enqueue_script( 'razorpay-checkout', 'https://checkout.razorpay.com/v1/checkout.js', array(), null, true );
		wp_enqueue_script( 'pmpro-razorpay', plugins_url( 'js/pmpro-razorpay.js', PMPRO_RAZORPAY_DIR . '/pmpro-razorpay.php' ), array( 'razorpay-checkout' ), PMPRO_RAZORPAY_VERSION, true );

		wp_localize_script( 'pmpro-razorpay', 'pmproRazorpay', $config );
	}

	/**
	 * Process a cancellation in Razorpay.
	 *
	 * @param MemberOrder $order The order object.
	 * @return boolean True if canceled successfuly, false otherwise.
	 * @since 1.0.0
	 */
	function cancel( &$order ) {
		//require a payment transaction id
		if ( empty( $order->subscription_transaction_id ) ) {
			return false;
		}

		//Call the cancel subscription at gateway function
		$result = $this->cancel_subscription_at_gateway( $order->subscription_transaction_id );

		if ( ! $result ) {
			$order->notes = trim( $order->notes . ' ' . sprintf( __( 'Error cancelling subscription %s at Razorpay.', 'pmpro-razorpay' ), $order->subscription_transaction_id ) );
			$order->saveOrder();
		}

		return $result;
	}

	/**
	 * Cancels a subscription in Razorpay.
	 *
	 * @param PMPro_Subscription $subscription to cancel.
	 * @return bool True if successful, false otherwise.
	 * @since 1.0.0
	 */
	function cancel_subscription( $subscription ) {
		//get subscription id
		$subscription_id = $subscription->get_subscription_transaction_id();
		return $this->cancel_subscription_at_gateway( $subscription_id );
	}

	/**
	 * Cancels a subscription at the gateway.
	 *
	 * @param String $subscription_id The subscription id of the subscription to cancel.
	 * @return bool True if successful, false otherwise.
	 * @since 1.0.0
	 */
	function cancel_subscription_at_gateway( $subscription_id ) {
		if ( empty( $subscription_id ) ) {
			return false;
		}

		$api = PMProGateway_Razorpay_API::get_instance();

		// Cancel at the end of the current cycle so members keep their remaining days.
		$response = $api->cancel_subscription( $subscription_id, true );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Pull subscription status from Razorpay
	 *
	 * @param PMPro_Subscription $subscription The subscription object.
	 * @return string|null Error message is returned if update fails.
	 * @since 1.0.0
	 */
	public function update_subscription_info( $subscription ) {

		// Bail if subscription ID is missing
		if ( empty( $subscription->get_subscription_transaction_id() ) ) {
			return __( 'Subscription transaction ID is missing.', 'pmpro-razorpay' );
		}

		$api = PMProGateway_Razorpay_API::get_instance();
		$razorpay_subscription = $api->fetch_subscription( $subscription->get_subscription_transaction_id() );

		if ( is_wp_error( $razorpay_subscription ) ) {
			return $razorpay_subscription->get_error_message();
		}

		if ( empty( $razorpay_subscription['id'] ) ) {
			return __( 'No subscription returned from Razorpay.', 'pmpro-razorpay' );
		}

		$update_array = array();

		// Map Razorpay statuses to PMPro statuses.
		// Only genuinely terminal states map to 'cancelled'. Transitional states
		// (authenticated, created, pending, paused, resumed) leave PMPro unchanged:
		// they are not proof of cancellation, nor proof of a paid membership.
		if ( ! empty( $razorpay_subscription['status'] ) ) {
			$status            = sanitize_text_field( $razorpay_subscription['status'] );
			$cancelled_statuses = array( 'cancelled', 'completed', 'halted', 'expired' );

			if ( in_array( $status, $cancelled_statuses, true ) ) {
				$update_array['status'] = 'cancelled';
			} elseif ( 'active' === $status ) {
				$update_array['status'] = 'active';
			}
			// Otherwise leave the PMPro status unchanged.
		}

		if ( ! empty( $razorpay_subscription['charge_at'] ) ) {
			$update_array['next_payment_date'] = date( 'Y-m-d H:i:s', (int) $razorpay_subscription['charge_at'] );
		}

		if ( ! empty( $razorpay_subscription['start_at'] ) ) {
			$update_array['startdate'] = date( 'Y-m-d H:i:s', (int) $razorpay_subscription['start_at'] );
		}

		if ( ! empty( $razorpay_subscription['ended_at'] ) ) {
			$update_array['enddate'] = date( 'Y-m-d H:i:s', (int) $razorpay_subscription['ended_at'] );
		}

		if ( defined( 'PMPRO_RAZORPAY_DEBUG' ) && PMPRO_RAZORPAY_DEBUG ) {
			error_log( sprintf(
				'[PMPro Razorpay] subscription_sync subscription=%s razorpay_status=%s pmpro_status=%s',
				isset( $razorpay_subscription['id'] ) ? $razorpay_subscription['id'] : '',
				isset( $razorpay_subscription['status'] ) ? $razorpay_subscription['status'] : '',
				isset( $update_array['status'] ) ? $update_array['status'] : 'unchanged'
			) );
		}

		// Update subscription object
		$subscription->set( $update_array );
	}

	/**
	 * Functionality to process refunds for Razorpay. This only supports full refunds.
	 *
	 * @param bool       $success
	 * @param MemberOrder $order
	 * @return bool
	 */
	static function process_refund( $success, $order ) {

		if ( empty( $order->payment_transaction_id ) ) {
			return false;
		}

		// Let's set success to false as a default.
		$success = false;

		$api = PMProGateway_Razorpay_API::get_instance();
		$response = $api->refund_payment( $order->payment_transaction_id, PMProGateway_Razorpay_API::amount_to_paise( $order->total ) );

		if ( is_wp_error( $response ) ) {
			$refund_note = trim( __( 'Admin: There was a problem processing the refund.', 'pmpro-razorpay' ) . ' ' . $response->get_error_message() );
			if ( false === strpos( $order->notes, $refund_note ) ) {
				$order->notes = trim( $order->notes . ' ' . $refund_note );
			}
			$order->SaveOrder();
			return false;
		}

		// Refund was successful lets send an email.
		$success = true;
		$order->status = 'refunded';

		$user = get_userdata( $order->user_id );

		//send an email to the member
		$myemail = new PMProEmail();
		$myemail->sendRefundedEmail( $user, $order );

		//send an email to the admin
		$myemail = new PMProEmail();
		$myemail->sendRefundedAdminEmail( $user, $order );

		$order->SaveOrder();

		return $success;
	}
}
