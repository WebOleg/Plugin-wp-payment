<?php
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

    public function generate_checkout_token($order) {
        $iframe_id = get_option('bna_smart_payment_iframe_id');
        if (empty($iframe_id)) {
            bna_error('iFrame ID not configured');
            return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
        }

        bna_log('Generating checkout token', array(
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total()
        ));

        $customer_result = $this->get_or_create_customer($order);

        if (is_wp_error($customer_result)) {
            return $customer_result;
        }

        $checkout_data = $this->create_checkout_payload($order, $customer_result);

        if (!$checkout_data) {
            bna_error('Failed to create checkout payload', array('order_id' => $order->get_id()));
            return new WP_Error('payload_error', 'Failed to create checkout payload');
        }

        // Log shipping data if present
        if (isset($checkout_data['customerInfo']['shippingAddress'])) {
            bna_log('Shipping address included in checkout payload', array(
                'order_id' => $order->get_id(),
                'shipping_country' => $checkout_data['customerInfo']['shippingAddress']['country'] ?? 'unknown',
                'shipping_city' => $checkout_data['customerInfo']['shippingAddress']['city'] ?? 'unknown'
            ));
        }

        $response = $this->make_request('v1/checkout', 'POST', $checkout_data);

        if (is_wp_error($response)) {
            bna_error('Checkout token generation failed', array(
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        if (!isset($response['token'])) {
            bna_error('Invalid checkout response - no token', array(
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response)
            ));
            return new WP_Error('invalid_response', 'Token not found in response');
        }

        if (!empty($customer_result['customer_id'])) {
            $order->add_meta_data('_bna_customer_id', $customer_result['customer_id']);
            $order->save();
        }

        bna_log('Checkout token generated successfully', array(
            'order_id' => $order->get_id(),
            'customer_id' => $customer_result['customer_id'] ?? 'new',
            'token_length' => strlen($response['token'])
        ));

        return $response;
    }

    private function get_or_create_customer($order) {
        $existing_customer_id = $order->get_meta('_bna_customer_id');
        if (!empty($existing_customer_id)) {
            bna_debug('Using existing customer ID', array(
                'customer_id' => $existing_customer_id,
                'order_id' => $order->get_id()
            ));

            return array(
                'customer_id' => $existing_customer_id,
                'is_existing' => true
            );
        }

        $customer_data = $this->build_customer_info($order);

        if (!$customer_data) {
            return new WP_Error('customer_data_error', 'Failed to build customer data');
        }

        bna_debug('Attempting to create customer', array(
            'order_id' => $order->get_id(),
            'customer_email' => $customer_data['email']
        ));

        $response = $this->make_request('v1/customers', 'POST', $customer_data);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_data = $response->get_error_data();

            // Check if this might NOT be a customer exists error
            $is_validation_error = $this->is_validation_error($error_message, $error_data);
            
            if ($is_validation_error) {
                // This might be a real validation error, not customer exists
                bna_error('Customer validation error - may be data issue', array(
                    'error' => $error_message,
                    'customer_email' => $customer_data['email'],
                    'status' => $error_data['status'] ?? 'unknown',
                    'customer_data_keys' => array_keys($customer_data)
                ));
                
                // Try without shipping address to see if that's the issue
                return $this->try_create_customer_without_shipping($order, $customer_data);
            }

            // Check if customer already exists
            $is_customer_exists = $this->is_customer_exists_error($error_message, $error_data);

            if ($is_customer_exists) {
                bna_debug('Customer exists, searching for existing customer', array(
                    'customer_email' => $customer_data['email'],
                    'error_status' => $error_data['status'] ?? 'unknown',
                    'error_message' => $error_message
                ));

                return $this->find_existing_customer($customer_data['email']);
            }

            bna_error('Customer creation failed', array(
                'error' => $error_message,
                'customer_email' => $customer_data['email'],
                'status' => $error_data['status'] ?? 'unknown'
            ));

            return $response;
        }

        if (empty($response['id'])) {
            bna_error('No customer ID in create response', array('response' => $response));
            return new WP_Error('invalid_customer_response', 'Customer ID not found in response');
        }

        bna_log('New customer created', array(
            'customer_id' => $response['id'],
            'customer_email' => $customer_data['email']
        ));

        return array(
            'customer_id' => $response['id'],
            'is_existing' => false
        );
    }

    private function is_validation_error($error_message, $error_data) {
        $validation_patterns = array(
            'validation error',
            'invalid data',
            'required field',
            'field validation',
            'data validation'
        );

        $error_lower = strtolower($error_message);
        foreach ($validation_patterns as $pattern) {
            if (stripos($error_lower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function try_create_customer_without_shipping($order, $customer_data) {
        bna_log('Trying to create customer without shipping address', array(
            'order_id' => $order->get_id(),
            'original_had_shipping' => isset($customer_data['shippingAddress'])
        ));

        // Remove shipping address and try again
        $customer_data_no_shipping = $customer_data;
        unset($customer_data_no_shipping['shippingAddress']);

        $response = $this->make_request('v1/customers', 'POST', $customer_data_no_shipping);

        if (is_wp_error($response)) {
            bna_error('Customer creation failed even without shipping', array(
                'error' => $response->get_error_message(),
                'customer_email' => $customer_data['email']
            ));
            return $response;
        }

        if (empty($response['id'])) {
            bna_error('No customer ID in response without shipping', array('response' => $response));
            return new WP_Error('invalid_customer_response', 'Customer ID not found in response');
        }

        bna_log('Customer created successfully without shipping address', array(
            'customer_id' => $response['id'],
            'customer_email' => $customer_data['email']
        ));

        return array(
            'customer_id' => $response['id'],
            'is_existing' => false
        );
    }

    private function is_customer_exists_error($error_message, $error_data) {
        // Check HTTP status codes
        if (isset($error_data['status']) && ($error_data['status'] === 400 || $error_data['status'] === 409)) {
            // Only treat as exists error if message matches
            $exists_patterns = array(
                'customer already exist',
                'customer already exists', 
                'email already exists',
                'duplicate',
                'already registered',
                'user exists'
            );

            $error_lower = strtolower($error_message);
            foreach ($exists_patterns as $pattern) {
                if (stripos($error_lower, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function find_existing_customer($email) {
        // Try different search strategies
        $search_strategies = array(
            array('email' => $email, 'limit' => 1),
            array('email' => $email, 'limit' => 10),
            array('email' => $email),
            array('limit' => 100) // Get more results and search manually
        );

        foreach ($search_strategies as $attempt_num => $search_params) {
            bna_debug('Customer search attempt', array(
                'attempt' => $attempt_num + 1,
                'params' => $search_params,
                'target_email' => $email
            ));

            $response = $this->make_request('v1/customers', 'GET', $search_params);

            if (is_wp_error($response)) {
                bna_error('Customer search failed', array(
                    'attempt' => $attempt_num + 1,
                    'error' => $response->get_error_message(),
                    'email' => $email
                ));
                continue;
            }

            // Try to find customer in response
            $found_customer = $this->extract_customer_from_response($response, $email, $attempt_num + 1);
            
            if ($found_customer) {
                return $found_customer;
            }
        }

        // Customer exists but cannot be found - create error with detailed info
        bna_error('Customer exists but not found in search', array(
            'email' => $email,
            'total_attempts' => count($search_strategies),
            'suggestion' => 'May need to manually check BNA portal or contact support'
        ));

        return new WP_Error('customer_search_failed', 'Customer exists but could not be located via API search');
    }

    private function extract_customer_from_response($response, $target_email, $attempt_num) {
        if (empty($response) || !is_array($response)) {
            bna_debug('Empty or invalid search response', array(
                'attempt' => $attempt_num,
                'response_type' => gettype($response)
            ));
            return false;
        }

        // Handle different response structures
        $customers = array();
        
        if (isset($response['data']) && is_array($response['data'])) {
            $customers = $response['data'];
        } elseif (isset($response[0]) && is_array($response[0])) {
            $customers = $response;
        } elseif (isset($response['id']) && isset($response['email'])) {
            $customers = array($response);
        }

        bna_debug('Extracted customers for search', array(
            'attempt' => $attempt_num,
            'customers_count' => count($customers),
            'target_email' => $target_email
        ));

        // Search through customers
        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }

            $customer_email = $customer['email'] ?? '';
            $customer_id = $customer['id'] ?? '';

            if (empty($customer_email) || empty($customer_id)) {
                continue;
            }

            // Exact match
            if ($customer_email === $target_email) {
                bna_log('Existing customer found via search', array(
                    'customer_id' => $customer_id,
                    'email' => $target_email,
                    'attempt' => $attempt_num
                ));

                return array(
                    'customer_id' => $customer_id,
                    'is_existing' => true
                );
            }
        }

        return false;
    }

    private function create_checkout_payload($order, $customer_result) {
        $payload = array(
            'iframeId' => get_option('bna_smart_payment_iframe_id'),
            'subtotal' => (float) $order->get_total(),
            'items' => $this->get_order_items($order)
        );

        if (!empty($customer_result['customer_id'])) {
            $payload['customerId'] = $customer_result['customer_id'];
            bna_debug('Using customer ID in payload', array(
                'customer_id' => $customer_result['customer_id']
            ));
        } else {
            $customer_info = $this->build_customer_info($order);
            if (!$customer_info) {
                return false;
            }
            $payload['customerInfo'] = $customer_info;
            bna_debug('Using customer info in payload');
        }

        $payload['invoiceInfo'] = array(
            'invoiceId' => $order->get_order_number(),
            'invoiceAdditionalInfo' => 'WooCommerce Order #' . $order->get_id()
        );

        return $payload;
    }

    private function build_customer_info($order) {
        $email = trim($order->get_billing_email());
        $first_name = $this->clean_name($order->get_billing_first_name());
        $last_name = $this->clean_name($order->get_billing_last_name());

        if (empty($email) || empty($first_name) || empty($last_name)) {
            bna_error('Missing required customer data', array(
                'has_email' => !empty($email),
                'has_firstName' => !empty($first_name),
                'has_lastName' => !empty($last_name)
            ));
            return false;
        }

        $customer_info = array(
            'type' => 'Personal',
            'email' => $email,
            'firstName' => $first_name,
            'lastName' => $last_name
        );

        // Add phone if enabled
        if (get_option('bna_smart_payment_enable_phone') === 'yes') {
            $phone = $this->get_clean_phone($order);
            if ($phone) {
                $customer_info['phoneCode'] = '+1';
                $customer_info['phoneNumber'] = $phone;
            }
        }

        // Add birthdate if enabled
        if (get_option('bna_smart_payment_enable_birthdate') === 'yes') {
            $birthdate = $this->get_valid_birthdate($order);
            if ($birthdate) {
                $customer_info['birthDate'] = $birthdate;
            }
        }

        // Add billing address
        $customer_info['address'] = $this->build_address($order);

        // Add shipping address if different from billing
        $shipping_address = $this->build_shipping_address($order);
        if ($shipping_address) {
            $customer_info['shippingAddress'] = $shipping_address;
            
            bna_log('Shipping address added to customer data', array(
                'order_id' => $order->get_id(),
                'shipping_country' => $shipping_address['country'] ?? 'unknown',
                'shipping_city' => $shipping_address['city'] ?? 'unknown',
                'same_as_billing' => $order->get_meta('_bna_shipping_same_as_billing')
            ));
        } else {
            bna_debug('No shipping address in customer data', array(
                'order_id' => $order->get_id(),
                'shipping_enabled' => get_option('bna_smart_payment_enable_shipping_address'),
                'same_as_billing' => $order->get_meta('_bna_shipping_same_as_billing'),
                'shipping_country_meta' => $order->get_meta('_bna_shipping_country'),
                'shipping_address_1_meta' => $order->get_meta('_bna_shipping_address_1')
            ));
        }

        return $customer_info;
    }

    private function build_address($order) {
        $enable_billing_address = get_option('bna_smart_payment_enable_billing_address', 'no');

        if ($enable_billing_address !== 'yes') {
            return array(
                'postalCode' => $this->format_postal_code($order->get_billing_postcode())
            );
        }

        $street = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());

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

        $apartment = trim($order->get_billing_address_2());
        if (!empty($apartment)) {
            $address['apartment'] = $apartment;
        }

        return $address;
    }

    private function build_shipping_address($order) {
        if (get_option('bna_smart_payment_enable_shipping_address') !== 'yes') {
            bna_debug('Shipping address disabled in settings');
            return null;
        }

        $same_as_billing = $order->get_meta('_bna_shipping_same_as_billing');
        if ($same_as_billing === 'yes') {
            bna_debug('Shipping same as billing, no separate address needed');
            return null;
        }

        // Get shipping data from order meta
        $shipping_country = $order->get_meta('_bna_shipping_country');
        $shipping_address_1 = $order->get_meta('_bna_shipping_address_1');
        $shipping_city = $order->get_meta('_bna_shipping_city');
        $shipping_state = $order->get_meta('_bna_shipping_state');
        $shipping_postcode = $order->get_meta('_bna_shipping_postcode');

        bna_debug('Raw shipping meta data', array(
            'order_id' => $order->get_id(),
            'country' => $shipping_country,
            'address_1' => $shipping_address_1,
            'city' => $shipping_city,
            'state' => $shipping_state,
            'postcode' => $shipping_postcode
        ));

        // Check if required fields are present
        if (empty($shipping_country) || empty($shipping_address_1) || empty($shipping_city)) {
            bna_debug('Missing required shipping fields', array(
                'has_country' => !empty($shipping_country),
                'has_address_1' => !empty($shipping_address_1),
                'has_city' => !empty($shipping_city)
            ));
            return null;
        }

        $street_number = $this->extract_street_number($shipping_address_1);
        $street_name = $this->clean_street_name($shipping_address_1, $street_number);

        $shipping_address = array(
            'streetNumber' => $street_number,
            'streetName' => $street_name,
            'city' => $this->clean_city_name($shipping_city),
            'province' => $shipping_state ?: 'ON',
            'country' => $shipping_country ?: 'CA',
            'postalCode' => $this->format_postal_code($shipping_postcode)
        );

        $shipping_address_2 = $order->get_meta('_bna_shipping_address_2');
        if (!empty($shipping_address_2)) {
            $shipping_address['apartment'] = trim($shipping_address_2);
        }

        bna_debug('Built shipping address', array(
            'shipping_address' => $shipping_address
        ));

        return $shipping_address;
    }

    private function extract_street_number($street) {
        if (empty($street)) {
            return '1';
        }

        $street = trim($street);

        // Try to find number at the beginning
        if (preg_match('/^(\d+)/', $street, $matches)) {
            return $matches[1];
        }

        // Try to find any number in the string
        if (preg_match('/(\d+)/', $street, $matches)) {
            return $matches[1];
        }

        return '1';
    }

    private function clean_street_name($street, $street_number) {
        if (empty($street)) {
            return 'Main Street';
        }

        $cleaned = preg_replace('/^' . preg_quote($street_number, '/') . '\s*/', '', trim($street));
        $cleaned = trim($cleaned);

        if (empty($cleaned)) {
            $cleaned = $street;
        }

        return empty($cleaned) ? 'Main Street' : $cleaned;
    }

    private function get_clean_phone($order) {
        $phone = trim($order->get_billing_phone());

        if (empty($phone)) {
            return false;
        }

        // Remove all non-digits
        $phone = preg_replace('/\D/', '', $phone);

        // Remove leading 1 for North American numbers
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            $phone = substr($phone, 1);
        }

        return strlen($phone) === 10 ? $phone : false;
    }

    private function get_valid_birthdate($order) {
        $birthdate = $order->get_meta('_billing_birthdate');
        if ($this->is_valid_birthdate($birthdate)) {
            return $birthdate;
        }

        // Check POST data as fallback
        if (isset($_POST['billing_birthdate']) && !empty($_POST['billing_birthdate'])) {
            $birthdate = sanitize_text_field($_POST['billing_birthdate']);
            if ($this->is_valid_birthdate($birthdate)) {
                return $birthdate;
            }
        }

        return '1990-01-01';
    }

    private function is_valid_birthdate($birthdate) {
        if (empty($birthdate)) {
            return false;
        }

        $date_obj = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $birthdate) {
            return false;
        }

        $now = new DateTime();
        $eighteen_years_ago = $now->modify('-18 years');
        $hundred_twenty_years_ago = new DateTime('-120 years');

        return $date_obj <= $eighteen_years_ago && $date_obj >= $hundred_twenty_years_ago;
    }

    private function clean_name($name) {
        if (empty($name)) {
            return '';
        }

        $cleaned = preg_replace('/[^a-zA-ZÀ-ÿ\s\'-]/u', '', trim($name));
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);

        return trim($cleaned);
    }

    private function clean_city_name($city) {
        if (empty($city)) {
            return 'Unknown';
        }

        $clean_city = preg_replace('/[^\da-zA-ZÀ-ÖØ-öø-ÿ\s-]/u', '', $city);
        $clean_city = trim($clean_city);

        return empty($clean_city) ? 'Unknown' : $clean_city;
    }

    private function format_postal_code($postal_code) {
        if (empty($postal_code)) {
            return 'A1A 1A1';
        }

        $postal = strtoupper(str_replace(' ', '', $postal_code));

        // Canadian postal code format
        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal)) {
            return substr($postal, 0, 3) . ' ' . substr($postal, 3, 3);
        }

        // US ZIP code format
        if (preg_match('/^\d{5}(-\d{4})?$/', $postal_code)) {
            return $postal_code;
        }

        return $postal_code ?: 'A1A 1A1';
    }

    private function get_order_items($order) {
        $items = array();

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

        bna_debug('Order items created', array(
            'order_id' => $order->get_id(),
            'items_count' => count($items)
        ));

        return $items;
    }

    public function get_iframe_url($token) {
        return $this->get_api_url() . '/v1/checkout/' . $token;
    }

    private function log_request_payload($endpoint, $method, $data) {
        if ($endpoint === 'v1/customers' && $method === 'POST') {
            bna_log('Customer creation payload', array(
                'endpoint' => $endpoint,
                'email' => $data['email'] ?? 'unknown',
                'has_shipping_address' => isset($data['shippingAddress']),
                'shipping_country' => isset($data['shippingAddress']) ? ($data['shippingAddress']['country'] ?? 'unknown') : 'none',
                'payload' => $data
            ));
        }
        
        if ($endpoint === 'v1/checkout' && $method === 'POST') {
            bna_debug('Checkout creation payload', array(
                'endpoint' => $endpoint,
                'has_customer_info' => isset($data['customerInfo']),
                'has_customer_id' => isset($data['customerId']),
                'has_shipping_in_customer_info' => isset($data['customerInfo']['shippingAddress']),
                'payload_keys' => array_keys($data)
            ));
        }
    }

    public function make_request($endpoint, $method = 'GET', $data = array()) {
        $start_time = microtime(true);

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

        // Log important payloads before sending
        if (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $this->log_request_payload($endpoint, $method, $data);
            $args['body'] = wp_json_encode($data);
            $args['headers']['Content-Type'] = 'application/json';
        }

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
            $error_message = $this->extract_error_message($parsed_response, $response_code);

            bna_error('API error response', array(
                'endpoint' => $endpoint,
                'response_code' => $response_code,
                'error_message' => $error_message,
                'request_time_ms' => $request_time,
                'response_body' => $response_body
            ));

            return new WP_Error('api_error', $error_message, array(
                'status' => $response_code,
                'response' => $parsed_response
            ));
        }

        bna_debug('API request successful', array(
            'endpoint' => $endpoint,
            'response_code' => $response_code,
            'request_time_ms' => $request_time
        ));

        return $parsed_response;
    }

    private function extract_error_message($parsed_response, $response_code) {
        if (isset($parsed_response['message'])) {
            return $parsed_response['message'];
        }

        if (isset($parsed_response['error'])) {
            return is_array($parsed_response['error'])
                ? implode(', ', $parsed_response['error'])
                : $parsed_response['error'];
        }

        if (isset($parsed_response['errors'])) {
            return is_array($parsed_response['errors'])
                ? implode(', ', $parsed_response['errors'])
                : $parsed_response['errors'];
        }

        return "API request failed with status $response_code";
    }

    private function get_api_url() {
        return self::ENVIRONMENTS[$this->environment] ?? self::ENVIRONMENTS['staging'];
    }

    private function get_auth_headers() {
        $credentials_string = $this->credentials['access_key'] . ':' . $this->credentials['secret_key'];
        $encoded_credentials = base64_encode($credentials_string);

        return array(
            'Authorization' => 'Basic ' . $encoded_credentials,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );
    }

    private function has_credentials() {
        return !empty($this->credentials['access_key']) && !empty($this->credentials['secret_key']);
    }

    public function test_connection() {
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
