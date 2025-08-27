<?php
/**
 * BNA Payment Controller - Use HEAD validation instead of GET
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Payment_Controller {

    private $iframe_service;

    public function __construct() {
        $this->iframe_service = new BNA_iFrame_Service();
        BNA_Logger::debug('Payment Controller initialized');
    }

    public function get_payment_data($order) {
        BNA_Logger::info('Getting payment data for order', [
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total()
        ]);

        // Check existing token with HEAD request (works correctly)
        $existing_token = $this->get_existing_token($order);
        
        if ($existing_token && $this->is_token_accessible_via_head($existing_token, $order->get_id())) {
            BNA_Logger::info('Using existing token validated via HEAD request', [
                'order_id' => $order->get_id(),
                'token_age_minutes' => $this->get_token_age_minutes($order)
            ]);
            
            return array(
                'token' => $existing_token,
                'iframe_url' => $this->iframe_service->get_iframe_url($existing_token)
            );
        }

        return $this->generate_new_token($order);
    }

    private function get_existing_token($order) {
        return $order->get_meta('_bna_checkout_token');
    }

    /**
     * Test token with HEAD request (this works correctly)
     */
    private function is_token_accessible_via_head($token, $order_id) {
        if (empty($token)) return false;

        $iframe_url = $this->iframe_service->get_iframe_url($token);
        
        BNA_Logger::debug('Testing token with HEAD request', [
            'order_id' => $order_id,
            'iframe_url' => $iframe_url
        ]);

        $response = wp_remote_head($iframe_url, [
            'timeout' => 10,
            'sslverify' => false,
            'user-agent' => 'BNA-WordPress-Plugin/1.0.0'
        ]);

        if (is_wp_error($response)) {
            BNA_Logger::error('HEAD request failed', [
                'order_id' => $order_id,
                'error' => $response->get_error_message()
            ]);
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $is_accessible = $status_code == 200;

        BNA_Logger::info('HEAD validation result', [
            'order_id' => $order_id,
            'status_code' => $status_code,
            'is_accessible' => $is_accessible
        ]);

        return $is_accessible;
    }

    private function generate_new_token($order) {
        BNA_Logger::info('Generating new token', ['order_id' => $order->get_id()]);

        // Clear old token
        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');
        $order->save();

        $response = $this->iframe_service->generate_checkout_token($order);
        
        if (is_wp_error($response) || !isset($response['token'])) {
            BNA_Logger::error('Token generation failed', ['order_id' => $order->get_id()]);
            return false;
        }

        $token = $response['token'];
        
        // Validate new token with HEAD request
        if (!$this->is_token_accessible_via_head($token, $order->get_id())) {
            BNA_Logger::error('New token failed HEAD validation', ['order_id' => $order->get_id()]);
            return false;
        }

        // Store valid token
        $order->add_meta_data('_bna_checkout_token', $token);
        $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
        $order->save();

        BNA_Logger::info('New token generated and validated', [
            'order_id' => $order->get_id(),
            'token_length' => strlen($token)
        ]);

        return array(
            'token' => $token,
            'iframe_url' => $this->iframe_service->get_iframe_url($token)
        );
    }

    private function get_token_age_minutes($order) {
        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        if (!$generated_at) return null;
        return round((current_time('timestamp') - $generated_at) / 60, 1);
    }
}
