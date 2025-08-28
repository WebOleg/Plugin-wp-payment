<?php
/**
 * BNA URL Handler V2 - Clean URL structure for referer matching
 * Updated to use new logging system
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
        $payment_url = home_url('/bna-payment/' . $order->get_id() . '/' . $order->get_order_key() . '/');
        
        bna_wc_debug('Payment URL generated', [
            'order_id' => $order->get_id(),
            'url' => $payment_url
        ]);
        
        return $payment_url;
    }

    /**
     * Check if this is payment request (both old and new format)
     */
    public static function is_payment_request() {
        // Support both URL formats
        if (isset($_GET[self::PAYMENT_ACTION]) && $_GET[self::PAYMENT_ACTION] === self::PROCESS_VALUE) {
            bna_wc_debug('Payment request detected via query parameters');
            return true;
        }
        
        // Check clean URL format: /bna-payment/ORDER_ID/ORDER_KEY/
        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        $is_clean_format = preg_match('/^bna-payment\/\d+\/[a-zA-Z0-9_]+\/?$/', $request_uri);
        
        if ($is_clean_format) {
            bna_wc_debug('Payment request detected via clean URL format', [
                'request_uri' => $request_uri
            ]);
        }
        
        return $is_clean_format;
    }

    /**
     * Get order from request (both URL formats)
     */
    public static function get_order_from_request() {
        // Try query parameters first (old format)
        if (isset($_GET['order_id']) && isset($_GET['order_key'])) {
            $order_id = intval($_GET['order_id']);
            $order_key = sanitize_text_field($_GET['order_key']);
            
            bna_wc_debug('Using query parameters for order lookup', [
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
            
            bna_wc_debug('Using clean URL format for order lookup', [
                'order_id' => $order_id,
                'order_key_length' => strlen($order_key),
                'request_uri' => $request_uri
            ]);
            
            return BNA_WooCommerce_Helper::validate_order($order_id, $order_key);
        }

        bna_wc_error('Could not extract order from request', [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'get_params' => array_keys($_GET)
        ]);
        
        return false;
    }

    public static function redirect_to_checkout() {
        bna_wc_debug('Redirecting to checkout page');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Get current request info for debugging
     */
    public static function get_request_info() {
        return [
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'query_string' => $_SERVER['QUERY_STRING'] ?? '',
            'get_params' => $_GET,
            'is_payment_request' => self::is_payment_request()
        ];
    }
}
