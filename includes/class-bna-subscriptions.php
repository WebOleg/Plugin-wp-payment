<?php
/**
 * BNA Subscriptions Core Class
 * 
 * Handles subscription management without API integration
 * Provides base functionality for subscription products and orders
 * 
 * @since 1.9.0
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
            'statuses' => count(self::STATUSES)
        ));
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_filter('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_subscription_data'), 20);
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
        $gateway = new BNA_Gateway();
        return $gateway->get_option('enable_subscriptions') === 'yes';
    }

    /**
     * Check if product is subscription
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

        return $product->get_type() === 'bna_subscription';
    }

    /**
     * Create subscription from order (placeholder)
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

        $has_subscription = false;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (self::is_subscription_product($product)) {
                $has_subscription = true;
                break;
            }
        }

        if ($has_subscription) {
            // Store subscription data in order meta (placeholder for future API integration)
            $order->update_meta_data('_bna_subscription_created', current_time('timestamp'));
            $order->update_meta_data('_bna_subscription_status', 'new');
            $order->save();

            bna_log('Subscription created from order', array(
                'order_id' => $order_id,
                'status' => 'new',
                'note' => 'Awaiting API integration'
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

        // Get subscription data from POST
        $frequency = isset($_POST['bna_subscription_frequency']) ? 
                     sanitize_text_field($_POST['bna_subscription_frequency']) : '';

        if ($frequency && array_key_exists($frequency, self::FREQUENCIES)) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_bna_subscription_frequency', $frequency);
                $order->save();

                bna_log('Subscription frequency saved', array(
                    'order_id' => $order_id,
                    'frequency' => $frequency
                ));
            }
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
     * Get user subscriptions (placeholder)
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
            $subscriptions[] = array(
                'id' => $order->get_id(),
                'order_id' => $order->get_id(),
                'status' => $order->get_meta('_bna_subscription_status', true) ?: 'new',
                'frequency' => $order->get_meta('_bna_subscription_frequency', true) ?: 'monthly',
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'created_date' => $order->get_date_created(),
                'next_payment' => $this->calculate_next_payment_date($order),
                'products' => $this->get_subscription_products($order)
            );
        }

        return $subscriptions;
    }

    /**
     * Calculate next payment date (placeholder)
     * @param WC_Order $order
     * @return string|null
     */
    private function calculate_next_payment_date($order) {
        $frequency = $order->get_meta('_bna_subscription_frequency', true);
        if (!$frequency) {
            return null;
        }

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
     * Get subscription products from order
     * @param WC_Order $order
     * @return array
     */
    private function get_subscription_products($order) {
        $products = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && self::is_subscription_product($product)) {
                $products[] = array(
                    'name' => $product->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total()
                );
            }
        }

        return $products;
    }
}
