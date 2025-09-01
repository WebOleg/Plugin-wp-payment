/**
 * BNA Smart Payment - iframe with message processing
 */

(function($) {
    'use strict';

    var BNA_Payment = {

        init: function() {
            this.setupIframeHandlers();
            this.setupMessageListener();
        },

        setupIframeHandlers: function() {
            var $iframe = $('#bna-payment-iframe');
            var $loading = $('#bna-payment-loading');
            var $errorContainer = $('#bna-payment-error');

            if (!$iframe.length) {
                return;
            }

            $iframe.on('load', function() {
                $loading.fadeOut(300, function() {
                    $iframe.fadeIn(300);
                });
            });

            $iframe.on('error', function() {
                $loading.hide();
                $errorContainer.show();
            });

            this.setupLoadTimeout($loading, $errorContainer);
        },

        setupLoadTimeout: function($loading, $errorContainer) {
            setTimeout(function() {
                if ($loading.is(':visible')) {
                    $loading.hide();
                    $errorContainer.show();
                    $('#bna-error-message').text('Payment form loading timed out. Please refresh the page.');
                }
            }, 15000);
        },

        setupMessageListener: function() {
            var self = this;
            
            window.addEventListener('message', function(event) {
                var allowedOrigins = [
                    'https://dev-api-service.bnasmartpayment.com',
                    'https://stage-api-service.bnasmartpayment.com', 
                    'https://api.bnasmartpayment.com'
                ];
                
                if (allowedOrigins.indexOf(event.origin) === -1) {
                    return;
                }
                
                if (event.data && event.data.type) {
                    self.handlePaymentMessage(event.data);
                }
            });
        },

        handlePaymentMessage: function(data) {
            switch(data.type) {
                case 'payment_success':
                    this.handlePaymentSuccess(data.data);
                    break;
                    
                case 'payment_failed':
                case 'payment_error':
                    this.handlePaymentFailure(data.message);
                    break;
            }
        },

        handlePaymentSuccess: function(paymentData) {
            this.showSuccessMessage();
            
            setTimeout(function() {
                var thankYouUrl = window.bnaPaymentData && window.bnaPaymentData.thankYouUrl;
                if (thankYouUrl) {
                    window.location.href = thankYouUrl;
                } else {
                    window.location.reload();
                }
            }, 2000);
        },

        handlePaymentFailure: function(errorMessage) {
            $('#bna-iframe-container').hide();
            $('#bna-payment-error').show();
            $('#bna-error-message').text(errorMessage || 'Payment failed. Please try again.');
        },

        showSuccessMessage: function() {
            var $container = $('#bna-iframe-container');
            $container.html(
                '<div style="text-align: center; padding: 40px; background: #f0f8ff; border: 2px solid #4CAF50; border-radius: 8px;">' +
                '<div style="font-size: 48px; color: #4CAF50; margin-bottom: 20px;">✓</div>' +
                '<h3 style="color: #4CAF50; margin-bottom: 15px;">Payment Successful!</h3>' +
                '<p>Redirecting you to confirmation page...</p>' +
                '</div>'
            );
        }
    };

    $(document).ready(function() {
        BNA_Payment.init();
    });

})(jQuery);
