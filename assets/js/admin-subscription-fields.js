jQuery(document).ready(function($) {
    
    // Toggle main subscription fields visibility
    function toggleSubscriptionFields() {
        var isChecked = $('#_bna_is_subscription').is(':checked');
        if (isChecked) {
            $('.bna_subscription_fields').slideDown();
        } else {
            $('.bna_subscription_fields').slideUp();
        }
    }
    
    // Toggle number of payments field
    function toggleNumPaymentsField() {
        var lengthType = $('#_bna_subscription_length_type').val();
        if (lengthType === 'limited') {
            $('._bna_subscription_num_payments_field').slideDown();
        } else {
            $('._bna_subscription_num_payments_field').slideUp();
        }
    }
    
    // === TRIAL PERIOD TOGGLE - NEW ===
    function toggleTrialFields() {
        var isTrialEnabled = $('#_bna_enable_trial').is(':checked');
        if (isTrialEnabled) {
            $('.bna_trial_fields').slideDown();
        } else {
            $('.bna_trial_fields').slideUp();
        }
    }
    // === END TRIAL PERIOD TOGGLE ===
    
    // Event: Subscription checkbox change
    $(document).on('change', '#_bna_is_subscription', function() {
        toggleSubscriptionFields();
    });
    
    // Event: Length type dropdown change
    $(document).on('change', '#_bna_subscription_length_type', function() {
        toggleNumPaymentsField();
    });
    
    // === EVENT: TRIAL PERIOD CHECKBOX - NEW ===
    $(document).on('change', '#_bna_enable_trial', function() {
        toggleTrialFields();
    });
    // === END EVENT: TRIAL PERIOD CHECKBOX ===
    
    // Validation: Number of payments
    $(document).on('blur', '#_bna_subscription_num_payments', function() {
        var value = $(this).val();
        var numValue = parseInt(value);
        if (value && (numValue < 1 || isNaN(numValue))) {
            $(this).val('12');
            alert('Number of payments must be at least 1');
        }
    });
    
    // === VALIDATION: TRIAL LENGTH - NEW ===
    $(document).on('blur', '#_bna_trial_length', function() {
        var value = $(this).val();
        var numValue = parseInt(value);
        
        if (value && isNaN(numValue)) {
            $(this).val('7');
            alert('Trial length must be a number');
            return;
        }
        
        if (numValue < 1) {
            $(this).val('7');
            alert('Trial length must be at least 1 day');
            return;
        }
        
        if (numValue > 365) {
            $(this).val('365');
            alert('Trial length cannot exceed 365 days');
        }
    });
    // === END VALIDATION: TRIAL LENGTH ===
    
    // Initialize on page load
    toggleSubscriptionFields();
    toggleNumPaymentsField();
    toggleTrialFields(); // NEW
    
    console.log('BNA Subscription Fields JavaScript loaded (with Trial Period support)');
});
