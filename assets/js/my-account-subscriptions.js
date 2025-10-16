jQuery(document).ready(function($) {
    'use strict';

    if (typeof window.bna_subscriptions === 'undefined' || !window.bna_subscriptions) {
        console.error('BNA Subscriptions: Configuration not loaded.');
        $('.bna-my-account-subscriptions').html(
            '<div class="woocommerce-info"><p>Subscriptions functionality is currently unavailable.</p></div>'
        );
        return;
    }

    var config = window.bna_subscriptions;
    var ajaxUrl = config.ajax_url;
    var nonce = config.nonce;
    var messages = config.messages || {};

    if (!ajaxUrl || !nonce) {
        console.error('BNA Subscriptions: Missing AJAX configuration');
        $('.bna-my-account-subscriptions').prepend(
            '<div class="woocommerce-error"><p>Configuration error: Missing authentication tokens.</p></div>'
        );
        return;
    }

    function setLoadingState($subscriptionItem, isLoading) {
        if (isLoading) {
            $subscriptionItem.addClass('subscription-loading');
            $subscriptionItem.find('.subscription-actions button').prop('disabled', true);
        } else {
            $subscriptionItem.removeClass('subscription-loading');
            $subscriptionItem.find('.subscription-actions button').prop('disabled', false);
        }
    }

    function showMessage(message, type = 'success') {
        $('.woocommerce-message, .woocommerce-error').remove();

        var messageClass = type === 'error' ? 'woocommerce-error' : 'woocommerce-message';
        var $notice = $('<div class="' + messageClass + '"><p>' + message + '</p></div>');
        $('.bna-my-account-subscriptions').prepend($notice);
        $('html, body').animate({ scrollTop: 0 }, 300);

        setTimeout(function() {
            $notice.fadeOut(300, function() { $(this).remove(); });
        }, 7000);
    }

    function updateSubscriptionStatus($subscriptionItem, newStatus) {
        var $statusBadge = $subscriptionItem.find('.status-badge');

        var statusColors = {
            'new': '#6c757d',
            'active': '#28a745',
            'suspended': '#ffc107',
            'cancelled': '#dc3545',
            'expired': '#6f42c1',
            'failed': '#fd7e14',
            'deleted': '#343a40'
        };

        var statusLabels = {
            'new': 'New',
            'active': 'Active',
            'suspended': 'Paused',
            'cancelled': 'Cancelled',
            'expired': 'Expired',
            'failed': 'Failed',
            'deleted': 'Deleted'
        };

        if (statusColors[newStatus] && statusLabels[newStatus]) {
            $statusBadge.css('background-color', statusColors[newStatus]).text(statusLabels[newStatus]);
            updateAvailableActions($subscriptionItem, newStatus);
        }
    }

    function updateAvailableActions($subscriptionItem, status) {
        var $actions = $subscriptionItem.find('.subscription-actions');
        $actions.find('.bna-subscription-action').hide();

        switch (status) {
            case 'active':
                $actions.find('[data-action="suspend"], [data-action="cancel"]').show();
                break;
            case 'suspended':
                $actions.find('[data-action="resume"], [data-action="cancel"]').show();
                break;
            case 'new':
                $actions.find('[data-action="suspend"], [data-action="cancel"]').show();
                break;
            case 'cancelled':
                $actions.find('[data-action="delete"]').show();
                break;
            case 'failed':
            case 'expired':
                $actions.find('[data-action="reactivate"]').show();
                break;
            case 'deleted':
                break;
        }

        var allowedNotificationStatuses = ['active', 'new', 'suspended', 'failed', 'expired'];
        if (allowedNotificationStatuses.includes(status)) {
            $actions.find('[data-action="resend_notification"]').show();
        }
    }

    function handleSubscriptionAction(action, orderId, subscriptionId) {
        var $subscriptionItem = $('[data-order-id="' + orderId + '"]').closest('.subscription-item');

        if ($subscriptionItem.length === 0) {
            console.error('Subscription item not found for order ID:', orderId);
            showMessage('Error: Subscription not found on page.', 'error');
            return;
        }

        setLoadingState($subscriptionItem, true);

        var data = {
            action: 'bna_' + action + '_subscription',
            nonce: nonce,
            order_id: orderId
        };

        if (subscriptionId && subscriptionId !== orderId) {
            data.subscription_id = subscriptionId;
        }

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                setLoadingState($subscriptionItem, false);

                if (response && response.success) {
                    var successMessage = response.data && response.data.message ?
                        response.data.message :
                        messages['success_' + action] ||
                        'Action completed successfully.';

                    showMessage(successMessage);

                    if ((action === 'delete' || action === 'cancel') && response.data && response.data.new_status === 'deleted') {
                        $subscriptionItem.fadeOut(500, function() {
                            $(this).remove();
                            if ($('.subscription-item').length === 0) {
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            }
                        });
                        return;
                    }

                    if (response.data && response.data.new_status) {
                        updateSubscriptionStatus($subscriptionItem, response.data.new_status);
                    }
                } else {
                    var errorMessage = 'An error occurred.';
                    if (response && response.data) {
                        if (typeof response.data === 'string') {
                            errorMessage = response.data;
                        } else if (response.data.message) {
                            errorMessage = response.data.message;
                        }
                    }
                    showMessage(errorMessage, 'error');
                }
            },
            error: function(xhr, status, error) {
                setLoadingState($subscriptionItem, false);

                console.error('BNA AJAX Error:', {
                    action: data.action,
                    status: xhr.status,
                    error: error
                });

                var errorMessage = messages.error || 'An error occurred. Please try again.';

                try {
                    var errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse && errorResponse.data) {
                        errorMessage = errorResponse.data;
                    }
                } catch (e) {
                    if (xhr.status === 400) {
                        errorMessage = 'Invalid request. Please refresh the page and try again.';
                    } else if (xhr.status === 403) {
                        errorMessage = 'Permission denied. Please refresh the page and try again.';
                    } else if (xhr.status === 404) {
                        errorMessage = 'Action not found. Please contact support.';
                    } else if (xhr.status === 500) {
                        errorMessage = 'Server error. Please try again or contact support.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'Network error. Please check your connection.';
                    }
                }

                showMessage(errorMessage, 'error');
            }
        });
    }

    $(document).on('click', '.bna-subscription-action', function(e) {
        e.preventDefault();

        var $button = $(this);
        var action = $button.data('action');
        var orderId = $button.data('order-id');
        var subscriptionId = $button.data('subscription-id');

        if (!action || !orderId) {
            console.error('BNA: Missing action or order ID');
            showMessage('Error: Missing subscription information.', 'error');
            return;
        }

        handleSubscriptionAction(action, orderId, subscriptionId);
    });

    if (!$('#bna-subscription-loading-styles').length) {
        $('<style id="bna-subscription-loading-styles">' +
            '.subscription-loading { opacity: 0.6; pointer-events: none; position: relative; }' +
            '.subscription-loading::before { content: ""; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255, 255, 255, 0.8); border-radius: 8px; z-index: 10; }' +
            '.subscription-loading::after { content: ""; position: absolute; top: 50%; left: 50%; width: 20px; height: 20px; margin: -10px 0 0 -10px; border: 2px solid #0073aa; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite; z-index: 11; }' +
            '@keyframes spin { to { transform: rotate(360deg); } }' +
            '.woocommerce-message, .woocommerce-error { margin-bottom: 20px; padding: 15px; border-radius: 4px; }' +
            '.woocommerce-message { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }' +
            '.woocommerce-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }' +
            '</style>').appendTo('head');
    }
});
