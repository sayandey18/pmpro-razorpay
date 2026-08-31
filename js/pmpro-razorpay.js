( function( window, document ) {
	'use strict';

	function pmproRazorpayOpenCheckout() {
		var config = window.pmproRazorpay;

		if ( ! config ) {
			return;
		}

		if ( typeof window.Razorpay === 'undefined' ) {
			return;
		}

		var options = {
			key: config.key || '',
			currency: config.currency || 'INR',
			name: config.name || '',
			description: config.description || '',
			prefill: config.prefill || {},
			theme: {
				color: config.theme_color || '#0C2457'
			}
		};

		if ( config.subscription_id ) {
			options.subscription_id = config.subscription_id;
		} else if ( config.order_id ) {
			options.order_id = config.order_id;
			options.amount = config.amount;
		}

		if ( config.callback_url ) {
			options.handler = function() {
				window.location.href = config.callback_url;
			};
		}

		var rzp = new window.Razorpay( options );

		rzp.on( 'payment.failed', function( response ) {
			var message = ( response.error && response.error.description ) ? response.error.description : 'Payment failed.';
			window.alert( message );
		} );

		rzp.open();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', pmproRazorpayOpenCheckout );
	} else {
		pmproRazorpayOpenCheckout();
	}
} )( window, document );
