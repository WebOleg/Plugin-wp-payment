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
        var lengthType = $('#_bna_subscription_length_type').val();
        if (lengthType === 'limited') {
            $('._bna_subscription_num_payments_field').slideDown();
        } else {
            $('._bna_subscription_num_payments_field').slideUp();
        }
    }

    $(document).on('change', '#_bna_is_subscription', function() {
        toggleSubscriptionFields();
    });

    $(document).on('change', '#_bna_subscription_length_type', function() {
        toggleNumPaymentsField();
    });

    $(document).on('blur', '#_bna_subscription_num_payments', function() {
        var value = $(this).val();
        var numValue = parseInt(value);

        if (value && (numValue < 1 || isNaN(numValue))) {
            $(this).val('12');
            alert('Number of payments must be at least 1');
        }
    });

    toggleSubscriptionFields();
    toggleNumPaymentsField();

    console.log('BNA Subscription Fields JavaScript loaded');
});