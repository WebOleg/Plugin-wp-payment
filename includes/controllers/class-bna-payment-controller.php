<?php
if (!defined('ABSPATH')) exit;

class BNA_Payment_Controller {

    private $iframe_service;

    public function __construct() {
        $this->iframe_service = new BNA_iFrame_Service();
        BNA_Logger::debug('Payment Controller initialized');
    }

    public function get_payment_data($order) {
        BNA_Logger::info('Getting payment data for order', [
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'is_paid' => $order->is_paid()
        ]);

        // Block already paid orders
        if ($order->is_paid()) {
            BNA_Logger::warning('Attempted to generate token for paid order', [
                'order_id' => $order->get_id(),
                'status' => $order->get_status()
            ]);
            return false;
        }

        // Check for valid existing token
        $existing_token = $this->get_existing_token($order);
        
        if ($existing_token && $this->is_token_valid($existing_token, $order)) {
            BNA_Logger::info('Using existing valid token', [
                'order_id' => $order->get_id()
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

    private function is_token_valid($token, $order) {
        if (empty($token)) return false;
        
        // Check token age (30 minutes max)
        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        if ($generated_at) {
            $age_minutes = (current_time('timestamp') - $generated_at) / 60;
            if ($age_minutes > 30) {
                BNA_Logger::debug('Token expired', [
                    'order_id' => $order->get_id(),
                    'age_minutes' => $age_minutes
                ]);
                return false;
            }
        }
        
        return true;
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
        
        // Store new token
        $order->add_meta_data('_bna_checkout_token', $token);
        $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
        $order->save();

        BNA_Logger::info('New token generated', [
            'order_id' => $order->get_id(),
            'token_length' => strlen($token)
        ]);

        return array(
            'token' => $token,
            'iframe_url' => $this->iframe_service->get_iframe_url($token)
        );
    }
}
