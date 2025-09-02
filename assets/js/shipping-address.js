/**
 * BNA Smart Payment - Shipping Address Handler
 * Handles shipping address form functionality
 */

(function($) {
    'use strict';

    var BNA_ShippingAddress = {
        
        // Canadian provinces/states
        canadianStates: {
            'AB': 'Alberta',
            'BC': 'British Columbia', 
            'MB': 'Manitoba',
            'NB': 'New Brunswick',
            'NL': 'Newfoundland and Labrador',
            'NS': 'Nova Scotia',
            'NT': 'Northwest Territories',
            'NU': 'Nunavut',
            'ON': 'Ontario',
            'PE': 'Prince Edward Island',
            'QC': 'Quebec',
            'SK': 'Saskatchewan',
            'YT': 'Yukon'
        },

        // US states (basic list)
        usStates: {
            'AL': 'Alabama', 'AK': 'Alaska', 'AZ': 'Arizona', 'AR': 'Arkansas',
            'CA': 'California', 'CO': 'Colorado', 'CT': 'Connecticut', 'DE': 'Delaware',
            'FL': 'Florida', 'GA': 'Georgia', 'HI': 'Hawaii', 'ID': 'Idaho',
            'IL': 'Illinois', 'IN': 'Indiana', 'IA': 'Iowa', 'KS': 'Kansas',
            'KY': 'Kentucky', 'LA': 'Louisiana', 'ME': 'Maine', 'MD': 'Maryland',
            'MA': 'Massachusetts', 'MI': 'Michigan', 'MN': 'Minnesota', 'MS': 'Mississippi',
            'MO': 'Missouri', 'MT': 'Montana', 'NE': 'Nebraska', 'NV': 'Nevada',
            'NH': 'New Hampshire', 'NJ': 'New Jersey', 'NM': 'New Mexico', 'NY': 'New York',
            'NC': 'North Carolina', 'ND': 'North Dakota', 'OH': 'Ohio', 'OK': 'Oklahoma',
            'OR': 'Oregon', 'PA': 'Pennsylvania', 'RI': 'Rhode Island', 'SC': 'South Carolina',
            'SD': 'South Dakota', 'TN': 'Tennessee', 'TX': 'Texas', 'UT': 'Utah',
            'VT': 'Vermont', 'VA': 'Virginia', 'WA': 'Washington', 'WV': 'West Virginia',
            'WI': 'Wisconsin', 'WY': 'Wyoming'
        },

        init: function() {
            console.log('BNA Shipping Address init');
            this.bindEvents();
            this.handlePaymentMethodChange();
            this.initializeSelects();
        },

        bindEvents: function() {
            var self = this;

            // Same as billing checkbox
            $(document).on('change', '#bna_shipping_same_as_billing', function() {
                self.toggleShippingFields($(this).is(':checked'));
                if ($(this).is(':checked')) {
                    self.copyBillingToShipping();
                }
            });

            // Country change
            $(document).on('change', '#bna_shipping_country', function() {
                self.updateStates($(this).val());
            });

            // Payment method change
            $(document).on('change', 'input[name="payment_method"]', function() {
                self.handlePaymentMethodChange();
            });

            // Auto-copy billing when billing fields change (if checkbox is checked)
            $(document).on('change blur', 'input[id^="billing_"], select[id^="billing_"]', function() {
                if ($('#bna_shipping_same_as_billing').is(':checked')) {
                    setTimeout(function() {
                        self.copyBillingToShipping();
                    }, 100);
                }
            });

            // Handle body updates (WooCommerce AJAX)
            $(document.body).on('updated_checkout', function() {
                self.handlePaymentMethodChange();
                self.initializeSelects();
            });
        },

        initializeSelects: function() {
            // Initialize select elements with proper styling
            var countrySelect = $('#bna_shipping_country');
            var stateSelect = $('#bna_shipping_state');

            // Ensure selects are styled properly
            countrySelect.css({
                'appearance': 'none',
                'background-image': 'url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 4 5\'%3E%3Cpath fill=\'%23666\' d=\'m2 0-2 2h4zm0 5 2-2h-4z\'/%3E%3C/svg%3E")',
                'background-repeat': 'no-repeat',
                'background-position': 'right 12px center',
                'background-size': '12px',
                'padding-right': '40px'
            });

            stateSelect.css({
                'appearance': 'none',
                'background-image': 'url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 4 5\'%3E%3Cpath fill=\'%23666\' d=\'m2 0-2 2h4zm0 5 2-2h-4z\'/%3E%3C/svg%3E")',
                'background-repeat': 'no-repeat',
                'background-position': 'right 12px center', 
                'background-size': '12px',
                'padding-right': '40px'
            });

            // Set default country if not set
            if (!countrySelect.val()) {
                countrySelect.val('CA').trigger('change');
            }
        },

        handlePaymentMethodChange: function() {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            var shippingSection = $('#bna-shipping-address-section');
            
            if (selectedMethod === bna_shipping.gateway_id) {
                shippingSection.slideDown(300, function() {
                    // Re-initialize selects after showing
                    BNA_ShippingAddress.initializeSelects();
                });
            } else {
                shippingSection.slideUp(300);
            }
        },

        toggleShippingFields: function(hide) {
            var fieldsContainer = $('#bna-shipping-fields');
            
            if (hide) {
                fieldsContainer.slideUp(300);
                this.clearValidationErrors();
            } else {
                fieldsContainer.slideDown(300, function() {
                    // Re-initialize selects after showing
                    BNA_ShippingAddress.initializeSelects();
                });
            }
        },

        copyBillingToShipping: function() {
            var mappings = {
                'billing_country': 'bna_shipping_country',
                'billing_address_1': 'bna_shipping_address_1',
                'billing_address_2': 'bna_shipping_address_2',
                'billing_city': 'bna_shipping_city',
                'billing_state': 'bna_shipping_state',
                'billing_postcode': 'bna_shipping_postcode'
            };

            $.each(mappings, function(billingId, shippingId) {
                var billingField = $('#' + billingId);
                var shippingField = $('#' + shippingId);
                
                if (billingField.length && shippingField.length) {
                    var billingValue = billingField.val();
                    
                    if (billingValue) {
                        shippingField.val(billingValue);
                        
                        // Trigger change for country to update states
                        if (shippingId === 'bna_shipping_country') {
                            shippingField.trigger('change');
                        }
                    }
                }
            });

            // Copy state after country is updated
            setTimeout(function() {
                var billingState = $('#billing_state').val();
                if (billingState) {
                    $('#bna_shipping_state').val(billingState);
                }
            }, 100);
        },

        updateStates: function(countryCode) {
            var stateSelect = $('#bna_shipping_state');
            var currentValue = stateSelect.val();
            var states = {};

            // Clear existing options
            stateSelect.empty();
            stateSelect.append('<option value="">' + 
                (bna_shipping.i18n.select_option || 'Select an option…') + '</option>');

            // Get states for selected country
            if (countryCode === 'CA') {
                states = this.canadianStates;
            } else if (countryCode === 'US') {
                states = this.usStates;
            }

            // Add state options
            $.each(states, function(code, name) {
                var selected = (code === currentValue) ? 'selected' : '';
                stateSelect.append('<option value="' + code + '" ' + selected + '>' + name + '</option>');
            });

            // Re-apply styling
            this.initializeSelects();

            // Set default province for Canada
            if (countryCode === 'CA' && !currentValue) {
                stateSelect.val('ON');
            }
        },

        clearValidationErrors: function() {
            // Remove validation errors from shipping fields when hidden
            $('#bna-shipping-fields input, #bna-shipping-fields select').each(function() {
                $(this).removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                $(this).siblings('.woocommerce-error').remove();
            });
        },

        validateShippingFields: function() {
            var isValid = true;
            var sameAsBilling = $('#bna_shipping_same_as_billing').is(':checked');
            
            // Only validate if shipping fields are visible and not same as billing
            if (sameAsBilling || $('#bna-shipping-fields').is(':hidden')) {
                return true;
            }

            var requiredFields = [
                'bna_shipping_country',
                'bna_shipping_address_1', 
                'bna_shipping_city',
                'bna_shipping_state',
                'bna_shipping_postcode'
            ];

            $.each(requiredFields, function(index, fieldId) {
                var field = $('#' + fieldId);
                var value = field.val();
                
                if (!value || value.trim() === '') {
                    field.addClass('woocommerce-invalid woocommerce-invalid-required-field');
                    isValid = false;
                } else {
                    field.removeClass('woocommerce-invalid woocommerce-invalid-required-field');
                }
            });

            if (!isValid) {
                console.log('Shipping validation failed');
            }

            return isValid;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        BNA_ShippingAddress.init();
    });

    // Also initialize after WooCommerce updates
    $(document.body).on('updated_checkout', function() {
        setTimeout(function() {
            BNA_ShippingAddress.init();
        }, 100);
    });

    // Make available globally
    window.BNA_ShippingAddress = BNA_ShippingAddress;

})(jQuery);
