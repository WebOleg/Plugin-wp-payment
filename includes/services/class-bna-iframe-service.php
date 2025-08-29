<?php
/**
 * BNA iFrame Service V4 - Fixed
 * Properly handles existing vs new customer logic
 */

if (!defined('ABSPATH')) exit;

class BNA_iFrame_Service {
    private $api_service;

    public function __construct() {
        $this->api_service = new BNA_API_Service();
        bna_api_debug('iFrame Service initialized');
    }

    /**
     * Generate checkout token
     */
    public function generate_checkout_token($order) {
        $iframe_id = get_option('bna_smart_payment_iframe_id');
        if (empty($iframe_id)) {
            bna_api_error('iFrame ID not configured');
            return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
        }

        bna_api_debug('Starting checkout token generation', [
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'iframe_id' => $iframe_id
        ]);

        // Try to find existing customer first
        $existing_customer_id = $this->find_existing_customer($order);

        if ($existing_customer_id) {
            // Use existing customer
            bna_api_log('Using existing customer for checkout', [
                'order_id' => $order->get_id(),
                'customer_id' => $existing_customer_id
            ]);

            $checkout_data = $this->create_checkout_payload($order, $existing_customer_id);
        } else {
            // Create new customer via customerInfo in checkout
            bna_api_log('Will create new customer via checkout', [
                'order_id' => $order->get_id(),
                'customer_email' => $order->get_billing_email()
            ]);

            $checkout_data = $this->create_checkout_payload($order, null);
        }

        // Make checkout API request
        $response = $this->api_service->make_request('v1/checkout', 'POST', $checkout_data);

        if (is_wp_error($response)) {
            bna_api_error('Checkout token generation failed', [
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message()
            ]);
            return $response;
        }

        if (!isset($response['token'])) {
            bna_api_error('Checkout response missing token', [
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response)
            ]);
            return new WP_Error('invalid_response', 'Token not found in response');
        }

        // Store customer data in order meta if we used existing customer
        if ($existing_customer_id) {
            $order->add_meta_data('_bna_customer_id', $existing_customer_id);
        }

        $order->save();

        bna_api_log('Checkout token generated successfully', [
            'order_id' => $order->get_id(),
            'used_existing_customer' => !empty($existing_customer_id),
            'token_length' => strlen($response['token'])
        ]);

        return $response;
    }

    /**
     * Try to find existing customer by email
     */
    private function find_existing_customer($order) {
        $email = $order->get_billing_email();

        bna_api_debug('Searching for existing customer', ['email' => $email]);

        // Search existing customers by email
        $customers = $this->api_service->make_request('v1/customers', 'GET', [
            'email' => $email
        ]);

        if (is_wp_error($customers)) {
            bna_api_debug('Customer search failed', [
                'error' => $customers->get_error_message()
            ]);
            return null;
        }

        if (!isset($customers['data']) || empty($customers['data'])) {
            bna_api_debug('No existing customers found', ['email' => $email]);
            return null;
        }

        // Find matching customer
        foreach ($customers['data'] as $customer) {
            if (isset($customer['id'], $customer['email'])) {
                if (strtolower($customer['email']) === strtolower($email)) {
                    bna_api_log('Found existing customer', [
                        'customer_id' => $customer['id'],
                        'email' => $customer['email']
                    ]);
                    return $customer['id'];
                }
            }
        }

        bna_api_debug('No matching customer found', ['email' => $email]);
        return null;
    }

    /**
     * Create checkout payload using the fixed payload class
     */
    private function create_checkout_payload($order, $customer_id = null) {
        $payload_generator = new BNA_Checkout_Payload($order, $customer_id);
        $checkout_data = $payload_generator->get_payload();

        bna_api_log('Checkout payload created', [
            'order_id' => $order->get_id(),
            'has_customer_id' => !empty($customer_id),
            'has_customer_info' => isset($checkout_data['customerInfo']),
            'payload_keys' => array_keys($checkout_data)
        ]);

        return $checkout_data;
    }

    /**
     * Get iframe URL
     */
    public function get_iframe_url($token) {
        $url = $this->api_service->get_api_url() . '/v1/checkout/' . $token;

        bna_api_debug('iFrame URL generated', [
            'token_length' => strlen($token),
            'url_length' => strlen($url),
            'environment' => $this->api_service->get_status()['environment']
        ]);

        return $url;
    }

    /**
     * Test iFrame configuration
     */
    public function test_iframe_config() {
        $iframe_id = get_option('bna_smart_payment_iframe_id');

        if (empty($iframe_id)) {
            bna_api_error('iFrame test failed - no iframe ID configured');
            return false;
        }

        // Test API connection first
        if (!$this->api_service->test_connection()) {
            bna_api_error('iFrame test failed - API connection failed');
            return false;
        }

        bna_api_log('iFrame configuration test passed', [
            'iframe_id' => $iframe_id,
            'api_status' => 'connected'
        ]);

        return true;
    }
}