/**
 * BNA Smart Payment - Simple iFrame Handler
 *
 * Basic iframe loading without message processing
 */

(function($) {
    'use strict';

    /**
     * Main payment object
     */
    var BNA_Payment = {

        /**
         * Initialize payment handler
         */
        init: function() {
            console.log('BNA Payment: Initializing...');
            this.setupIframeHandlers();
        },

        /**
         * Setup iframe loading handlers
         */
        setupIframeHandlers: function() {
            var $iframe = $('#bna-payment-iframe');
            var $loading = $('#bna-payment-loading');
            var $errorContainer = $('#bna-payment-error');

            if (!$iframe.length) {
                console.log('BNA Payment: No iframe found');
                return;
            }

            console.log('BNA Payment: Setting up iframe handlers');

            // Handle successful iframe load
            $iframe.on('load', function() {
                console.log('BNA Payment: iFrame loaded successfully');
                $loading.fadeOut(300, function() {
                    $iframe.fadeIn(300);
                });
            });

            // Handle iframe load errors
            $iframe.on('error', function() {
                console.error('BNA Payment: iFrame failed to load');
                $loading.hide();
                $errorContainer.show();
            });

            // Timeout handler (15 seconds)
            this.setupLoadTimeout($loading, $errorContainer);
        },

        /**
         * Setup loading timeout
         */
        setupLoadTimeout: function($loading, $errorContainer) {
            setTimeout(function() {
                if ($loading.is(':visible')) {
                    console.warn('BNA Payment: iFrame loading timeout');
                    $loading.hide();
                    $errorContainer.show();
                    $('#bna-error-message').text('Payment form loading timed out. Please refresh the page or try again.');
                }
            }, 15000);
        }
    };

    /**
     * Initialize when document ready
     */
    $(document).ready(function() {
        BNA_Payment.init();
    });

})(jQuery);