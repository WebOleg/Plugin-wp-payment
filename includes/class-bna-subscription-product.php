<?php
/**
 * BNA Subscription Product Type
 * 
 * Adds custom subscription product type to WooCommerce
 * Creates subscription tab in product edit page
 * 
 * @since 1.9.0
 * @package BNA_Smart_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define the custom product class at file level (not in method)
if (class_exists('WC_Product') && !class_exists('WC_Product_BNA_Subscription')) {
    class WC_Product_BNA_Subscription extends WC_Product {
        
        public function __construct($product = 0) {
            $this->product_type = 'bna_subscription';
            parent::__construct($product);
        }

        public function get_type() {
            return 'bna_subscription';
        }

        public function is_virtual() {
            return true; // Subscriptions are virtual by default
        }

        public function is_downloadable() {
            return false;
        }

        /**
         * Get subscription frequency
         * @return string
         */
        public function get_subscription_frequency() {
            return $this->get_meta('_bna_subscription_frequency', true) ?: 'monthly';
        }

        /**
         * Get subscription length
         * @return string
         */
        public function get_subscription_length() {
            return $this->get_meta('_bna_subscription_length', true) ?: 'until_cancelled';
        }

        /**
         * Get trial period
         * @return int
         */
        public function get_trial_length() {
            return absint($this->get_meta('_bna_trial_length', true));
        }

        /**
         * Get signup fee
         * @return float
         */
        public function get_signup_fee() {
            return floatval($this->get_meta('_bna_signup_fee', true));
        }
    }
}

class BNA_Subscription_Product {

    /**
     * Initialize hooks
     */
    public static function init() {
        // Only init if WooCommerce is loaded
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        add_filter('product_type_selector', array(__CLASS__, 'add_product_type'));
        add_filter('woocommerce_product_class', array(__CLASS__, 'load_product_class'), 10, 2);
        
        // Admin product data tabs
        add_filter('woocommerce_product_data_tabs', array(__CLASS__, 'add_subscription_tab'));
        add_action('woocommerce_product_data_panels', array(__CLASS__, 'subscription_tab_content'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_subscription_fields'));
        
        // Frontend
        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'add_subscription_fields_to_cart'));
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_subscription_cart'), 10, 3);
        
        bna_log('BNA Subscription Product initialized');
    }

    /**
     * Load correct product class
     */
    public static function load_product_class($class_name, $product_type) {
        if ($product_type === 'bna_subscription') {
            return 'WC_Product_BNA_Subscription';
        }
        return $class_name;
    }

    /**
     * Add subscription to product type selector
     */
    public static function add_product_type($types) {
        // Only show if subscriptions are enabled
        if (BNA_Subscriptions::is_enabled()) {
            $types['bna_subscription'] = __('BNA Subscription', 'bna-smart-payment');
        }
        return $types;
    }

    /**
     * Add subscription tab to product data
     */
    public static function add_subscription_tab($tabs) {
        $tabs['bna_subscription'] = array(
            'label'    => __('Subscription', 'bna-smart-payment'),
            'target'   => 'bna_subscription_product_data',
            'class'    => array('show_if_bna_subscription'),
            'priority' => 25,
        );
        return $tabs;
    }

    /**
     * Subscription tab content
     */
    public static function subscription_tab_content() {
        global $post;
        
        $product = wc_get_product($post->ID);
        $frequency = $product ? $product->get_meta('_bna_subscription_frequency', true) : 'monthly';
        $length = $product ? $product->get_meta('_bna_subscription_length', true) : 'until_cancelled';
        $trial_length = $product ? absint($product->get_meta('_bna_trial_length', true)) : 0;
        $signup_fee = $product ? floatval($product->get_meta('_bna_signup_fee', true)) : 0;
        
        ?>
        <div id="bna_subscription_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="_bna_subscription_frequency"><?php _e('Billing Interval', 'bna-smart-payment'); ?></label>
                    <select name="_bna_subscription_frequency" id="_bna_subscription_frequency" class="select short">
                        <?php foreach (BNA_Subscriptions::get_frequencies() as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($frequency, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="description"><?php _e('How often the subscription will be billed.', 'bna-smart-payment'); ?></span>
                </p>

                <p class="form-field">
                    <label for="_bna_subscription_length"><?php _e('Subscription Length', 'bna-smart-payment'); ?></label>
                    <select name="_bna_subscription_length" id="_bna_subscription_length" class="select short">
                        <option value="until_cancelled" <?php selected($length, 'until_cancelled'); ?>>
                            <?php _e('Until cancelled', 'bna-smart-payment'); ?>
                        </option>
                        <?php for ($i = 1; $i <= 24; $i++) : ?>
                            <option value="<?php echo $i; ?>" <?php selected($length, $i); ?>>
                                <?php printf(_n('%d payment', '%d payments', $i, 'bna-smart-payment'), $i); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <span class="description"><?php _e('How many payments before the subscription expires.', 'bna-smart-payment'); ?></span>
                </p>
            </div>

            <div class="options_group">
                <p class="form-field">
                    <label for="_bna_trial_length"><?php _e('Free Trial Length', 'bna-smart-payment'); ?></label>
                    <input type="number" class="short" name="_bna_trial_length" id="_bna_trial_length" 
                           value="<?php echo esc_attr($trial_length); ?>" placeholder="0" min="0" max="365" />
                    <span class="description"><?php _e('Number of days for free trial (0 for no trial).', 'bna-smart-payment'); ?></span>
                </p>

                <p class="form-field">
                    <label for="_bna_signup_fee"><?php _e('Sign-up Fee', 'bna-smart-payment'); ?></label>
                    <input type="number" class="short wc_input_price" name="_bna_signup_fee" id="_bna_signup_fee" 
                           value="<?php echo esc_attr($signup_fee); ?>" placeholder="0.00" step="0.01" min="0" />
                    <span class="description"><?php _e('One-time fee charged when subscription starts.', 'bna-smart-payment'); ?></span>
                </p>
            </div>

            <div class="options_group">
                <div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin: 10px 0;">
                    <h4 style="margin-top: 0;"><?php _e('Subscription Settings', 'bna-smart-payment'); ?></h4>
                    <p><strong><?php _e('How it works:', 'bna-smart-payment'); ?></strong></p>
                    <ul style="margin-left: 20px;">
                        <li><?php _e('Customer purchases this product once', 'bna-smart-payment'); ?></li>
                        <li><?php _e('Recurring payments are handled by BNA API', 'bna-smart-payment'); ?></li>
                        <li><?php _e('Customer can manage subscription in My Account', 'bna-smart-payment'); ?></li>
                        <li><?php _e('You can track subscriptions in BNA portal', 'bna-smart-payment'); ?></li>
                    </ul>
                    <p><strong><?php _e('Note:', 'bna-smart-payment'); ?></strong> <?php _e('Virtual and downloadable options are automatically managed for subscription products.', 'bna-smart-payment'); ?></p>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Show/hide subscription tab based on product type
                $('select#product-type').change(function(){
                    var product_type = $(this).val();
                    
                    if (product_type === 'bna_subscription') {
                        $('.show_if_bna_subscription').show();
                        $('.hide_if_bna_subscription').hide();
                        
                        // Auto-check virtual for subscriptions
                        $('#_virtual').prop('checked', true).trigger('change');
                        
                        // Hide inventory tab options that don't apply
                        $('.inventory_options .show_if_simple').hide();
                    } else {
                        $('.show_if_bna_subscription').hide();
                        $('.hide_if_bna_subscription').show();
                    }
                }).trigger('change');
                
                // Update description when frequency changes
                $('#_bna_subscription_frequency').change(function(){
                    var frequency = $(this).val();
                    var frequency_text = $(this).find('option:selected').text().toLowerCase();
                    var description = 'Customer will be billed ' + frequency_text.toLowerCase() + '.';
                    $(this).next('.description').text(description);
                });
            });
        </script>

        <style type="text/css">
            .show_if_bna_subscription {
                display: none;
            }
            #bna_subscription_product_data .form-field {
                margin-bottom: 20px;
            }
            #bna_subscription_product_data .options_group {
                border-bottom: 1px solid #eee;
                padding-bottom: 15px;
                margin-bottom: 15px;
            }
            #bna_subscription_product_data .options_group:last-child {
                border-bottom: none;
            }
        </style>
        <?php
    }

    /**
     * Save subscription fields
     */
    public static function save_subscription_fields($post_id) {
        $product = wc_get_product($post_id);
        
        if ($product && $product->get_type() === 'bna_subscription') {
            // Save subscription fields
            $frequency = isset($_POST['_bna_subscription_frequency']) ? 
                        sanitize_text_field($_POST['_bna_subscription_frequency']) : 'monthly';
            
            $length = isset($_POST['_bna_subscription_length']) ? 
                     sanitize_text_field($_POST['_bna_subscription_length']) : 'until_cancelled';
            
            $trial_length = isset($_POST['_bna_trial_length']) ? 
                           absint($_POST['_bna_trial_length']) : 0;
            
            $signup_fee = isset($_POST['_bna_signup_fee']) ? 
                         floatval($_POST['_bna_signup_fee']) : 0;
            
            $product->update_meta_data('_bna_subscription_frequency', $frequency);
            $product->update_meta_data('_bna_subscription_length', $length);
            $product->update_meta_data('_bna_trial_length', $trial_length);
            $product->update_meta_data('_bna_signup_fee', $signup_fee);
            
            // Force virtual for subscriptions
            $product->update_meta_data('_virtual', 'yes');
            $product->update_meta_data('_downloadable', 'no');
            
            $product->save();
            
            bna_log('Subscription product saved', array(
                'product_id' => $post_id,
                'frequency' => $frequency,
                'length' => $length,
                'trial_length' => $trial_length,
                'signup_fee' => $signup_fee
            ));
        }
    }

    /**
     * Add subscription fields to cart form
     */
    public static function add_subscription_fields_to_cart() {
        global $product;
        
        if (!$product || $product->get_type() !== 'bna_subscription') {
            return;
        }
        
        $frequency = $product->get_meta('_bna_subscription_frequency', true) ?: 'monthly';
        $signup_fee = floatval($product->get_meta('_bna_signup_fee', true));
        $trial_length = absint($product->get_meta('_bna_trial_length', true));
        
        ?>
        <div class="bna-subscription-info">
            <h4><?php _e('Subscription Details', 'bna-smart-payment'); ?></h4>
            
            <div>
                <strong><?php _e('Billing:', 'bna-smart-payment'); ?></strong>
                <?php echo esc_html(BNA_Subscriptions::FREQUENCIES[$frequency] ?? ucfirst($frequency)); ?>
            </div>
            <div>
                <strong><?php _e('Price:', 'bna-smart-payment'); ?></strong>
                <?php echo wc_price($product->get_price()); ?> / <?php echo esc_html(strtolower(BNA_Subscriptions::FREQUENCIES[$frequency] ?? $frequency)); ?>
            </div>
            
            <?php if ($signup_fee > 0) : ?>
                <div>
                    <strong><?php _e('Sign-up Fee:', 'bna-smart-payment'); ?></strong>
                    <?php echo wc_price($signup_fee); ?> <?php _e('(one-time)', 'bna-smart-payment'); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($trial_length > 0) : ?>
                <div>
                    <strong><?php _e('Free Trial:', 'bna-smart-payment'); ?></strong>
                    <?php printf(_n('%d day', '%d days', $trial_length, 'bna-smart-payment'), $trial_length); ?>
                </div>
            <?php endif; ?>
            
            <input type="hidden" name="bna_subscription_frequency" value="<?php echo esc_attr($frequency); ?>">
            
            <p><?php _e('This is a subscription product. You will be charged automatically based on the billing interval.', 'bna-smart-payment'); ?></p>
        </div>
        <?php
    }

    /**
     * Validate subscription when adding to cart
     */
    public static function validate_subscription_cart($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        
        if ($product && $product->get_type() === 'bna_subscription') {
            // Check if subscriptions are enabled
            if (!BNA_Subscriptions::is_enabled()) {
                wc_add_notice(__('Subscriptions are currently disabled.', 'bna-smart-payment'), 'error');
                return false;
            }
            
            // Only allow quantity of 1 for subscriptions
            if ($quantity > 1) {
                wc_add_notice(__('You can only purchase one subscription at a time.', 'bna-smart-payment'), 'error');
                return false;
            }
            
            // Check if cart already has subscription
            foreach (WC()->cart->get_cart() as $cart_item) {
                $cart_product = $cart_item['data'];
                if ($cart_product->get_type() === 'bna_subscription') {
                    wc_add_notice(__('You can only have one subscription product in your cart.', 'bna-smart-payment'), 'error');
                    return false;
                }
            }
            
            bna_log('Subscription product validated for cart', array(
                'product_id' => $product_id,
                'frequency' => $product->get_meta('_bna_subscription_frequency', true)
            ));
        }
        
        return $passed;
    }
}

// Initialize when plugin loads
add_action('plugins_loaded', array('BNA_Subscription_Product', 'init'), 20);
