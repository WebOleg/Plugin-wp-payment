<?php
/**
 * BNA API Handler
 * Updated version - no shipping, taxes, or additionalInfo
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

        // Create checkout payload
        $checkout_data = $this->create_checkout_payload($order);

        if (!$checkout_data) {
            bna_error('Failed to create checkout payload', array('order_id' => $order->get_id()));
            return new WP_Error('payload_error', 'Failed to create checkout payload');
        }

        // Log the full payload for debugging
        bna_debug('Full checkout payload being sent', array(
            'order_id' => $order->get_id(),
            'payload' => $checkout_data
        ));

        // Make API request
        $response = $this->make_request('v1/checkout', 'POST', $checkout_data);

        if (is_wp_error($response)) {
            bna_error('Checkout token generation failed', array(
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message(),
                'error_data' => $response->get_error_data(),
                'sent_payload' => $checkout_data
            ));
            return $response;
        }

        if (!isset($response['token'])) {
            bna_error('Invalid checkout response - no token', array(
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response),
                'full_response' => $response
            ));
            return new WP_Error('invalid_response', 'Token not found in response');
        }

        bna_log('Checkout token generated successfully', array(
            'order_id' => $order->get_id(),
            'token_length' => strlen($response['token'])
        ));

        return $response;
    }

    /**
     * Create checkout payload with customerInfo structure
     */
    private function create_checkout_payload($order) {
        // Build customer info
        $customer_info = $this->build_customer_info($order);

        if (!$customer_info) {
            bna_error('Failed to build customer info', array('order_id' => $order->get_id()));
            return false;
        }

        // Create base payload
        $payload = array(
            'iframeId' => get_option('bna_smart_payment_iframe_id'),
            'customerInfo' => $customer_info,
            'subtotal' => (float) $order->get_total(),
            'items' => $this->get_order_items($order)
        );

        // Add invoice info
        $payload['invoiceInfo'] = array(
            'invoiceId' => $order->get_order_number(),
            'invoiceAdditionalInfo' => 'WooCommerce Order #' . $order->get_id()
        );

        bna_debug('Checkout payload created', array(
            'order_id' => $order->get_id(),
            'payload_keys' => array_keys($payload),
            'customer_email' => $customer_info['email'] ?? 'missing',
            'subtotal' => $payload['subtotal'],
            'items_count' => count($payload['items']),
            'has_address_with_street_number' => isset($customer_info['address']['streetNumber'])
        ));

        return $payload;
    }

    /**
     * Build customer info object for checkout payload
     * Updated: No additionalInfo sent
     */
    private function build_customer_info($order) {
        // Required fields
        $email = trim($order->get_billing_email());
        $first_name = $this->clean_name($order->get_billing_first_name());
        $last_name = $this->clean_name($order->get_billing_last_name());

        if (empty($email) || empty($first_name) || empty($last_name)) {
            bna_error('Missing required customer data', array(
                'has_email' => !empty($email),
                'has_firstName' => !empty($first_name),
                'has_lastName' => !empty($last_name),
                'email' => $email,
                'firstName' => $first_name,
                'lastName' => $last_name
            ));
            return false;
        }

        // Base customer info
        $customer_info = array(
            'type' => 'Personal',
            'email' => $email,
            'firstName' => $first_name,
            'lastName' => $last_name
        );

        // Add phone if enabled and valid
        if (get_option('bna_smart_payment_enable_phone') === 'yes') {
            $phone = $this->get_clean_phone($order);
            if ($phone) {
                $customer_info['phoneCode'] = '+1';
                $customer_info['phoneNumber'] = $phone;
            }
        }

        // Add birthdate if enabled and available
        if (get_option('bna_smart_payment_enable_birthdate') === 'yes') {
            $birthdate = $this->get_valid_birthdate($order);
            if ($birthdate) {
                $customer_info['birthDate'] = $birthdate;
            }
        }

        // ALWAYS add address with streetNumber - required by BNA API
        $customer_info['address'] = $this->build_address($order);

        // REMOVED: additionalInfo is no longer sent

        bna_debug('Customer info built', array(
            'order_id' => $order->get_id(),
            'has_phone' => isset($customer_info['phoneNumber']),
            'has_birthdate' => isset($customer_info['birthDate']),
            'has_address' => isset($customer_info['address']),
            'address_has_street_number' => isset($customer_info['address']['streetNumber'])
        ));

        return $customer_info;
    }

    /**
     * Build address - streetNumber and postalCode are required by BNA API
     */
    private function build_address($order) {
        $street = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());

        // Extract street number - ALWAYS required
        $street_number = $this->extract_street_number($street);
        $street_name = $this->clean_street_name($street, $street_number);

        $address = array(
            'streetNumber' => $street_number,
            'streetName' => $street_name,
            'city' => $this->clean_city_name($city),
            'province' => $order->get_billing_state() ?: 'ON',
            'country' => $order->get_billing_country() ?: 'CA',
            'postalCode' => $this->format_postal_code($order->get_billing_postcode())
        );

        // Add apartment if exists
        $apartment = trim($order->get_billing_address_2());
        if (!empty($apartment)) {
            $address['apartment'] = $apartment;
        }

        bna_debug('Address built', array(
            'order_id' => $order->get_id(),
            'original_street' => $street,
            'extracted_number' => $street_number,
            'cleaned_name' => $street_name,
            'full_address' => $address
        ));

        return $address;
    }

    /**
     * Extract street number from address - always return something
     */
    private function extract_street_number($street) {
        if (empty($street)) {
            bna_debug('No street provided, using default number');
            return '1';
        }

        $street = trim($street);

        // Look for numbers at the beginning (most common format)
        if (preg_match('/^(\d+)/', $street, $matches)) {
            bna_debug('Found street number at beginning', array(
                'street' => $street,
                'number' => $matches[1]
            ));
            return $matches[1];
        }

        // Look for any numbers in the string
        if (preg_match('/(\d+)/', $street, $matches)) {
            bna_debug('Found street number in middle', array(
                'street' => $street,
                'number' => $matches[1]
            ));
            return $matches[1];
        }

        // No number found - return default
        bna_debug('No number found in street, using default', array('street' => $street));
        return '1';
    }

    /**
     * Clean street name - remove extracted number and clean up
     */
    private function clean_street_name($street, $street_number) {
        if (empty($street)) {
            return 'Main Street';
        }

        $street = trim($street);

        // If street number is at the beginning, remove it
        $cleaned = preg_replace('/^' . preg_quote($street_number, '/') . '\s*/', '', $street);
        $cleaned = trim($cleaned);

        // If nothing left after removing number, use original
        if (empty($cleaned)) {
            $cleaned = $street;
        }

        // If still empty, use default
        if (empty($cleaned)) {
            $cleaned = 'Main Street';
        }

        return $cleaned;
    }

    /**
     * Get clean phone number
     */
    private function get_clean_phone($order) {
        $phone = trim($order->get_billing_phone());

        if (empty($phone)) {
            return false;
        }

        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Remove leading 1 if present (North American format)
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            $phone = substr($phone, 1);
        }

        // Must be exactly 10 digits for North American format
        if (strlen($phone) !== 10) {
            bna_debug('Invalid phone format', array('phone' => $phone, 'length' => strlen($phone)));
            return false;
        }

        return $phone;
    }

    /**
     * Get valid birthdate
     */
    private function get_valid_birthdate($order) {
        // Try from order meta first
        $birthdate = $order->get_meta('_billing_birthdate');
        if ($this->is_valid_birthdate($birthdate)) {
            return $birthdate;
        }

        // Try from POST data during checkout
        if (isset($_POST['billing_birthdate']) && !empty($_POST['billing_birthdate'])) {
            $birthdate = sanitize_text_field($_POST['billing_birthdate']);
            if ($this->is_valid_birthdate($birthdate)) {
                return $birthdate;
            }
        }

        // Default to reasonable birthdate if required
        return '1990-01-01';
    }

    /**
     * Validate birthdate format and logic
     */
    private function is_valid_birthdate($birthdate) {
        if (empty($birthdate)) {
            return false;
        }

        $date_obj = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $birthdate) {
            return false;
        }

        // Check reasonable age limits (18-120 years old)
        $now = new DateTime();
        $eighteen_years_ago = $now->modify('-18 years');
        $hundred_twenty_years_ago = new DateTime('-120 years');

        return $date_obj <= $eighteen_years_ago && $date_obj >= $hundred_twenty_years_ago;
    }

    /**
     * Clean name fields - remove special characters
     */
    private function clean_name($name) {
        if (empty($name)) {
            return '';
        }

        // Remove special characters, keep letters, spaces, hyphens and accented characters
        $cleaned = preg_replace('/[^a-zA-ZÀ-ÿ\s\'-]/u', '', trim($name));
        $cleaned = preg_replace('/\s+/', ' ', $cleaned); // Multiple spaces to single

        return trim($cleaned);
    }

    /**
     * Clean city name
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
     * Format postal code
     */
    private function format_postal_code($postal_code) {
        if (empty($postal_code)) {
            return 'A1A 1A1';
        }

        $postal = strtoupper(str_replace(' ', '', $postal_code));

        // Canadian postal code format
        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal)) {
            return substr($postal, 0, 3) . ' ' . substr($postal, 3, 3);
        }

        // US zip code - return as is
        if (preg_match('/^\d{5}(-\d{4})?$/', $postal_code)) {
            return $postal_code;
        }

        return $postal_code ?: 'A1A 1A1';
    }

    /**
     * Get order items for BNA API
     * Updated: NO shipping or taxes included
     */
    private function get_order_items($order) {
        $items = array();

        // Only add actual products - no shipping or taxes
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();

            $items[] = array(
                'sku' => $product && $product->get_sku() ? $product->get_sku() : 'ITEM-' . $item_id,
                'description' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'price' => (float) $order->get_item_total($item, false, false),
                'amount' => (float) $order->get_line_total($item, false, false)
            );
        }

        // REMOVED: Shipping and taxes are no longer added to items

        bna_debug('Order items created (products only)', array(
            'order_id' => $order->get_id(),
            'items_count' => count($items),
            'total_amount' => array_sum(array_column($items, 'amount')),
            'shipping_excluded' => true,
            'tax_excluded' => true
        ));

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
            $args['headers']['Content-Type'] = 'application/json';
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

        bna_debug('Raw API response', array(
            'endpoint' => $endpoint,
            'response_code' => $response_code,
            'response_body_preview' => substr($response_body, 0, 500)
        ));

        // Try to decode JSON response
        $parsed_response = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('Invalid JSON response', array(
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'response_code' => $response_code,
                'response_body' => $response_body
            ));
            return new WP_Error('invalid_response', 'Invalid JSON response: ' . json_last_error_msg());
        }

        if ($response_code >= 400) {
            $error_message = $this->extract_error_message($parsed_response, $response_code);

            bna_error('API error response', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'response_code' => $response_code,
                'error_message' => $error_message,
                'request_time_ms' => $request_time,
                'full_response' => $parsed_response,
                'request_data' => $method !== 'GET' ? $data : null
            ));

            return new WP_Error('api_error', $error_message, array(
                'status' => $response_code,
                'response' => $parsed_response
            ));
        }

        bna_debug('API request successful', array(
            'endpoint' => $endpoint,
            'response_code' => $response_code,
            'request_time_ms' => $request_time,
            'response_has_token' => isset($parsed_response['token'])
        ));

        return $parsed_response;
    }

    /**
     * Extract error message from API response
     */
    private function extract_error_message($parsed_response, $response_code) {
        if (isset($parsed_response['message'])) {
            return $parsed_response['message'];
        }

        if (isset($parsed_response['error'])) {
            if (is_array($parsed_response['error'])) {
                return implode(', ', $parsed_response['error']);
            }
            return $parsed_response['error'];
        }

        if (isset($parsed_response['errors'])) {
            if (is_array($parsed_response['errors'])) {
                return implode(', ', $parsed_response['errors']);
            }
            return $parsed_response['errors'];
        }

        return "API request failed with status $response_code";
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