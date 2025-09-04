(function($) {
    'use strict';

    var countriesData = {};
    var statesData = {};
    var savedShippingData = {};
    var isInitialized = false;

    $(document).ready(function() {
        
        if (typeof bna_shipping_data !== 'undefined') {
            countriesData = bna_shipping_data.countries || {};
            statesData = bna_shipping_data.states || {};
            savedShippingData = bna_shipping_data.saved_shipping || {};
            
            console.log('BNA Shipping Data loaded:', {
                countries: Object.keys(countriesData).length,
                states: Object.keys(statesData).length,
                savedData: savedShippingData
            });
        }
        
        function handlePaymentMethodChange() {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            if (selectedMethod === 'bna_smart_payment') {
                $('#bna-shipping-address-section').show();
                if (!isInitialized) {
                    setTimeout(function() {
                        populateCountryOptions();
                        initCustomSelect();
                        loadSavedShippingData();
                        isInitialized = true;
                    }, 100);
                }
            } else {
                $('#bna-shipping-address-section').hide();
            }
        }

        function loadSavedShippingData() {
            console.log('Loading saved shipping data:', savedShippingData);
            
            // Check if we have any meaningful saved shipping data
            var hasData = savedShippingData.country || 
                         savedShippingData.address_1 || 
                         savedShippingData.city || 
                         savedShippingData.postcode;
            
            if (!hasData) {
                console.log('No meaningful saved shipping data found');
                return;
            }

            console.log('Found saved shipping data, loading...');

            // Always show the fields first if we have data
            $('#bna-shipping-fields').show();

            // Set country first
            if (savedShippingData.country && countriesData[savedShippingData.country]) {
                console.log('Setting country:', savedShippingData.country, countriesData[savedShippingData.country]);
                setCustomSelectValue('#bna_country_selector', savedShippingData.country, countriesData[savedShippingData.country]);
                $('#bna_shipping_country').val(savedShippingData.country);
                populateStateOptions(savedShippingData.country);
            }

            // Set text fields
            if (savedShippingData.address_1) {
                $('#bna_shipping_address_1').val(savedShippingData.address_1);
            }
            if (savedShippingData.address_2) {
                $('#bna_shipping_address_2').val(savedShippingData.address_2);
            }
            if (savedShippingData.city) {
                $('#bna_shipping_city').val(savedShippingData.city);
            }
            if (savedShippingData.postcode) {
                $('#bna_shipping_postcode').val(savedShippingData.postcode);
            }

            // Set state after country options are loaded
            if (savedShippingData.state && savedShippingData.country) {
                setTimeout(function() {
                    if (statesData[savedShippingData.country] && statesData[savedShippingData.country][savedShippingData.state]) {
                        console.log('Setting state:', savedShippingData.state, statesData[savedShippingData.country][savedShippingData.state]);
                        setCustomSelectValue('#bna_province_selector', savedShippingData.state, statesData[savedShippingData.country][savedShippingData.state]);
                        $('#bna_shipping_state').val(savedShippingData.state);
                    }
                }, 300);
            }

            // Set checkbox based on whether data is different from billing
            var $checkbox = $('#bna_shipping_same_as_billing');
            
            console.log('is_different_from_billing:', savedShippingData.is_different_from_billing);
            
            // If shipping is different from billing, uncheck and show
            if (savedShippingData.is_different_from_billing === true) {
                $checkbox.prop('checked', false);
                console.log('Shipping is different from billing - unchecking checkbox');
            } else {
                // Even if same as billing, uncheck to show the saved data to user
                $checkbox.prop('checked', false);
                console.log('Shipping same as billing but showing saved data');
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
                // If we have saved data and user unchecks, reload saved data
                if (savedShippingData && (savedShippingData.country || savedShippingData.address_1)) {
                    setTimeout(function() {
                        loadSavedShippingData();
                    }, 100);
                } else {
                    clearShippingFields();
                }
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
            if ($('#bna_shipping_same_as_billing').is(':checked') && savedShippingData.is_different_from_billing !== true) {
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
            if (!isInitialized) {
                setTimeout(function() {
                    handlePaymentMethodChange();
                }, 300);
            }
        });

        // Initialize on page load
        handlePaymentMethodChange();
    });

})(jQuery);
