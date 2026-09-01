<?php
/**
 * Uninstall routine for the PMPro Razorpay add-on.
 *
 * Removes plugin data when the site administrator has opted in via the
 * "Remove all Razorpay data on uninstall" setting.
 *
 * @package PMPro Razorpay
 */

// Exit if uninstall is not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Only remove plugin options when the administrator opted in.
if ( get_option( 'pmpro_razorpay_delete_data_on_uninstall', false ) ) {
	$pmpro_razorpay_options_to_delete = array(
		'pmpro_razorpay_key_id',
		'pmpro_razorpay_key_secret',
		'pmpro_razorpay_webhook_secret',
		'pmpro_razorpay_sandbox_key_id',
		'pmpro_razorpay_sandbox_key_secret',
		'pmpro_razorpay_sandbox_webhook_secret',
		'pmpro_razorpay_show_billing_address',
	);

	foreach ( $pmpro_razorpay_options_to_delete as $pmpro_razorpay_option ) {
		delete_option( $pmpro_razorpay_option );
	}
}

// Always remove the opt-in flag and the activation notice transient.
delete_option( 'pmpro_razorpay_delete_data_on_uninstall' );
delete_transient( 'pmpro-razorpay-admin-notice' );
