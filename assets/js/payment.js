/**
 * BNA Smart Payment - Payment JavaScript
 * 
 * Simple iframe handling without complex message processing
 */

(function($) {
    'use strict';

    var BNA_Payment = {
        
        init: function() {
            this.setupIframe();
        },

        setupIframe: function() {
            var $iframe = $('#bna-payment-iframe');
            var $loading = $('#bna-payment-loading');
            var $errorContainer = $('#bna-payment-error');
            
            if (!$iframe.length) {
                return;
            }

            // Handle iframe load
            $iframe.on('load', function() {
                $loading.hide();
                $iframe.show();
                console.log('BNA Payment iframe loaded successfully');
            });

            // Handle iframe load error
            $iframe.on('error', function() {
                $loading.hide();
                $errorContainer.show();
                console.error('BNA Payment iframe failed to load');
            });

            // Timeout handler
            setTimeout(function() {
                if ($loading.is(':visible')) {
                    $loading.hide();
                    $errorContainer.show();
                    $('#bna-error-message').text('Payment form loading timed out. Please refresh the page.');
                }
            }, 15000);

            console.log('BNA Payment iframe setup completed');
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        BNA_Payment.init();
    });

})(jQuery);
