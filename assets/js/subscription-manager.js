jQuery(document).ready(function($) {
    // Handle subscription action buttons in My Account
    $('.bna-suspend-subscription, .bna-resume-subscription, .bna-cancel-subscription').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var subscriptionId = $button.data('subscription-id');
        var action = $button.data('action');
        var confirmRequired = $button.data('confirm');
        
        if (!subscriptionId || !action) {
            alert(bna_subscription_ajax.messages.error);
            return;
        }
        
        var confirmMessage = '';
        switch(action) {
            case 'suspend':
                confirmMessage = bna_subscription_ajax.messages.confirm_suspend;
                break;
            case 'resume':
                confirmMessage = bna_subscription_ajax.messages.confirm_resume;
                break;
            case 'cancel':
                confirmMessage = bna_subscription_ajax.messages.confirm_cancel;
                break;
        }
        
        if (confirmRequired && confirmMessage && !confirm(confirmMessage)) {
            return;
        }
        
        $button.prop('disabled', true).text(bna_subscription_ajax.messages.processing);
        
        $.ajax({
            url: bna_subscription_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bna_subscription_action',
                nonce: bna_subscription_ajax.nonce,
                subscription_id: subscriptionId,
                action_type: action
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    location.reload();
                } else {
                    alert(response.data || bna_subscription_ajax.messages.error);
                    $button.prop('disabled', false).text(getOriginalButtonText(action));
                }
            },
            error: function() {
                alert(bna_subscription_ajax.messages.error);
                $button.prop('disabled', false).text(getOriginalButtonText(action));
            }
        });
    });
    
    function getOriginalButtonText(action) {
        switch(action) {
            case 'suspend':
                return 'Pause';
            case 'resume':
                return 'Resume';
            case 'cancel':
                return 'Cancel';
            default:
                return 'Action';
        }
    }
    
    // Admin product type handling
    $('#product-type').on('change', function() {
        var productType = $(this).val();
        if (productType === 'bna_subscription') {
            $('.show_if_bna_subscription').show();
            $('.hide_if_bna_subscription').hide();
            $('#_virtual').prop('checked', true);
            $('#_downloadable').prop('checked', false);
            $('._regular_price_field').show();
            $('._sale_price_field').show();
        } else {
            $('.show_if_bna_subscription').hide();
            $('.hide_if_bna_subscription').show();
        }
    });
    
    if ($('#product-type').val() === 'bna_subscription') {
        $('.show_if_bna_subscription').show();
        $('.hide_if_bna_subscription').hide();
        $('._regular_price_field').show();
        $('._sale_price_field').show();
    }

    // Handle subscription product add to cart
    $('.single_add_to_cart_button').on('click', function(e) {
        var productType = $('input[name="product-type"]').val();
        if (productType === 'bna_subscription') {
            // Allow normal WooCommerce add to cart process
            // The subscription will be created after successful payment
            return true;
        }
    });
});
