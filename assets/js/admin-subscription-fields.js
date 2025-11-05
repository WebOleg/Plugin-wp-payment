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

    function toggleTrialFields() {
        var isTrialEnabled = $('#_bna_enable_trial').is(':checked');
        if (isTrialEnabled) {
            $('.bna_trial_fields').slideDown();
        } else {
            $('.bna_trial_fields').slideUp();
        }
    }

    function validateTrialLength() {
        var $input = $('#_bna_trial_length');
        var value = parseInt($input.val()) || 0;

        $('.trial-length-warning').remove();

        if (!$('#_bna_enable_trial').is(':checked')) {
            return;
        }

        if (typeof bnaTrialValidation === 'undefined') {
            return;
        }

        var minDays = bnaTrialValidation.minDays;
        var maxDays = bnaTrialValidation.maxDays;

        if (value < minDays && value !== 0) {
            $input.after(
                '<span class="trial-length-warning" style="color: #dc3232; margin-left: 10px; font-weight: 500;">' +
                '⚠️ ' + bnaTrialValidation.messages.tooLow +
                '</span>'
            );
        } else if (value > maxDays) {
            $input.after(
                '<span class="trial-length-warning" style="color: #dc3232; margin-left: 10px; font-weight: 500;">' +
                '⚠️ ' + bnaTrialValidation.messages.tooHigh +
                '</span>'
            );
        }
    }

    $(document).on('change', '#_bna_is_subscription', function() {
        toggleSubscriptionFields();
    });

    $(document).on('change', '#_bna_subscription_length_type', function() {
        toggleNumPaymentsField();
    });

    $(document).on('change', '#_bna_enable_trial', function() {
        toggleTrialFields();
        if ($(this).is(':checked')) {
            validateTrialLength();
        } else {
            $('.trial-length-warning').remove();
        }
    });

    $(document).on('input change', '#_bna_trial_length', function() {
        validateTrialLength();
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
    toggleTrialFields();

    if ($('#_bna_enable_trial').is(':checked')) {
        validateTrialLength();
    }

    console.log('BNA Subscription Fields initialized');
});