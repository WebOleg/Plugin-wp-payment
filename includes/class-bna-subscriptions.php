<?php
/**
 * BNA Subscriptions Core Class
 * 
 * Handles subscription management without API integration
 * Provides base functionality for subscription products and orders
 * Updated to work with product meta fields instead of custom product type
 * 
 * @since 1.9.0 Updated to use product meta fields
 * @package BNA_Smart_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Subscriptions {

    /**
     * Single instance of the class
     * @var BNA_Subscriptions|null
     */
    private static $instance = null;

    /**
     * Available subscription frequencies
     * @var array
     */
    const FREQUENCIES = array(
        'daily'     => 'Daily',
        'weekly'    => 'Weekly', 
        'biweekly'  => 'Bi-Weekly',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        'biannual'  => 'Bi-Annual',
        'annual'    => 'Annual'
    );

    /**
     * Subscription statuses
     * @var array
     */
    const STATUSES = array(
        'new'       => 'New',
        'active'    => 'Active', 
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'expired'   => 'Expired',
        'failed'    => 'Failed'
    );

    /**
     * Get instance
     * @return BNA_Subscriptions
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        bna_log('BNA Subscriptions initialized', array(
            'frequencies' => count(self::FREQUENCIES),
            'statuses' => count(self::STATUSES),
            'using_meta_fields' => true
        ));
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_filter('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_subscription_data'), 20);
        
        // Add cart validation for subscription products
        add_action('woocommerce_add_to_cart_validation', array($this, 'validate_subscription_cart'), 10, 3);
    }

    /**
     * Initialize after WooCommerce is loaded
     */
    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Hook into checkout process for subscription orders
        add_action('woocommerce_thankyou', array($this, 'create_subscription_from_order'), 10);
        
        // Add subscription data to cart items
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_subscription_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_subscription_cart_item_data'), 10, 2);
    }

    /**
     * Get available frequencies for dropdown
     * @return array
     */
    public static function get_frequencies() {
        return self::FREQUENCIES;
    }

    /**
     * Get available statuses
     * @return array
     */
    public static function get_statuses() {
        return self::STATUSES;
    }

    /**
     * Check if subscriptions are enabled
     * @return bool
     */
    public static function is_enabled() {
        return get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
    }

    /**
     * Check if product is subscription - Updated to use meta fields
     * @param WC_Product|int $product
     * @return bool
     */
    public static function is_subscription_product($product) {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product) {
            return false;
        }

        return get_post_meta($product->get_id(), '_bna_is_subscription', true) === 'yes';
    }

    /**
     * Get subscription data for product
     * @param WC_Product|int $product
     * @return array|false
     */
    public static function get_subscription_data($product) {
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product || !self::is_subscription_product($product)) {
            return false;
        }

        return array(
            'frequency' => get_post_meta($product->get_id(), '_bna_subscription_frequency', true) ?: 'monthly',
            'trial_days' => absint(get_post_meta($product->get_id(), '_bna_subscription_trial_days', true)),
            'signup_fee' => floatval(get_post_meta($product->get_id(), '_bna_subscription_signup_fee', true))
        );
    }

    /**
     * Add subscription data to cart item
     * @param array $cart_item_data
     * @param int $product_id
     * @param int $variation_id
     * @return array
     */
    public function add_subscription_cart_item_data($cart_item_data, $product_id, $variation_id) {
        $product = wc_get_product($product_id);
        
        if (self::is_subscription_product($product)) {
            $subscription_data = self::get_subscription_data($product);
            if ($subscription_data) {
                $cart_item_data['bna_subscription'] = $subscription_data;
            }
        }

        return $cart_item_data;
    }

    /**
     * Display subscription data in cart
     * @param array $item_data
     * @param array $cart_item
     * @return array
     */
    public function display_subscription_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['bna_subscription'])) {
            $subscription = $cart_item['bna_subscription'];
            
            // Add frequency info
            $frequency_label = self::FREQUENCIES[$subscription['frequency']] ?? $subscription['frequency'];
            $item_data[] = array(
                'name' => __('Billing', 'bna-smart-payment'),
                'value' => $frequency_label,
                'display' => ''
            );

            // Add trial info
            if ($subscription['trial_days'] > 0) {
                $item_data[] = array(
                    'name' => __('Free Trial', 'bna-smart-payment'),
                    'value' => sprintf(_n('%d day', '%d days', $subscription['trial_days'], 'bna-smart-payment'), $subscription['trial_days']),
                    'display' => ''
                );
            }

            // Add signup fee info
            if ($subscription['signup_fee'] > 0) {
                $item_data[] = array(
                    'name' => __('Sign-up Fee', 'bna-smart-payment'),
                    'value' => wc_price($subscription['signup_fee']),
                    'display' => ''
                );
            }
        }

        return $item_data;
    }

    /**
     * Validate subscription products in cart
     * @param bool $passed
     * @param int $product_id
     * @param int $quantity
     * @return bool
     */
    public function validate_subscription_cart($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        
        if (!$product || !self::is_subscription_product($product)) {
            return $passed;
        }

        // Check if subscriptions are enabled
        if (!self::is_enabled()) {
            wc_add_notice(__('Subscriptions are currently disabled.', 'bna-smart-payment'), 'error');
            return false;
        }

        // Allow only one subscription product in cart (optional rule)
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['bna_subscription'])) {
                wc_add_notice(__('You can only have one subscription product in your cart at a time.', 'bna-smart-payment'), 'error');
                return false;
            }
        }

        return $passed;
    }

    /**
     * Create subscription from order
     * @param int $order_id
     */
    public function create_subscription_from_order($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $subscription_items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (self::is_subscription_product($product)) {
                $subscription_data = self::get_subscription_data($product);
                $subscription_items[] = array(
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total(),
                    'subscription_data' => $subscription_data
                );
            }
        }

        if (!empty($subscription_items)) {
            // Store subscription data in order meta
            $order->update_meta_data('_bna_subscription_created', current_time('timestamp'));
            $order->update_meta_data('_bna_subscription_status', 'new');
            $order->update_meta_data('_bna_subscription_items', $subscription_items);
            $order->save();

            bna_log('Subscription created from order', array(
                'order_id' => $order_id,
                'status' => 'new',
                'items_count' => count($subscription_items),
                'note' => 'Using product meta fields'
            ));
        }
    }

    /**
     * Save subscription data from checkout
     * @param int $order_id
     */
    public function save_subscription_data($order_id) {
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if order contains subscription products
        $has_subscription = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (self::is_subscription_product($product)) {
                $has_subscription = true;
                break;
            }
        }

        if ($has_subscription) {
            $order->update_meta_data('_bna_has_subscription', 'yes');
            $order->save();

            bna_log('Subscription order detected', array(
                'order_id' => $order_id
            ));
        }
    }

    /**
     * Handle order status changes
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     */
    public function handle_order_status_change($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_meta('_bna_subscription_created')) {
            return;
        }

        // Update subscription status based on order status
        $subscription_status = $this->map_order_status_to_subscription($new_status);
        if ($subscription_status) {
            $order->update_meta_data('_bna_subscription_status', $subscription_status);
            $order->save();

            bna_log('Subscription status updated', array(
                'order_id' => $order_id,
                'old_order_status' => $old_status,
                'new_order_status' => $new_status,
                'subscription_status' => $subscription_status
            ));
        }
    }

    /**
     * Map WooCommerce order status to subscription status
     * @param string $order_status
     * @return string|null
     */
    private function map_order_status_to_subscription($order_status) {
        $status_map = array(
            'completed' => 'active',
            'processing' => 'active',
            'on-hold' => 'suspended',
            'cancelled' => 'cancelled',
            'refunded' => 'cancelled',
            'failed' => 'failed'
        );

        return isset($status_map[$order_status]) ? $status_map[$order_status] : null;
    }

    /**
     * Get user subscriptions
     * @param int $user_id
     * @return array
     */
    public function get_user_subscriptions($user_id) {
        if (!$user_id) {
            return array();
        }

        // Get orders with subscription data
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'meta_key' => '_bna_subscription_created',
            'meta_compare' => 'EXISTS',
            'limit' => -1
        ));

        $subscriptions = array();
        foreach ($orders as $order) {
            $subscription_items = $order->get_meta('_bna_subscription_items', true);
            if (is_array($subscription_items)) {
                $subscriptions[] = array(
                    'id' => $order->get_id(),
                    'order_id' => $order->get_id(),
                    'status' => $order->get_meta('_bna_subscription_status', true) ?: 'new',
                    'total' => $order->get_total(),
                    'currency' => $order->get_currency(),
                    'created_date' => $order->get_date_created(),
                    'next_payment' => $this->calculate_next_payment_date($order, $subscription_items),
                    'items' => $subscription_items
                );
            }
        }

        return $subscriptions;
    }

    /**
     * Calculate next payment date
     * @param WC_Order $order
     * @param array $subscription_items
     * @return string|null
     */
    private function calculate_next_payment_date($order, $subscription_items) {
        if (empty($subscription_items)) {
            return null;
        }

        // Use frequency from first subscription item
        $first_item = reset($subscription_items);
        $frequency = $first_item['subscription_data']['frequency'] ?? 'monthly';

        $last_payment = $order->get_date_created();
        if (!$last_payment) {
            return null;
        }

        $interval_map = array(
            'daily' => '+1 day',
            'weekly' => '+1 week',
            'biweekly' => '+2 weeks',
            'monthly' => '+1 month',
            'quarterly' => '+3 months',
            'biannual' => '+6 months',
            'annual' => '+1 year'
        );

        if (!isset($interval_map[$frequency])) {
            return null;
        }

        return date('Y-m-d', strtotime($interval_map[$frequency], $last_payment->getTimestamp()));
    }

    /**
     * Check if order has subscription products
     * @param WC_Order|int $order
     * @return bool
     */
    public static function order_has_subscription($order) {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        if (!$order) {
            return false;
        }

        return $order->get_meta('_bna_has_subscription', true) === 'yes';
    }

    /**
     * Get subscription frequency label
     * @param string $frequency
     * @return string
     */
    public static function get_frequency_label($frequency) {
        return self::FREQUENCIES[$frequency] ?? $frequency;
    }

    /**
     * Get subscription status label
     * @param string $status
     * @return string
     */
    public static function get_status_label($status) {
        return self::STATUSES[$status] ?? $status;
    }
}
