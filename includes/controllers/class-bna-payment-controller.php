<?php
/**
 * BNA Payment Controller
 * Simplified payment processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Payment_Controller {

    private $iframe_service;

    public function __construct() {
        $this->iframe_service = new BNA_iFrame_Service();
    }

    /**
     * Get payment data for order
     */
    public function get_payment_data($order) {
        $token_data = $this->get_valid_token($order);
        
        if (!$token_data) {
            $token_data = $this->generate_new_token($order);
        }

        return $token_data;
    }

    /**
     * Check for existing valid token
     */
    private function get_valid_token($order) {
        $token = $order->get_meta('_bna_checkout_token');
        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        
        if (!$token || !$generated_at) {
            return false;
        }

        // Check if token is still valid (25 minutes)
        if (current_time('timestamp') - $generated_at > 25 * 60) {
            return false;
        }

        return array(
            'token' => $token,
            'iframe_url' => $this->iframe_service->get_iframe_url($token)
        );
    }

    /**
     * Generate new token
     */
    private function generate_new_token($order) {
        $response = $this->iframe_service->generate_checkout_token($order);
        
        if (is_wp_error($response) || !isset($response['token'])) {
            return false;
        }

        $token = $response['token'];
        
        // Store token in order
        $order->add_meta_data('_bna_checkout_token', $token);
        $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
        $order->save();

        return array(
            'token' => $token,
            'iframe_url' => $this->iframe_service->get_iframe_url($token)
        );
    }
}
