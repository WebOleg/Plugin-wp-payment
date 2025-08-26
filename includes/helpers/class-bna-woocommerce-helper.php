<?php
/**
 * BNA Smart Payment WooCommerce Helper
 * 
 * Helper functions for WooCommerce integration
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
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_order_key() !== $order_key) {
            return false;
        }

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

        return !empty($orders) ? $orders[0] : false;
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
            return;
        }

        $formatted_note = '[BNA Smart Payment] ' . $note;
        $order->add_order_note($formatted_note, $is_customer_note);
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
            return;
        }

        $formatted_note = !empty($note) ? '[BNA Smart Payment] ' . $note : 'Order status updated via BNA Smart Payment';
        $order->update_status($status, $formatted_note);
    }

    /**
     * Get payment URL for order
     * 
     * @param WC_Order $order
     * @return string
     */
    public static function get_payment_url($order) {
        if (!$order) {
            return wc_get_checkout_url();
        }

        return add_query_arg(array(
            'bna_payment' => 'process',
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key()
        ), wc_get_checkout_url());
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

        return $order->get_payment_method() === 'bna_smart_payment';
    }

    /**
     * Get order currency formatted for BNA API
     * 
     * @param WC_Order $order
     * @return string
     */
    public static function get_order_currency($order) {
        if (!$order) {
            return get_woocommerce_currency();
        }

        return $order->get_currency() ?: get_woocommerce_currency();
    }
}
