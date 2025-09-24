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
     * Available subscription frequencies (BNA API Compatible)
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
     * BNA API frequency mapping
     * @var array
     */
    const BNA_FREQUENCY_MAP = array(
        'daily'     => 'DAILY',
        'weekly'    => 'WEEKLY',
        'biweekly'  => 'BIWEEKLY',
        'monthly'   => 'MONTHLY',
        'quarterly' => 'QUARTERLY',
        'biannual'  => 'BIANNUAL',
        'annual'    => 'ANNUAL'
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
            'using_meta_fields' => true,
            'validation_enabled' => true
        ));
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_filter('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_subscription_data'), 20);

        // Enhanced cart validation for subscription products (BNA Rules)
        add_action('woocommerce_add_to_cart_validation', array($this, 'validate_subscription_cart'), 10, 3);
        add_action('woocommerce_before_cart', array($this, 'validate_cart_subscription_rules'));
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_subscription_rules'));
        add_filter('woocommerce_cart_item_quantity', array($this, 'limit_subscription_quantity'), 10, 3);

        // Additional cart hooks for real-time validation
        add_action('woocommerce_cart_loaded_from_session', array($this, 'validate_cart_on_load'));
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
     * Get BNA API frequency mapping
     * @return array
     */
    public static function get_bna_frequency_map() {
        return self::BNA_FREQUENCY_MAP;
    }

    /**
     * Convert WooCommerce frequency to BNA API format
     * @param string $wc_frequency
     * @return string
     */
    public static function convert_frequency_to_bna($wc_frequency) {
        return self::BNA_FREQUENCY_MAP[$wc_frequency] ?? 'MONTHLY';
    }

    /**
     * Get available statuses
     * @return array
     */
    public static function get_statuses() {
        return self::STATUSES;
    }

    /**
     * Check if subscriptions are enabled - WITH DEBUG
     * @return bool
     */
    public static function is_enabled() {
        $enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';

        bna_debug('=== BNA_Subscriptions::is_enabled() ===', array(
            'option_value' => get_option('bna_smart_payment_enable_subscriptions', 'no'),
            'is_enabled' => $enabled
        ));

        return $enabled;
    }

    /**
     * Check if product is subscription - Updated to use meta fields + DEBUG
     * @param WC_Product|int $product
     * @return bool
     */
    public static function is_subscription_product($product) {
        bna_debug('=== BNA_Subscriptions::is_subscription_product() START ===', array(
            'product_input' => is_numeric($product) ? "ID: $product" : (is_object($product) ? get_class($product) : gettype($product))
        ));

        if (is_numeric($product)) {
            $product = wc_get_product($product);
            bna_debug('Loaded product by ID', array(
                'product_id' => $product ? $product->get_id() : 'failed_to_load'
            ));
        }

        if (!$product) {
            bna_debug('is_subscription_product: RETURNING FALSE - no product');
            return false;
        }

        $product_id = $product->get_id();
        $meta_value = get_post_meta($product_id, '_bna_is_subscription', true);
        $is_subscription = $meta_value === 'yes';

        bna_debug('=== SUBSCRIPTION PRODUCT CHECK ===', array(
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
            'meta_value' => $meta_value,
            'is_subscription' => $is_subscription
        ));

        return $is_subscription;
    }

    /**
     * Get subscription data for product - WITH DEBUG
     * @param WC_Product|int $product
     * @return array|false
     */
    public static function get_subscription_data($product) {
        bna_debug('=== BNA_Subscriptions::get_subscription_data() START ===');

        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product || !self::is_subscription_product($product)) {
            bna_debug('get_subscription_data: RETURNING FALSE - not subscription product');
            return false;
        }

        $product_id = $product->get_id();
        $frequency = get_post_meta($product_id, '_bna_subscription_frequency', true) ?: 'monthly';
        $trial_days = absint(get_post_meta($product_id, '_bna_subscription_trial_days', true));
        $signup_fee = floatval(get_post_meta($product_id, '_bna_subscription_signup_fee', true));

        $data = array(
            'frequency' => $frequency,
            'trial_days' => $trial_days,
            'signup_fee' => $signup_fee
        );

        bna_debug('=== SUBSCRIPTION DATA RESULT ===', array(
            'product_id' => $product_id,
            'subscription_data' => $data
        ));

        return $data;
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

        bna_debug('=== ADD SUBSCRIPTION CART ITEM DATA ===', array(
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'is_subscription' => self::is_subscription_product($product)
        ));

        if (self::is_subscription_product($product)) {
            $subscription_data = self::get_subscription_data($product);
            if ($subscription_data) {
                $cart_item_data['bna_subscription'] = $subscription_data;
                bna_debug('Added subscription data to cart item', array(
                    'product_id' => $product_id,
                    'subscription_data' => $subscription_data
                ));
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
     * Enhanced subscription cart validation (BNA Rules Implementation) - WITH DEBUG
     * @param bool $passed
     * @param int $product_id
     * @param int $quantity
     * @return bool
     */
    public function validate_subscription_cart($passed, $product_id, $quantity) {
        bna_debug('=== VALIDATE SUBSCRIPTION CART START ===', array(
            'passed' => $passed,
            'product_id' => $product_id,
            'quantity' => $quantity,
            'subscriptions_enabled' => self::is_enabled()
        ));

        $product = wc_get_product($product_id);

        if (!$product || !self::is_subscription_product($product)) {
            bna_debug('Not subscription product, checking regular product validation');
            // If it's a regular product, check if cart has subscriptions
            return $this->validate_regular_product_with_subscriptions($passed, $product_id);
        }

        bna_debug('=== SUBSCRIPTION PRODUCT VALIDATION ===', array(
            'product_id' => $product_id,
            'product_name' => $product->get_name()
        ));

        // Check if subscriptions are enabled
        if (!self::is_enabled()) {
            wc_add_notice(__('Subscriptions are currently disabled.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: subscriptions disabled');
            return false;
        }

        // BNA Rule: Quantity must be 1 for subscription products
        if ($quantity > 1) {
            wc_add_notice(__('You can only purchase 1 unit of subscription products.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: quantity > 1', array('quantity' => $quantity));
            return false;
        }

        // BNA Rule: Only one subscription product in cart
        $cart_subscription_count = $this->count_subscription_products_in_cart();
        if ($cart_subscription_count > 0) {
            wc_add_notice(__('You can only have one subscription product in your cart at a time.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: multiple subscriptions', array(
                'existing_subscriptions' => $cart_subscription_count
            ));
            return false;
        }

        // BNA Rule: No regular products with subscription products
        $cart_regular_count = $this->count_regular_products_in_cart();
        if ($cart_regular_count > 0) {
            wc_add_notice(__('You cannot mix subscription products with regular products in the same order.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: mixed cart', array(
                'regular_products' => $cart_regular_count
            ));
            return false;
        }

        // Validate subscription frequency is supported by BNA
        $subscription_data = self::get_subscription_data($product);
        if ($subscription_data && !isset(self::BNA_FREQUENCY_MAP[$subscription_data['frequency']])) {
            wc_add_notice(sprintf(__('Subscription frequency "%s" is not supported.', 'bna-smart-payment'), $subscription_data['frequency']), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: unsupported frequency', array(
                'frequency' => $subscription_data['frequency']
            ));
            return false;
        }

        bna_log('Subscription product validation passed', array(
            'product_id' => $product_id,
            'quantity' => $quantity,
            'frequency' => $subscription_data['frequency'] ?? 'unknown'
        ));

        return $passed;
    }

    /**
     * Validate regular product when subscriptions are in cart - WITH DEBUG
     * @param bool $passed
     * @param int $product_id
     * @return bool
     */
    private function validate_regular_product_with_subscriptions($passed, $product_id) {
        if (!$passed) {
            bna_debug('Regular product validation already failed, returning false');
            return $passed;
        }

        $cart_subscription_count = $this->count_subscription_products_in_cart();

        bna_debug('=== REGULAR PRODUCT VS SUBSCRIPTIONS CHECK ===', array(
            'product_id' => $product_id,
            'cart_subscription_count' => $cart_subscription_count
        ));

        if ($cart_subscription_count > 0) {
            wc_add_notice(__('You cannot add regular products to a cart that contains subscription products.', 'bna-smart-payment'), 'error');
            bna_error('REGULAR PRODUCT VALIDATION FAILED: cart has subscriptions', array(
                'product_id' => $product_id,
                'subscriptions_in_cart' => $cart_subscription_count
            ));
            return false;
        }

        return $passed;
    }

    /**
     * Count subscription products in cart - WITH DEBUG
     * @return int
     */
    private function count_subscription_products_in_cart() {
        if (empty(WC()->cart)) {
            bna_debug('count_subscription_products_in_cart: No cart available');
            return 0;
        }

        $count = 0;
        $subscription_products = array();

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['bna_subscription'])) {
                $count++;
                $subscription_products[] = array(
                    'product_id' => $cart_item['data']->get_id(),
                    'product_name' => $cart_item['data']->get_name(),
                    'quantity' => $cart_item['quantity']
                );
            }
        }

        bna_debug('=== SUBSCRIPTION PRODUCTS IN CART ===', array(
            'count' => $count,
            'products' => $subscription_products
        ));

        return $count;
    }

    /**
     * Count regular (non-subscription) products in cart - WITH DEBUG
     * @return int
     */
    private function count_regular_products_in_cart() {
        if (empty(WC()->cart)) {
            bna_debug('count_regular_products_in_cart: No cart available');
            return 0;
        }

        $count = 0;
        $regular_products = array();

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!isset($cart_item['bna_subscription'])) {
                $count++;
                $regular_products[] = array(
                    'product_id' => $cart_item['data']->get_id(),
                    'product_name' => $cart_item['data']->get_name(),
                    'quantity' => $cart_item['quantity']
                );
            }
        }

        bna_debug('=== REGULAR PRODUCTS IN CART ===', array(
            'count' => $count,
            'products' => $regular_products
        ));

        return $count;
    }

    /**
     * Validate cart subscription rules on cart page - WITH DEBUG
     */
    public function validate_cart_subscription_rules() {
        bna_debug('=== VALIDATE CART SUBSCRIPTION RULES START ===', array(
            'subscriptions_enabled' => self::is_enabled()
        ));

        if (!self::is_enabled()) {
            bna_debug('Cart subscription rules validation skipped - subscriptions disabled');
            return;
        }

        $subscription_count = $this->count_subscription_products_in_cart();
        $regular_count = $this->count_regular_products_in_cart();

        bna_debug('=== CART SUBSCRIPTION RULES CHECK ===', array(
            'subscription_count' => $subscription_count,
            'regular_count' => $regular_count
        ));

        // Check for mixed cart (subscription + regular products)
        if ($subscription_count > 0 && $regular_count > 0) {
            wc_add_notice(__('Your cart contains both subscription and regular products. Please remove one type to proceed.', 'bna-smart-payment'), 'error');
            bna_error('CART VALIDATION FAILED: mixed cart', array(
                'subscriptions' => $subscription_count,
                'regulars' => $regular_count
            ));
        }

        // Check for multiple subscriptions
        if ($subscription_count > 1) {
            wc_add_notice(__('You can only have one subscription product in your cart. Please remove additional subscription products.', 'bna-smart-payment'), 'error');
            bna_error('CART VALIDATION FAILED: multiple subscriptions', array(
                'subscription_count' => $subscription_count
            ));
        }
    }

    /**
     * Validate subscription rules during checkout - WITH DEBUG
     */
    public function validate_checkout_subscription_rules() {
        bna_debug('=== VALIDATE CHECKOUT SUBSCRIPTION RULES START ===', array(
            'subscriptions_enabled' => self::is_enabled(),
            'is_user_logged_in' => is_user_logged_in(),
            'registration_enabled' => WC()->checkout()->is_registration_enabled()
        ));

        if (!self::is_enabled()) {
            bna_debug('Checkout subscription rules validation skipped - subscriptions disabled');
            return;
        }

        $this->validate_cart_subscription_rules();

        // Additional checkout validation
        $subscription_count = $this->count_subscription_products_in_cart();
        if ($subscription_count > 0) {
            bna_debug('=== CHECKOUT SUBSCRIPTION VALIDATION ===', array(
                'subscription_count' => $subscription_count,
                'user_logged_in' => is_user_logged_in(),
                'registration_enabled' => WC()->checkout()->is_registration_enabled()
            ));

            // Ensure customer is logged in or can create account for subscriptions
            if (!is_user_logged_in() && !WC()->checkout()->is_registration_enabled()) {
                wc_add_notice(__('You must create an account to purchase subscription products.', 'bna-smart-payment'), 'error');
                bna_error('CHECKOUT SUBSCRIPTION VALIDATION FAILED: no account creation available');
            }
        }
    }

    /**
     * Limit subscription product quantity to 1
     * @param int $quantity
     * @param string $cart_item_key
     * @param array $cart_item
     * @return int
     */
    public function limit_subscription_quantity($quantity, $cart_item_key, $cart_item) {
        if (isset($cart_item['bna_subscription'])) {
            bna_debug('Limiting subscription quantity to 1', array(
                'original_quantity' => $quantity,
                'product_id' => $cart_item['data']->get_id()
            ));
            return 1; // Force quantity to 1 for subscription products
        }
        return $quantity;
    }

    /**
     * Validate cart when loaded from session
     */
    public function validate_cart_on_load() {
        if (!self::is_enabled() || is_admin()) {
            return;
        }

        $subscription_count = $this->count_subscription_products_in_cart();
        $regular_count = $this->count_regular_products_in_cart();

        // Log cart state for debugging
        bna_debug('Cart loaded with subscription validation', array(
            'subscription_products' => $subscription_count,
            'regular_products' => $regular_count,
            'total_items' => WC()->cart->get_cart_contents_count()
        ));

        // Clean up invalid combinations silently (without notices)
        if ($subscription_count > 1) {
            $this->remove_excess_subscription_products();
        }

        // Fix quantity issues
        $this->fix_subscription_quantities();
    }

    /**
     * Remove excess subscription products (keep only first)
     */
    private function remove_excess_subscription_products() {
        $subscription_found = false;
        $items_to_remove = array();

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['bna_subscription'])) {
                if ($subscription_found) {
                    $items_to_remove[] = $cart_item_key;
                } else {
                    $subscription_found = true;
                }
            }
        }

        foreach ($items_to_remove as $cart_item_key) {
            WC()->cart->remove_cart_item($cart_item_key);
        }

        if (!empty($items_to_remove)) {
            bna_log('Removed excess subscription products from cart', array(
                'removed_items' => count($items_to_remove)
            ));
        }
    }

    /**
     * Fix subscription product quantities (set to 1)
     */
    private function fix_subscription_quantities() {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['bna_subscription']) && $cart_item['quantity'] > 1) {
                WC()->cart->set_quantity($cart_item_key, 1);
            }
        }
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
                    'subscription_data' => $subscription_data,
                    'bna_frequency' => self::convert_frequency_to_bna($subscription_data['frequency'])
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
                'note' => 'Using product meta fields',
                'bna_frequencies' => array_column($subscription_items, 'bna_frequency')
            ));
        }
    }

    /**
     * Save subscription data from checkout - WITH DEBUG
     * @param int $order_id
     */
    public function save_subscription_data($order_id) {
        bna_debug('=== SAVE SUBSCRIPTION DATA START ===', array(
            'order_id' => $order_id
        ));

        if (!$order_id) {
            bna_debug('save_subscription_data: No order ID provided');
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            bna_debug('save_subscription_data: Order not found', array('order_id' => $order_id));
            return;
        }

        // Check if order contains subscription products
        $has_subscription = false;
        $subscription_items = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (self::is_subscription_product($product)) {
                $has_subscription = true;
                $subscription_items[] = array(
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name()
                );
            }
        }

        bna_debug('=== ORDER SUBSCRIPTION CHECK ===', array(
            'order_id' => $order_id,
            'has_subscription' => $has_subscription,
            'subscription_items' => $subscription_items
        ));

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
     * Check if order has subscription products - WITH DEBUG
     * @param WC_Order|int $order
     * @return bool
     */
    public static function order_has_subscription($order) {
        bna_debug('=== BNA_Subscriptions::order_has_subscription() START ===');

        if (is_numeric($order)) {
            $order = wc_get_order($order);
            bna_debug('Loaded order by ID', array(
                'order_id' => $order ? $order->get_id() : 'failed_to_load'
            ));
        }

        if (!$order) {
            bna_debug('order_has_subscription: RETURNING FALSE - no order');
            return false;
        }

        $has_subscription_meta = $order->get_meta('_bna_has_subscription', true) === 'yes';

        bna_debug('=== ORDER SUBSCRIPTION CHECK ===', array(
            'order_id' => $order->get_id(),
            'meta_value' => $order->get_meta('_bna_has_subscription', true),
            'has_subscription' => $has_subscription_meta
        ));

        return $has_subscription_meta;
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

    /**
     * Check if cart is valid for BNA checkout
     * @return bool
     */
    public static function is_cart_valid_for_checkout() {
        if (!self::is_enabled()) {
            return true;
        }

        $instance = self::get_instance();
        $subscription_count = $instance->count_subscription_products_in_cart();
        $regular_count = $instance->count_regular_products_in_cart();

        // Mixed cart is invalid
        if ($subscription_count > 0 && $regular_count > 0) {
            return false;
        }

        // Multiple subscriptions is invalid
        if ($subscription_count > 1) {
            return false;
        }

        return true;
    }
}