/**
 * BNA Smart Payment - Cart Validation
 * Frontend validation for subscription cart rules
 * 
 * @since 1.9.0
 * @package BNA_Smart_Payment
 */

(function($) {
    'use strict';

    // BNA Cart Validation Object
    var BNACartValidation = {
        
        // Configuration
        config: {
            debug: false,
            ajaxUrl: bna_cart_validation.ajax_url || '/wp-admin/admin-ajax.php',
            nonce: bna_cart_validation.nonce || '',
            subscriptionsEnabled: bna_cart_validation.subscriptions_enabled || false,
            messages: {
                mixed_cart: bna_cart_validation.messages?.mixed_cart || 'You cannot mix subscription products with regular products.',
                multiple_subscriptions: bna_cart_validation.messages?.multiple_subscriptions || 'You can only have one subscription product in your cart.',
                subscription_quantity: bna_cart_validation.messages?.subscription_quantity || 'Subscription products can only have a quantity of 1.',
                subscriptions_disabled: bna_cart_validation.messages?.subscriptions_disabled || 'Subscriptions are currently disabled.'
            }
        },

        // Initialize validation
        init: function() {
            if (!this.config.subscriptionsEnabled) {
                this.log('Subscriptions disabled, skipping validation');
                return;
            }

            this.log('Initializing BNA Cart Validation');
            this.bindEvents();
            this.validateCurrentCart();
        },

        // Bind events
        bindEvents: function() {
            var self = this;

            // Cart page events
            $(document.body).on('updated_cart_totals', function() {
                self.validateCurrentCart();
            });

            // Quantity change events
            $(document).on('change', 'input.qty', function() {
                self.handleQuantityChange($(this));
            });

            // Add to cart form submission
            $('form.cart').on('submit', function(e) {
                if (!self.validateAddToCart($(this))) {
                    e.preventDefault();
                    return false;
                }
            });

            // Single product add to cart (AJAX)
            $(document).on('click', '.add_to_cart_button', function(e) {
                var $button = $(this);
                var productId = $button.data('product_id');
                
                if (!self.validateSingleProductAdd(productId, $button)) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            });

            // Remove item events
            $(document).on('click', '.remove', function() {
                setTimeout(function() {
                    self.validateCurrentCart();
                }, 500);
            });

            // Checkout validation
            $('form.checkout').on('submit', function(e) {
                if (!self.validateCheckout()) {
                    e.preventDefault();
                    return false;
                }
            });

            this.log('Event listeners bound');
        },

        // Validate current cart state
        validateCurrentCart: function() {
            var self = this;
            var cartData = this.analyzeCart();
            
            this.log('Cart analysis:', cartData);

            // Clear previous notices
            this.clearNotices();

            // Check for mixed cart
            if (cartData.hasSubscriptions && cartData.hasRegular) {
                this.showError(this.config.messages.mixed_cart);
                this.disableCheckout();
                return false;
            }

            // Check for multiple subscriptions
            if (cartData.subscriptionCount > 1) {
                this.showError(this.config.messages.multiple_subscriptions);
                this.disableCheckout();
                return false;
            }

            // Check subscription quantities
            if (cartData.hasInvalidQuantities) {
                this.showError(this.config.messages.subscription_quantity);
                this.fixSubscriptionQuantities();
            }

            // Enable checkout if all validations pass
            if (!cartData.hasSubscriptions || (cartData.subscriptionCount === 1 && !cartData.hasRegular)) {
                this.enableCheckout();
                return true;
            }

            return false;
        },

        // Analyze current cart
        analyzeCart: function() {
            var cartData = {
                hasSubscriptions: false,
                hasRegular: false,
                subscriptionCount: 0,
                regularCount: 0,
                hasInvalidQuantities: false,
                items: []
            };

            // Analyze cart table rows
            $('.woocommerce-cart-form .cart_item').each(function() {
                var $row = $(this);
                var isSubscription = $row.find('[data-subscription="true"]').length > 0 || 
                                   $row.find('.subscription-frequency').length > 0 ||
                                   $row.hasClass('subscription-item');
                
                var quantity = parseInt($row.find('input.qty').val()) || 1;
                
                if (isSubscription) {
                    cartData.hasSubscriptions = true;
                    cartData.subscriptionCount++;
                    
                    if (quantity > 1) {
                        cartData.hasInvalidQuantities = true;
                    }
                } else {
                    cartData.hasRegular = true;
                    cartData.regularCount++;
                }

                cartData.items.push({
                    isSubscription: isSubscription,
                    quantity: quantity,
                    element: $row
                });
            });

            return cartData;
        },

        // Handle quantity changes
        handleQuantityChange: function($input) {
            var $row = $input.closest('.cart_item');
            var isSubscription = $row.find('[data-subscription="true"]').length > 0 || 
                               $row.find('.subscription-frequency').length > 0 ||
                               $row.hasClass('subscription-item');
            
            if (isSubscription) {
                var quantity = parseInt($input.val());
                if (quantity > 1) {
                    $input.val(1);
                    this.showWarning(this.config.messages.subscription_quantity);
                    
                    // Trigger cart update
                    $('[name="update_cart"]').removeAttr('disabled').trigger('click');
                }
            }

            // Validate after quantity change
            setTimeout(() => {
                this.validateCurrentCart();
            }, 100);
        },

        // Validate add to cart form
        validateAddToCart: function($form) {
            var productId = $form.find('[name="add-to-cart"]').val() || 
                          $form.find('[name="product_id"]').val();
            
            if (!productId) {
                return true; // Let WooCommerce handle
            }

            return this.validateSingleProductAdd(productId, $form);
        },

        // Validate single product addition
        validateSingleProductAdd: function(productId, $element) {
            if (!productId) {
                return true;
            }

            // Check if product is subscription
            var isSubscription = $element.hasClass('subscription-product') || 
                               $element.data('subscription') === true ||
                               $element.closest('.product').find('.subscription-info').length > 0;

            if (!isSubscription) {
                // Regular product - check if cart has subscriptions
                var cartData = this.analyzeCart();
                if (cartData.hasSubscriptions) {
                    this.showError(this.config.messages.mixed_cart);
                    return false;
                }
                return true;
            }

            // Subscription product validation
            var cartData = this.analyzeCart();
            
            // Check for existing subscriptions
            if (cartData.subscriptionCount > 0) {
                this.showError(this.config.messages.multiple_subscriptions);
                return false;
            }

            // Check for regular products
            if (cartData.hasRegular) {
                this.showError(this.config.messages.mixed_cart);
                return false;
            }

            return true;
        },

        // Validate checkout
        validateCheckout: function() {
            var cartData = this.analyzeCart();
            
            // Mixed cart check
            if (cartData.hasSubscriptions && cartData.hasRegular) {
                this.showError(this.config.messages.mixed_cart);
                return false;
            }

            // Multiple subscriptions check
            if (cartData.subscriptionCount > 1) {
                this.showError(this.config.messages.multiple_subscriptions);
                return false;
            }

            return true;
        },

        // Fix subscription quantities automatically
        fixSubscriptionQuantities: function() {
            $('.woocommerce-cart-form .cart_item').each(function() {
                var $row = $(this);
                var isSubscription = $row.find('[data-subscription="true"]').length > 0 || 
                                   $row.find('.subscription-frequency').length > 0 ||
                                   $row.hasClass('subscription-item');
                
                if (isSubscription) {
                    var $qtyInput = $row.find('input.qty');
                    if (parseInt($qtyInput.val()) > 1) {
                        $qtyInput.val(1);
                    }
                }
            });

            // Update cart
            $('[name="update_cart"]').removeAttr('disabled').trigger('click');
        },

        // Show error message
        showError: function(message) {
            this.showNotice(message, 'error');
        },

        // Show warning message
        showWarning: function(message) {
            this.showNotice(message, 'notice');
        },

        // Show notice
        showNotice: function(message, type) {
            type = type || 'error';
            
            var noticeClass = 'woocommerce-' + type;
            var $notice = $('<div class="' + noticeClass + '">' + message + '</div>');
            
            // Remove existing BNA notices
            $('.bna-cart-notice').remove();
            $notice.addClass('bna-cart-notice');
            
            // Find insertion point
            var $target = $('.woocommerce-notices-wrapper').first();
            if ($target.length === 0) {
                $target = $('.woocommerce').first();
            }
            if ($target.length === 0) {
                $target = $('main').first();
            }
            
            if ($target.length > 0) {
                $target.prepend($notice);
                
                // Scroll to notice
                $('html, body').animate({
                    scrollTop: $notice.offset().top - 100
                }, 300);
            }

            this.log('Notice shown:', message, type);
        },

        // Clear notices
        clearNotices: function() {
            $('.bna-cart-notice').fadeOut(200, function() {
                $(this).remove();
            });
        },

        // Disable checkout button
        disableCheckout: function() {
            $('.checkout-button, .wc-proceed-to-checkout .button')
                .prop('disabled', true)
                .addClass('disabled')
                .css('opacity', '0.5');
            
            this.log('Checkout disabled');
        },

        // Enable checkout button
        enableCheckout: function() {
            $('.checkout-button, .wc-proceed-to-checkout .button')
                .prop('disabled', false)
                .removeClass('disabled')
                .css('opacity', '1');
            
            this.log('Checkout enabled');
        },

        // AJAX validation (fallback)
        validateWithServer: function(productId, callback) {
            var self = this;
            
            $.ajax({
                url: this.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bna_validate_cart_item',
                    product_id: productId,
                    nonce: this.config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        callback(true, response.data);
                    } else {
                        self.showError(response.data.message || 'Validation failed');
                        callback(false, response.data);
                    }
                },
                error: function() {
                    self.log('AJAX validation failed');
                    callback(true, {}); // Allow on AJAX failure
                }
            });
        },

        // Logging
        log: function() {
            if (this.config.debug && console && console.log) {
                console.log.apply(console, ['[BNA Cart Validation]'].concat(Array.prototype.slice.call(arguments)));
            }
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        // Check if we're on cart, checkout, or shop pages
        if ($('.woocommerce').length > 0) {
            BNACartValidation.init();
        }
    });

    // Also initialize after AJAX cart updates
    $(document.body).on('wc_fragments_refreshed updated_cart_totals', function() {
        if ($('.woocommerce').length > 0) {
            setTimeout(function() {
                BNACartValidation.validateCurrentCart();
            }, 100);
        }
    });

    // Make available globally for debugging
    window.BNACartValidation = BNACartValidation;

})(jQuery);
