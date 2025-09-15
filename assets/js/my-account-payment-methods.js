(function($) {
    'use strict';

    var BNA_PaymentMethods = {
        isDeleting: false,
        retryCount: 0,
        maxRetries: 2,

        init: function() {
            this.bindEvents();
            this.initAccessibility();
        },

        bindEvents: function() {
            $(document).on('click', '.bna-delete-method', this.handleDelete.bind(this));
        },

        initAccessibility: function() {
            $('.bna-delete-method').attr({
                'role': 'button',
                'aria-label': 'Delete payment method'
            });
        },

        handleDelete: function(e) {
            e.preventDefault();

            if (this.isDeleting) {
                return;
            }

            var $button = $(e.currentTarget);
            var methodId = $button.data('method-id');
            var $methodRow = $button.closest('.bna-payment-method');

            if (!methodId) {
                this.showMessage('Invalid payment method. Please refresh the page.', 'error');
                return;
            }

            var methodName = $methodRow.find('.bna-method-name').text();
            var confirmMessage = bna_my_account.messages.confirm_delete + '\n\n' + methodName;

            if (!confirm(confirmMessage)) {
                return;
            }

            this.deletePaymentMethod($button, $methodRow, methodId);
        },

        deletePaymentMethod: function($button, $methodRow, methodId) {
            if (this.isDeleting) {
                return;
            }

            this.isDeleting = true;
            this.retryCount = 0;

            var originalText = $button.text();
            this.setLoadingState($button, $methodRow, true);

            this.performDelete($button, $methodRow, methodId, originalText);
        },

        performDelete: function($button, $methodRow, methodId, originalText) {
            var self = this;

            $.ajax({
                url: bna_my_account.ajax_url,
                type: 'POST',
                timeout: 15000,
                data: {
                    action: 'bna_delete_payment_method',
                    payment_method_id: methodId,
                    nonce: bna_my_account.nonce
                },
                success: function(response) {
                    self.handleSuccess(response, $button, $methodRow, originalText);
                },
                error: function(xhr, status, error) {
                    self.handleError(xhr, status, error, $button, $methodRow, methodId, originalText);
                }
            });
        },

        handleSuccess: function(response, $button, $methodRow, originalText) {
            this.isDeleting = false;

            if (response && response.success) {
                this.animateRemoval($methodRow);
                this.showMessage(response.data || bna_my_account.messages.success, 'success');
                this.updateMethodCount(-1);
            } else {
                var errorMessage = (response && response.data) ? response.data : bna_my_account.messages.error;
                this.showMessage(errorMessage, 'error');
                this.setLoadingState($button, $methodRow, false, originalText);
            }
        },

        handleError: function(xhr, status, error, $button, $methodRow, methodId, originalText) {
            var self = this;

            if (status === 'timeout' && this.retryCount < this.maxRetries) {
                this.retryCount++;
                $button.text('Retrying... (' + this.retryCount + '/' + this.maxRetries + ')');

                setTimeout(function() {
                    self.performDelete($button, $methodRow, methodId, originalText);
                }, 2000);
                return;
            }

            this.isDeleting = false;

            var errorMessage = this.getErrorMessage(xhr, status, error);
            this.showMessage(errorMessage, 'error');
            this.setLoadingState($button, $methodRow, false, originalText);
        },

        getErrorMessage: function(xhr, status, error) {
            if (status === 'timeout') {
                return 'Request timed out. The payment method may still be deleted. Please refresh the page to check.';
            }

            if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                return xhr.responseJSON.data;
            }

            if (status === 'abort') {
                return 'Request was cancelled. Please try again.';
            }

            return bna_my_account.messages.error + ' (Error: ' + status + ')';
        },

        setLoadingState: function($button, $methodRow, isLoading, originalText) {
            if (isLoading) {
                $button.prop('disabled', true).text(bna_my_account.messages.deleting);
                $button.attr('aria-disabled', 'true');
                $methodRow.css('opacity', '0.6').addClass('bna-deleting');
            } else {
                $button.prop('disabled', false).text(originalText || 'Delete');
                $button.attr('aria-disabled', 'false');
                $methodRow.css('opacity', '1').removeClass('bna-deleting');
            }
        },

        animateRemoval: function($methodRow) {
            var self = this;

            $methodRow.slideUp(400, function() {
                $(this).remove();
                self.checkIfEmpty();
            });
        },

        updateMethodCount: function(change) {
            var $countElement = $('.bna-methods-count');
            if ($countElement.length) {
                var currentCount = parseInt($countElement.text()) || 0;
                var newCount = Math.max(0, currentCount + change);
                $countElement.text(newCount);
            }
        },

        checkIfEmpty: function() {
            var $methodsList = $('.bna-methods-list');
            var $paymentMethods = $methodsList.find('.bna-payment-method');

            if ($paymentMethods.length === 0) {
                var noMethodsHtml = '<div class="bna-no-methods" role="status">' +
                    '<div class="bna-no-methods-icon" aria-hidden="true">💳</div>' +
                    '<h4>No Payment Methods Saved</h4>' +
                    '<p>You have no saved payment methods.</p>' +
                    '<p><small>Payment methods will be automatically saved when you make a purchase.</small></p>' +
                    '</div>';

                $methodsList.replaceWith(noMethodsHtml);
                $('.bna-methods-info').slideUp(300);

                this.announceChange('All payment methods have been removed.');
            }
        },

        showMessage: function(message, type) {
            var $container = $('.bna-payment-methods');
            var $existing = $container.find('.bna-message');

            if ($existing.length) {
                $existing.remove();
            }

            var iconClass = type === 'success' ? '✓' : '⚠';
            var className = type === 'success' ? 'bna-message-success' : 'bna-message-error';

            var $message = $('<div class="bna-message ' + className + '" role="alert" aria-live="polite">' +
                '<span class="bna-message-icon" aria-hidden="true">' + iconClass + '</span>' +
                '<span class="bna-message-text">' + this.escapeHtml(message) + '</span>' +
                '<button class="bna-message-close" type="button" aria-label="Close message">&times;</button>' +
                '</div>');

            $container.prepend($message);

            $message.find('.bna-message-close').on('click', function() {
                $message.fadeOut(200, function() { $(this).remove(); });
            });

            if (type === 'success') {
                setTimeout(function() {
                    if ($message.is(':visible')) {
                        $message.fadeOut(300, function() { $(this).remove(); });
                    }
                }, 5000);
            }
        },

        announceChange: function(message) {
            var $announcer = $('#bna-sr-announcer');
            if (!$announcer.length) {
                $announcer = $('<div id="bna-sr-announcer" class="sr-only" aria-live="polite"></div>');
                $('body').append($announcer);
            }
            $announcer.text(message);
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        BNA_PaymentMethods.init();
    });

})(jQuery);