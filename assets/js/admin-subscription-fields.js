/**
 * Admin Subscription Fields JavaScript
 * Handles show/hide logic for subscription fields in product edit page
 */
jQuery(document).ready(function($) {
    
    // Show/hide subscription fields based on checkbox
    function toggleSubscriptionFields() {
        var isChecked = $('#_bna_is_subscription').is(':checked');
        
        if (isChecked) {
            $('.bna_subscription_fields').slideDown();
        } else {
            $('.bna_subscription_fields').slideUp();
        }
    }

    // Handle checkbox change
    $(document).on('change', '#_bna_is_subscription', function() {
        toggleSubscriptionFields();
    });

    // Validate numeric fields
    $(document).on('blur', '#_bna_subscription_trial_days, #_bna_subscription_signup_fee', function() {
        var value = $(this).val();
        var field = $(this);
        
        if (value && value < 0) {
            field.val('');
            alert('Value cannot be negative');
        }
        
        // Ensure signup fee has proper decimal format
        if (field.attr('id') === '_bna_subscription_signup_fee' && value) {
            var numValue = parseFloat(value);
            if (!isNaN(numValue)) {
                field.val(numValue.toFixed(2));
            }
        }
    });

    console.log('BNA Subscription Fields JavaScript loaded');
});
