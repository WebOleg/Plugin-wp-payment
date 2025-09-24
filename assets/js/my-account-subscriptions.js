/**
 * My Account Subscriptions JavaScript
 * Handles AJAX actions for subscription management
 */

jQuery(document).ready(function($) {
    'use strict';

    var config = window.bna_subscriptions || {};
    var ajaxUrl = config.ajax_url || '/wp-admin/admin-ajax.php';
    var nonce = config.nonce || '';
    var messages = config.messages || {};

    /**
     * Show loading state for subscription item
     */
    function setLoadingState($subscriptionItem, isLoading) {
        if (isLoading) {
            $subscriptionItem.addClass('subscription-loading');
            $subscriptionItem.find('.subscription-actions button').prop('disabled', true);
        } else {
            $subscriptionItem.removeClass('subscription-loading');
            $subscriptionItem.find('.subscription-actions button').prop('disabled', false);
        }
    }

    /**
     * Show notification message
     */
    function showMessage(message, type = 'success') {
        var messageClass = type === 'error' ? 'woocommerce-error' : 'woocommerce-message';
        var $notice = $('<div class="' + messageClass + '"><p>' + message + '</p></div>');

        $('.bna-my-account-subscriptions').prepend($notice);

        $('html, body').animate({ scrollTop: 0 }, 300);
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    /**
     * Update subscription status in UI
     */
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
            $statusBadge.css('background-color', statusColors[newStatus])
                .text(statusLabels[newStatus]);

            updateAvailableActions($subscriptionItem, newStatus);
        }
    }

    /**
     * Update available action buttons based on status
     */
    function updateAvailableActions($subscriptionItem, status) {
        var $actions = $subscriptionItem.find('.subscription-actions');

        // Hide all action buttons except view details
        $actions.find('.bna-subscription-action').hide();
        $actions.find('.bna-view-subscription-details').show();

        // Show appropriate buttons based on status
        switch (status) {
            case 'active':
                $actions.find('[data-action="suspend"], [data-action="cancel"]').show();
                break;
            case 'suspended':
                $actions.find('[data-action="resume"], [data-action="cancel"]').show();
                break;
            case 'new':
                $actions.find('[data-action="cancel"]').show();
                break;
            case 'cancelled':
                $actions.find('[data-action="delete"]').show();
                break;
            case 'failed':
            case 'expired':
                $actions.find('[data-action="reactivate"]').show();
                break;
        }

        // Show resend notification for all except deleted
        if (status !== 'deleted') {
            $actions.find('[data-action="resend_notification"]').show();
        }
    }

    /**
     * Generic AJAX handler for subscription actions
     */
    function handleSubscriptionAction(action, orderId, subscriptionId, confirmMessage) {
        if (confirmMessage && !confirm(confirmMessage)) {
            return;
        }

        var $subscriptionItem = $('[data-order-id="' + orderId + '"]').closest('.subscription-item');
        setLoadingState($subscriptionItem, true);

        var data = {
            action: 'bna_' + action + '_subscription',
            nonce: nonce,
            order_id: orderId
        };

        // Add subscription_id if provided
        if (subscriptionId) {
            data.subscription_id = subscriptionId;
        }

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                setLoadingState($subscriptionItem, false);

                if (response.success) {
                    showMessage(response.data.message || messages['success_' + action] || 'Action completed successfully.');

                    // Handle delete action - hide the item
                    if (action === 'delete' && response.data.new_status === 'deleted') {
                        $subscriptionItem.fadeOut(300, function() {
                            $(this).remove();
                            // Check if no subscriptions left
                            if ($('.subscription-item').length === 0) {
                                location.reload();
                            }
                        });
                        return;
                    }

                    // Update UI if new status provided
                    if (response.data.new_status) {
                        updateSubscriptionStatus($subscriptionItem, response.data.new_status);
                    }
                } else {
                    showMessage(response.data || messages.error || 'An error occurred.', 'error');
                }
            },
            error: function(xhr, status, error) {
                setLoadingState($subscriptionItem, false);
                console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                showMessage(messages.error || 'An error occurred. Please try again.', 'error');
            }
        });
    }

    // Unified handler for new bna-subscription-action buttons
    $(document).on('click', '.bna-subscription-action', function(e) {
        e.preventDefault();

        var $button = $(this);
        var action = $button.data('action');
        var orderId = $button.data('order-id');
        var subscriptionId = $button.data('subscription-id');
        var confirmMessage = '';

        // Set confirmation message based on action
        switch (action) {
            case 'suspend':
                confirmMessage = messages.confirm_suspend || 'Are you sure you want to pause this subscription?';
                break;
            case 'resume':
                confirmMessage = messages.confirm_resume || 'Are you sure you want to resume this subscription?';
                break;
            case 'cancel':
                confirmMessage = messages.confirm_cancel || 'Are you sure you want to cancel this subscription? This action cannot be undone.';
                break;
            case 'delete':
                confirmMessage = messages.confirm_delete || 'Are you sure you want to permanently delete this subscription? This action cannot be undone.';
                break;
            case 'reactivate':
                confirmMessage = messages.confirm_reactivate || 'Are you sure you want to reactivate this subscription?';
                break;
            case 'resend_notification':
                confirmMessage = messages.confirm_resend_notification || 'Resend notification for this subscription?';
                break;
        }

        if (action) {
            handleSubscriptionAction(action, orderId, subscriptionId, confirmMessage);
        }
    });

    // Backward compatibility handlers
    $(document).on('click', '.bna-suspend-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_suspend || 'Are you sure you want to pause this subscription?';
        handleSubscriptionAction('suspend', orderId, null, confirmMessage);
    });

    $(document).on('click', '.bna-resume-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_resume || 'Are you sure you want to resume this subscription?';
        handleSubscriptionAction('resume', orderId, null, confirmMessage);
    });

    $(document).on('click', '.bna-cancel-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_cancel || 'Are you sure you want to cancel this subscription? This action cannot be undone.';
        handleSubscriptionAction('cancel', orderId, null, confirmMessage);
    });

    $(document).on('click', '.bna-delete-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var subscriptionId = $(this).data('subscription-id');
        var confirmMessage = messages.confirm_delete || 'Are you sure you want to permanently delete this subscription? This action cannot be undone.';
        handleSubscriptionAction('delete', orderId, subscriptionId, confirmMessage);
    });

    $(document).on('click', '.bna-resend-notification', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var subscriptionId = $(this).data('subscription-id');
        var confirmMessage = messages.confirm_resend_notification || 'Resend notification for this subscription?';
        handleSubscriptionAction('resend_notification', orderId, subscriptionId, confirmMessage);
    });

    $(document).on('click', '.bna-reactivate-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_reactivate || 'Are you sure you want to reactivate this subscription?';
        handleSubscriptionAction('reactivate', orderId, null, confirmMessage);
    });

    // View subscription details handler
    $(document).on('click', '.bna-view-subscription-details', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');

        var $button = $(this);
        var originalText = $button.text();
        $button.text(messages.processing || 'Loading...').prop('disabled', true);

        var data = {
            action: 'bna_get_subscription_details',
            nonce: nonce,
            order_id: orderId
        };

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                $button.text(originalText).prop('disabled', false);

                if (response.success) {
                    showSubscriptionDetailsModal(response.data);
                } else {
                    showMessage(response.data || 'Could not load subscription details.', 'error');
                }
            },
            error: function() {
                $button.text(originalText).prop('disabled', false);
                showMessage('Error loading subscription details.', 'error');
            }
        });
    });

    /**
     * Show subscription details in a modal
     */
    function showSubscriptionDetailsModal(details) {
        var modalHtml = '<div id="subscription-details-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">' +
            '<div style="background: white; padding: 30px; border-radius: 8px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">' +
            '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #0073aa; padding-bottom: 15px;">' +
            '<h3 style="margin: 0; color: #0073aa;">Subscription Details #' + details.order_id + '</h3>' +
            '<button id="close-modal" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #666;">&times;</button>' +
            '</div>' +
            '<div style="margin-bottom: 15px;">' +
            '<strong>Subscription ID:</strong> ' + (details.subscription_id || 'N/A') + '<br>' +
            '<strong>Status:</strong> ' + (details.status || 'Unknown') + '<br>' +
            '<strong>Total:</strong> ' + (details.total || 'N/A') + '<br>' +
            '<strong>Created:</strong> ' + (details.created_date ? new Date(details.created_date).toLocaleDateString() : 'N/A') +
            '</div>';

        if (details.items && details.items.length > 0) {
            modalHtml += '<div style="margin-bottom: 15px;"><strong>Items:</strong><ul style="margin-left: 20px;">';
            details.items.forEach(function(item) {
                modalHtml += '<li style="margin-bottom: 5px;">' + item.name + ' (Qty: ' + item.quantity + ') - ' + item.total;
                if (item.frequency) {
                    modalHtml += '<br><small style="color: #666;">Billing: ' + item.frequency + '</small>';
                }
                if (item.trial_days && item.trial_days > 0) {
                    modalHtml += '<br><small style="color: #666;">Free trial: ' + item.trial_days + ' days</small>';
                }
                modalHtml += '</li>';
            });
            modalHtml += '</ul></div>';
        }

        if (details.bna_details) {
            modalHtml += '<div style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px;">' +
                '<strong>BNA API Details:</strong><br>' +
                '<small style="color: #666;">Last synced: ' + new Date().toLocaleString() + '</small>' +
                '</div>';
        }

        modalHtml += '<div style="text-align: right; margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">' +
            '<button id="close-modal-btn" class="button button-primary">Close</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(modalHtml);

        // Close modal handlers
        $('#close-modal, #close-modal-btn').on('click', function(e) {
            e.preventDefault();
            $('#subscription-details-modal').remove();
        });

        // Close on backdrop click
        $('#subscription-details-modal').on('click', function(e) {
            if (e.target === this) {
                $('#subscription-details-modal').remove();
            }
        });

        // Close on escape key
        $(document).on('keydown.modal', function(e) {
            if (e.keyCode === 27) { // Escape key
                $('#subscription-details-modal').remove();
                $(document).off('keydown.modal');
            }
        });
    }

    // Add loading styles if not present
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

    // Initialize
    console.log('BNA Subscriptions JS initialized with new actions support');
});