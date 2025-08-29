<?php
/**
 * BNA iFrame Service V3
 * Updated to use BNA_Checkout_Payload class
 */

if (!defined('ABSPATH')) exit;

class BNA_iFrame_Service {
    private $api_service;

    public function __construct() {
        $this->api_service = new BNA_API_Service();
        bna_api_debug('iFrame Service initialized');
    }

    /**
     * Generate checkout token using payload class
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

        // Get or create customer
        $customer_result = $this->get_or_create_customer($order);
        
        if (is_wp_error($customer_result)) {
            bna_api_error('Customer setup failed for checkout', [
                'order_id' => $order->get_id(),
                'error' => $customer_result->get_error_message()
            ]);
            return $customer_result;
        }

        // Create payload using new class
        $payload_generator = new BNA_Checkout_Payload($order, $customer_result['id']);
        $checkout_data = $payload_generator->get_payload();

        bna_api_log('Creating checkout token with payload class', [
            'order_id' => $order->get_id(),
            'customer_id' => $customer_result['id'],
            'customer_action' => $customer_result['action'],
            'payload_keys' => array_keys($checkout_data)
        ]);

        // Make API request
        $response = $this->api_service->make_request('v1/checkout', 'POST', $checkout_data);

        if (is_wp_error($response)) {
            bna_api_error('Checkout token generation failed', [
                'order_id' => $order->get_id(),
                'customer_id' => $customer_result['id'],
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

        // Store customer ID and birthdate in order meta
        $order->add_meta_data('_bna_customer_id', $customer_result['id']);
        
        // Store birthdate if provided in checkout
        if (isset($_POST['bna_customer_birthdate'])) {
            $order->add_meta_data('_bna_customer_birthdate', sanitize_text_field($_POST['bna_customer_birthdate']));
        }
        
        $order->save();

        bna_api_log('Checkout token generated successfully', [
            'order_id' => $order->get_id(),
            'customer_id' => $customer_result['id'],
            'token_length' => strlen($response['token'])
        ]);

        return $response;
    }

    /**
     * Get or create customer (simplified - no duplicate logic)
     */
    private function get_or_create_customer($order) {
        $email = $order->get_billing_email();
        
        bna_api_debug('Looking up customer', ['email' => $email]);
        
        // Search existing customers
        $customers = $this->api_service->make_request('v1/customers', 'GET', [
            'email' => $email
        ]);

        if (!is_wp_error($customers) && isset($customers['data']) && !empty($customers['data'])) {
            foreach ($customers['data'] as $customer) {
                if (isset($customer['id']) && isset($customer['email'])) {
                    if (strtolower($customer['email']) === strtolower($email)) {
                        bna_api_log('Found existing customer', [
                            'customer_id' => $customer['id'],
                            'email' => $customer['email']
                        ]);
                        return ['id' => $customer['id'], 'action' => 'found'];
                    }
                }
            }
        }

        // Create new customer using payload class
        $customer_payload = new BNA_Checkout_Payload($order);
        $customer_data = $customer_payload->get_customer_info();

        if (empty($customer_data)) {
            bna_api_error('Customer data generation failed', [
                'email' => $email
            ]);
            return new WP_Error('customer_data_failed', 'Customer data generation failed');
        }

        bna_api_log('Creating new customer', [
            'email' => $email,
            'customer_data_keys' => array_keys($customer_data)
        ]);

        $create_result = $this->api_service->make_request('v1/customers', 'POST', $customer_data);

        if (is_wp_error($create_result)) {
            bna_api_error('Customer creation failed', [
                'email' => $email,
                'error' => $create_result->get_error_message()
            ]);
            return $create_result;
        }

        if (isset($create_result['id'])) {
            bna_api_log('Customer created successfully', [
                'customer_id' => $create_result['id'],
                'email' => $email
            ]);
            return ['id' => $create_result['id'], 'action' => 'created'];
        }

        bna_api_error('Customer creation response invalid', [
            'email' => $email,
            'response_keys' => array_keys($create_result)
        ]);
        
        return new WP_Error('customer_failed', 'Customer setup failed');
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
