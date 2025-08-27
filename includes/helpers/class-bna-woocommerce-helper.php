<?php
/**
 * BNA Smart Payment WooCommerce Helper
 * 
 * Helper functions for WooCommerce integration with logging
 *
 * @package BnaSmartPayment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BNA_WooCommerce_Helper
 * Helper functions for WooCommerce operations
 */
class BNA_WooCommerce_Helper {

    /**
     * Validate order and order key
     * 
     * @param int $order_id
     * @param string $order_key
     * @return WC_Order|false
     */
    public static function validate_order($order_id, $order_key) {
        BNA_Logger::debug('Validating order', [
            'order_id' => $order_id,
            'order_key_length' => strlen($order_key)
        ]);

        $order = wc_get_order($order_id);
        
        if (!$order) {
            BNA_Logger::error('Order not found during validation', [
                'order_id' => $order_id
            ]);
            return false;
        }

        if ($order->get_order_key() !== $order_key) {
            BNA_Logger::error('Order key validation failed', [
                'order_id' => $order_id,
                'provided_key_length' => strlen($order_key),
                'expected_key_length' => strlen($order->get_order_key())
            ]);
            return false;
        }

        BNA_Logger::debug('Order validation successful', [
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'order_total' => $order->get_total()
        ]);

        return $order;
    }

    /**
     * Get order by meta value
     * 
     * @param string $meta_key
     * @param string $meta_value
     * @return WC_Order|false
     */
    public static function get_order_by_meta($meta_key, $meta_value) {
        BNA_Logger::debug('Searching order by meta', [
            'meta_key' => $meta_key,
            'meta_value_length' => strlen($meta_value)
        ]);

        $orders = wc_get_orders(array(
            'meta_query' => array(
                array(
                    'key' => $meta_key,
                    'value' => $meta_value,
                    'compare' => '='
                )
            ),
            'limit' => 1
        ));

        if (empty($orders)) {
            BNA_Logger::debug('No order found with meta', [
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ]);
            return false;
        }

        $order = $orders[0];
        BNA_Logger::debug('Order found by meta', [
            'order_id' => $order->get_id(),
            'meta_key' => $meta_key
        ]);

        return $order;
    }

    /**
     * Add order note with BNA prefix
     * 
     * @param WC_Order $order
     * @param string $note
     * @param bool $is_customer_note
     */
    public static function add_order_note($order, $note, $is_customer_note = false) {
        if (!$order) {
            BNA_Logger::error('Cannot add order note - no order provided');
            return;
        }

        $formatted_note = '[BNA Smart Payment] ' . $note;
        $order->add_order_note($formatted_note, $is_customer_note);

        BNA_Logger::debug('Order note added', [
            'order_id' => $order->get_id(),
            'note_length' => strlen($note),
            'is_customer_note' => $is_customer_note
        ]);
    }

    /**
     * Update order status with BNA note
     * 
     * @param WC_Order $order
     * @param string $status
     * @param string $note
     */
    public static function update_order_status($order, $status, $note = '') {
        if (!$order) {
            BNA_Logger::error('Cannot update order status - no order provided');
            return;
        }

        $old_status = $order->get_status();
        $formatted_note = !empty($note) ? '[BNA Smart Payment] ' . $note : 'Order status updated via BNA Smart Payment';
        
        $order->update_status($status, $formatted_note);

        BNA_Logger::info('Order status updated', [
            'order_id' => $order->get_id(),
            'old_status' => $old_status,
            'new_status' => $status,
            'note' => $note
        ]);
    }

    /**
     * Get payment URL for order
     * 
     * @param WC_Order $order
     * @return string
     */
    public static function get_payment_url($order) {
        if (!$order) {
            BNA_Logger::error('Cannot generate payment URL - no order provided');
            return wc_get_checkout_url();
        }

        $payment_url = add_query_arg(array(
            'bna_payment' => 'process',
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key()
        ), wc_get_checkout_url());

        BNA_Logger::debug('Payment URL generated', [
            'order_id' => $order->get_id(),
            'url_length' => strlen($payment_url)
        ]);

        return $payment_url;
    }

    /**
     * Check if order is paid via BNA
     * 
     * @param WC_Order $order
     * @return bool
     */
    public static function is_bna_order($order) {
        if (!$order) {
            return false;
        }

        $is_bna = $order->get_payment_method() === 'bna_smart_payment';

        BNA_Logger::debug('BNA order check', [
            'order_id' => $order->get_id(),
            'payment_method' => $order->get_payment_method(),
            'is_bna_order' => $is_bna
        ]);

        return $is_bna;
    }

    /**
     * Get order currency formatted for BNA API
     * 
     * @param WC_Order $order
     * @return string
     */
    public static function get_order_currency($order) {
        $currency = get_woocommerce_currency(); // Default

        if ($order) {
            $order_currency = $order->get_currency();
            if ($order_currency) {
                $currency = $order_currency;
            }
        }

        BNA_Logger::debug('Order currency retrieved', [
            'order_id' => $order ? $order->get_id() : 'no_order',
            'currency' => $currency
        ]);

        return $currency;
    }

    /**
     * Check if order has BNA payment token
     * 
     * @param WC_Order $order
     * @return bool
     */
    public static function has_bna_token($order) {
        if (!$order) {
            return false;
        }

        $token = $order->get_meta('_bna_checkout_token');
        $has_token = !empty($token);

        BNA_Logger::debug('BNA token check', [
            'order_id' => $order->get_id(),
            'has_token' => $has_token,
            'token_length' => $has_token ? strlen($token) : 0
        ]);

        return $has_token;
    }

    /**
     * Clear BNA payment data from order
     * 
     * @param WC_Order $order
     */
    public static function clear_bna_payment_data($order) {
        if (!$order) {
            BNA_Logger::error('Cannot clear BNA data - no order provided');
            return;
        }

        BNA_Logger::debug('Clearing BNA payment data', [
            'order_id' => $order->get_id()
        ]);

        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');
        $order->delete_meta_data('_bna_payment_method');
        $order->delete_meta_data('_bna_payment_started_at');
        $order->save();

        BNA_Logger::info('BNA payment data cleared', [
            'order_id' => $order->get_id()
        ]);
    }

    /**
     * Get order items formatted for BNA API
     * 
     * @param WC_Order $order
     * @return array
     */
    public static function get_formatted_order_items($order) {
        if (!$order) {
            BNA_Logger::error('Cannot format order items - no order provided');
            return array();
        }

        BNA_Logger::debug('Formatting order items for API', [
            'order_id' => $order->get_id(),
            'items_count' => count($order->get_items())
        ]);

        $formatted_items = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $formatted_item = array(
                'id' => $item_id,
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $order->get_item_total($item),
                'total' => $order->get_line_total($item),
                'sku' => $product ? $product->get_sku() : 'N/A'
            );

            $formatted_items[] = $formatted_item;
        }

        BNA_Logger::debug('Order items formatted', [
            'order_id' => $order->get_id(),
            'formatted_items_count' => count($formatted_items),
            'total_amount' => array_sum(array_column($formatted_items, 'total'))
        ]);

        return $formatted_items;
    }

    /**
     * Validate order for BNA payment processing
     * 
     * @param WC_Order $order
     * @return array Array with 'valid' => bool, 'errors' => array
     */
    public static function validate_order_for_bna($order) {
        BNA_Logger::debug('Validating order for BNA payment', [
            'order_id' => $order ? $order->get_id() : 'null'
        ]);

        $errors = array();

        if (!$order) {
            $errors[] = 'Order not found';
            BNA_Logger::error('Order validation failed - no order provided');
            return array('valid' => false, 'errors' => $errors);
        }

        // Check order total
        if ($order->get_total() <= 0) {
            $errors[] = 'Order total must be greater than 0';
        }

        // Check customer email
        if (empty($order->get_billing_email())) {
            $errors[] = 'Customer email is required';
        }

        // Check customer name
        if (empty($order->get_billing_first_name()) && empty($order->get_billing_last_name())) {
            $errors[] = 'Customer name is required';
        }

        // Check order status
        $allowed_statuses = array('pending', 'on-hold', 'failed');
        if (!in_array($order->get_status(), $allowed_statuses)) {
            $errors[] = 'Order status not valid for payment processing';
        }

        $is_valid = empty($errors);

        BNA_Logger::info('Order validation completed', [
            'order_id' => $order->get_id(),
            'is_valid' => $is_valid,
            'errors_count' => count($errors),
            'errors' => $errors
        ]);

        return array(
            'valid' => $is_valid,
            'errors' => $errors
        );
    }

    /**
     * Get BNA payment timeline for order
     * 
     * @param WC_Order $order
     * @return array
     */
    public static function get_payment_timeline($order) {
        if (!$order) {
            return array();
        }

        BNA_Logger::debug('Getting payment timeline', [
            'order_id' => $order->get_id()
        ]);

        $timeline = array();

        // Order created
        $timeline[] = array(
            'event' => 'order_created',
            'timestamp' => $order->get_date_created()->getTimestamp(),
            'description' => 'Order created'
        );

        // Payment started
        $payment_started = $order->get_meta('_bna_payment_started_at');
        if ($payment_started) {
            $timeline[] = array(
                'event' => 'payment_started',
                'timestamp' => $payment_started,
                'description' => 'BNA payment process started'
            );
        }

        // Token generated
        $token_generated = $order->get_meta('_bna_checkout_generated_at');
        if ($token_generated) {
            $timeline[] = array(
                'event' => 'token_generated',
                'timestamp' => $token_generated,
                'description' => 'Checkout token generated'
            );
        }

        // Payment completed
        if ($order->is_paid()) {
            $timeline[] = array(
                'event' => 'payment_completed',
                'timestamp' => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time(),
                'description' => 'Payment completed'
            );
        }

        // Sort by timestamp
        usort($timeline, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        BNA_Logger::debug('Payment timeline generated', [
            'order_id' => $order->get_id(),
            'timeline_events' => count($timeline)
        ]);

        return $timeline;
    }
}
