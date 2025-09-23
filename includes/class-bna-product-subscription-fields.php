<?php
/**
 * Product Subscription Fields
 * Adds subscription options to regular WooCommerce products
 * Fields appear only if enabled in plugin settings
 *
 * @since 1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Product_Subscription_Fields {

    private static $instance = null;

    /**
     * Available subscription frequencies - only those supported by BNA API
     * @var array
     */
    const FREQUENCIES = array(
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'biweekly' => 'Bi-Weekly (Every 2 weeks)',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly (Every 3 months)',
        'semiannual' => 'Semi-Annual (Every 6 months)',
        'annual' => 'Annual (Yearly)'
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add subscription fields to product data panels
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_subscription_fields'));
        
        // Save subscription fields
        add_action('woocommerce_process_product_meta', array($this, 'save_subscription_fields'));
        
        // Add admin styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // Display subscription info on frontend
        add_action('woocommerce_single_product_summary', array($this, 'display_subscription_info'), 25);
    }

    /**
     * Add subscription fields to product general tab
     */
    public function add_subscription_fields() {
        global $post;
        
        // Check if subscriptions are enabled in gateway settings
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        $trials_enabled = get_option('bna_smart_payment_allow_subscription_trials', 'yes') === 'yes';
        $signup_fees_enabled = get_option('bna_smart_payment_allow_signup_fees', 'yes') === 'yes';
        
        if (!$subscriptions_enabled) {
            // Don't show anything if subscriptions are completely disabled
            return;
        }

        echo '<div class="options_group bna_subscription_options">';
        
        // Enable subscription checkbox
        woocommerce_wp_checkbox(array(
            'id' => '_bna_is_subscription',
            'label' => __('Enable Subscription', 'bna-smart-payment'),
            'description' => __('Check this to make this product a subscription.', 'bna-smart-payment'),
            'desc_tip' => true,
        ));

        $current_is_subscription = get_post_meta($post->ID, '_bna_is_subscription', true) === 'yes';
        $fields_style = $current_is_subscription ? '' : 'style="display: none;"';

        echo '<div class="bna_subscription_fields" ' . $fields_style . '>';

        // Billing frequency
        woocommerce_wp_select(array(
            'id' => '_bna_subscription_frequency',
            'label' => __('Billing Frequency', 'bna-smart-payment'),
            'description' => __('How often should the customer be billed?', 'bna-smart-payment'),
            'desc_tip' => true,
            'options' => self::FREQUENCIES,
            'value' => get_post_meta($post->ID, '_bna_subscription_frequency', true) ?: 'monthly'
        ));

        // Trial period - only if enabled in settings
        if ($trials_enabled) {
            woocommerce_wp_text_input(array(
                'id' => '_bna_subscription_trial_days',
                'label' => __('Trial Period (days)', 'bna-smart-payment'),
                'description' => __('Number of days for free trial. Leave empty for no trial.', 'bna-smart-payment'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => array(
                    'step' => '1',
                    'min' => '0'
                )
            ));
        }

        // Signup fee - only if enabled in settings
        if ($signup_fees_enabled) {
            woocommerce_wp_text_input(array(
                'id' => '_bna_subscription_signup_fee',
                'label' => __('Sign-up Fee', 'bna-smart-payment') . ' (' . get_woocommerce_currency_symbol() . ')',
                'description' => __('One-time fee charged at signup. Leave empty for no fee.', 'bna-smart-payment'),
                'desc_tip' => true,
                'type' => 'number',
                'custom_attributes' => array(
                    'step' => '0.01',
                    'min' => '0'
                )
            ));
        }

        echo '</div>'; // .bna_subscription_fields
        echo '</div>'; // .bna_subscription_options
    }

    /**
     * Save subscription fields
     */
    public function save_subscription_fields($post_id) {
        // Check if subscriptions are enabled - if not, don't save anything
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            // Remove all subscription meta if subscriptions are disabled
            delete_post_meta($post_id, '_bna_is_subscription');
            delete_post_meta($post_id, '_bna_subscription_frequency');
            delete_post_meta($post_id, '_bna_subscription_trial_days');
            delete_post_meta($post_id, '_bna_subscription_signup_fee');
            return;
        }

        // Enable subscription
        $is_subscription = isset($_POST['_bna_is_subscription']) ? 'yes' : 'no';
        update_post_meta($post_id, '_bna_is_subscription', $is_subscription);

        if ($is_subscription === 'yes') {
            $trials_enabled = get_option('bna_smart_payment_allow_subscription_trials', 'yes') === 'yes';
            $signup_fees_enabled = get_option('bna_smart_payment_allow_signup_fees', 'yes') === 'yes';

            // Save frequency - validate against allowed frequencies
            if (isset($_POST['_bna_subscription_frequency'])) {
                $frequency = sanitize_text_field($_POST['_bna_subscription_frequency']);
                if (array_key_exists($frequency, self::FREQUENCIES)) {
                    update_post_meta($post_id, '_bna_subscription_frequency', $frequency);
                } else {
                    // Default to monthly if invalid frequency
                    update_post_meta($post_id, '_bna_subscription_frequency', 'monthly');
                }
            }

            // Save trial days - only if trials are enabled
            if ($trials_enabled && isset($_POST['_bna_subscription_trial_days'])) {
                $trial_days = absint($_POST['_bna_subscription_trial_days']);
                update_post_meta($post_id, '_bna_subscription_trial_days', $trial_days);
            } else {
                delete_post_meta($post_id, '_bna_subscription_trial_days');
            }

            // Save signup fee - only if signup fees are enabled
            if ($signup_fees_enabled && isset($_POST['_bna_subscription_signup_fee'])) {
                $signup_fee = floatval($_POST['_bna_subscription_signup_fee']);
                update_post_meta($post_id, '_bna_subscription_signup_fee', $signup_fee);
            } else {
                delete_post_meta($post_id, '_bna_subscription_signup_fee');
            }
        } else {
            // Remove subscription meta if disabled
            delete_post_meta($post_id, '_bna_subscription_frequency');
            delete_post_meta($post_id, '_bna_subscription_trial_days');
            delete_post_meta($post_id, '_bna_subscription_signup_fee');
        }

        bna_debug('Subscription fields saved', array(
            'product_id' => $post_id,
            'is_subscription' => $is_subscription,
            'subscriptions_enabled' => $subscriptions_enabled
        ));
    }

    /**
     * Enqueue admin scripts and styles - only if subscriptions enabled
     */
    public function admin_enqueue_scripts($hook) {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        $screen = get_current_screen();
        if ($screen->id !== 'product') {
            return;
        }

        // Only load scripts if subscriptions are enabled
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            return;
        }

        wp_enqueue_script(
            'bna-admin-subscription-fields',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/admin-subscription-fields.js',
            array('jquery'),
            BNA_SMART_PAYMENT_VERSION,
            true
        );

        wp_enqueue_style(
            'bna-admin-subscription',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/admin-subscription.css',
            array(),
            BNA_SMART_PAYMENT_VERSION
        );
    }

    /**
     * Display subscription information on product page - only if subscriptions enabled
     */
    public function display_subscription_info() {
        global $product;
        
        // Don't show anything if subscriptions are disabled
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            return;
        }
        
        if (!$this->is_subscription_product($product)) {
            return;
        }

        $trials_enabled = get_option('bna_smart_payment_allow_subscription_trials', 'yes') === 'yes';
        $signup_fees_enabled = get_option('bna_smart_payment_allow_signup_fees', 'yes') === 'yes';

        $frequency = get_post_meta($product->get_id(), '_bna_subscription_frequency', true);
        $trial_days = $trials_enabled ? get_post_meta($product->get_id(), '_bna_subscription_trial_days', true) : 0;
        $signup_fee = $signup_fees_enabled ? get_post_meta($product->get_id(), '_bna_subscription_signup_fee', true) : 0;

        echo '<div class="bna-subscription-info">';
        
        echo '<p class="subscription-type">';
        echo '<strong>' . __('Subscription Product', 'bna-smart-payment') . '</strong>';
        echo '</p>';

        if ($frequency && isset(self::FREQUENCIES[$frequency])) {
            echo '<p class="subscription-frequency">';
            echo '<strong>' . __('Billing:', 'bna-smart-payment') . '</strong> ';
            echo esc_html(self::FREQUENCIES[$frequency]);
            echo '</p>';
        }

        if ($trials_enabled && $trial_days > 0) {
            echo '<p class="subscription-trial">';
            echo '<strong>' . __('Free Trial:', 'bna-smart-payment') . '</strong> ';
            printf(_n('%d day', '%d days', $trial_days, 'bna-smart-payment'), $trial_days);
            echo '</p>';
        }

        if ($signup_fees_enabled && $signup_fee > 0) {
            echo '<p class="subscription-signup-fee">';
            echo '<strong>' . __('Sign-up Fee:', 'bna-smart-payment') . '</strong> ';
            echo wc_price($signup_fee);
            echo '</p>';
        }

        echo '</div>';
    }

    /**
     * Check if product is subscription - FIXED: proper type checking
     */
    public static function is_subscription_product($product) {
        // Handle different input types
        if (is_string($product)) {
            // If it's a string, try to get product by ID or slug
            if (is_numeric($product)) {
                $product = wc_get_product($product);
            } else {
                // It might be a slug, try to get product by slug
                $post = get_page_by_path($product, OBJECT, 'product');
                if ($post) {
                    $product = wc_get_product($post->ID);
                } else {
                    return false;
                }
            }
        } elseif (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        // If we still don't have a valid product object, return false
        if (!$product || !is_a($product, 'WC_Product')) {
            return false;
        }

        // If subscriptions are globally disabled, no product can be a subscription
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            return false;
        }

        return get_post_meta($product->get_id(), '_bna_is_subscription', true) === 'yes';
    }

    /**
     * Get subscription data for product
     */
    public static function get_subscription_data($product_id) {
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        $trials_enabled = get_option('bna_smart_payment_allow_subscription_trials', 'yes') === 'yes';
        $signup_fees_enabled = get_option('bna_smart_payment_allow_signup_fees', 'yes') === 'yes';

        if (!$subscriptions_enabled) {
            return array(
                'is_subscription' => false,
                'frequency' => 'monthly',
                'trial_days' => 0,
                'signup_fee' => 0
            );
        }

        $frequency = get_post_meta($product_id, '_bna_subscription_frequency', true) ?: 'monthly';
        
        // Validate frequency against allowed values
        if (!array_key_exists($frequency, self::FREQUENCIES)) {
            $frequency = 'monthly';
        }

        return array(
            'is_subscription' => get_post_meta($product_id, '_bna_is_subscription', true) === 'yes',
            'frequency' => $frequency,
            'trial_days' => $trials_enabled ? absint(get_post_meta($product_id, '_bna_subscription_trial_days', true)) : 0,
            'signup_fee' => $signup_fees_enabled ? floatval(get_post_meta($product_id, '_bna_subscription_signup_fee', true)) : 0
        );
    }

    /**
     * Get frequency label
     */
    public static function get_frequency_label($frequency) {
        return self::FREQUENCIES[$frequency] ?? $frequency;
    }

    /**
     * Get all available frequencies
     */
    public static function get_frequencies() {
        return self::FREQUENCIES;
    }
}

// Initialize
BNA_Product_Subscription_Fields::get_instance();
