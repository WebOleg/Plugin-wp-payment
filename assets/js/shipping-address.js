(function($) {
    'use strict';

    var countriesData = {};
    var statesData = {};

    $(document).ready(function() {
        
        if (typeof bna_shipping_data !== 'undefined') {
            countriesData = bna_shipping_data.countries || {};
            statesData = bna_shipping_data.states || {};
        }
        
        function handlePaymentMethodChange() {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            if (selectedMethod === 'bna_smart_payment') {
                $('#bna-shipping-address-section').show();
                setTimeout(function() {
                    populateCountryOptions();
                    initCustomSelect();
                }, 100);
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

        function populateCountryOptions() {
            var $countryOptions = $('#bna_country_selector .bna-select-options');
            $countryOptions.empty();
            $countryOptions.append('<li data-value="">' + getI18nText('select_country') + '</li>');
            
            Object.keys(countriesData).forEach(function(code) {
                $countryOptions.append('<li data-value="' + code + '">' + countriesData[code] + '</li>');
            });
        }

        function populateStateOptions(countryCode) {
            var $stateOptions = $('#bna_province_selector .bna-select-options');
            $stateOptions.empty();
            
            if (!countryCode || !statesData[countryCode] || Object.keys(statesData[countryCode]).length === 0) {
                $stateOptions.append('<li data-value="">' + getI18nText('no_states') + '</li>');
                setCustomSelectValue('#bna_province_selector', '', getI18nText('no_states'));
                $('#bna_shipping_state').val('');
                return;
            }
            
            $stateOptions.append('<li data-value="">' + getI18nText('select_state') + '</li>');
            
            Object.keys(statesData[countryCode]).forEach(function(stateCode) {
                $stateOptions.append('<li data-value="' + stateCode + '">' + statesData[countryCode][stateCode] + '</li>');
            });
            
            setCustomSelectValue('#bna_province_selector', '', getI18nText('select_state'));
            $('#bna_shipping_state').val('');
        }

        function getI18nText(key) {
            if (typeof bna_shipping_data !== 'undefined' && bna_shipping_data.i18n && bna_shipping_data.i18n[key]) {
                return bna_shipping_data.i18n[key];
            }
            
            var defaults = {
                'select_country': 'Select Country...',
                'select_state': 'Select State/Province...',
                'no_states': 'No states available'
            };
            
            return defaults[key] || '';
        }

        function copyBillingToShipping() {
            var billingCountry = $('#billing_country').val();
            var billingState = $('#billing_state').val();
            
            if (billingCountry && countriesData[billingCountry]) {
                setCustomSelectValue('#bna_country_selector', billingCountry, countriesData[billingCountry]);
                $('#bna_shipping_country').val(billingCountry);
                
                populateStateOptions(billingCountry);
                
                setTimeout(function() {
                    if (billingState && statesData[billingCountry] && statesData[billingCountry][billingState]) {
                        setCustomSelectValue('#bna_province_selector', billingState, statesData[billingCountry][billingState]);
                        $('#bna_shipping_state').val(billingState);
                    }
                }, 100);
            }
            
            $('#bna_shipping_address_1').val($('#billing_address_1').val());
            $('#bna_shipping_address_2').val($('#billing_address_2').val());
            $('#bna_shipping_city').val($('#billing_city').val());
            $('#bna_shipping_postcode').val($('#billing_postcode').val());
        }

        function clearShippingFields() {
            setCustomSelectValue('#bna_country_selector', '', getI18nText('select_country'));
            setCustomSelectValue('#bna_province_selector', '', getI18nText('select_state'));
            $('#bna_shipping_country').val('');
            $('#bna_shipping_state').val('');
            $('#bna_shipping_address_1').val('');
            $('#bna_shipping_address_2').val('');
            $('#bna_shipping_city').val('');
            $('#bna_shipping_postcode').val('');
            
            var $stateOptions = $('#bna_province_selector .bna-select-options');
            $stateOptions.empty();
            $stateOptions.append('<li data-value="">' + getI18nText('select_state') + '</li>');
        }

        function initCustomSelect() {
            $(document).off('click', '.bna-select-selected').on('click', '.bna-select-selected', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var $selected = $(this);
                var $options = $selected.siblings('.bna-select-options');
                
                $('.bna-select-selected').not($selected).removeClass('open');
                $('.bna-select-options').not($options).hide();
                
                $selected.toggleClass('open');
                $options.toggle();
            });

            $(document).off('click', '.bna-select-options li').on('click', '.bna-select-options li', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var $option = $(this);
                var value = $option.data('value');
                var text = $option.text();
                var $selector = $option.closest('.bna-custom-select');
                
                setCustomSelectValue('#' + $selector.attr('id'), value, text);
                
                if ($selector.attr('id') === 'bna_country_selector') {
                    $('#bna_shipping_country').val(value);
                    if (value) {
                        populateStateOptions(value);
                    } else {
                        populateStateOptions('');
                    }
                } else if ($selector.attr('id') === 'bna_province_selector') {
                    $('#bna_shipping_state').val(value);
                }
                
                $('.bna-select-selected').removeClass('open');
                $('.bna-select-options').hide();
            });

            $(document).off('click.bna-select').on('click.bna-select', function(e) {
                if (!$(e.target).closest('.bna-custom-select').length) {
                    $('.bna-select-selected').removeClass('open');
                    $('.bna-select-options').hide();
                }
            });
        }

        function setCustomSelectValue(selectorId, value, text) {
            var $selector = $(selectorId);
            var $selected = $selector.find('.bna-select-selected');
            
            $selected.attr('data-value', value);
            $selected.find('.bna-select-text').text(text);
        }

        $(document).on('change', 'input[name="payment_method"]', handlePaymentMethodChange);
        
        $(document).on('change', '#bna_shipping_same_as_billing', function() {
            var isChecked = $(this).is(':checked');
            
            toggleShippingFields(isChecked);
            
            if (isChecked) {
                copyBillingToShipping();
            } else {
                clearShippingFields();
            }
        });

        $(document).on('change', '#billing_country', function() {
            if ($('#bna_shipping_same_as_billing').is(':checked')) {
                setTimeout(function() {
                    copyBillingToShipping();
                }, 300);
            }
        });

        $(document).on('change', '#billing_state', function() {
            if ($('#bna_shipping_same_as_billing').is(':checked')) {
                setTimeout(function() {
                    var billingCountry = $('#billing_country').val();
                    var billingState = $('#billing_state').val();
                    
                    if (billingState && statesData[billingCountry] && statesData[billingCountry][billingState]) {
                        setCustomSelectValue('#bna_province_selector', billingState, statesData[billingCountry][billingState]);
                        $('#bna_shipping_state').val(billingState);
                    }
                }, 100);
            }
        });

        $(document.body).on('updated_checkout', function() {
            setTimeout(function() {
                handlePaymentMethodChange();
                var isChecked = $('#bna_shipping_same_as_billing').is(':checked');
                toggleShippingFields(isChecked);
                
                if (isChecked) {
                    copyBillingToShipping();
                }
            }, 200);
        });

        handlePaymentMethodChange();
        
        var initialChecked = $('#bna_shipping_same_as_billing').is(':checked');
        toggleShippingFields(initialChecked);
    });

})(jQuery);
