<?php
/**
 * Plugin Name: Paid Memberships Pro - Razorpay Gateway
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/razorpay
 * Description: PMPro Gateway integration for Razorpay
 * Version: 1.0.0
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Author: Sayan Dey
 * Author URI: https://github.com/sayandey18
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pmpro-razorpay
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PMPRO_RAZORPAY_DIR', dirname( __FILE__ ) );
define( 'PMPRO_RAZORPAY_VERSION', '1.0.0' );

/**
 * Loads the Razorpay gateway if PMPro is active.
 */
function pmpro_razorpay_load_gateway() {
	if ( class_exists( 'PMProGateway' ) ) {
		require_once PMPRO_RAZORPAY_DIR . '/classes/class.pmprogateway_razorpay_api.php';
		require_once PMPRO_RAZORPAY_DIR . '/classes/class.pmprogateway_razorpay.php';
	}
}
add_action( 'plugins_loaded', 'pmpro_razorpay_load_gateway' );

/**
 * Callback for the Razorpay webhook.
 */
function pmpro_wp_ajax_razorpay_webhook() {
	require_once dirname( __FILE__ ) . '/webhook.php';
	exit;
}
add_action( 'wp_ajax_nopriv_razorpay-webhook', 'pmpro_wp_ajax_razorpay_webhook' );
add_action( 'wp_ajax_razorpay-webhook', 'pmpro_wp_ajax_razorpay_webhook' );

/**
 * Runs only when the plugin is activated.
 *
 * @since 1.0.0
 */
function pmpro_razorpay_admin_notice_activation_hook() {
	// Create transient data.
	set_transient( 'pmpro-razorpay-admin-notice', true, 5 );
}
register_activation_hook( __FILE__, 'pmpro_razorpay_admin_notice_activation_hook' );

/**
 * Admin Notice on Activation.
 *
 * @since 1.0.0
 */
function pmpro_razorpay_admin_notice() {
	// Check transient, if available display notice.
	if ( get_transient( 'pmpro-razorpay-admin-notice' ) && class_exists( 'PMProGateway' ) ) {
		?>
		<div class="updated notice is-dismissible">
			<p>
			<?php
			printf(
				wp_kses(
					/* translators: %s: URL to the PMPro payment settings page. */
					__( 'Thank you for activating the Paid Memberships Pro: Razorpay Add On. <a href="%s">Visit the payment settings page</a> to configure the Razorpay Payment Gateway.', 'pmpro-razorpay' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) )
			);
			?>
			</p>
		</div>
		<?php
		// Delete transient, only display this notice once.
		delete_transient( 'pmpro-razorpay-admin-notice' );
	}
}
add_action( 'admin_notices', 'pmpro_razorpay_admin_notice' );

/**
 * Warn admins when Paid Memberships Pro is not installed or activated.
 *
 * @since 1.0.0
 */
function pmpro_razorpay_admin_notices() {
	if ( class_exists( 'PMProGateway' ) ) {
		return;
	}

	$install_url = wp_nonce_url(
		self_admin_url( 'plugin-install.php?s=paid-memberships-pro&tab=search&type=term' ),
		'install-plugin_paid-memberships-pro'
	);
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				wp_kses(
					/* translators: 1: Opening link tag, 2: Closing link tag. */
					__( 'The Paid Memberships Pro - Razorpay Gateway Add On requires the Paid Memberships Pro plugin to be installed and activated. %1$sInstall or activate Paid Memberships Pro%2$s.', 'pmpro-razorpay' ),
					array( 'a' => array( 'href' => array() ) )
				),
				'<a href="' . esc_url( $install_url ) . '">',
				'</a>'
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'pmpro_razorpay_admin_notices' );

/**
 * Function to add links to the plugin action links
 *
 * @param array $links Array of links to be shown in plugin action links.
 */
function pmpro_razorpay_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$new_links = array(
			'<a href="' . get_admin_url( null, 'admin.php?page=pmpro-paymentsettings' ) . '">' . __( 'Configure Razorpay', 'pmpro-razorpay' ) . '</a>',
		);
		$links     = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pmpro_razorpay_plugin_action_links' );

/**
 * Function to add links to the plugin row meta
 *
 * @param array  $links Array of links to be shown in plugin meta.
 * @param string $file  Filename of the plugin meta is being shown for.
 */
function pmpro_razorpay_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-razorpay.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( 'https://github.com/sayandey18/pmpro-razorpay' ) . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-razorpay' ) ) . '">' . __( 'Docs', 'pmpro-razorpay' ) . '</a>',
			'<a href="' . esc_url( 'https://github.com/sayandey18/pmpro-razorpay/issues' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-razorpay' ) ) . '">' . __( 'Support', 'pmpro-razorpay' ) . '</a>',
		);
		$links = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'pmpro_razorpay_plugin_row_meta', 10, 2 );
