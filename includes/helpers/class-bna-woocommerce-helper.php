<?php
/**
 * BNA WooCommerce Helper V2
 * Enhanced with new logging system
 */

if (!defined('ABSPATH')) exit;

class BNA_WooCommerce_Helper {

    /**
     * Validate order and order key
     */
    public static function validate_order($order_id, $order_key) {
        bna_wc_debug('Validating order', [
            'order_id' => $order_id,
            'order_key_length' => strlen($order_key)
        ]);

        $order = wc_get_order($order_id);
        
        if (!$order) {
            bna_wc_error('Order not found during validation', [
                'order_id' => $order_id
            ]);
            return false;
        }

        if ($order->get_order_key() !== $order_key) {
            bna_wc_error('Order key validation failed', [
                'order_id' => $order_id,
                'provided_key_length' => strlen($order_key),
                'expected_key_length' => strlen($order->get_order_key())
            ]);
            return false;
        }

        bna_wc_debug('Order validation successful', [
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'order_total' => $order->get_total(),
            'customer_email' => $order->get_billing_email()
        ]);

        return $order;
    }

    /**
     * Get order by meta value
     */
    public static function get_order_by_meta($meta_key, $meta_value) {
        bna_wc_debug('Searching order by meta', [
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
            bna_wc_debug('No order found with meta', [
                'meta_key' => $meta_key,
                'meta_value' => $meta_value
            ]);
            return false;
        }

        $order = $orders[0];
        bna_wc_debug('Order found by meta', [
            'order_id' => $order->get_id(),
            'meta_key' => $meta_key,
            'order_status' => $order->get_status()
        ]);

        return $order;
    }

    /**
     * Add order note with BNA prefix
     */
    public static function add_order_note($order, $note, $is_customer_note = false) {
        if (!$order) {
            bna_wc_error('Cannot add order note - no order provided', [
                'note_preview' => substr($note, 0, 50)
            ]);
            return;
        }

        $formatted_note = '[BNA Smart Payment] ' . $note;
        $order->add_order_note($formatted_note, $is_customer_note);

        bna_wc_debug('Order note added', [
            'order_id' => $order->get_id(),
            'note_length' => strlen($note),
            'is_customer_note' => $is_customer_note,
            'note_preview' => substr($note, 0, 100)
        ]);
    }

    /**
     * Update order status with BNA note
     */
    public static function update_order_status($order, $status, $note = '') {
        if (!$order) {
            bna_wc_error('Cannot update order status - no order provided', [
                'target_status' => $status
            ]);
            return;
        }

        $old_status = $order->get_status();
        $formatted_note = !empty($note) ? '[BNA Smart Payment] ' . $note : 'Order status updated via BNA Smart Payment';
        
        $order->update_status($status, $formatted_note);

        bna_wc_log('Order status updated', [
            'order_id' => $order->get_id(),
            'old_status' => $old_status,
            'new_status' => $status,
            'note' => $note,
            'update_successful' => $order->get_status() === $status
        ]);
    }

    /**
     * Get payment URL for order
     */
    public static function get_payment_url($order) {
        if (!$order) {
            bna_wc_error('Cannot generate payment URL - no order provided');
            return wc_get_checkout_url();
        }

        $payment_url = add_query_arg(array(
            'bna_payment' => 'process',
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key()
        ), wc_get_checkout_url());

        bna_wc_debug('Payment URL generated', [
            'order_id' => $order->get_id(),
            'url_length' => strlen($payment_url)
        ]);

        return $payment_url;
    }

    /**
     * Check if order is paid via BNA
     */
    public static function is_bna_order($order) {
        if (!$order) {
            return false;
        }

        $is_bna = $order->get_payment_method() === 'bna_smart_payment';

        bna_wc_debug('BNA order check', [
            'order_id' => $order->get_id(),
            'payment_method' => $order->get_payment_method(),
            'is_bna_order' => $is_bna
        ]);

        return $is_bna;
    }

    /**
     * Get order currency formatted for BNA API
     */
    public static function get_order_currency($order) {
        $currency = get_woocommerce_currency(); // Default

        if ($order) {
            $order_currency = $order->get_currency();
            if ($order_currency) {
                $currency = $order_currency;
            }
        }

        bna_wc_debug('Order currency retrieved', [
            'order_id' => $order ? $order->get_id() : 'no_order',
            'currency' => $currency,
            'wc_default' => get_woocommerce_currency()
        ]);

        return $currency;
    }

    /**
     * Check if order has BNA payment token
     */
    public static function has_bna_token($order) {
        if (!$order) {
            return false;
        }

        $token = $order->get_meta('_bna_checkout_token');
        $has_token = !empty($token);

        bna_wc_debug('BNA token check', [
            'order_id' => $order->get_id(),
            'has_token' => $has_token,
            'token_length' => $has_token ? strlen($token) : 0
        ]);

        return $has_token;
    }

    /**
     * Clear BNA payment data from order
     */
    public static function clear_bna_payment_data($order) {
        if (!$order) {
            bna_wc_error('Cannot clear BNA data - no order provided');
            return;
        }

        bna_wc_debug('Clearing BNA payment data', [
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status()
        ]);

        // Clear token data
        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');
        
        // Clear other BNA data
        $order->delete_meta_data('_bna_payment_method');
        $order->delete_meta_data('_bna_payment_started_at');
        $order->delete_meta_data('_bna_customer_id');
        
        $order->save();

        bna_wc_log('BNA payment data cleared', [
            'order_id' => $order->get_id(),
            'cleared_at' => current_time('c')
        ]);
    }

    /**
     * Get order items formatted for BNA API
     */
    public static function get_formatted_order_items($order) {
        if (!$order) {
            bna_wc_error('Cannot format order items - no order provided');
            return array();
        }

        bna_wc_debug('Formatting order items for API', [
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
                'price' => (float) $order->get_item_total($item),
                'total' => (float) $order->get_line_total($item),
                'sku' => $product && $product->get_sku() ? $product->get_sku() : 'N/A'
            );

            $formatted_items[] = $formatted_item;
        }

        bna_wc_debug('Order items formatted', [
            'order_id' => $order->get_id(),
            'formatted_items_count' => count($formatted_items),
            'total_amount' => array_sum(array_column($formatted_items, 'total'))
        ]);

        return $formatted_items;
    }

    /**
     * Validate order for BNA payment processing
     */
    public static function validate_order_for_bna($order) {
        bna_wc_debug('Validating order for BNA payment', [
            'order_id' => $order ? $order->get_id() : 'null'
        ]);

        $errors = array();

        if (!$order) {
            $errors[] = 'Order not found';
            bna_wc_error('Order validation failed - no order provided');
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

        // Check if already paid
        if ($order->is_paid()) {
            $errors[] = 'Order is already paid';
        }

        $is_valid = empty($errors);

        bna_wc_log('Order validation completed', [
            'order_id' => $order->get_id(),
            'is_valid' => $is_valid,
            'errors_count' => count($errors),
            'errors' => $errors,
            'order_total' => $order->get_total(),
            'order_status' => $order->get_status(),
            'is_paid' => $order->is_paid()
        ]);

        return array(
            'valid' => $is_valid,
            'errors' => $errors
        );
    }

    /**
     * Get BNA payment timeline for order
     */
    public static function get_payment_timeline($order) {
        if (!$order) {
            return array();
        }

        bna_wc_debug('Getting payment timeline', [
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
        $payment_completed = $order->get_meta('_bna_payment_completed_at');
        if ($payment_completed) {
            $timeline[] = array(
                'event' => 'payment_completed',
                'timestamp' => $payment_completed,
                'description' => 'Payment completed'
            );
        } elseif ($order->is_paid()) {
            $timeline[] = array(
                'event' => 'payment_completed',
                'timestamp' => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time(),
                'description' => 'Payment completed'
            );
        }

        // Payment failed
        $payment_failed = $order->get_meta('_bna_payment_failed_at');
        if ($payment_failed) {
            $timeline[] = array(
                'event' => 'payment_failed',
                'timestamp' => $payment_failed,
                'description' => 'Payment failed: ' . ($order->get_meta('_bna_failure_reason') ?: 'Unknown reason')
            );
        }

        // Sort by timestamp
        usort($timeline, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        bna_wc_debug('Payment timeline generated', [
            'order_id' => $order->get_id(),
            'timeline_events' => count($timeline)
        ]);

        return $timeline;
    }

    /**
     * Get order payment method details
     */
    public static function get_payment_method_details($order) {
        if (!$order) {
            return null;
        }

        $details = [
            'method' => $order->get_payment_method(),
            'method_title' => $order->get_payment_method_title(),
            'is_bna' => self::is_bna_order($order),
            'has_token' => self::has_bna_token($order),
            'transaction_id' => $order->get_transaction_id(),
            'bna_customer_id' => $order->get_meta('_bna_customer_id'),
            'bna_transaction_id' => $order->get_meta('_bna_transaction_id')
        ];

        bna_wc_debug('Payment method details retrieved', [
            'order_id' => $order->get_id(),
            'details' => $details
        ]);

        return $details;
    }
}
