<?php
/**
 * BNA iFrame Service V2
 * Enhanced with new logging system
 */

if (!defined('ABSPATH')) exit;

class BNA_iFrame_Service {
    private $api_service;

    public function __construct() {
        $this->api_service = new BNA_API_Service();
        bna_api_debug('iFrame Service initialized');
    }

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

        // Prepare checkout data
        $checkout_data = [
            'iframeId' => $iframe_id,
            'customerId' => $customer_result['id'],
            'items' => $this->prepare_order_items($order),
            'subtotal' => (float) $order->get_total()
        ];

        bna_api_log('Creating checkout token', [
            'order_id' => $order->get_id(),
            'customer_id' => $customer_result['id'],
            'customer_action' => $customer_result['action'],
            'items_count' => count($checkout_data['items']),
            'subtotal' => $checkout_data['subtotal']
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

        // Store customer ID in order
        $order->add_meta_data('_bna_customer_id', $customer_result['id']);
        $order->save();

        bna_api_log('Checkout token generated successfully', [
            'order_id' => $order->get_id(),
            'customer_id' => $customer_result['id'],
            'token_length' => strlen($response['token'])
        ]);

        return $response;
    }

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
                            'email' => $customer['email'],
                            'status' => $customer['status'] ?? 'unknown'
                        ]);
                        return ['id' => $customer['id'], 'action' => 'found'];
                    }
                }
            }
        }

        // Create new customer
        bna_api_log('Creating new customer', [
            'email' => $email,
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name()
        ]);
        
        $customer_data = [
            'type' => 'Personal',
            'email' => $email,
            'firstName' => $order->get_billing_first_name() ?: 'Customer',
            'lastName' => $order->get_billing_last_name() ?: 'Customer',
            'phoneCode' => '+1',
            'phoneNumber' => $this->format_phone_number($order->get_billing_phone()),
            'birthDate' => date('Y-m-d', strtotime('-25 years'))
        ];

        // Add address if available
        if ($order->get_billing_address_1()) {
            $customer_data['address'] = [
                'streetName' => $order->get_billing_address_1(),
                'streetNumber' => '',
                'apartment' => $order->get_billing_address_2() ?: '',
                'city' => $order->get_billing_city() ?: 'Unknown',
                'province' => $order->get_billing_state() ?: 'Unknown',
                'country' => $order->get_billing_country() ?: 'CA',
                'postalCode' => $order->get_billing_postcode() ?: 'A1A1A1'
            ];
        }

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
                'email' => $email,
                'type' => $customer_data['type']
            ]);
            return ['id' => $create_result['id'], 'action' => 'created'];
        }

        bna_api_error('Customer creation response invalid', [
            'email' => $email,
            'response_keys' => array_keys($create_result)
        ]);
        
        return new WP_Error('customer_failed', 'Customer setup failed');
    }

    private function prepare_order_items($order) {
        $items = [];
        
        bna_api_debug('Preparing order items', [
            'order_id' => $order->get_id(),
            'items_count' => count($order->get_items())
        ]);
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $formatted_item = [
                'sku' => $product && $product->get_sku() ? $product->get_sku() : 'ITEM-' . $item_id,
                'description' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => (float) $order->get_item_total($item),
                'amount' => (float) $order->get_line_total($item)
            ];
            
            $items[] = $formatted_item;
        }

        // Add shipping as item if exists
        if ($order->get_shipping_total() > 0) {
            $items[] = [
                'sku' => 'SHIPPING',
                'description' => 'Shipping',
                'quantity' => 1,
                'price' => (float) $order->get_shipping_total(),
                'amount' => (float) $order->get_shipping_total()
            ];
        }

        // Add taxes as item if exists  
        if ($order->get_total_tax() > 0) {
            $items[] = [
                'sku' => 'TAX',
                'description' => 'Tax',
                'quantity' => 1,
                'price' => (float) $order->get_total_tax(),
                'amount' => (float) $order->get_total_tax()
            ];
        }

        bna_api_debug('Order items prepared', [
            'total_items' => count($items),
            'total_amount' => array_sum(array_column($items, 'amount'))
        ]);
        
        return $items;
    }

    private function format_phone_number($phone) {
        if (empty($phone)) {
            return '1234567890';
        }
        
        // Remove all non-digits
        $phone = preg_replace('/\D/', '', $phone);
        
        // Ensure it's at least 10 digits
        if (strlen($phone) < 10) {
            $phone = str_pad($phone, 10, '0', STR_PAD_RIGHT);
        }
        
        // Take only first 10 digits
        return substr($phone, 0, 10);
    }

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
