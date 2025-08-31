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

        // Log the full payload being sent for debugging
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
                'sent_payload' => $checkout_data // Add payload to error log
            ));
            return $response;
        }

        if (!isset($response['token'])) {
            bna_error('Invalid checkout response', array(
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response),
                'full_response' => $response
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

        // Either use existing customer ID OR create new customer
        if (!empty($customer_id)) {
            $payload['customerId'] = $customer_id;
            bna_debug('Using existing customer ID', array(
                'customer_id' => $customer_id
            ));
        } else {
            // Create new customer first, then use customerId
            $new_customer_id = $this->create_customer_via_api($order);

            if (!empty($new_customer_id)) {
                $payload['customerId'] = $new_customer_id;

                // Store customer ID in order for future use
                $order->add_meta_data('_bna_customer_id', $new_customer_id);
                $order->save();

                bna_log('Created new customer via API', array(
                    'order_id' => $order->get_id(),
                    'customer_id' => $new_customer_id
                ));
            } else {
                bna_error('Failed to create customer, cannot proceed', array(
                    'order_id' => $order->get_id()
                ));
                return false;
            }
        }

        bna_debug('Checkout payload structure', array(
            'order_id' => $order->get_id(),
            'customer_id' => $payload['customerId'],
            'payload_keys' => array_keys($payload),
            'iframe_id' => $payload['iframeId'],
            'subtotal' => $payload['subtotal'],
            'items_count' => count($payload['items'])
        ));

        return $payload;
    }

    /**
     * Create customer via BNA API
     */
    private function create_customer_via_api($order) {
        $customer_data = $this->build_customer_data($order);

        if (!$customer_data) {
            bna_error('Cannot build customer data', array(
                'order_id' => $order->get_id()
            ));
            return false;
        }

        bna_debug('Creating customer via API', array(
            'order_id' => $order->get_id(),
            'customer_data' => $customer_data
        ));

        $response = $this->make_request('v1/customers', 'POST', $customer_data);

        if (is_wp_error($response)) {
            bna_error('Customer creation failed via API', array(
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message(),
                'customer_data' => $customer_data
            ));
            return false;
        }

        if (!isset($response['id'])) {
            bna_error('Invalid customer creation response', array(
                'order_id' => $order->get_id(),
                'response' => $response
            ));
            return false;
        }

        return $response['id'];
    }

    /**
     * Build customer data for API creation
     */
    private function build_customer_data($order) {
        // Basic required fields - MUST be real data
        $email = $order->get_billing_email();
        $firstName = $this->clean_name($order->get_billing_first_name());
        $lastName = $this->clean_name($order->get_billing_last_name());

        if (empty($email) || empty($firstName) || empty($lastName)) {
            bna_error('Missing required customer data', array(
                'has_email' => !empty($email),
                'has_firstName' => !empty($firstName),
                'has_lastName' => !empty($lastName)
            ));
            return false;
        }

        $customer_data = array(
            'type' => 'Personal',
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName
        );

        // Add phone if available and valid
        $phone = $this->get_real_phone($order);
        if ($phone) {
            $customer_data['phoneCode'] = '+1';
            $customer_data['phoneNumber'] = $phone;
            bna_debug('Added real phone to customer data', array('phone' => $phone));
        }

        // Add birthdate if available and valid
        $birthdate = $this->get_real_birthdate($order);
        if ($birthdate) {
            $customer_data['birthDate'] = $birthdate;
            bna_debug('Added real birthdate to customer data', array('birthdate' => $birthdate));
        }

        // Add address if enabled and valid
        if (get_option('bna_smart_payment_enable_billing_address') === 'yes') {
            if ($this->has_valid_billing_address($order)) {
                $customer_data['address'] = $this->get_clean_billing_address($order);
            }
        }

        return $customer_data;
    }

    /**
     * Get real phone number (no defaults)
     */
    private function get_real_phone($order) {
        $phone = $order->get_billing_phone();

        if (empty($phone)) {
            return false;
        }

        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);

        // Remove leading 1 if present (North American format)
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            $phone = substr($phone, 1);
        }

        // Must be exactly 10 digits
        if (strlen($phone) !== 10) {
            bna_debug('Phone number invalid length', array(
                'phone' => $phone,
                'length' => strlen($phone)
            ));
            return false;
        }

        return $phone;
    }

    /**
     * Get real birthdate (no defaults)
     */
    private function get_real_birthdate($order) {
        // From order meta (saved during checkout)
        $birthdate = $order->get_meta('_billing_birthdate');
        if (!empty($birthdate) && $this->is_valid_birthdate($birthdate)) {
            bna_debug('Using birthdate from order meta', array('birthdate' => $birthdate));
            return $birthdate;
        }

        // From POST data (during checkout process)
        if (isset($_POST['billing_birthdate']) && !empty($_POST['billing_birthdate'])) {
            $birthdate = sanitize_text_field($_POST['billing_birthdate']);
            if ($this->is_valid_birthdate($birthdate)) {
                bna_debug('Using birthdate from POST', array('birthdate' => $birthdate));
                return $birthdate;
            }
        }

        bna_debug('No valid birthdate found');
        return false;
    }

    /**
     * Clean name fields - remove special characters
     */
    private function clean_name($name) {
        if (empty($name)) {
            return '';
        }

        // Remove special characters, keep letters, spaces and hyphens
        $cleaned = preg_replace('/[^a-zA-ZÀ-ÿ\s-]/u', '', trim($name));
        $cleaned = preg_replace('/\s+/', ' ', $cleaned); // Multiple spaces to single

        return trim($cleaned);
    }

    /**
     * Validate birthdate format and age
     */
    private function is_valid_birthdate($birthdate) {
        if (empty($birthdate)) {
            return false;
        }

        // Check if it's valid date format YYYY-MM-DD
        $date_obj = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $birthdate) {
            return false;
        }

        // Check if date is not in future
        if ($date_obj > new DateTime()) {
            return false;
        }

        // Check minimum age (18 years)
        $eighteen_years_ago = new DateTime('-18 years');
        if ($date_obj > $eighteen_years_ago) {
            return false;
        }

        return true;
    }

    /**
     * Check if billing address is valid for BNA API
     */
    private function has_valid_billing_address($order) {
        $street = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());

        if (empty($street) || empty($city)) {
            bna_debug('Billing address incomplete', array(
                'has_street' => !empty($street),
                'has_city' => !empty($city)
            ));
            return false;
        }

        // City must pass BNA validation pattern: /^[\da-zA-ZÀ-ÖØ-öø-ÿ\s-]+$/u
        $city_valid = preg_match('/^[\da-zA-ZÀ-ÖØ-öø-ÿ\s-]+$/u', $city);
        if (!$city_valid) {
            bna_debug('City name validation failed', array(
                'city' => $city,
                'pattern_match' => $city_valid
            ));
        }

        return $city_valid;
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
            'province' => $order->get_billing_state() ?: 'ON', // Default to Ontario
            'country' => $order->get_billing_country() ?: 'CA',
            'postalCode' => $this->format_postal_code($order->get_billing_postcode())
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
     * Format postal code for Canada
     */
    private function format_postal_code($postal_code) {
        if (empty($postal_code)) {
            return 'A1A1A1'; // Default Canadian postal code
        }

        // Remove spaces and convert to uppercase
        $postal = strtoupper(str_replace(' ', '', $postal_code));

        // If it looks like Canadian postal code, format it properly
        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal)) {
            return substr($postal, 0, 3) . ' ' . substr($postal, 3, 3);
        }

        // For US zip codes, just return as is
        if (preg_match('/^\d{5}(-\d{4})?$/', $postal_code)) {
            return $postal_code;
        }

        return $postal_code ?: 'A1A1A1';
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

        return '';
    }

    /**
     * Get customer birthdate with better validation
     */
    private function get_customer_birthdate($order) {
        // From order meta (saved during checkout)
        $birthdate = $order->get_meta('_billing_birthdate');
        if (!empty($birthdate) && $this->is_valid_birthdate($birthdate)) {
            bna_debug('Using birthdate from order meta', array('birthdate' => $birthdate));
            return $birthdate;
        }

        // From POST data (during checkout process)
        if (isset($_POST['billing_birthdate']) && !empty($_POST['billing_birthdate'])) {
            $birthdate = sanitize_text_field($_POST['billing_birthdate']);
            if ($this->is_valid_birthdate($birthdate)) {
                bna_debug('Using birthdate from POST', array('birthdate' => $birthdate));
                return $birthdate;
            }
        }

        // Fallback to reasonable birthdate (30 years old)
        $fallback_birthdate = date('Y-m-d', strtotime('-30 years'));
        bna_debug('Using fallback birthdate', array('birthdate' => $fallback_birthdate));
        return $fallback_birthdate;
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
                'quantity' => (int) $item->get_quantity(),
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

        bna_debug('Order items created', array(
            'order_id' => $order->get_id(),
            'items_count' => count($items),
            'total_amount' => array_sum(array_column($items, 'amount'))
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

        // First try to decode the response
        $parsed_response = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('Invalid JSON response', array(
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'response_code' => $response_code,
                'response_body_preview' => substr($response_body, 0, 500)
            ));
            return new WP_Error('invalid_response', 'Invalid JSON response: ' . json_last_error_msg());
        }

        if ($response_code >= 400) {
            $error_message = 'API request failed';

            // Try to extract error message from response
            if (isset($parsed_response['message'])) {
                $error_message = $parsed_response['message'];
            } elseif (isset($parsed_response['error'])) {
                $error_message = $parsed_response['error'];
            } elseif (isset($parsed_response['errors'])) {
                if (is_array($parsed_response['errors'])) {
                    $error_message = implode(', ', $parsed_response['errors']);
                } else {
                    $error_message = $parsed_response['errors'];
                }
            }

            // Enhanced error logging with full response details
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