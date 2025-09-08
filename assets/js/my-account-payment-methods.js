(function($) {
    'use strict';

    var BNA_PaymentMethods = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('click', '.bna-delete-method', this.handleDelete);
        },

        handleDelete: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var methodId = $button.data('method-id');
            var $methodRow = $button.closest('.bna-payment-method');
            
            if (!methodId) {
                return;
            }

            if (!confirm(bna_my_account.messages.confirm_delete)) {
                return;
            }

            BNA_PaymentMethods.deletePaymentMethod($button, $methodRow, methodId);
        },

        deletePaymentMethod: function($button, $methodRow, methodId) {
            var originalText = $button.text();
            
            $button.prop('disabled', true).text(bna_my_account.messages.deleting);
            $methodRow.css('opacity', '0.6');

            $.ajax({
                url: bna_my_account.ajax_url,
                type: 'POST',
                data: {
                    action: 'bna_delete_payment_method',
                    payment_method_id: methodId,
                    nonce: bna_my_account.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $methodRow.fadeOut(300, function() {
                            $(this).remove();
                            BNA_PaymentMethods.checkIfEmpty();
                        });
                        BNA_PaymentMethods.showMessage(bna_my_account.messages.success, 'success');
                    } else {
                        BNA_PaymentMethods.showMessage(response.data || bna_my_account.messages.error, 'error');
                        BNA_PaymentMethods.resetButton($button, $methodRow, originalText);
                    }
                },
                error: function() {
                    BNA_PaymentMethods.showMessage(bna_my_account.messages.error, 'error');
                    BNA_PaymentMethods.resetButton($button, $methodRow, originalText);
                }
            });
        },

        resetButton: function($button, $methodRow, originalText) {
            $button.prop('disabled', false).text(originalText);
            $methodRow.css('opacity', '1');
        },

        checkIfEmpty: function() {
            var $methodsList = $('.bna-methods-list');
            var $paymentMethods = $methodsList.find('.bna-payment-method');
            
            if ($paymentMethods.length === 0) {
                var noMethodsHtml = '<div class="bna-no-methods">' +
                    '<p>' + 'You have no saved payment methods.' + '</p>' +
                    '<p>' + 'Payment methods will be automatically saved when you make a purchase.' + '</p>' +
                    '</div>';
                
                $methodsList.replaceWith(noMethodsHtml);
                $('.bna-methods-info').hide();
            }
        },

        showMessage: function(message, type) {
            var $container = $('.bna-payment-methods');
            var $existing = $container.find('.bna-message');
            
            if ($existing.length) {
                $existing.remove();
            }
            
            var className = type === 'success' ? 'notice-success' : 'notice-error';
            var $message = $('<div class="bna-message notice ' + className + '"><p>' + message + '</p></div>');
            
            $container.prepend($message);
            
            setTimeout(function() {
                $message.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };

    $(document).ready(function() {
        BNA_PaymentMethods.init();
    });

})(jQuery);
