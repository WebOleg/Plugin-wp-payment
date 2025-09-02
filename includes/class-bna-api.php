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

            $is_customer_exists = false;

            if (isset($error_data['status']) && ($error_data['status'] === 400 || $error_data['status'] === 409)) {
                $is_customer_exists = true;
            }

            if (stripos($error_message, 'customer already exist') !== false ||
                stripos($error_message, 'customer already exists') !== false) {
                $is_customer_exists = true;
            }

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

    private function find_existing_customer($email) {
        $response = $this->make_request('v1/customers', 'GET', array(
            'email' => $email,
            'limit' => 1
        ));

        if (is_wp_error($response)) {
            bna_error('Failed to search for existing customer', array(
                'error' => $response->get_error_message(),
                'email' => $email
            ));
            return $response;
        }

        if (empty($response) || !is_array($response)) {
            bna_error('Invalid customer search response format', array('response' => $response));
            return new WP_Error('invalid_search_response', 'Invalid customer search response');
        }

        $customers = isset($response['data']) ? $response['data'] : $response;

        if (empty($customers) || !is_array($customers)) {
            bna_error('No customers found in search response', array(
                'email' => $email,
                'response' => $response
            ));
            return new WP_Error('customer_not_found', 'Customer not found');
        }

        $customer = is_array($customers) && isset($customers[0]) ? $customers[0] : $customers;

        if (empty($customer['id'])) {
            bna_error('Customer found but no ID', array('customer' => $customer));
            return new WP_Error('customer_no_id', 'Customer found but no ID available');
        }

        bna_log('Existing customer found', array(
            'customer_id' => $customer['id'],
            'email' => $email
        ));

        return array(
            'customer_id' => $customer['id'],
            'is_existing' => true
        );
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

        if (get_option('bna_smart_payment_enable_phone') === 'yes') {
            $phone = $this->get_clean_phone($order);
            if ($phone) {
                $customer_info['phoneCode'] = '+1';
                $customer_info['phoneNumber'] = $phone;
            }
        }

        if (get_option('bna_smart_payment_enable_birthdate') === 'yes') {
            $birthdate = $this->get_valid_birthdate($order);
            if ($birthdate) {
                $customer_info['birthDate'] = $birthdate;
            }
        }

        $customer_info['address'] = $this->build_address($order);

        // Add shipping address if different from billing
        $shipping_address = $this->build_shipping_address($order);
        if ($shipping_address) {
            $customer_info['shippingAddress'] = $shipping_address;
            
            bna_debug('Shipping address added to customer info', array(
                'order_id' => $order->get_id(),
                'shipping_country' => $shipping_address['country'] ?? 'unknown'
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
            return null;
        }

        $same_as_billing = $order->get_meta('_bna_shipping_same_as_billing');
        if ($same_as_billing === 'yes') {
            return null;
        }

        $shipping_country = $order->get_meta('_bna_shipping_country');
        $shipping_address_1 = $order->get_meta('_bna_shipping_address_1');
        $shipping_city = $order->get_meta('_bna_shipping_city');
        $shipping_state = $order->get_meta('_bna_shipping_state');
        $shipping_postcode = $order->get_meta('_bna_shipping_postcode');

        if (empty($shipping_country) || empty($shipping_address_1) || empty($shipping_city)) {
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

        return $shipping_address;
    }

    private function extract_street_number($street) {
        if (empty($street)) {
            return '1';
        }

        $street = trim($street);

        if (preg_match('/^(\d+)/', $street, $matches)) {
            return $matches[1];
        }

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

        $phone = preg_replace('/\D/', '', $phone);

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

        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal)) {
            return substr($postal, 0, 3) . ' ' . substr($postal, 3, 3);
        }

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

        if (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
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
                'request_time_ms' => $request_time
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
