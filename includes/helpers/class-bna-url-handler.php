<?php
/**
 * BNA URL Handler - Clean URL structure for referer matching
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_URL_Handler {

    const PAYMENT_ACTION = 'bna_payment';
    const PROCESS_VALUE = 'process';

    /**
     * Generate payment URL that matches portal configuration
     */
    public static function get_payment_url($order) {
        // Use clean path structure instead of query parameters
        return home_url('/bna-payment/' . $order->get_id() . '/' . $order->get_order_key() . '/');
    }

    /**
     * Check if this is payment request (both old and new format)
     */
    public static function is_payment_request() {
        // Support both URL formats
        if (isset($_GET[self::PAYMENT_ACTION]) && $_GET[self::PAYMENT_ACTION] === self::PROCESS_VALUE) {
            return true;
        }
        
        // Check clean URL format: /bna-payment/ORDER_ID/ORDER_KEY/
        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        return preg_match('/^bna-payment\/\d+\/[a-zA-Z0-9_]+\/?$/', $request_uri);
    }

    /**
     * Get order from request (both URL formats)
     */
    public static function get_order_from_request() {
        // Try query parameters first (old format)
        if (isset($_GET['order_id']) && isset($_GET['order_key'])) {
            $order_id = intval($_GET['order_id']);
            $order_key = sanitize_text_field($_GET['order_key']);
            
            BNA_Logger::debug('Using query parameters', [
                'order_id' => $order_id,
                'order_key_length' => strlen($order_key)
            ]);
            
            return BNA_WooCommerce_Helper::validate_order($order_id, $order_key);
        }

        // Try clean URL format: /bna-payment/ORDER_ID/ORDER_KEY/
        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        if (preg_match('/^bna-payment\/(\d+)\/([a-zA-Z0-9_]+)\/?$/', $request_uri, $matches)) {
            $order_id = intval($matches[1]);
            $order_key = sanitize_text_field($matches[2]);
            
            BNA_Logger::debug('Using clean URL format', [
                'order_id' => $order_id,
                'order_key_length' => strlen($order_key)
            ]);
            
            return BNA_WooCommerce_Helper::validate_order($order_id, $order_key);
        }

        BNA_Logger::error('Could not extract order from request');
        return false;
    }

    public static function redirect_to_checkout() {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}
