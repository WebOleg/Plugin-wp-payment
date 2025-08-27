/**
 * BNA Smart Payment - iframe with message processing
 */

(function($) {
    'use strict';

    var BNA_Payment = {

        init: function() {
            console.log('BNA Payment: Initializing...');
            this.setupIframeHandlers();
            this.setupMessageListener();
        },

        setupIframeHandlers: function() {
            var $iframe = $('#bna-payment-iframe');
            var $loading = $('#bna-payment-loading');
            var $errorContainer = $('#bna-payment-error');

            if (!$iframe.length) {
                console.log('BNA Payment: No iframe found');
                return;
            }

            // Handle iframe load
            $iframe.on('load', function() {
                console.log('BNA Payment: iFrame loaded successfully');
                $loading.fadeOut(300, function() {
                    $iframe.fadeIn(300);
                });
            });

            // Handle iframe errors
            $iframe.on('error', function() {
                console.error('BNA Payment: iFrame failed to load');
                $loading.hide();
                $errorContainer.show();
            });

            // Timeout handler
            this.setupLoadTimeout($loading, $errorContainer);
        },

        setupLoadTimeout: function($loading, $errorContainer) {
            setTimeout(function() {
                if ($loading.is(':visible')) {
                    console.warn('BNA Payment: iFrame loading timeout');
                    $loading.hide();
                    $errorContainer.show();
                    $('#bna-error-message').text('Payment form loading timed out. Please refresh the page.');
                }
            }, 15000);
        },

        setupMessageListener: function() {
            var self = this;
            
            window.addEventListener('message', function(event) {
                console.log('BNA Payment: Message received:', event.data);
                
                // Validate origin for security
                var allowedOrigins = [
                    'https://dev-api-service.bnasmartpayment.com',
                    'https://stage-api-service.bnasmartpayment.com', 
                    'https://api.bnasmartpayment.com'
                ];
                
                if (allowedOrigins.indexOf(event.origin) === -1) {
                    console.warn('BNA Payment: Message from untrusted origin:', event.origin);
                    return;
                }
                
                if (event.data && event.data.type) {
                    self.handlePaymentMessage(event.data);
                }
            });
        },

        handlePaymentMessage: function(data) {
            console.log('BNA Payment: Handling message type:', data.type);
            
            switch(data.type) {
                case 'payment_success':
                    this.handlePaymentSuccess(data.data);
                    break;
                    
                case 'payment_failed':
                case 'payment_error':
                    this.handlePaymentFailure(data.message);
                    break;
                    
                default:
                    console.log('BNA Payment: Unknown message type:', data.type);
            }
        },

        handlePaymentSuccess: function(paymentData) {
            console.log('BNA Payment: Payment successful, redirecting...');
            
            // Show success message briefly
            this.showSuccessMessage();
            
            // Redirect to thank you page after short delay
            setTimeout(function() {
                // Get thank you URL from page data or construct it
                var thankYouUrl = window.bnaPaymentData && window.bnaPaymentData.thankYouUrl;
                if (thankYouUrl) {
                    window.location.href = thankYouUrl;
                } else {
                    // Fallback: reload page to trigger PHP redirect check
                    window.location.reload();
                }
            }, 2000);
        },

        handlePaymentFailure: function(errorMessage) {
            console.log('BNA Payment: Payment failed:', errorMessage);
            
            // Hide iframe and show error
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

    // Initialize when document ready
    $(document).ready(function() {
        BNA_Payment.init();
    });

})(jQuery);
