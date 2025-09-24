/**
 * My Account Subscriptions JavaScript
 * Handles AJAX actions for subscription management
 */

jQuery(document).ready(function($) {
    'use strict';

    // Configuration from localized script
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

        // Scroll to top and remove after delay
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
            'failed': '#fd7e14'
        };

        var statusLabels = {
            'new': 'New',
            'active': 'Active',
            'suspended': 'Paused',
            'cancelled': 'Cancelled',
            'expired': 'Expired',
            'failed': 'Failed'
        };

        if (statusColors[newStatus] && statusLabels[newStatus]) {
            $statusBadge.css('background-color', statusColors[newStatus])
                .text(statusLabels[newStatus]);

            // Update available actions based on new status
            updateAvailableActions($subscriptionItem, newStatus);
        }
    }

    /**
     * Update available action buttons based on status
     */
    function updateAvailableActions($subscriptionItem, status) {
        var $actions = $subscriptionItem.find('.subscription-actions');

        // Hide all action buttons first
        $actions.find('.bna-suspend-subscription, .bna-resume-subscription, .bna-cancel-subscription, .bna-reactivate-subscription').hide();

        // Show appropriate buttons based on status
        switch (status) {
            case 'active':
                $actions.find('.bna-suspend-subscription, .bna-cancel-subscription').show();
                break;
            case 'suspended':
                $actions.find('.bna-resume-subscription, .bna-cancel-subscription').show();
                break;
            case 'new':
                $actions.find('.bna-cancel-subscription').show();
                break;
            case 'failed':
            case 'expired':
                $actions.find('.bna-reactivate-subscription').show();
                break;
            default:
                // For cancelled, no actions available
                break;
        }
    }

    /**
     * Generic AJAX handler for subscription actions
     */
    function handleSubscriptionAction(action, orderId, confirmMessage) {
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

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                setLoadingState($subscriptionItem, false);

                if (response.success) {
                    showMessage(response.data.message || messages['success_' + action] || 'Action completed successfully.');

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

    // Event handlers for subscription action buttons
    $(document).on('click', '.bna-suspend-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_suspend || 'Are you sure you want to pause this subscription?';
        handleSubscriptionAction('suspend', orderId, confirmMessage);
    });

    $(document).on('click', '.bna-resume-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_resume || 'Are you sure you want to resume this subscription?';
        handleSubscriptionAction('resume', orderId, confirmMessage);
    });

    $(document).on('click', '.bna-cancel-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_cancel || 'Are you sure you want to cancel this subscription? This action cannot be undone.';
        handleSubscriptionAction('cancel', orderId, confirmMessage);
    });

    $(document).on('click', '.bna-reactivate-subscription', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        var confirmMessage = messages.confirm_reactivate || 'Are you sure you want to reactivate this subscription?';
        handleSubscriptionAction('reactivate', orderId, confirmMessage);
    });

    // View subscription details handler
    $(document).on('click', '.bna-view-subscription-details', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');

        // Show loading state
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
            '<button id="close-modal" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>' +
            '</div>' +
            '<div style="margin-bottom: 15px;">' +
            '<strong>Status:</strong> ' + (details.status || 'Unknown') + '<br>' +
            '<strong>Total:</strong> ' + (details.total || 'N/A') + '<br>' +
            '<strong>Created:</strong> ' + (details.created_date ? new Date(details.created_date).toLocaleDateString() : 'N/A') +
            '</div>';

        if (details.items && details.items.length > 0) {
            modalHtml += '<div style="margin-bottom: 15px;"><strong>Items:</strong><ul>';
            details.items.forEach(function(item) {
                modalHtml += '<li>' + item.name + ' (Qty: ' + item.quantity + ') - ' + item.total + '</li>';
                if (item.frequency) {
                    modalHtml += '<small>Billing: ' + item.frequency + '</small>';
                }
            });
            modalHtml += '</ul></div>';
        }

        modalHtml += '<div style="text-align: right; margin-top: 20px;">' +
            '<button id="close-modal-btn" class="button">Close</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(modalHtml);

        // Close modal handlers
        $('#close-modal, #close-modal-btn, #subscription-details-modal').on('click', function(e) {
            if (e.target === this) {
                $('#subscription-details-modal').remove();
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
            '</style>').appendTo('head');
    }

    // Initialize
    console.log('BNA Subscriptions JS initialized');
});