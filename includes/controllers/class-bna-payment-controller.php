<?php
/**
 * BNA Payment Controller V2
 * Enhanced with new logging system
 */

if (!defined('ABSPATH')) exit;

class BNA_Payment_Controller {

    private $iframe_service;

    public function __construct() {
        $this->iframe_service = new BNA_iFrame_Service();
        bna_api_debug('Payment Controller initialized');
    }

    public function get_payment_data($order) {
        bna_wc_log('Getting payment data for order', [
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'order_total' => $order->get_total(),
            'customer_email' => $order->get_billing_email(),
            'is_paid' => $order->is_paid()
        ]);

        // Block already paid orders
        if ($order->is_paid()) {
            bna_wc_error('Attempted to generate token for paid order', [
                'order_id' => $order->get_id(),
                'status' => $order->get_status(),
                'date_paid' => $order->get_date_paid() ? $order->get_date_paid()->format('c') : 'unknown'
            ]);
            return false;
        }

        // Check for valid existing token
        $existing_token = $this->get_existing_token($order);
        
        if ($existing_token && $this->is_token_valid($existing_token, $order)) {
            bna_api_log('Using existing valid token', [
                'order_id' => $order->get_id(),
                'token_age_minutes' => $this->get_token_age_minutes($order),
                'reused' => true
            ]);
            
            return array(
                'token' => $existing_token,
                'iframe_url' => $this->iframe_service->get_iframe_url($existing_token),
                'source' => 'existing'
            );
        }

        // Generate new token if existing is invalid or missing
        return $this->generate_new_token($order);
    }

    private function get_existing_token($order) {
        $token = $order->get_meta('_bna_checkout_token');
        
        bna_api_debug('Checking existing token', [
            'order_id' => $order->get_id(),
            'has_token' => !empty($token),
            'token_length' => !empty($token) ? strlen($token) : 0
        ]);
        
        return $token;
    }

    private function is_token_valid($token, $order) {
        if (empty($token)) {
            bna_api_debug('Token invalid - empty', ['order_id' => $order->get_id()]);
            return false;
        }
        
        // Check token age (30 minutes max)
        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        if (!$generated_at) {
            bna_api_debug('Token invalid - no generation timestamp', [
                'order_id' => $order->get_id()
            ]);
            return false;
        }
        
        $age_minutes = (current_time('timestamp') - $generated_at) / 60;
        $is_valid = $age_minutes <= 30;
        
        bna_api_debug('Token validity check', [
            'order_id' => $order->get_id(),
            'age_minutes' => round($age_minutes, 2),
            'max_age_minutes' => 30,
            'is_valid' => $is_valid
        ]);
        
        if (!$is_valid) {
            bna_api_debug('Token expired', [
                'order_id' => $order->get_id(),
                'age_minutes' => round($age_minutes, 2)
            ]);
        }
        
        return $is_valid;
    }

    private function get_token_age_minutes($order) {
        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        if (!$generated_at) {
            return null;
        }
        
        return round((current_time('timestamp') - $generated_at) / 60, 2);
    }

    private function generate_new_token($order) {
        bna_api_log('Generating new checkout token', [
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'customer_email' => $order->get_billing_email()
        ]);

        // Clear old token data
        $this->clear_existing_token_data($order);

        // Generate new token via iFrame service
        $response = $this->iframe_service->generate_checkout_token($order);
        
        if (is_wp_error($response)) {
            bna_api_error('Token generation failed', [
                'order_id' => $order->get_id(),
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message()
            ]);
            return false;
        }

        if (!isset($response['token'])) {
            bna_api_error('Token generation response invalid', [
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response),
                'has_token' => false
            ]);
            return false;
        }

        $token = $response['token'];
        
        // Store new token data
        $this->store_token_data($order, $token);

        bna_api_log('New checkout token generated successfully', [
            'order_id' => $order->get_id(),
            'token_length' => strlen($token),
            'stored_at' => current_time('c')
        ]);

        bna_wc_log('Payment data prepared', [
            'order_id' => $order->get_id(),
            'has_token' => true,
            'source' => 'new'
        ]);

        return array(
            'token' => $token,
            'iframe_url' => $this->iframe_service->get_iframe_url($token),
            'source' => 'new'
        );
    }

    private function clear_existing_token_data($order) {
        bna_api_debug('Clearing existing token data', [
            'order_id' => $order->get_id()
        ]);

        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');
        $order->save();

        bna_api_debug('Token data cleared', [
            'order_id' => $order->get_id()
        ]);
    }

    private function store_token_data($order, $token) {
        $timestamp = current_time('timestamp');
        
        bna_api_debug('Storing new token data', [
            'order_id' => $order->get_id(),
            'token_length' => strlen($token),
            'timestamp' => $timestamp
        ]);

        $order->add_meta_data('_bna_checkout_token', $token);
        $order->add_meta_data('_bna_checkout_generated_at', $timestamp);
        $order->save();

        bna_api_debug('Token data stored successfully', [
            'order_id' => $order->get_id()
        ]);
    }

    /**
     * Validate order for payment processing
     */
    public function validate_order_for_payment($order) {
        $validation_errors = [];

        bna_wc_debug('Validating order for payment processing', [
            'order_id' => $order->get_id(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'payment_method' => $order->get_payment_method()
        ]);

        // Check if order is already paid
        if ($order->is_paid()) {
            $validation_errors[] = 'Order is already paid';
        }

        // Check order total
        if ($order->get_total() <= 0) {
            $validation_errors[] = 'Order total must be greater than 0';
        }

        // Check customer email
        if (empty($order->get_billing_email())) {
            $validation_errors[] = 'Customer email is required';
        }

        // Check order status
        $allowed_statuses = array('pending', 'on-hold', 'failed');
        if (!in_array($order->get_status(), $allowed_statuses)) {
            $validation_errors[] = 'Order status not valid for payment processing';
        }

        $is_valid = empty($validation_errors);

        bna_wc_log('Order validation completed', [
            'order_id' => $order->get_id(),
            'is_valid' => $is_valid,
            'errors_count' => count($validation_errors),
            'errors' => $validation_errors
        ]);

        return [
            'valid' => $is_valid,
            'errors' => $validation_errors
        ];
    }

    /**
     * Get payment statistics for order
     */
    public function get_payment_stats($order) {
        $stats = [
            'order_id' => $order->get_id(),
            'has_token' => !empty($this->get_existing_token($order)),
            'token_age_minutes' => $this->get_token_age_minutes($order),
            'token_valid' => false,
            'payment_attempts' => 0,
            'last_attempt' => null
        ];

        $token = $this->get_existing_token($order);
        if ($token) {
            $stats['token_valid'] = $this->is_token_valid($token, $order);
        }

        // Count payment attempts from order notes
        $notes = $order->get_customer_order_notes();
        foreach ($notes as $note) {
            if (strpos($note->comment_content, 'BNA payment') !== false) {
                $stats['payment_attempts']++;
                if (!$stats['last_attempt']) {
                    $stats['last_attempt'] = $note->comment_date;
                }
            }
        }

        bna_wc_debug('Payment stats calculated', $stats);

        return $stats;
    }
}
