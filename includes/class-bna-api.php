<?php

if (!defined('ABSPATH')) {
    exit;
}

class BNA_API {

    private $access_key;
    private $secret_key;
    private $environment;
    private $base_url;

    private static $COUNTRY_CODE_MAPPING = array(
        'US' => 'United States',
        'CA' => 'Canada',
        'GB' => 'United Kingdom',
        'DE' => 'Germany',
        'FR' => 'France',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'JP' => 'Japan',
        'CN' => 'China',
        'IN' => 'India',
        'BR' => 'Brazil',
        'MX' => 'Mexico',
        'AR' => 'Argentina',
        'CL' => 'Chile',
        'CO' => 'Colombia',
        'PE' => 'Peru',
        'VE' => 'Venezuela',
        'UY' => 'Uruguay',
        'PY' => 'Paraguay',
        'BO' => 'Bolivia',
        'EC' => 'Ecuador',
        'GY' => 'Guyana',
        'SR' => 'Suriname',
        'GF' => 'French Guiana',
        'FK' => 'Falkland Islands',
        'UA' => 'Ukraine',
        'PL' => 'Poland',
        'RO' => 'Romania',
        'HU' => 'Hungary',
        'CZ' => 'Czech Republic',
        'SK' => 'Slovakia',
        'SI' => 'Slovenia',
        'HR' => 'Croatia',
        'RS' => 'Serbia',
        'BA' => 'Bosnia and Herzegovina',
        'ME' => 'Montenegro',
        'MK' => 'North Macedonia',
        'AL' => 'Albania',
        'BG' => 'Bulgaria',
        'MD' => 'Moldova',
        'BY' => 'Belarus',
        'LT' => 'Lithuania',
        'LV' => 'Latvia',
        'EE' => 'Estonia'
    );

    /**
     * BNA API subscription frequency mapping
     * @var array
     */
    private static $BNA_SUBSCRIPTION_FREQUENCIES = array(
        'daily' => 'DAILY',
        'weekly' => 'WEEKLY',
        'biweekly' => 'BIWEEKLY',
        'monthly' => 'MONTHLY',
        'quarterly' => 'QUARTERLY',
        'biannual' => 'BIANNUAL',
        'annual' => 'ANNUAL'
    );

    public function __construct() {
        $this->access_key = get_option('bna_smart_payment_access_key', '');
        $this->secret_key = get_option('bna_smart_payment_secret_key', '');
        $this->environment = get_option('bna_smart_payment_environment', 'staging');
        $this->base_url = $this->get_api_url();

        bna_debug('BNA API initialized', array(
            'environment' => $this->environment,
            'has_credentials' => $this->has_credentials(),
            'subscriptions_supported' => true
        ));
    }

    public function has_credentials() {
        return !empty($this->access_key) && !empty($this->secret_key);
    }

    public function get_api_url() {
        if ($this->environment === 'production') {
            return 'https://api-service.bnasmartpayment.com';
        }
        return 'https://dev-api-service.bnasmartpayment.com';
    }

    public function test_connection() {
        if (!$this->has_credentials()) {
            return false;
        }

        $response = $this->make_request('v1/health', 'GET');
        return !is_wp_error($response);
    }

    public function make_request($endpoint, $method = 'GET', $data = array()) {
        if (!$this->has_credentials()) {
            return new WP_Error('missing_credentials', 'API credentials are not configured');
        }

        $request_start_time = microtime(true);
        $url = $this->get_api_url() . '/' . ltrim($endpoint, '/');

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($this->access_key . ':' . $this->secret_key),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );

        if (!empty($data)) {
            if ($method === 'GET') {
                $url = add_query_arg($data, $url);
            } else {
                $args['body'] = wp_json_encode($data);
            }
        }

        bna_log('HTTP Request Details', array(
            'method' => $method,
            'url' => $url,
            'endpoint' => $endpoint,
            'headers' => array(
                'Authorization' => 'Basic [access_key: ' . substr($this->access_key, 0, 4) . '***, secret_key: ***]',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'has_body' => !empty($args['body']),
            'body_size' => isset($args['body']) ? strlen($args['body']) : 0,
            'timeout' => $args['timeout']
        ));

        if (isset($args['body'])) {
            bna_debug('Request Body Content', array(
                'content_type' => 'application/json',
                'body_preview' => substr($args['body'], 0, 300) . (strlen($args['body']) > 300 ? '...' : ''),
                'full_length' => strlen($args['body'])
            ));
        }

        $response = wp_remote_request($url, $args);
        $duration_ms = round((microtime(true) - $request_start_time) * 1000, 2);

        if (is_wp_error($response)) {
            bna_error('HTTP Request Failed', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'duration_ms' => $duration_ms,
                'error' => $response->get_error_message(),
                'error_code' => $response->get_error_code()
            ));
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        bna_log('HTTP Response Details', array(
            'endpoint' => $endpoint,
            'method' => $method,
            'duration_ms' => $duration_ms,
            'status_code' => $status_code,
            'status_message' => wp_remote_retrieve_response_message($response),
            'response_headers' => wp_remote_retrieve_headers($response)->getAll(),
            'body_size' => strlen($body),
            'content_type' => wp_remote_retrieve_header($response, 'content-type')
        ));

        if (!empty($body)) {
            bna_debug('Response Body Content', array(
                'status_code' => $status_code,
                'body_preview' => substr($body, 0, 300) . (strlen($body) > 300 ? '...' : ''),
                'full_length' => strlen($body),
                'is_empty' => empty($body)
            ));
        }

        if ($status_code >= 400) {
            bna_error('API Error Response', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $status_code,
                'response_body' => $body
            ));
            return new WP_Error('api_error', 'API request failed with status ' . $status_code, array('response' => $body));
        }

        // ИСПРАВЛЕНИЕ: Handle successful DELETE operations (204 No Content) and other empty responses
        if ($status_code === 204) {
            bna_log('API Request Successful (No Content)', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $status_code,
                'duration_ms' => $duration_ms
            ));

            return array('success' => true, 'status' => 'deleted');
        }

        // Handle other successful responses with empty body
        if (empty($body) && $status_code >= 200 && $status_code < 300) {
            bna_log('API Request Successful (Empty Response)', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $status_code,
                'duration_ms' => $duration_ms
            ));

            return array('success' => true);
        }

        $decoded_response = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('JSON Decode Error', array(
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'raw_body' => $body
            ));
            return new WP_Error('json_error', 'Failed to decode JSON response');
        }

        bna_log('API Request Successful', array(
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $status_code,
            'duration_ms' => $duration_ms,
            'response_keys' => is_array($decoded_response) ? array_keys($decoded_response) : array()
        ));

        return $decoded_response;
    }

    // ==========================================
    // SUBSCRIPTION MANAGEMENT METHODS (v1.9.0) - FIXED ENDPOINTS
    // ==========================================

    /**
     * Create subscription via BNA API
     * @param string $customer_id BNA Customer ID
     * @param string $frequency Subscription frequency (daily, weekly, monthly, etc.)
     * @param float $amount Subscription amount
     * @param string $currency Currency code (default: CAD)
     * @param array $additional_data Additional subscription data
     * @return array|WP_Error
     */
    public function create_subscription($customer_id, $frequency, $amount, $currency = 'CAD', $additional_data = array()) {
        try {
            if (empty($customer_id) || empty($frequency) || !$amount) {
                return new WP_Error('invalid_subscription_data', 'Customer ID, frequency, and amount are required');
            }

            // Convert WooCommerce frequency to BNA format
            $bna_frequency = $this->convert_frequency_to_bna($frequency);
            if (!$bna_frequency) {
                return new WP_Error('unsupported_frequency', 'Subscription frequency not supported: ' . $frequency);
            }

            $subscription_data = array(
                'customerId' => $customer_id,
                'recurrence' => $bna_frequency,
                'amount' => (float) $amount,
                'currency' => $currency,
                'action' => 'SALE'
            );

            // Add additional data if provided
            if (!empty($additional_data)) {
                $subscription_data = array_merge($subscription_data, $additional_data);
            }

            bna_log('Creating BNA subscription', array(
                'customer_id' => $customer_id,
                'frequency' => $frequency,
                'bna_frequency' => $bna_frequency,
                'amount' => $amount,
                'currency' => $currency
            ));

            $response = $this->make_request('v1/subscription', 'POST', $subscription_data);

            if (is_wp_error($response)) {
                bna_error('Subscription creation failed', array(
                    'customer_id' => $customer_id,
                    'error' => $response->get_error_message()
                ));
                return $response;
            }

            if (empty($response['id'])) {
                return new WP_Error('invalid_subscription_response', 'Subscription ID not found in response');
            }

            bna_log('Subscription created successfully', array(
                'subscription_id' => $response['id'],
                'customer_id' => $customer_id,
                'frequency' => $bna_frequency,
                'amount' => $amount
            ));

            return $response;

        } catch (Exception $e) {
            bna_error('Exception in create_subscription', array(
                'customer_id' => $customer_id,
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return new WP_Error('subscription_creation_exception', 'Subscription creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get subscription by ID
     * @param string $subscription_id BNA Subscription ID
     * @return array|WP_Error
     */
    public function get_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_debug('Retrieving subscription', array('subscription_id' => $subscription_id));

        $response = $this->make_request('v1/subscription/' . $subscription_id, 'GET');

        if (is_wp_error($response)) {
            bna_error('Failed to retrieve subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        bna_log('Subscription retrieved successfully', array(
            'subscription_id' => $subscription_id,
            'status' => $response['status'] ?? 'unknown'
        ));

        return $response;
    }

    /**
     * Get subscriptions for customer
     * @param string $customer_id BNA Customer ID
     * @param string $status Filter by status (optional)
     * @return array|WP_Error
     */
    public function get_customer_subscriptions($customer_id, $status = null) {
        if (empty($customer_id)) {
            return new WP_Error('missing_customer_id', 'Customer ID is required');
        }

        $params = array('customerId' => $customer_id);
        if ($status) {
            $params['status'] = $status;
        }

        bna_debug('Retrieving customer subscriptions', $params);

        $response = $this->make_request('v1/subscription', 'GET', $params);

        if (is_wp_error($response)) {
            bna_error('Failed to retrieve customer subscriptions', array(
                'customer_id' => $customer_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        $subscriptions = $response['data'] ?? array();

        bna_log('Customer subscriptions retrieved', array(
            'customer_id' => $customer_id,
            'subscriptions_count' => count($subscriptions)
        ));

        return $subscriptions;
    }

    /**
     * Suspend subscription - FIXED: Using correct endpoint
     * @param string $subscription_id BNA Subscription ID
     * @return array|WP_Error
     */
    public function suspend_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_log('Suspending subscription', array('subscription_id' => $subscription_id));

        // FIXED: Added /suspend to endpoint per BNA API documentation
        $response = $this->make_request('v1/subscription/' . $subscription_id . '/suspend', 'PATCH', array(
            'suspend' => true
        ));

        if (is_wp_error($response)) {
            bna_error('Failed to suspend subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        bna_log('Subscription suspended successfully', array('subscription_id' => $subscription_id));

        return $response;
    }

    /**
     * Resume suspended subscription - FIXED: Using correct endpoint
     * @param string $subscription_id BNA Subscription ID
     * @return array|WP_Error
     */
    public function resume_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_log('Resuming subscription', array('subscription_id' => $subscription_id));

        // FIXED: Using /suspend endpoint with suspend=false per BNA API documentation
        $response = $this->make_request('v1/subscription/' . $subscription_id . '/suspend', 'PATCH', array(
            'suspend' => false
        ));

        if (is_wp_error($response)) {
            bna_error('Failed to resume subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        bna_log('Subscription resumed successfully', array('subscription_id' => $subscription_id));

        return $response;
    }

    /**
     * Delete subscription
     * @param string $subscription_id BNA Subscription ID
     * @return array|WP_Error
     */
    public function delete_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_log('Deleting subscription', array('subscription_id' => $subscription_id));

        $response = $this->make_request('v1/subscription/' . $subscription_id, 'DELETE');

        if (is_wp_error($response)) {
            bna_error('Failed to delete subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        bna_log('Subscription deleted successfully', array('subscription_id' => $subscription_id));

        return $response;
    }

    /**
     * Resend subscription notification
     * @param string $subscription_id BNA Subscription ID
     * @return array|WP_Error
     */
    public function resend_subscription_notification($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_log('Resending subscription notification', array('subscription_id' => $subscription_id));

        $response = $this->make_request('v1/subscription/' . $subscription_id . '/notify', 'POST');

        if (is_wp_error($response)) {
            bna_error('Failed to resend subscription notification', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        bna_log('Subscription notification sent successfully', array('subscription_id' => $subscription_id));

        return $response;
    }

    /**
     * Convert WooCommerce frequency to BNA API format
     * @param string $wc_frequency
     * @return string|false
     */
    private function convert_frequency_to_bna($wc_frequency) {
        return self::$BNA_SUBSCRIPTION_FREQUENCIES[$wc_frequency] ?? false;
    }

    /**
     * Get supported subscription frequencies
     * @return array
     */
    public static function get_supported_frequencies() {
        return self::$BNA_SUBSCRIPTION_FREQUENCIES;
    }

    /**
     * Check if order contains subscription products
     * @param WC_Order $order
     * @return bool
     */
    private function order_has_subscriptions($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && BNA_Subscriptions::is_subscription_product($product)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get subscription data from order items
     * @param WC_Order $order
     * @return array|false
     */
    private function get_order_subscription_data($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && BNA_Subscriptions::is_subscription_product($product)) {
                return BNA_Subscriptions::get_subscription_data($product);
            }
        }
        return false;
    }

    // ==========================================
    // EXISTING METHODS (Updated for Subscriptions)
    // ==========================================

    private function build_shipping_address($order) {
        if (get_option('bna_smart_payment_enable_shipping_address') !== 'yes') {
            return null;
        }

        $same_as_billing = $order->get_meta('_bna_shipping_same_as_billing');

        if ($same_as_billing === '1') {
            bna_debug('Shipping same as billing, using billing address', array(
                'order_id' => $order->get_id()
            ));
            return $this->build_address($order);
        }

        $shipping_country = $order->get_shipping_country();
        $shipping_address_1 = $order->get_shipping_address_1();
        $shipping_city = $order->get_shipping_city();
        $shipping_state = $order->get_shipping_state();
        $shipping_postcode = $order->get_shipping_postcode();
        $shipping_address_2 = $order->get_shipping_address_2();

        if (empty($shipping_country) || empty($shipping_address_1) || empty($shipping_city)) {
            $shipping_country = $order->get_meta('_bna_shipping_country');
            $shipping_address_1 = $order->get_meta('_bna_shipping_address_1');
            $shipping_city = $order->get_meta('_bna_shipping_city');
            $shipping_state = $order->get_meta('_bna_shipping_state');
            $shipping_postcode = $order->get_meta('_bna_shipping_postcode');
            $shipping_address_2 = $order->get_meta('_bna_shipping_address_2');
        }

        if (empty($shipping_country) || empty($shipping_address_1) || empty($shipping_city)) {
            bna_debug('Shipping address incomplete, skipping', array(
                'order_id' => $order->get_id(),
                'has_country' => !empty($shipping_country),
                'has_address' => !empty($shipping_address_1),
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
            'province' => $shipping_state ? $shipping_state : 'ON',
            'country' => $this->map_country_code($shipping_country),
            'postalCode' => $this->format_postal_code($shipping_postcode)
        );

        $apartment = trim($shipping_address_2);
        if (!empty($apartment)) {
            $shipping_address['apartment'] = $apartment;
        }

        bna_debug('Shipping address built successfully', array(
            'final_address' => $shipping_address
        ));

        return $shipping_address;
    }

    private function build_customer_info($order) {
        try {
            bna_debug('Building customer info for order', array(
                'order_id' => $order->get_id()
            ));

            $email = trim($order->get_billing_email());
            $first_name = $this->clean_name($order->get_billing_first_name());
            $last_name = $this->clean_name($order->get_billing_last_name());

            if (empty($email) || empty($first_name) || empty($last_name)) {
                bna_error('Missing required customer data', array(
                    'email' => !empty($email),
                    'first_name' => !empty($first_name),
                    'last_name' => !empty($last_name)
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
                $phone_data = $this->process_phone_number($order);
                if ($phone_data) {
                    $customer_info['phoneCode'] = $phone_data['code'];
                    $customer_info['phoneNumber'] = $phone_data['number'];

                    bna_log('Phone data added to customer', array(
                        'phone_code' => $phone_data['code'],
                        'phone_number' => $phone_data['number']
                    ));
                }
            }

            if (get_option('bna_smart_payment_enable_birthdate') === 'yes') {
                $birthdate = $this->get_valid_birthdate($order);
                if ($birthdate) {
                    $customer_info['birthDate'] = $birthdate;
                }
            }

            $billing_address = $this->build_address($order);
            if (!empty($billing_address)) {
                $customer_info['billingAddress'] = $billing_address;
            }

            $shipping_address = $this->build_shipping_address($order);
            if ($shipping_address) {
                $customer_info['shippingAddress'] = $shipping_address;
            }

            $has_phone = isset($customer_info['phoneCode']) && isset($customer_info['phoneNumber']);
            $address_street = $customer_info['billingAddress']['streetName'] ?? 'unknown';
            $address_number = $customer_info['billingAddress']['streetNumber'] ?? 'unknown';

            bna_log('Customer info built successfully', array(
                'fields_count' => count($customer_info),
                'has_shipping' => isset($customer_info['shippingAddress']),
                'has_phone' => $has_phone,
                'address_street' => $address_street,
                'address_number' => $address_number
            ));

            return $customer_info;

        } catch (Exception $e) {
            bna_error('Exception in build_customer_info', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return false;
        }
    }

    private function generate_customer_data_hash($customer_data) {
        try {
            bna_debug('Generating hash for customer data', array(
                'data_keys' => array_keys($customer_data)
            ));

            $relevant_data = array();

            $fields_to_check = array(
                'firstName', 'lastName', 'email', 'phoneCode', 'phoneNumber',
                'birthDate', 'billingAddress', 'shippingAddress', 'type'
            );

            foreach ($fields_to_check as $field) {
                if (isset($customer_data[$field])) {
                    if (is_array($customer_data[$field])) {
                        ksort($customer_data[$field]);
                        $relevant_data[$field] = $customer_data[$field];
                    } else {
                        $relevant_data[$field] = trim($customer_data[$field]);
                    }
                }
            }

            ksort($relevant_data);

            $json_string = $this->safe_json_encode($relevant_data);
            $hash = md5($json_string);

            bna_debug('Generated customer data hash', array(
                'hash' => $hash,
                'data_keys' => array_keys($relevant_data)
            ));

            return $hash;

        } catch (Exception $e) {
            bna_error('Exception in generate_customer_data_hash', array(
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'customer_data' => $this->safe_json_encode($customer_data)
            ));

            return md5(serialize($customer_data));
        }
    }

    private function has_customer_data_changed($order, $current_data) {
        try {
            bna_debug('Checking if customer data changed', array(
                'order_id' => $order->get_id()
            ));

            $stored_hash = $order->get_meta('_bna_customer_data_hash');
            $current_hash = $this->generate_customer_data_hash($current_data);

            bna_debug('Comparing customer data hashes', array(
                'order_id' => $order->get_id(),
                'stored_hash' => $stored_hash,
                'current_hash' => $current_hash,
                'has_stored_hash' => !empty($stored_hash),
                'are_different' => ($stored_hash !== $current_hash)
            ));

            if (empty($stored_hash)) {
                bna_log('No stored hash found, considering data as changed', array(
                    'order_id' => $order->get_id()
                ));
                return true;
            }

            return $stored_hash !== $current_hash;

        } catch (Exception $e) {
            bna_error('Exception in has_customer_data_changed', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return true;
        }
    }

    public function generate_checkout_token($order) {
        try {
            $iframe_id = get_option('bna_smart_payment_iframe_id');
            if (empty($iframe_id)) {
                bna_error('iFrame ID not configured');
                return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
            }

            $is_subscription_order = $this->order_has_subscriptions($order);

            bna_log('Generating checkout token', array(
                'order_id' => $order->get_id(),
                'order_total' => $order->get_total(),
                'is_subscription' => $is_subscription_order
            ));

            $customer_result = $this->get_or_create_customer($order);

            if (is_wp_error($customer_result)) {
                bna_error('Customer creation/retrieval failed', array(
                    'order_id' => $order->get_id(),
                    'error' => $customer_result->get_error_message()
                ));
                return $customer_result;
            }

            $payload = $this->create_checkout_payload($order, $customer_result);

            if (!$payload) {
                bna_error('Failed to create checkout payload', array(
                    'order_id' => $order->get_id()
                ));
                return new WP_Error('payload_error', 'Failed to create checkout payload');
            }

            $response = $this->make_request('v1/checkout', 'POST', $payload);

            if (is_wp_error($response)) {
                bna_error('Checkout token generation failed', array(
                    'order_id' => $order->get_id(),
                    'error' => $response->get_error_message()
                ));
                return $response;
            }

            if (empty($response['token'])) {
                bna_error('Token not found in response', array(
                    'order_id' => $order->get_id(),
                    'response_keys' => array_keys($response)
                ));
                return new WP_Error('missing_token', 'Token not found in API response');
            }

            // Store customer data hash for future reference
            $customer_info = $this->build_customer_info($order);
            if ($customer_info) {
                $current_hash = $this->generate_customer_data_hash($customer_info);
                $order->update_meta_data('_bna_customer_data_hash', $current_hash);
                $order->save();
            }

            bna_log('Checkout token generated successfully', array(
                'order_id' => $order->get_id(),
                'customer_id' => $customer_result['customer_id'] ?? 'unknown',
                'was_updated' => $customer_result['was_updated'] ?? false,
                'token_length' => strlen($response['token']),
                'is_subscription' => $is_subscription_order
            ));

            return $response;

        } catch (Exception $e) {
            bna_error('Exception in generate_checkout_token', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ));

            return new WP_Error('checkout_exception', 'Token generation failed: ' . $e->getMessage());
        }
    }

    private function get_or_create_customer($order) {
        try {
            bna_debug('Starting get_or_create_customer', array(
                'order_id' => $order->get_id()
            ));

            $existing_customer_id = $order->get_meta('_bna_customer_id');

            if (empty($existing_customer_id) && is_user_logged_in()) {
                $wp_customer_id = $order->get_customer_id();
                $existing_customer_id = get_user_meta($wp_customer_id, '_bna_customer_id', true);

                if (!empty($existing_customer_id)) {
                    $order->add_meta_data('_bna_customer_id', $existing_customer_id);
                    $order->save();

                    bna_log('Found existing BNA customer ID in user meta', array(
                        'wp_customer_id' => $wp_customer_id,
                        'bna_customer_id' => $existing_customer_id
                    ));
                }
            }

            bna_debug('Building customer info', array(
                'order_id' => $order->get_id()
            ));

            $customer_data = $this->build_customer_info($order);

            if (!$customer_data) {
                bna_error('Failed to build customer data', array(
                    'order_id' => $order->get_id()
                ));
                return new WP_Error('customer_data_error', 'Failed to build customer data');
            }

            bna_debug('Customer data built successfully', array(
                'order_id' => $order->get_id(),
                'data_keys' => array_keys($customer_data)
            ));

            if (!empty($existing_customer_id)) {
                if ($this->has_customer_data_changed($order, $customer_data)) {
                    bna_log('Customer data changed, updating', array(
                        'customer_id' => $existing_customer_id,
                        'order_id' => $order->get_id()
                    ));

                    return $this->update_existing_customer($existing_customer_id, $customer_data, $order);
                } else {
                    bna_debug('Customer data unchanged, using existing customer', array(
                        'customer_id' => $existing_customer_id
                    ));

                    return array(
                        'customer_id' => $existing_customer_id,
                        'is_existing' => true,
                        'was_updated' => false
                    );
                }
            }

            return $this->create_new_customer($customer_data, $order);

        } catch (Exception $e) {
            bna_error('Exception in get_or_create_customer', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return new WP_Error('customer_exception', 'Customer operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create checkout payload - Fixed for API compatibility (v1.9.0)
     */
    private function create_checkout_payload($order, $customer_result) {
        $payload = array(
            'iframeId' => get_option('bna_smart_payment_iframe_id'),
            'subtotal' => (float) $order->get_total(),
            'items' => $this->get_order_items($order)
        );

        // Check if this is a subscription order
        $subscription_data = $this->get_order_subscription_data($order);
        if ($subscription_data && bna_subscriptions_enabled()) {
            // Add recurrence data for subscription orders
            $bna_frequency = $this->convert_frequency_to_bna($subscription_data['frequency']);

            if ($bna_frequency) {
                $payload['recurrence'] = $bna_frequency;
                // REMOVED: $payload['recurring'] = true; - not allowed by API

                bna_log('Added subscription data to checkout payload', array(
                    'order_id' => $order->get_id(),
                    'frequency' => $subscription_data['frequency'],
                    'bna_frequency' => $bna_frequency,
                    'trial_days' => $subscription_data['trial_days'],
                    'signup_fee' => $subscription_data['signup_fee']
                ));

                // Add signup fee if present
                if ($subscription_data['signup_fee'] > 0) {
                    $payload['signupFee'] = (float) $subscription_data['signup_fee'];
                }

                // Add trial period if present
                if ($subscription_data['trial_days'] > 0) {
                    $payload['trialDays'] = (int) $subscription_data['trial_days'];
                }
            }
        }

        // Add customer information
        if (!empty($customer_result['customer_id'])) {
            $payload['customerId'] = $customer_result['customer_id'];
        } else {
            $customer_info = $this->build_customer_info($order);
            if (!$customer_info) {
                return false;
            }
            $payload['customerInfo'] = $customer_info;
        }

        // Add invoice information
        $payload['invoiceInfo'] = array(
            'invoiceId' => $order->get_order_number(),
            'invoiceAdditionalInfo' => 'WooCommerce Order #' . $order->get_id()
        );

        // REMOVED: $payload['saveCustomer'] = true; - not allowed by API

        return $payload;
    }

    private function get_order_items($order) {
        $items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';

            if (empty($sku)) {
                $product_name = $item->get_name();
                $sku = 'WC-' . $item_id . '-' . sanitize_title($product_name);
            }

            $items[] = array(
                'sku' => $sku,
                'description' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'price' => (float) $order->get_item_total($item, false, false),
                'amount' => (float) $order->get_line_total($item, false, false)
            );
        }
        return $items;
    }

    public function get_iframe_url($token) {
        return $this->get_api_url() . '/v1/checkout/' . $token;
    }

    private function create_new_customer($customer_data, $order = null) {
        try {
            $validation_result = $this->validate_customer_data($customer_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            bna_debug('Creating new customer', array(
                'customer_email' => $customer_data['email']
            ));

            $response = $this->make_request('v1/customers', 'POST', $customer_data);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $error_data = $response->get_error_data();

                if ($this->is_customer_exists_error($error_message, $error_data)) {
                    bna_debug('Customer exists, searching', array(
                        'customer_email' => $customer_data['email']
                    ));
                    return $this->find_existing_customer($customer_data['email'], $order);
                }

                return $response;
            }

            if (empty($response['id'])) {
                return new WP_Error('invalid_customer_response', 'Customer ID not found in response');
            }

            bna_log('New customer created', array(
                'customer_id' => $response['id'],
                'customer_email' => $customer_data['email']
            ));

            if ($order) {
                $data_hash = $this->generate_customer_data_hash($customer_data);
                $order->update_meta_data('_bna_customer_data_hash', $data_hash);

                if (is_user_logged_in()) {
                    $wp_customer_id = $order->get_customer_id();
                    if ($wp_customer_id) {
                        update_user_meta($wp_customer_id, '_bna_customer_id', $response['id']);

                        bna_log('Saved BNA customer ID to user meta', array(
                            'wp_customer_id' => $wp_customer_id,
                            'bna_customer_id' => $response['id']
                        ));
                    }
                }

                $order->save();
            }

            return array(
                'customer_id' => $response['id'],
                'is_existing' => false
            );

        } catch (Exception $e) {
            bna_error('Exception in create_new_customer', array(
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'customer_email' => $customer_data['email'] ?? 'unknown'
            ));

            return new WP_Error('customer_creation_exception', 'Customer creation failed: ' . $e->getMessage());
        }
    }

    private function update_existing_customer($customer_id, $customer_data, $order) {
        try {
            $update_data = $customer_data;

            if (isset($update_data['email'])) {
                unset($update_data['email']);
                bna_debug('Removed email from update data - email cannot be updated for existing customers');
            }

            bna_log('Updating customer', array(
                'customer_id' => $customer_id,
                'fields_to_update' => array_keys($update_data),
                'has_shipping_address' => isset($update_data['shippingAddress'])
            ));

            $response = $this->make_request('v1/customers/' . $customer_id, 'PATCH', $update_data);

            if (is_wp_error($response)) {
                bna_error('Customer update failed', array(
                    'customer_id' => $customer_id,
                    'error' => $response->get_error_message()
                ));
                return $response;
            }

            bna_log('Customer updated successfully', array(
                'customer_id' => $customer_id
            ));

            $data_hash = $this->generate_customer_data_hash($customer_data);
            $order->update_meta_data('_bna_customer_data_hash', $data_hash);
            $order->save();

            bna_log('Customer update completed successfully', array(
                'customer_id' => $customer_id,
                'order_id' => $order->get_id()
            ));

            return array(
                'customer_id' => $customer_id,
                'is_existing' => true,
                'was_updated' => true
            );

        } catch (Exception $e) {
            bna_error('Exception in update_existing_customer', array(
                'customer_id' => $customer_id,
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return new WP_Error('customer_update_exception', 'Customer update failed: ' . $e->getMessage());
        }
    }

    private function find_existing_customer($email, $order = null) {
        try {
            $response = $this->make_request('v1/customers', 'GET', array('email' => $email));

            if (is_wp_error($response)) {
                return $response;
            }

            if (empty($response['data']) || !is_array($response['data'])) {
                return new WP_Error('customer_not_found', 'Customer not found');
            }

            $customer = reset($response['data']);

            if (empty($customer['id'])) {
                return new WP_Error('invalid_customer_data', 'Invalid customer data received');
            }

            bna_log('Found existing customer', array(
                'customer_id' => $customer['id'],
                'customer_email' => $email
            ));

            if ($order && is_user_logged_in()) {
                $wp_customer_id = $order->get_customer_id();
                if ($wp_customer_id) {
                    update_user_meta($wp_customer_id, '_bna_customer_id', $customer['id']);
                }
            }

            return array(
                'customer_id' => $customer['id'],
                'is_existing' => true
            );

        } catch (Exception $e) {
            bna_error('Exception in find_existing_customer', array(
                'email' => $email,
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return new WP_Error('customer_search_exception', 'Customer search failed: ' . $e->getMessage());
        }
    }

    private function validate_customer_data($customer_data) {
        $errors = array();

        if (empty($customer_data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!is_email($customer_data['email'])) {
            $errors[] = 'Invalid email format';
        }

        if (empty($customer_data['firstName'])) {
            $errors[] = 'First name is required';
        }

        if (empty($customer_data['lastName'])) {
            $errors[] = 'Last name is required';
        }

        if (isset($customer_data['phoneNumber'])) {
            if (!$this->is_valid_phone_number($customer_data['phoneNumber'])) {
                $errors[] = 'Invalid phone number format';
            }
        }

        if (!empty($errors)) {
            return new WP_Error('validation_failed', 'Customer data validation failed', $errors);
        }

        return true;
    }

    private function is_valid_country_code($country_code) {
        return isset(self::$COUNTRY_CODE_MAPPING[$country_code]) ||
            in_array($country_code, self::$COUNTRY_CODE_MAPPING);
    }

    private function is_valid_phone_number($phone) {
        $digits_only = preg_replace('/\D/', '', $phone);
        return strlen($digits_only) >= 7 && strlen($digits_only) <= 15;
    }

    private function is_customer_exists_error($error_message, $error_data) {
        return strpos($error_message, 'already exists') !== false ||
            strpos($error_message, 'duplicate') !== false ||
            (is_array($error_data) && isset($error_data['response']) &&
                strpos($error_data['response'], 'already exists') !== false);
    }

    private function build_address($order) {
        $country = $order->get_billing_country();
        $address_1 = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());
        $state = $order->get_billing_state();
        $postal_code = $order->get_billing_postcode();

        if (empty($country) || empty($address_1) || empty($city)) {
            bna_debug('Address incomplete, skipping', array(
                'has_country' => !empty($country),
                'has_address' => !empty($address_1),
                'has_city' => !empty($city)
            ));
            return null;
        }

        $street_number = $this->extract_street_number($address_1);
        $street_name = $this->clean_street_name($address_1, $street_number);

        $address = array(
            'streetNumber' => $street_number,
            'streetName' => $street_name,
            'city' => $this->clean_city_name($city),
            'province' => $state ? $state : 'ON',
            'country' => $this->map_country_code($country),
            'postalCode' => $this->format_postal_code($postal_code)
        );

        $apartment = trim($order->get_billing_address_2());
        if (!empty($apartment)) {
            $address['apartment'] = $apartment;
        }

        bna_debug('Address built successfully', array(
            'final_address' => $address
        ));

        return $address;
    }

    private function process_phone_number($order) {
        $phone = trim($order->get_billing_phone());
        $billing_country = $order->get_billing_country();

        if (empty($phone)) {
            return null;
        }

        bna_debug('Processing phone number', array(
            'original_phone' => $phone,
            'billing_country' => $billing_country
        ));

        $digits_only = preg_replace('/\D/', '', $phone);

        $phone_code = $this->determine_phone_country_code($digits_only, $billing_country);
        $phone_number = $this->format_phone_number($digits_only, $phone_code);

        if (!$phone_number) {
            bna_debug('Phone number could not be processed', array(
                'original' => $phone,
                'digits_only' => $digits_only
            ));
            return null;
        }

        bna_log('Phone number processed successfully', array(
            'original' => $phone,
            'digits_only' => $digits_only,
            'result_code' => $phone_code,
            'result_number' => $phone_number
        ));

        return array(
            'code' => $phone_code,
            'number' => $phone_number
        );
    }

    private function determine_phone_country_code($digits_only, $billing_country) {
        bna_debug('Determining phone country code', array(
            'digits_only' => $digits_only,
            'length' => strlen($digits_only),
            'billing_country' => $billing_country,
            'first_digit' => substr($digits_only, 0, 1),
            'first_three' => substr($digits_only, 0, 3)
        ));

        $ukraine_mobile_prefixes = array('050', '063', '066', '067', '068', '091', '092', '093', '094', '095', '096', '097', '098', '099');

        if (strlen($digits_only) == 10 && substr($digits_only, 0, 1) === '0') {
            $prefix = substr($digits_only, 0, 3);
            if (in_array($prefix, $ukraine_mobile_prefixes)) {
                bna_log('Detected Ukrainian mobile number', array(
                    'prefix' => $prefix,
                    'number' => $digits_only
                ));
                return '+380';
            }
        }

        $phone_country_map = array(
            'CA' => '+1',
            'US' => '+1',
            'GB' => '+44',
            'DE' => '+49',
            'FR' => '+33',
            'UA' => '+380',
            'PL' => '+48',
            'AU' => '+61',
            'JP' => '+81',
            'CN' => '+86',
            'IN' => '+91',
            'BR' => '+55',
            'MX' => '+52'
        );

        if (isset($phone_country_map[$billing_country])) {
            return $phone_country_map[$billing_country];
        }

        return '+1';
    }

    private function format_phone_number($digits_only, $phone_code) {
        if ($phone_code === '+380') {
            if (strlen($digits_only) == 10 && substr($digits_only, 0, 1) === '0') {
                return substr($digits_only, 1);
            }
            if (strlen($digits_only) == 9) {
                return $digits_only;
            }
        }

        if ($phone_code === '+1') {
            if (strlen($digits_only) == 11 && substr($digits_only, 0, 1) === '1') {
                return substr($digits_only, 1);
            }
            if (strlen($digits_only) == 10) {
                return $digits_only;
            }
        }

        return $digits_only;
    }

    private function get_valid_birthdate($order) {
        $birthdate = $order->get_meta('_billing_birthdate');

        if (empty($birthdate)) {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $birthdate);
        if ($date && $date->format('Y-m-d') === $birthdate) {
            $today = new DateTime();
            $age = $today->diff($date)->y;

            if ($age >= 13 && $age <= 120) {
                return $birthdate;
            }
        }

        return null;
    }

    private function extract_street_number($address_string) {
        $address_string = trim($address_string);

        if (preg_match('/^(\d+[a-zA-Z]?)\s+(.+)/', $address_string, $matches)) {
            bna_debug('Street number found at beginning', array(
                'number' => $matches[1],
                'pattern' => 'beginning'
            ));
            return $matches[1];
        }

        if (preg_match('/(.+)\s+(\d+[a-zA-Z]?)$/', $address_string, $matches)) {
            bna_debug('Street number found at end', array(
                'number' => $matches[2],
                'pattern' => 'end'
            ));
            return $matches[2];
        }

        if (preg_match('/(\d+[a-zA-Z]?)/', $address_string, $matches)) {
            bna_debug('Street number found anywhere', array(
                'number' => $matches[1],
                'pattern' => 'anywhere'
            ));
            return $matches[1];
        }

        bna_debug('No street number found, using default', array(
            'address' => $address_string
        ));
        return '1';
    }

    private function clean_street_name($address_string, $street_number) {
        bna_debug('Cleaning street name', array(
            'original_street' => $address_string,
            'street_number' => $street_number
        ));

        $street_name = trim($address_string);

        $street_name = preg_replace('/^' . preg_quote($street_number, '/') . '\s*/', '', $street_name);
        $street_name = preg_replace('/\s*' . preg_quote($street_number, '/') . '$/', '', $street_name);

        $street_name = trim($street_name);

        if (empty($street_name)) {
            $street_name = 'Main Street';
        }

        bna_debug('Street name cleaned', array(
            'original' => $address_string,
            'number_removed' => $street_number,
            'final_street_name' => $street_name
        ));

        return $street_name;
    }

    private function clean_city_name($city) {
        return trim(ucwords(strtolower($city)));
    }

    private function clean_name($name) {
        return trim(ucwords(strtolower($name)));
    }

    private function map_country_code($country_code) {
        return isset(self::$COUNTRY_CODE_MAPPING[$country_code]) ?
            self::$COUNTRY_CODE_MAPPING[$country_code] : $country_code;
    }

    private function format_postal_code($postal_code) {
        $postal_code = strtoupper(trim($postal_code));

        if (preg_match('/^([A-Z]\d[A-Z])\s*(\d[A-Z]\d)$/', $postal_code, $matches)) {
            return $matches[1] . ' ' . $matches[2];
        }

        return $postal_code;
    }

    private function safe_json_encode($data) {
        $flags = JSON_UNESCAPED_UNICODE;

        if (defined('JSON_SORT_KEYS')) {
            $flags |= JSON_SORT_KEYS;
        }

        $json = wp_json_encode($data, $flags);

        if ($json === false) {
            bna_error('JSON encode failed', array(
                'error' => json_last_error_msg(),
                'data_type' => gettype($data)
            ));
            return serialize($data);
        }

        return $json;
    }
}