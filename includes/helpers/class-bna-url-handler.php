<?php
/**
 * BNA URL Handler
 * Handles all URL generation and validation
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_URL_Handler {

    const PAYMENT_ACTION = 'bna_payment';
    const PROCESS_VALUE = 'process';

    /**
     * Get payment URL for order
     */
    public static function get_payment_url($order) {
        return add_query_arg(array(
            self::PAYMENT_ACTION => self::PROCESS_VALUE,
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key()
        ), home_url('/'));
    }

    /**
     * Check if current request is payment request
     */
    public static function is_payment_request() {
        return isset($_GET[self::PAYMENT_ACTION]) && $_GET[self::PAYMENT_ACTION] === self::PROCESS_VALUE;
    }

    /**
     * Get and validate order from URL parameters
     */
    public static function get_order_from_request() {
        if (!isset($_GET['order_id']) || !isset($_GET['order_key'])) {
            return false;
        }

        $order_id = intval($_GET['order_id']);
        $order_key = sanitize_text_field($_GET['order_key']);
        
        return BNA_WooCommerce_Helper::validate_order($order_id, $order_key);
    }

    /**
     * Redirect to checkout with error
     */
    public static function redirect_to_checkout() {
        wp_redirect(wc_get_checkout_url());
        exit;
    }
}
