/**
 * Admin Subscription Fields JavaScript
 * Handles show/hide logic for subscription fields in product edit page
 */
jQuery(document).ready(function($) {
    
    function toggleSubscriptionFields() {
        var isChecked = $('#_bna_is_subscription').is(':checked');
        if (isChecked) {
            $('.bna_subscription_fields').slideDown();
        } else {
            $('.bna_subscription_fields').slideUp();
        }
    }

    function toggleNumPaymentsField() {
        var lengthType = $('input[name="_bna_subscription_length_type"]:checked').val();
        var numPaymentsField = $('.bna_num_payments_field');
        
        if (lengthType === 'limited') {
            numPaymentsField.slideDown();
            $('#_bna_subscription_num_payments').prop('disabled', false);
        } else {
            numPaymentsField.slideUp();
            $('#_bna_subscription_num_payments').prop('disabled', true);
        }
    }

    $(document).on('change', '#_bna_is_subscription', function() {
        toggleSubscriptionFields();
    });

    $(document).on('change', 'input[name="_bna_subscription_length_type"]', function() {
        toggleNumPaymentsField();
    });

    $(document).on('blur', '#_bna_subscription_trial_days, #_bna_subscription_signup_fee, #_bna_subscription_num_payments', function() {
        var value = $(this).val();
        var field = $(this);
        
        if (value && value < 0) {
            field.val('');
            alert('Value cannot be negative');
        }

        if (field.attr('id') === '_bna_subscription_signup_fee' && value) {
            var numValue = parseFloat(value);
            if (!isNaN(numValue)) {
                field.val(numValue.toFixed(2));
            }
        }

        if (field.attr('id') === '_bna_subscription_num_payments' && value) {
            var intValue = parseInt(value);
            if (!isNaN(intValue) && intValue > 0) {
                field.val(intValue);
            } else {
                field.val('12');
            }
        }
    });

    toggleSubscriptionFields();
    toggleNumPaymentsField();

    console.log('BNA Subscription Fields JavaScript loaded');
});
