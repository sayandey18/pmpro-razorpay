=== Paid Memberships Pro - Razorpay Gateway ===
Contributors: sayandey18
Tags: paid memberships pro, payment gateway, razorpay, upi autopay
Requires at least: 6.0
Tested up to: 7.1
Requires Plugins: paid-memberships-pro
Stable tag: 1.0.0

Adds the ability to accept payments using the Razorpay Payment Gateway

== Description ==

Adds Razorpay as a payment gateway to your list of accepted payment gateways. Razorpay supports one-time payments and recurring subscriptions via cards, UPI Autopay, and eMandates. International currencies are supported via cards; UPI Autopay and eMandates support INR only.

This Add On requires the [Paid Memberships Pro](https://wordpress.org/plugins/paid-memberships-pro/) plugin.

= Requirements =

* [Paid Memberships Pro](https://wordpress.org/plugins/paid-memberships-pro/) (2.12.6 or higher)

[Read the full documentation for the Razorpay Gateway Add On](https://github.com/sayandey18/pmpro-razorpay)

== Installation ==

1. Make sure you have the Paid Memberships Pro plugin installed and activated.
1. Upload the `pmpro-razorpay` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. Navigate to Memberships > Settings > Payment Gateways & SSL and select the Razorpay payment gateway.
1. Enter your Razorpay Key ID, Key Secret, and Webhook Secret (found in the Razorpay Dashboard under Settings > API Keys and Webhooks).
1. In the Razorpay Dashboard, add a webhook pointing to `{site}/wp-admin/admin-ajax.php?action=razorpay-webhook` with the secret you entered above, and subscribe to the relevant events (payment.captured, payment.failed, subscription.authenticated, subscription.charged, subscription.charge.failed, subscription.cancelled, subscription.halted, subscription.paused, subscription.resumed, subscription.completed, refund.created).
1. Set the Paid Memberships Pro currency. UPI Autopay and eMandates support INR only; international currencies are supported via cards.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the GitHub issue tracker here: https://github.com/sayandey18/pmpro-razorpay/issues

= I need help installing, configuring, or customizing the plugin. =

== Changelog ==
= 1.0.0 =
* Initial Release
* ENHANCEMENT: Added Razorpay as a payment gateway supporting one-time payments and recurring subscriptions via cards and UPI Autopay.
* NOTE: UPI Autopay and eMandates support INR only; international currencies are supported via cards.
