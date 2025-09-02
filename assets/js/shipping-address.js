(function($) {
    'use strict';

    $(document).ready(function() {
        
        function handlePaymentMethodChange() {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            if (selectedMethod === 'bna_smart_payment') {
                $('#bna-shipping-address-section').show();
            } else {
                $('#bna-shipping-address-section').hide();
            }
        }

        function toggleShippingFields(hide) {
            if (hide) {
                $('#bna-shipping-fields').hide();
            } else {
                $('#bna-shipping-fields').show();
            }
        }

        function copyBillingToShipping() {
            $('#bna_shipping_country').val('Canada');
            $('#bna_shipping_address_1').val($('#billing_address_1').val());
            $('#bna_shipping_address_2').val($('#billing_address_2').val());
            $('#bna_shipping_city').val($('#billing_city').val());
            $('#bna_shipping_postcode').val($('#billing_postcode').val());
            
            var billingStateText = $('#billing_state option:selected').text();
            if (billingStateText && billingStateText !== 'Select an option…') {
                $('#bna_shipping_province').val(billingStateText);
            }
        }

        $(document).on('change', 'input[name="payment_method"]', handlePaymentMethodChange);
        
        $(document).on('change', '#bna_shipping_same_as_billing', function() {
            var isChecked = $(this).is(':checked');
            toggleShippingFields(!isChecked);
            if (isChecked) {
                copyBillingToShipping();
            }
        });

        $(document.body).on('updated_checkout', function() {
            handlePaymentMethodChange();
        });

        handlePaymentMethodChange();
    });

})(jQuery);
