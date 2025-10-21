<?php
if (!defined('ABSPATH')) {
    exit;
}

class BNA_Subscriptions {

    private static $instance = null;

    const FREQUENCIES = array(
        'daily'     => 'Daily',
        'weekly'    => 'Weekly',
        'biweekly'  => 'Bi-Weekly',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        'biannual'  => 'Bi-Annual',
        'annual'    => 'Annual'
    );

    const BNA_FREQUENCY_MAP = array(
        'daily'     => 'DAILY',
        'weekly'    => 'WEEKLY',
        'biweekly'  => 'BIWEEKLY',
        'monthly'   => 'MONTHLY',
        'quarterly' => 'QUARTERLY',
        'biannual'  => 'BIANNUAL',
        'annual'    => 'ANNUAL'
    );

    const STATUSES = array(
        'new'       => 'New',
        'active'    => 'Active',
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'expired'   => 'Expired',
        'failed'    => 'Failed'
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        bna_log('BNA Subscriptions initialized', array(
            'frequencies' => count(self::FREQUENCIES),
            'statuses' => count(self::STATUSES),
            'using_meta_fields' => true,
            'validation_enabled' => true,
            'trial_period_support' => true
        ));
    }

    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_filter('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 3);
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_subscription_data'), 20);

        add_action('woocommerce_add_to_cart_validation', array($this, 'validate_subscription_cart'), 10, 3);
        add_action('woocommerce_before_cart', array($this, 'validate_cart_subscription_rules'));
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_subscription_rules'));
        add_filter('woocommerce_cart_item_quantity', array($this, 'limit_subscription_quantity'), 10, 3);

        add_action('woocommerce_cart_loaded_from_session', array($this, 'validate_cart_on_load'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_thankyou', array($this, 'create_subscription_from_order'), 10);

        add_filter('woocommerce_add_cart_item_data', array($this, 'add_subscription_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_subscription_cart_item_data'), 10, 2);
    }

    public static function get_frequencies() {
        return self::FREQUENCIES;
    }

    public static function get_bna_frequency_map() {
        return self::BNA_FREQUENCY_MAP;
    }

    public static function convert_frequency_to_bna($wc_frequency) {
        return self::BNA_FREQUENCY_MAP[$wc_frequency] ?? 'MONTHLY';
    }

    public static function get_statuses() {
        return self::STATUSES;
    }

    public static function is_enabled() {
        $enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';

        bna_debug('=== BNA_Subscriptions::is_enabled() ===', array(
            'option_value' => get_option('bna_smart_payment_enable_subscriptions', 'no'),
            'is_enabled' => $enabled
        ));

        return $enabled;
    }

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
        $length_type = get_post_meta($product_id, '_bna_subscription_length_type', true) ?: 'unlimited';
        $num_payments = absint(get_post_meta($product_id, '_bna_subscription_num_payments', true));
        
        // === GET TRIAL PERIOD DATA - NEW ===
        $enable_trial = get_post_meta($product_id, '_bna_enable_trial', true) === 'yes';
        $trial_length = absint(get_post_meta($product_id, '_bna_trial_length', true));
        // === END TRIAL PERIOD DATA ===

        $data = array(
            'frequency' => $frequency,
            'length_type' => $length_type,
            'num_payments' => $num_payments,
            'enable_trial' => $enable_trial,
            'trial_length' => $trial_length
        );

        bna_debug('=== SUBSCRIPTION DATA RESULT ===', array(
            'product_id' => $product_id,
            'subscription_data' => $data
        ));

        return $data;
    }

    // === CALCULATE START PAYMENT DATE WITH TRIAL - NEW ===
    /**
     * Calculate the start payment date for subscription
     * If trial period is enabled, the start date will be today + trial_length days
     * Otherwise, the start date will be today
     * 
     * @param array $subscription_data Subscription data with trial info
     * @return string Formatted date string (Y-m-d H:i:s)
     */
    public static function calculate_start_payment_date($subscription_data) {
        $current_time = current_time('timestamp');
        
        // Check if trial period is enabled
        if (!empty($subscription_data['enable_trial']) && !empty($subscription_data['trial_length'])) {
            $trial_days = absint($subscription_data['trial_length']);
            
            // Add trial days to current date
            $start_timestamp = strtotime("+{$trial_days} days", $current_time);
            
            bna_log('Start payment date calculated with trial period', array(
                'current_date' => date('Y-m-d H:i:s', $current_time),
                'trial_days' => $trial_days,
                'start_payment_date' => date('Y-m-d H:i:s', $start_timestamp)
            ));
        } else {
            // No trial - start immediately
            $start_timestamp = $current_time;
            
            bna_debug('Start payment date - no trial period', array(
                'start_payment_date' => date('Y-m-d H:i:s', $start_timestamp)
            ));
        }
        
        // Return formatted date for BNA API
        return date('Y-m-d H:i:s', $start_timestamp);
    }
    // === END CALCULATE START PAYMENT DATE ===

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

    public function display_subscription_cart_item_data($item_data, $cart_item) {
        if (isset($cart_item['bna_subscription'])) {
            $subscription = $cart_item['bna_subscription'];

            // === DISPLAY TRIAL PERIOD IN CART - NEW ===
            if (!empty($subscription['enable_trial']) && !empty($subscription['trial_length'])) {
                $item_data[] = array(
                    'name' => __('Trial Period', 'bna-smart-payment'),
                    'value' => sprintf(
                        _n('%d day free', '%d days free', $subscription['trial_length'], 'bna-smart-payment'),
                        $subscription['trial_length']
                    ),
                    'display' => ''
                );
            }
            // === END TRIAL PERIOD DISPLAY ===

            $frequency_label = self::FREQUENCIES[$subscription['frequency']] ?? $subscription['frequency'];
            $item_data[] = array(
                'name' => __('Billing', 'bna-smart-payment'),
                'value' => $frequency_label,
                'display' => ''
            );

            if ($subscription['length_type'] === 'limited' && $subscription['num_payments'] > 0) {
                $item_data[] = array(
                    'name' => __('Duration', 'bna-smart-payment'),
                    'value' => sprintf(_n('%d payment', '%d payments', $subscription['num_payments'], 'bna-smart-payment'), $subscription['num_payments']),
                    'display' => ''
                );
            }
        }

        return $item_data;
    }

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
            return $this->validate_regular_product_with_subscriptions($passed, $product_id);
        }

        bna_debug('=== SUBSCRIPTION PRODUCT VALIDATION ===', array(
            'product_id' => $product_id,
            'product_name' => $product->get_name()
        ));

        if (!self::is_enabled()) {
            wc_add_notice(__('Subscriptions are currently disabled.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: subscriptions disabled');
            return false;
        }

        if ($quantity > 1) {
            wc_add_notice(__('You can only purchase 1 unit of subscription products.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: quantity > 1', array('quantity' => $quantity));
            return false;
        }

        $cart_subscription_count = $this->count_subscription_products_in_cart();
        if ($cart_subscription_count > 0) {
            wc_add_notice(__('You can only have one subscription product in your cart at a time.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: multiple subscriptions', array(
                'existing_subscriptions' => $cart_subscription_count
            ));
            return false;
        }

        $cart_regular_count = $this->count_regular_products_in_cart();
        if ($cart_regular_count > 0) {
            wc_add_notice(__('You cannot mix subscription products with regular products in the same order.', 'bna-smart-payment'), 'error');
            bna_error('SUBSCRIPTION CART VALIDATION FAILED: mixed cart', array(
                'regular_products' => $cart_regular_count
            ));
            return false;
        }

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
            'frequency' => $subscription_data['frequency'] ?? 'unknown',
            'trial_enabled' => !empty($subscription_data['enable_trial']),
            'trial_days' => $subscription_data['trial_length'] ?? 0
        ));

        return $passed;
    }

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

        if ($subscription_count > 0 && $regular_count > 0) {
            wc_add_notice(__('Your cart contains both subscription and regular products. Please remove one type to proceed.', 'bna-smart-payment'), 'error');
            bna_error('CART VALIDATION FAILED: mixed cart', array(
                'subscriptions' => $subscription_count,
                'regulars' => $regular_count
            ));
        }

        if ($subscription_count > 1) {
            wc_add_notice(__('You can only have one subscription product in your cart. Please remove additional subscription products.', 'bna-smart-payment'), 'error');
            bna_error('CART VALIDATION FAILED: multiple subscriptions', array(
                'subscription_count' => $subscription_count
            ));
        }
    }

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

        $subscription_count = $this->count_subscription_products_in_cart();
        if ($subscription_count > 0) {
            bna_debug('=== CHECKOUT SUBSCRIPTION VALIDATION ===', array(
                'subscription_count' => $subscription_count,
                'user_logged_in' => is_user_logged_in(),
                'registration_enabled' => WC()->checkout()->is_registration_enabled()
            ));

            if (!is_user_logged_in() && !WC()->checkout()->is_registration_enabled()) {
                wc_add_notice(__('You must create an account to purchase subscription products.', 'bna-smart-payment'), 'error');
                bna_error('CHECKOUT SUBSCRIPTION VALIDATION FAILED: no account creation available');
            }
        }
    }

    public function limit_subscription_quantity($quantity, $cart_item_key, $cart_item) {
        if (isset($cart_item['bna_subscription'])) {
            bna_debug('Limiting subscription quantity to 1', array(
                'original_quantity' => $quantity,
                'product_id' => $cart_item['data']->get_id()
            ));
            return 1;
        }
        return $quantity;
    }

    public function validate_cart_on_load() {
        if (!self::is_enabled() || is_admin()) {
            return;
        }

        $subscription_count = $this->count_subscription_products_in_cart();
        $regular_count = $this->count_regular_products_in_cart();

        bna_debug('Cart loaded with subscription validation', array(
            'subscription_products' => $subscription_count,
            'regular_products' => $regular_count,
            'total_items' => WC()->cart->get_cart_contents_count()
        ));

        if ($subscription_count > 1) {
            $this->remove_excess_subscription_products();
        }

        $this->fix_subscription_quantities();
    }

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

    private function fix_subscription_quantities() {
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['bna_subscription']) && $cart_item['quantity'] > 1) {
                WC()->cart->set_quantity($cart_item_key, 1);
            }
        }
    }

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
                
                // === CALCULATE START PAYMENT DATE WITH TRIAL - NEW ===
                $start_payment_date = self::calculate_start_payment_date($subscription_data);
                // === END CALCULATE START PAYMENT DATE ===
                
                $subscription_items[] = array(
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total(),
                    'subscription_data' => $subscription_data,
                    'bna_frequency' => self::convert_frequency_to_bna($subscription_data['frequency']),
                    'start_payment_date' => $start_payment_date,
                    'trial_enabled' => !empty($subscription_data['enable_trial']),
                    'trial_days' => $subscription_data['trial_length'] ?? 0
                );
            }
        }

        if (!empty($subscription_items)) {
            $order->update_meta_data('_bna_subscription_created', current_time('timestamp'));
            $order->update_meta_data('_bna_subscription_status', 'new');
            $order->update_meta_data('_bna_subscription_items', $subscription_items);
            $order->save();

            bna_log('Subscription created from order', array(
                'order_id' => $order_id,
                'status' => 'new',
                'items_count' => count($subscription_items),
                'note' => 'Using product meta fields with trial period support',
                'bna_frequencies' => array_column($subscription_items, 'bna_frequency'),
                'start_payment_dates' => array_column($subscription_items, 'start_payment_date'),
                'trial_enabled' => array_column($subscription_items, 'trial_enabled')
            ));
        }
    }

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

        $has_subscription = false;
        $subscription_items = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (self::is_subscription_product($product)) {
                $has_subscription = true;
                $subscription_data = self::get_subscription_data($product);
                $subscription_items[] = array(
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'trial_enabled' => !empty($subscription_data['enable_trial']),
                    'trial_days' => $subscription_data['trial_length'] ?? 0
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
                'order_id' => $order_id,
                'trial_info' => $subscription_items
            ));
        }
    }

    public function handle_order_status_change($order_id, $old_status, $new_status) {
        $order = wc_get_order($order_id);
        if (!$order || !$order->get_meta('_bna_subscription_created')) {
            return;
        }

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

    public function get_user_subscriptions($user_id) {
        if (!$user_id) {
            return array();
        }

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

    private function calculate_next_payment_date($order, $subscription_items) {
        if (empty($subscription_items)) {
            return null;
        }

        $first_item = reset($subscription_items);
        
        // === USE START PAYMENT DATE IF AVAILABLE (TRIAL PERIOD) - NEW ===
        if (!empty($first_item['start_payment_date'])) {
            // If we have a start payment date (from trial), use that
            $start_date = strtotime($first_item['start_payment_date']);
            
            // If start date is in the future, that's the next payment
            if ($start_date > time()) {
                return date('Y-m-d', $start_date);
            }
            
            // Otherwise, calculate from the start date
            $last_payment = new DateTime($first_item['start_payment_date']);
        } else {
            // No trial period, use order creation date
            $last_payment = $order->get_date_created();
        }
        // === END START PAYMENT DATE CHECK ===
        
        $frequency = $first_item['subscription_data']['frequency'] ?? 'monthly';

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

    public static function get_frequency_label($frequency) {
        return self::FREQUENCIES[$frequency] ?? $frequency;
    }

    public static function get_status_label($status) {
        return self::STATUSES[$status] ?? $status;
    }

    public static function is_cart_valid_for_checkout() {
        if (!self::is_enabled()) {
            return true;
        }

        $instance = self::get_instance();
        $subscription_count = $instance->count_subscription_products_in_cart();
        $regular_count = $instance->count_regular_products_in_cart();

        if ($subscription_count > 0 && $regular_count > 0) {
            return false;
        }

        if ($subscription_count > 1) {
            return false;
        }

        return true;
    }
}
