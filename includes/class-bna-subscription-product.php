<?php

if (!defined('ABSPATH')) exit;

class BNA_Subscription_Product {

    public function __construct() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('product_type_selector', array($this, 'add_subscription_product_type'));
        add_filter('woocommerce_product_class', array($this, 'woocommerce_product_class'), 10, 2);
        add_action('woocommerce_product_options_general_product_data', array($this, 'subscription_options_product_tab_content'));
        add_action('woocommerce_process_product_meta', array($this, 'save_subscription_options_fields'));
        add_filter('woocommerce_product_data_tabs', array($this, 'subscription_product_data_tabs'));
        add_action('woocommerce_product_data_panels', array($this, 'subscription_options_product_data_panel'));
        add_action('admin_footer', array($this, 'subscription_admin_script'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_subscription_scripts'));
    }

    public function enqueue_subscription_scripts() {
        if (is_product() || is_shop() || is_product_category()) {
            wp_enqueue_script(
                'bna-subscription-product',
                BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/subscription-manager.js',
                array('jquery'),
                BNA_SMART_PAYMENT_VERSION,
                true
            );
        }
    }

    public function add_subscription_product_type($types) {
        $types['bna_subscription'] = __('BNA Subscription', 'bna-smart-payment');
        return $types;
    }

    public function woocommerce_product_class($classname, $product_type) {
        if ($product_type === 'bna_subscription') {
            $classname = 'WC_Product_BNA_Subscription';
        }
        return $classname;
    }

    public function subscription_product_data_tabs($tabs) {
        $tabs['bna_subscription'] = array(
            'label' => __('Subscription', 'bna-smart-payment'),
            'target' => 'bna_subscription_product_data',
            'class' => array('show_if_bna_subscription'),
            'priority' => 25,
        );
        return $tabs;
    }

    public function subscription_options_product_data_panel() {
        global $post;
        
        echo '<div id="bna_subscription_product_data" class="panel woocommerce_options_panel">';

        woocommerce_wp_select(array(
            'id' => '_bna_subscription_frequency',
            'label' => __('Billing Frequency', 'bna-smart-payment'),
            'description' => __('How often the subscription will be billed.', 'bna-smart-payment'),
            'desc_tip' => true,
            'options' => array(
                'daily' => __('Daily', 'bna-smart-payment'),
                'weekly' => __('Weekly', 'bna-smart-payment'),
                'monthly' => __('Monthly', 'bna-smart-payment'),
                'quarterly' => __('Quarterly (3 months)', 'bna-smart-payment'),
                'biannual' => __('Biannual (6 months)', 'bna-smart-payment'),
                'annual' => __('Annual', 'bna-smart-payment')
            ),
            'value' => get_post_meta($post->ID, '_bna_subscription_frequency', true) ?: 'monthly'
        ));

        woocommerce_wp_text_input(array(
            'id' => '_bna_subscription_payments',
            'label' => __('Number of Payments', 'bna-smart-payment'),
            'description' => __('Leave empty for unlimited payments.', 'bna-smart-payment'),
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '1'
            ),
            'value' => get_post_meta($post->ID, '_bna_subscription_payments', true)
        ));

        woocommerce_wp_text_input(array(
            'id' => '_bna_subscription_trial_days',
            'label' => __('Free Trial (days)', 'bna-smart-payment'),
            'description' => __('Number of free trial days before first payment.', 'bna-smart-payment'),
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '0'
            ),
            'value' => get_post_meta($post->ID, '_bna_subscription_trial_days', true)
        ));

        echo '</div>';
    }

    public function subscription_options_product_tab_content() {
        echo '<div class="options_group show_if_bna_subscription">';
        echo '<p><strong>' . __('Subscription Price:', 'bna-smart-payment') . '</strong> ' . __('Set the subscription price in the Regular Price field above.', 'bna-smart-payment') . '</p>';
        echo '</div>';
    }

    public function subscription_admin_script() {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            function bna_subscription_type_change() {
                var productType = $('#product-type').val();
                
                if (productType === 'bna_subscription') {
                    $('.show_if_bna_subscription').show();
                    $('.hide_if_bna_subscription').hide();
                    $('._regular_price_field').show();
                    $('._sale_price_field').show();
                    $('#_virtual').prop('checked', true);
                    $('#_downloadable').prop('checked', false);
                    $('.show_if_virtual').show();
                    $('.hide_if_virtual').hide();
                    $('.shipping_tab').hide();
                } else {
                    $('.show_if_bna_subscription').hide();
                    $('.hide_if_bna_subscription').show();
                }
            }
            
            bna_subscription_type_change();
            $('#product-type').on('change', bna_subscription_type_change);
            
            $('#product-type').on('change', function() {
                if ($(this).val() === 'bna_subscription') {
                    $('#_virtual').trigger('change');
                }
            });
        });
        </script>
        <?php
    }

    public function save_subscription_options_fields($post_id) {
        $frequency = sanitize_text_field($_POST['_bna_subscription_frequency'] ?? 'monthly');
        update_post_meta($post_id, '_bna_subscription_frequency', $frequency);

        $payments = intval($_POST['_bna_subscription_payments'] ?? 0);
        if ($payments > 0) {
            update_post_meta($post_id, '_bna_subscription_payments', $payments);
        } else {
            delete_post_meta($post_id, '_bna_subscription_payments');
        }

        $trial_days = intval($_POST['_bna_subscription_trial_days'] ?? 0);
        if ($trial_days > 0) {
            update_post_meta($post_id, '_bna_subscription_trial_days', $trial_days);
        } else {
            delete_post_meta($post_id, '_bna_subscription_trial_days');
        }
    }
}

class WC_Product_BNA_Subscription extends WC_Product {

    public function __construct($product = 0) {
        $this->product_type = 'bna_subscription';
        parent::__construct($product);
    }

    public function get_type() {
        return 'bna_subscription';
    }

    public function is_virtual($context = 'view') {
        return true;
    }

    public function is_downloadable($context = 'view') {
        return false;
    }

    public function needs_shipping() {
        return false;
    }

    public function is_sold_individually($context = 'view') {
        return true;
    }

    public function is_purchasable() {
        return true;
    }

    public function get_frequency() {
        return $this->get_meta('_bna_subscription_frequency') ?: 'monthly';
    }

    public function get_payment_count() {
        return $this->get_meta('_bna_subscription_payments');
    }

    public function get_trial_days() {
        return $this->get_meta('_bna_subscription_trial_days');
    }

    public function get_price_html($price = '') {
        $frequency = $this->get_frequency();
        $price = parent::get_price_html();
        
        if (empty($price)) {
            return $price;
        }

        $frequency_text = array(
            'daily' => __('per day', 'bna-smart-payment'),
            'weekly' => __('per week', 'bna-smart-payment'),
            'monthly' => __('per month', 'bna-smart-payment'),
            'quarterly' => __('every 3 months', 'bna-smart-payment'),
            'biannual' => __('every 6 months', 'bna-smart-payment'),
            'annual' => __('per year', 'bna-smart-payment')
        );

        $frequency_display = isset($frequency_text[$frequency]) ? $frequency_text[$frequency] : __('per period', 'bna-smart-payment');
        
        $payment_count = $this->get_payment_count();
        $trial_days = $this->get_trial_days();

        $subscription_text = $price . ' ' . $frequency_display;

        if ($payment_count > 0) {
            $subscription_text .= ' ' . sprintf(__('for %d payments', 'bna-smart-payment'), $payment_count);
        }

        if ($trial_days > 0) {
            $subscription_text = sprintf(__('%d day free trial, then %s', 'bna-smart-payment'), $trial_days, $subscription_text);
        }

        return $subscription_text;
    }

    public function add_to_cart_text() {
        if ($this->is_purchasable() && $this->is_in_stock()) {
            return __('Subscribe', 'bna-smart-payment');
        }
        return __('Read more', 'bna-smart-payment');
    }

    public function single_add_to_cart_text() {
        return __('Subscribe Now', 'bna-smart-payment');
    }
}

new BNA_Subscription_Product();
