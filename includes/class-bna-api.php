<?php
/**
 * BNA API Handler
 * Single class for all BNA Smart Payment API operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_API {
    
    const ENVIRONMENTS = array(
        'dev' => 'https://dev-api-service.bnasmartpayment.com',
        'staging' => 'https://stage-api-service.bnasmartpayment.com',
        'production' => 'https://api.bnasmartpayment.com'
    );
    
    private $environment;
    private $credentials;
    
    public function __construct() {
        $this->environment = get_option('bna_smart_payment_environment', 'staging');
        $this->credentials = array(
            'access_key' => get_option('bna_smart_payment_access_key', ''),
            'secret_key' => get_option('bna_smart_payment_secret_key', '')
        );
        
        bna_debug('BNA API initialized', array(
            'environment' => $this->environment,
            'has_credentials' => $this->has_credentials()
        ));
    }
    
    /**
     * Generate checkout token for order
     */
    public function generate_checkout_token($order) {
        $iframe_id = get_option('bna_smart_payment_iframe_id');
        if (empty($iframe_id)) {
            bna_error('iFrame ID not configured');
            return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
        }
        
        bna_log('Generating checkout token', array(
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'iframe_id' => $iframe_id
        ));
        
        // Try to find existing customer
        $existing_customer_id = $this->find_existing_customer($order);
        
        // Create checkout payload
        $checkout_data = $this->create_checkout_payload($order, $existing_customer_id);
        
        // Make API request
        $response = $this->make_request('v1/checkout', 'POST', $checkout_data);
        
        if (is_wp_error($response)) {
            bna_error('Checkout token generation failed', array(
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message()
            ));
            return $response;
        }
        
        if (!isset($response['token'])) {
            bna_error('Invalid checkout response', array(
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response)
            ));
            return new WP_Error('invalid_response', 'Token not found in response');
        }
        
        // Store customer ID if we used existing customer
        if ($existing_customer_id) {
            $order->add_meta_data('_bna_customer_id', $existing_customer_id);
            $order->save();
        }
        
        bna_log('Checkout token generated successfully', array(
            'order_id' => $order->get_id(),
            'used_existing_customer' => !empty($existing_customer_id),
            'token_length' => strlen($response['token'])
        ));
        
        return $response;
    }
    
    /**
     * Find existing customer by email
     */
    private function find_existing_customer($order) {
        $email = $order->get_billing_email();
        
        bna_debug('Searching for existing customer', array('email' => $email));
        
        $customers = $this->make_request('v1/customers', 'GET', array('email' => $email));
        
        if (is_wp_error($customers) || !isset($customers['data']) || empty($customers['data'])) {
            bna_debug('No existing customers found', array('email' => $email));
            return null;
        }
        
        // Find matching customer
        foreach ($customers['data'] as $customer) {
            if (isset($customer['id'], $customer['email'])) {
                if (strtolower($customer['email']) === strtolower($email)) {
                    bna_log('Found existing customer', array(
                        'customer_id' => $customer['id'],
                        'email' => $customer['email']
                    ));
                    return $customer['id'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Create checkout payload
     */
    private function create_checkout_payload($order, $customer_id = null) {
        $payload = array(
            'iframeId' => get_option('bna_smart_payment_iframe_id'),
            'subtotal' => (float) $order->get_total(),
            'items' => $this->get_order_items($order)
        );
        
        // Either use existing customer ID OR send customer info
        if (!empty($customer_id)) {
            $payload['customerId'] = $customer_id;
        } else {
            $customer_info = $this->get_customer_info($order);
            if (!empty($customer_info)) {
                $payload['customerInfo'] = $customer_info;
            }
        }
        
        bna_debug('Checkout payload created', array(
            'order_id' => $order->get_id(),
            'has_customer_id' => !empty($customer_id),
            'has_customer_info' => isset($payload['customerInfo']),
            'payload_keys' => array_keys($payload)
        ));
        
        return $payload;
    }
    
    /**
     * Get customer info for new customer creation
     */
    private function get_customer_info($order) {
        $customer_info = array(
            'type' => 'Personal',
            'email' => $order->get_billing_email(),
            'firstName' => $order->get_billing_first_name() ?: 'Customer',
            'lastName' => $order->get_billing_last_name() ?: 'Customer'
        );
        
        // Add phone if enabled
        if (get_option('bna_smart_payment_enable_phone') === 'yes' && !empty($order->get_billing_phone())) {
            $customer_info['phoneCode'] = '+1';
            $customer_info['phoneNumber'] = $this->format_phone_number($order->get_billing_phone());
        }
        
        // Add birthdate if enabled
        if (get_option('bna_smart_payment_enable_birthdate') === 'yes') {
            $birthdate = $this->get_customer_birthdate($order);
            if ($birthdate) {
                $customer_info['birthDate'] = $birthdate;
            }
        }
        
        // Add address if enabled and valid
        if (get_option('bna_smart_payment_enable_billing_address') === 'yes' && $this->has_valid_billing_address($order)) {
            $customer_info['address'] = $this->get_clean_billing_address($order);
        }
        
        return $customer_info;
    }
    
    /**
     * Check if billing address is valid for BNA API
     */
    private function has_valid_billing_address($order) {
        $street = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());
        
        if (empty($street) || empty($city)) {
            return false;
        }
        
        // City must pass BNA validation pattern: /^[\da-zA-ZÀ-ÖØ-öø-ÿ\s-]+$/u
        return preg_match('/^[\da-zA-ZÀ-ÖØ-öø-ÿ\s-]+$/u', $city);
    }
    
    /**
     * Get clean billing address
     */
    private function get_clean_billing_address($order) {
        $street = trim($order->get_billing_address_1());
        $city = $this->clean_city_name($order->get_billing_city());
        
        $address = array(
            'streetName' => $street,
            'city' => $city,
            'province' => $order->get_billing_state() ?: 'Unknown',
            'country' => $order->get_billing_country() ?: 'CA',
            'postalCode' => $order->get_billing_postcode() ?: 'A1A1A1'
        );
        
        // Only add street number if we can extract it
        $street_number = $this->extract_street_number($street);
        if (!empty($street_number)) {
            $address['streetNumber'] = $street_number;
        }
        
        // Only add apartment if it exists
        $apartment = trim($order->get_billing_address_2());
        if (!empty($apartment)) {
            $address['apartment'] = $apartment;
        }
        
        return $address;
    }
    
    /**
     * Clean city name - remove invalid characters
     */
    private function clean_city_name($city) {
        if (empty($city)) {
            return 'Unknown';
        }
        
        $clean_city = preg_replace('/[^\da-zA-ZÀ-ÖØ-öø-ÿ\s-]/u', '', $city);
        $clean_city = trim($clean_city);
        
        return empty($clean_city) ? 'Unknown' : $clean_city;
    }
    
    /**
     * Extract street number from address
     */
    private function extract_street_number($street) {
        if (empty($street)) {
            return '';
        }
        
        // Look for numbers at the beginning
        if (preg_match('/^(\d+)/', trim($street), $matches)) {
            return $matches[1];
        }
        
        // Look for numbers anywhere as fallback
        if (preg_match('/(\d+)/', $street, $matches)) {
            return $matches[1];
        }
        
        return '';
    }
    
    /**
     * Get customer birthdate
     */
    private function get_customer_birthdate($order) {
        // From order meta
        $birthdate = $order->get_meta('_billing_birthdate');
        if (!empty($birthdate)) {
            return $birthdate;
        }
        
        // From POST data
        if (isset($_POST['billing_birthdate']) && !empty($_POST['billing_birthdate'])) {
            return sanitize_text_field($_POST['billing_birthdate']);
        }
        
        // Fallback
        return date('Y-m-d', strtotime('-25 years'));
    }
    
    /**
     * Format phone number
     */
    private function format_phone_number($phone) {
        if (empty($phone)) {
            return '1234567890';
        }
        
        $phone = preg_replace('/\D/', '', $phone);
        
        if (strlen($phone) < 10) {
            $phone = str_pad($phone, 10, '0', STR_PAD_RIGHT);
        }
        
        return substr($phone, 0, 10);
    }
    
    /**
     * Get order items for BNA API
     */
    private function get_order_items($order) {
        $items = array();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $items[] = array(
                'sku' => $product && $product->get_sku() ? $product->get_sku() : 'ITEM-' . $item_id,
                'description' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => (float) $order->get_item_total($item),
                'amount' => (float) $order->get_line_total($item)
            );
        }
        
        // Add tax if exists
        if ($order->get_total_tax() > 0) {
            $items[] = array(
                'sku' => 'TAX',
                'description' => 'Tax',
                'quantity' => 1,
                'price' => (float) $order->get_total_tax(),
                'amount' => (float) $order->get_total_tax()
            );
        }
        
        return $items;
    }
    
    /**
     * Get iFrame URL
     */
    public function get_iframe_url($token) {
        return $this->get_api_url() . '/v1/checkout/' . $token;
    }
    
    /**
     * Make HTTP request to BNA API
     */
    public function make_request($endpoint, $method = 'GET', $data = array()) {
        $start_time = microtime(true);
        
        bna_debug('API request started', array(
            'method' => $method,
            'endpoint' => $endpoint,
            'has_data' => !empty($data)
        ));
        
        if (!$this->has_credentials()) {
            bna_error('API credentials missing');
            return new WP_Error('missing_credentials', 'API credentials are not configured');
        }
        
        $url = $this->get_api_url() . '/' . ltrim($endpoint, '/');
        
        $args = array(
            'method' => strtoupper($method),
            'headers' => $this->get_auth_headers(),
            'timeout' => 30,
            'sslverify' => true
        );
        
        // Add body for POST/PUT requests
        if (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }
        
        // Add query params for GET requests
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }
        
        $response = wp_remote_request($url, $args);
        $request_time = round((microtime(true) - $start_time) * 1000, 2);
        
        if (is_wp_error($response)) {
            bna_error('API request failed', array(
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
                'request_time_ms' => $request_time
            ));
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $parsed_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('Invalid JSON response', array(
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'response_code' => $response_code
            ));
            return new WP_Error('invalid_response', 'Invalid JSON response: ' . json_last_error_msg());
        }
        
        if ($response_code >= 400) {
            $error_message = isset($parsed_response['message']) ? $parsed_response['message'] : 'API request failed';
            bna_error('API error response', array(
                'endpoint' => $endpoint,
                'response_code' => $response_code,
                'error_message' => $error_message,
                'request_time_ms' => $request_time
            ));
            return new WP_Error('api_error', $error_message, array('status' => $response_code));
        }
        
        bna_debug('API request successful', array(
            'endpoint' => $endpoint,
            'response_code' => $response_code,
            'request_time_ms' => $request_time
        ));
        
        return $parsed_response;
    }
    
    /**
     * Get API URL for current environment
     */
    private function get_api_url() {
        return self::ENVIRONMENTS[$this->environment] ?? self::ENVIRONMENTS['staging'];
    }
    
    /**
     * Get authorization headers
     */
    private function get_auth_headers() {
        $credentials_string = $this->credentials['access_key'] . ':' . $this->credentials['secret_key'];
        $encoded_credentials = base64_encode($credentials_string);
        
        return array(
            'Authorization' => 'Basic ' . $encoded_credentials,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );
    }
    
    /**
     * Check if credentials are configured
     */
    private function has_credentials() {
        return !empty($this->credentials['access_key']) && !empty($this->credentials['secret_key']);
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        bna_debug('Testing API connection');
        
        $result = $this->make_request('v1/account', 'GET');
        
        if (is_wp_error($result)) {
            bna_error('API connection test failed', array(
                'error' => $result->get_error_message()
            ));
            return false;
        }
        
        bna_log('API connection test successful', array(
            'environment' => $this->environment
        ));
        
        return true;
    }
}
