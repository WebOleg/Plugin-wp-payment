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
        $gateway_settings = get_option('woocommerce_bna_smart_payment_settings', array());

        $this->access_key = isset($gateway_settings['access_key']) ? $gateway_settings['access_key'] : '';
        $this->secret_key = isset($gateway_settings['secret_key']) ? $gateway_settings['secret_key'] : '';
        $this->environment = isset($gateway_settings['environment']) ? $gateway_settings['environment'] : 'dev';
        $this->base_url = $this->get_api_url();

        bna_debug('BNA API initialized', array(
            'environment' => $this->environment,
            'has_credentials' => $this->has_credentials()
        ));
    }

    public function has_credentials() {
        return !empty($this->access_key) && !empty($this->secret_key);
    }

    public function get_api_url() {
        if ($this->environment === 'production') {
            return 'https://api.bnasmartpayment.com';
        } elseif ($this->environment === 'staging') {
            return 'https://stage-api-service.bnasmartpayment.com';
        }
        return 'https://dev-api-service.bnasmartpayment.com';
    }

    private function get_current_iframe_id() {
        $gateway_settings = get_option('woocommerce_bna_smart_payment_settings', array());
        return isset($gateway_settings['iframe_id']) ? $gateway_settings['iframe_id'] : '';
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
                $encoded_params = array();
                foreach ($data as $key => $value) {
                    $encoded_params[urlencode($key)] = urlencode($value);
                }
                $url = add_query_arg($encoded_params, $url);
            } else {
                $args['body'] = wp_json_encode($data);
            }
        }

        bna_log('HTTP Request', array(
            'method' => $method,
            'endpoint' => $endpoint,
            'has_body' => !empty($args['body'])
        ));

        if (isset($args['body'])) {
            bna_debug('Request Body', array(
                'preview' => substr($args['body'], 0, 300) . (strlen($args['body']) > 300 ? '...' : '')
            ));
        }

        $response = wp_remote_request($url, $args);
        $duration_ms = round((microtime(true) - $request_start_time) * 1000, 2);

        if (is_wp_error($response)) {
            bna_error('HTTP Request Failed', array(
                'endpoint' => $endpoint,
                'duration_ms' => $duration_ms,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        bna_log('HTTP Response', array(
            'endpoint' => $endpoint,
            'status_code' => $status_code,
            'duration_ms' => $duration_ms
        ));

        if ($status_code >= 400) {
            bna_error('API Error', array(
                'endpoint' => $endpoint,
                'status_code' => $status_code,
                'response_body' => $body
            ));
            return new WP_Error('api_error', 'API request failed with status ' . $status_code, array('response' => $body));
        }

        if ($status_code === 204) {
            bna_log('API Success (No Content)', array('endpoint' => $endpoint));
            return array('success' => true, 'status' => 'deleted');
        }

        if (empty($body) && $status_code >= 200 && $status_code < 300) {
            return array('success' => true);
        }

        $decoded_response = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('JSON Decode Error', array(
                'endpoint' => $endpoint,
                'error' => json_last_error_msg()
            ));
            return new WP_Error('json_error', 'Failed to decode JSON response');
        }

        bna_log('API Success', array('endpoint' => $endpoint, 'status_code' => $status_code));

        return $decoded_response;
    }

    public function create_subscription($customer_id, $frequency, $amount, $currency = 'CAD', $additional_data = array()) {
        try {
            if (empty($customer_id) || empty($frequency) || !$amount) {
                return new WP_Error('invalid_subscription_data', 'Customer ID, frequency, and amount are required');
            }

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

            if (!empty($additional_data['startPaymentDate'])) {
                $subscription_data['startPaymentDate'] = $additional_data['startPaymentDate'];

                bna_log('Trial period detected', array(
                    'startPaymentDate' => $additional_data['startPaymentDate'],
                    'trial_days' => isset($additional_data['trial_days']) ? $additional_data['trial_days'] : 'unknown'
                ));
            }

            if (!empty($additional_data)) {
                $subscription_data = array_merge($subscription_data, $additional_data);
            }

            bna_log('Creating subscription', array(
                'customer_id' => $customer_id,
                'frequency' => $bna_frequency,
                'amount' => $amount
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

            bna_log('Subscription created', array(
                'subscription_id' => $response['id'],
                'customer_id' => $customer_id
            ));

            return $response;

        } catch (Exception $e) {
            bna_error('Exception in create_subscription', array(
                'customer_id' => $customer_id,
                'exception' => $e->getMessage()
            ));
            return new WP_Error('subscription_creation_exception', 'Subscription creation failed: ' . $e->getMessage());
        }
    }

    public function get_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        $response = $this->make_request('v1/subscription/' . $subscription_id, 'GET');

        if (is_wp_error($response)) {
            bna_error('Failed to retrieve subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        return $response;
    }

    public function get_customer_subscriptions($customer_id, $status = null) {
        if (empty($customer_id)) {
            return new WP_Error('missing_customer_id', 'Customer ID is required');
        }

        $params = array('customerId' => $customer_id);
        if ($status) {
            $params['status'] = $status;
        }

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
            'count' => count($subscriptions)
        ));

        return $subscriptions;
    }

    public function suspend_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_log('Suspending subscription', array('subscription_id' => $subscription_id));

        $response = $this->make_request('v1/subscription/' . $subscription_id . '/suspend', 'PATCH', array('suspend' => true));

        if (is_wp_error($response)) {
            bna_error('Failed to suspend subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        return $response;
    }

    public function resume_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_log('Resuming subscription', array('subscription_id' => $subscription_id));

        $response = $this->make_request('v1/subscription/' . $subscription_id . '/suspend', 'PATCH', array('suspend' => false));

        if (is_wp_error($response)) {
            bna_error('Failed to resume subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        return $response;
    }

    public function cancel_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        bna_log('Cancelling subscription', array('subscription_id' => $subscription_id));

        $response = $this->make_request('v1/subscription/' . $subscription_id, 'DELETE');

        if (is_wp_error($response)) {
            bna_error('Failed to cancel subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        return $response;
    }

    public function delete_subscription($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        $response = $this->make_request('v1/subscription/' . $subscription_id, 'DELETE');

        if (is_wp_error($response)) {
            bna_error('Failed to delete subscription', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        return $response;
    }

    public function resend_subscription_notification($subscription_id) {
        if (empty($subscription_id)) {
            return new WP_Error('missing_subscription_id', 'Subscription ID is required');
        }

        $response = $this->make_request('v1/subscription/' . $subscription_id . '/notify', 'POST');

        if (is_wp_error($response)) {
            bna_error('Failed to resend subscription notification', array(
                'subscription_id' => $subscription_id,
                'error' => $response->get_error_message()
            ));
            return $response;
        }

        return $response;
    }

    private function convert_frequency_to_bna($wc_frequency) {
        return self::$BNA_SUBSCRIPTION_FREQUENCIES[$wc_frequency] ?? false;
    }

    public static function get_supported_frequencies() {
        return self::$BNA_SUBSCRIPTION_FREQUENCIES;
    }

    private function order_has_subscriptions($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && BNA_Subscriptions::is_subscription_product($product)) {
                return true;
            }
        }
        return false;
    }

    private function get_order_subscription_data($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && BNA_Subscriptions::is_subscription_product($product)) {
                return BNA_Subscriptions::get_subscription_data($product);
            }
        }
        return false;
    }

    private function build_shipping_address($order) {
        if (get_option('bna_smart_payment_enable_shipping_address') !== 'yes') {
            return null;
        }

        $same_as_billing = $order->get_meta('_bna_shipping_same_as_billing');
        if ($same_as_billing === '1') {
            bna_debug('Shipping same as billing', array('order_id' => $order->get_id()));
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
            bna_debug('Shipping address incomplete', array('order_id' => $order->get_id()));
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

        return $shipping_address;
    }

    private function build_customer_info($order, $is_update = false) {
        try {
            bna_debug('Building customer info', array(
                'order_id' => $order->get_id(),
                'is_update' => $is_update
            ));

            $email = trim($order->get_billing_email());
            $first_name = $this->clean_name($order->get_billing_first_name());
            $last_name = $this->clean_name($order->get_billing_last_name());

            if (empty($email) || empty($first_name) || empty($last_name)) {
                bna_error('Missing required customer data', array(
                    'has_email' => !empty($email),
                    'has_first_name' => !empty($first_name),
                    'has_last_name' => !empty($last_name)
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
            if ($shipping_address !== null) {
                $customer_info['shippingAddress'] = $shipping_address;
            } elseif ($is_update) {
                $customer_info['shippingAddress'] = null;
                bna_log('Setting shippingAddress to null for UPDATE', array('order_id' => $order->get_id()));
            }

            bna_log('Customer info built', array(
                'has_shipping' => isset($customer_info['shippingAddress']),
                'shipping_is_null' => (isset($customer_info['shippingAddress']) && $customer_info['shippingAddress'] === null),
                'is_update' => $is_update
            ));

            return $customer_info;

        } catch (Exception $e) {
            bna_error('Exception in build_customer_info', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage()
            ));
            return false;
        }
    }

    private function generate_customer_data_hash($customer_data) {
        try {
            $relevant_data = array();
            $fields_to_check = array(
                'firstName',
                'lastName',
                'email',
                'phoneCode',
                'phoneNumber',
                'birthDate',
                'billingAddress',
                'shippingAddress',
                'type'
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

            if (!isset($relevant_data['shippingAddress'])) {
                $relevant_data['shippingAddress'] = null;
            }

            ksort($relevant_data);
            $json_string = $this->safe_json_encode($relevant_data);
            $hash = md5($json_string);

            bna_debug('Generated customer hash', array(
                'hash' => $hash,
                'has_shipping' => isset($relevant_data['shippingAddress']),
                'shipping_is_null' => $relevant_data['shippingAddress'] === null
            ));

            return $hash;

        } catch (Exception $e) {
            bna_error('Exception in generate_customer_data_hash', array(
                'exception' => $e->getMessage()
            ));
            return md5(serialize($customer_data));
        }
    }

    private function has_customer_data_changed($order, $current_data) {
        try {
            $stored_hash = '';

            if (is_user_logged_in()) {
                $wp_customer_id = $order->get_customer_id();
                if ($wp_customer_id) {
                    $stored_hash = get_user_meta($wp_customer_id, '_bna_customer_data_hash', true);
                }
            }

            if (empty($stored_hash)) {
                $stored_hash = $order->get_meta('_bna_customer_data_hash');
            }

            $current_hash = $this->generate_customer_data_hash($current_data);

            bna_debug('Comparing hashes', array(
                'stored' => $stored_hash,
                'current' => $current_hash,
                'changed' => ($stored_hash !== $current_hash)
            ));

            if (empty($stored_hash)) {
                return true;
            }

            return $stored_hash !== $current_hash;

        } catch (Exception $e) {
            bna_error('Exception in has_customer_data_changed', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage()
            ));
            return true;
        }
    }

    private function clear_customer_data($order) {
        try {
            $order->delete_meta_data('_bna_customer_id');
            $order->delete_meta_data('_bna_customer_iframe_id');
            $order->delete_meta_data('_bna_customer_data_hash');

            if (is_user_logged_in()) {
                $wp_customer_id = $order->get_customer_id();
                if ($wp_customer_id) {
                    delete_user_meta($wp_customer_id, '_bna_customer_id');
                    delete_user_meta($wp_customer_id, '_bna_customer_iframe_id');
                    delete_user_meta($wp_customer_id, '_bna_customer_data_hash');

                    bna_log('Cleared customer data from user meta', array(
                        'wp_customer_id' => $wp_customer_id
                    ));
                }
            }

            $order->save();

            bna_log('Cleared customer data from order', array(
                'order_id' => $order->get_id()
            ));

        } catch (Exception $e) {
            bna_error('Exception in clear_customer_data', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage()
            ));
        }
    }

    public function generate_checkout_token($order) {
        try {
            $gateway_settings = get_option('woocommerce_bna_smart_payment_settings');
            $iframe_id = isset($gateway_settings['iframe_id']) ? $gateway_settings['iframe_id'] : '';

            if (empty($iframe_id)) {
                bna_error('iFrame ID not configured');
                return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
            }

            $is_subscription_order = $this->order_has_subscriptions($order);

            bna_log('Generating checkout token', array(
                'order_id' => $order->get_id(),
                'is_subscription' => $is_subscription_order,
                'iframe_id' => $iframe_id
            ));

            $customer_result = $this->get_or_create_customer($order);
            if (is_wp_error($customer_result)) {
                bna_error('Customer operation failed', array(
                    'order_id' => $order->get_id(),
                    'error' => $customer_result->get_error_message()
                ));
                return $customer_result;
            }

            $payload = $this->create_checkout_payload($order, $customer_result);
            if (!$payload) {
                bna_error('Failed to create checkout payload', array('order_id' => $order->get_id()));
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
                bna_error('Token not found in response', array('order_id' => $order->get_id()));
                return new WP_Error('missing_token', 'Token not found in API response');
            }

            $customer_info = $this->build_customer_info($order, false);
            if ($customer_info) {
                $current_hash = $this->generate_customer_data_hash($customer_info);

                if (is_user_logged_in()) {
                    $wp_customer_id = $order->get_customer_id();
                    if ($wp_customer_id) {
                        update_user_meta($wp_customer_id, '_bna_customer_data_hash', $current_hash);
                    }
                }

                $order->update_meta_data('_bna_customer_data_hash', $current_hash);
                $order->save();
            }

            bna_log('Checkout token generated', array(
                'order_id' => $order->get_id(),
                'customer_id' => $customer_result['customer_id'] ?? 'unknown'
            ));

            return $response;

        } catch (Exception $e) {
            bna_error('Exception in generate_checkout_token', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage()
            ));
            return new WP_Error('checkout_exception', 'Token generation failed: ' . $e->getMessage());
        }
    }

    private function get_or_create_customer($order) {
        try {
            $current_iframe_id = $this->get_current_iframe_id();

            if (empty($current_iframe_id)) {
                bna_error('Current iframe_id not available');
                return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
            }

            $existing_customer_id = $order->get_meta('_bna_customer_id');
            $stored_iframe_id = $order->get_meta('_bna_customer_iframe_id');

            if (empty($existing_customer_id) && is_user_logged_in()) {
                $wp_customer_id = $order->get_customer_id();
                $existing_customer_id = get_user_meta($wp_customer_id, '_bna_customer_id', true);
                $stored_iframe_id = get_user_meta($wp_customer_id, '_bna_customer_iframe_id', true);

                if (!empty($existing_customer_id)) {
                    $order->update_meta_data('_bna_customer_id', $existing_customer_id);
                    if (!empty($stored_iframe_id)) {
                        $order->update_meta_data('_bna_customer_iframe_id', $stored_iframe_id);
                    }
                    $order->save();

                    bna_log('Found existing BNA customer ID from user meta', array(
                        'wp_customer_id' => $wp_customer_id,
                        'bna_customer_id' => $existing_customer_id,
                        'stored_iframe_id' => $stored_iframe_id
                    ));
                }
            }

            if (!empty($existing_customer_id)) {
                if (empty($stored_iframe_id) || $stored_iframe_id !== $current_iframe_id) {
                    bna_log('iFrame ID missing or changed - creating new customer', array(
                        'stored_iframe_id' => $stored_iframe_id ?: 'empty',
                        'current_iframe_id' => $current_iframe_id,
                        'old_customer_id' => $existing_customer_id
                    ));

                    $this->clear_customer_data($order);
                    $existing_customer_id = '';
                    $stored_iframe_id = '';
                }
            }

            $customer_data = $this->build_customer_info($order, !empty($existing_customer_id));
            if (!$customer_data) {
                bna_error('Failed to build customer data', array('order_id' => $order->get_id()));
                return new WP_Error('customer_data_error', 'Failed to build customer data');
            }

            if (!empty($existing_customer_id)) {
                if ($this->has_customer_data_changed($order, $customer_data)) {
                    bna_log('Customer data changed, updating', array('customer_id' => $existing_customer_id));
                    return $this->update_existing_customer($existing_customer_id, $customer_data, $order);
                } else {
                    bna_debug('Customer data unchanged', array('customer_id' => $existing_customer_id));
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
                'exception' => $e->getMessage()
            ));
            return new WP_Error('customer_exception', 'Customer operation failed: ' . $e->getMessage());
        }
    }

    private function create_checkout_payload($order, $customer_result) {
        $gateway_settings = get_option('woocommerce_bna_smart_payment_settings');
        $iframe_id = isset($gateway_settings['iframe_id']) ? $gateway_settings['iframe_id'] : '';

        $payload = array(
            'iframeId' => $iframe_id,
            'subtotal' => (float) $order->get_total(),
            'items' => $this->get_order_items($order)
        );

        $subscription_data = $this->get_order_subscription_data($order);
        if ($subscription_data && bna_subscriptions_enabled()) {
            $bna_frequency = $this->convert_frequency_to_bna($subscription_data['frequency']);
            if ($bna_frequency) {
                $payload['recurrence'] = $bna_frequency;

                if ($subscription_data['length_type'] === 'limited' && $subscription_data['num_payments'] > 0) {
                    $payload['remainingPayments'] = (int) $subscription_data['num_payments'];
                }

                if (!empty($subscription_data['enable_trial']) && !empty($subscription_data['trial_length'])) {
                    $start_payment_date = BNA_Subscriptions::calculate_start_payment_date($subscription_data);
                    $payload['startPaymentDate'] = $start_payment_date;

                    bna_log('Trial period added to payload', array(
                        'trial_days' => $subscription_data['trial_length'],
                        'startPaymentDate' => $start_payment_date
                    ));
                }

                bna_log('Subscription data added to payload', array(
                    'frequency' => $bna_frequency,
                    'iframe_id' => $iframe_id,
                    'has_trial' => isset($payload['startPaymentDate'])
                ));
            }
        }

        if (!empty($customer_result['customer_id'])) {
            $payload['customerId'] = $customer_result['customer_id'];
        } else {
            $customer_info = $this->build_customer_info($order, false);
            if (!$customer_info) {
                return false;
            }
            $payload['customerInfo'] = $customer_info;
        }

        $payload['invoiceInfo'] = array(
            'invoiceId' => $order->get_order_number(),
            'invoiceAdditionalInfo' => 'WooCommerce Order #' . $order->get_id()
        );

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

            bna_debug('Creating new customer', array('email' => $customer_data['email']));

            $response = $this->make_request('v1/customers', 'POST', $customer_data);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $error_data = $response->get_error_data();

                if ($this->is_customer_exists_error($error_message, $error_data)) {
                    bna_debug('Customer exists, searching', array('email' => $customer_data['email']));
                    return $this->find_existing_customer($customer_data['email'], $order);
                }

                return $response;
            }

            if (empty($response['id'])) {
                return new WP_Error('invalid_customer_response', 'Customer ID not found in response');
            }

            bna_log('New customer created', array(
                'customer_id' => $response['id'],
                'email' => $customer_data['email']
            ));

            if ($order) {
                $data_hash = $this->generate_customer_data_hash($customer_data);
                $current_iframe_id = $this->get_current_iframe_id();

                if (is_user_logged_in()) {
                    $wp_customer_id = $order->get_customer_id();
                    if ($wp_customer_id) {
                        update_user_meta($wp_customer_id, '_bna_customer_id', $response['id']);
                        update_user_meta($wp_customer_id, '_bna_customer_iframe_id', $current_iframe_id);
                        update_user_meta($wp_customer_id, '_bna_customer_data_hash', $data_hash);

                        bna_log('Saved customer data to user meta', array(
                            'wp_customer_id' => $wp_customer_id,
                            'bna_customer_id' => $response['id'],
                            'iframe_id' => $current_iframe_id
                        ));
                    }
                }

                $order->update_meta_data('_bna_customer_id', $response['id']);
                $order->update_meta_data('_bna_customer_iframe_id', $current_iframe_id);
                $order->update_meta_data('_bna_customer_data_hash', $data_hash);
                $order->save();

                bna_log('Saved customer data to order meta', array(
                    'order_id' => $order->get_id(),
                    'bna_customer_id' => $response['id'],
                    'iframe_id' => $current_iframe_id
                ));
            }

            return array(
                'customer_id' => $response['id'],
                'is_existing' => false
            );

        } catch (Exception $e) {
            bna_error('Exception in create_new_customer', array(
                'exception' => $e->getMessage(),
                'email' => $customer_data['email'] ?? 'unknown'
            ));
            return new WP_Error('customer_creation_exception', 'Customer creation failed: ' . $e->getMessage());
        }
    }

    private function update_existing_customer($customer_id, $customer_data, $order) {
        try {
            $update_data = $customer_data;

            if (isset($update_data['email'])) {
                unset($update_data['email']);
                bna_debug('Removed email from update (cannot be changed)');
            }

            bna_log('Updating customer', array(
                'customer_id' => $customer_id,
                'fields' => array_keys($update_data),
                'has_shipping' => isset($update_data['shippingAddress']),
                'shipping_is_null' => (isset($update_data['shippingAddress']) && $update_data['shippingAddress'] === null)
            ));

            $response = $this->make_request('v1/customers/' . $customer_id, 'PATCH', $update_data);

            if (is_wp_error($response)) {
                bna_error('Customer update failed', array(
                    'customer_id' => $customer_id,
                    'error' => $response->get_error_message()
                ));
                return $response;
            }

            bna_log('Customer updated successfully', array('customer_id' => $customer_id));

            $data_hash = $this->generate_customer_data_hash($customer_data);

            if (is_user_logged_in()) {
                $wp_customer_id = $order->get_customer_id();
                if ($wp_customer_id) {
                    update_user_meta($wp_customer_id, '_bna_customer_data_hash', $data_hash);
                }
            }

            $order->update_meta_data('_bna_customer_data_hash', $data_hash);
            $order->save();

            return array(
                'customer_id' => $customer_id,
                'is_existing' => true,
                'was_updated' => true
            );

        } catch (Exception $e) {
            bna_error('Exception in update_existing_customer', array(
                'customer_id' => $customer_id,
                'exception' => $e->getMessage()
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

            bna_debug('=== API SEARCH RESPONSE ===', array(
                'email_searched' => $email,
                'response_type' => gettype($response),
                'response_keys' => is_array($response) ? array_keys($response) : 'not_array',
                'has_data_key' => isset($response['data']),
                'data_type' => isset($response['data']) ? gettype($response['data']) : 'not_set',
                'data_count' => (isset($response['data']) && is_array($response['data'])) ? count($response['data']) : 0,
                'full_response_json' => wp_json_encode($response)
            ));

            if (empty($response['data']) || !is_array($response['data'])) {
                bna_error('Customer not found in response', array(
                    'email' => $email,
                    'response_structure' => wp_json_encode($response)
                ));
                return new WP_Error('customer_not_found', 'Customer not found');
            }

            $customer = reset($response['data']);

            bna_debug('=== FIRST CUSTOMER FROM DATA ===', array(
                'customer_data' => wp_json_encode($customer),
                'has_id' => isset($customer['id']),
                'customer_email' => isset($customer['email']) ? $customer['email'] : 'no_email'
            ));

            if (empty($customer['id'])) {
                return new WP_Error('invalid_customer_data', 'Invalid customer data received');
            }

            bna_log('Found existing customer', array(
                'customer_id' => $customer['id'],
                'email' => $email
            ));

            if ($order) {
                $current_iframe_id = $this->get_current_iframe_id();

                if (is_user_logged_in()) {
                    $wp_customer_id = $order->get_customer_id();
                    if ($wp_customer_id) {
                        update_user_meta($wp_customer_id, '_bna_customer_id', $customer['id']);
                        update_user_meta($wp_customer_id, '_bna_customer_iframe_id', $current_iframe_id);

                        bna_log('Saved found customer to user meta', array(
                            'wp_customer_id' => $wp_customer_id,
                            'bna_customer_id' => $customer['id'],
                            'iframe_id' => $current_iframe_id
                        ));
                    }
                }

                $order->update_meta_data('_bna_customer_id', $customer['id']);
                $order->update_meta_data('_bna_customer_iframe_id', $current_iframe_id);
                $order->save();

                bna_log('Saved found customer to order meta', array(
                    'order_id' => $order->get_id(),
                    'bna_customer_id' => $customer['id'],
                    'iframe_id' => $current_iframe_id
                ));
            }

            return array(
                'customer_id' => $customer['id'],
                'is_existing' => true
            );

        } catch (Exception $e) {
            bna_error('Exception in find_existing_customer', array(
                'email' => $email,
                'exception' => $e->getMessage()
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
        return isset(self::$COUNTRY_CODE_MAPPING[$country_code]) || in_array($country_code, self::$COUNTRY_CODE_MAPPING);
    }

    private function is_valid_phone_number($phone) {
        $digits_only = preg_replace('/\D/', '', $phone);
        return strlen($digits_only) >= 7 && strlen($digits_only) <= 15;
    }

    private function is_customer_exists_error($error_message, $error_data) {
        return strpos($error_message, 'already exists') !== false ||
            strpos($error_message, 'duplicate') !== false ||
            (is_array($error_data) && isset($error_data['response']) && strpos($error_data['response'], 'already exists') !== false);
    }

    private function build_address($order) {
        $country = $order->get_billing_country();
        $address_1 = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());
        $state = $order->get_billing_state();
        $postal_code = $order->get_billing_postcode();

        if (empty($country) || empty($address_1) || empty($city)) {
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

        return $address;
    }

    private function process_phone_number($order) {
        $phone = trim($order->get_billing_phone());

        if (empty($phone)) {
            return null;
        }

        $phone_code = null;

        $phone_code_from_meta = $order->get_meta('_billing_phone_code');
        if (!empty($phone_code_from_meta)) {
            $phone_code = sanitize_text_field($phone_code_from_meta);
            bna_debug('Phone code from order meta', array('code' => $phone_code));
        }

        if (!$phone_code) {
            $phone_code = $this->determine_phone_country_code($phone);
            bna_debug('Phone code extracted from phone value', array('code' => $phone_code));
        }

        $phone_number = $this->format_phone_number($phone, $phone_code);

        if (!$phone_number) {
            return null;
        }

        bna_debug('Phone processing result', array(
            'original' => $phone,
            'extracted_code' => $phone_code,
            'formatted_number' => $phone_number,
            'source' => !empty($phone_code_from_meta) ? 'order_meta' : 'extracted'
        ));

        return array(
            'code' => $phone_code,
            'number' => $phone_number
        );
    }

    private function determine_phone_country_code($phone_value) {
        $cleaned = preg_replace('/[^\d+]/', '', $phone_value);

        if (preg_match('/^\+(\d{1,4})/', $cleaned, $matches)) {
            $code = '+' . $matches[1];

            $valid_codes = array(
                '+1', '+30', '+31', '+32', '+33', '+34', '+36', '+39', '+40', '+41',
                '+44', '+45', '+46', '+47', '+48', '+49', '+351', '+358', '+370',
                '+371', '+372', '+380', '+420', '+421'
            );

            if (in_array($code, $valid_codes)) {
                bna_debug('Country code extracted from phone', array(
                    'original' => $phone_value,
                    'extracted' => $code
                ));
                return $code;
            }
        }

        bna_error('Invalid phone format - no valid country code', array(
            'phone' => $phone_value
        ));

        return '+1';
    }

    private function format_phone_number($phone_value, $phone_code) {
        $digits_only = preg_replace('/\D/', '', $phone_value);

        $code_digits = preg_replace('/\D/', '', $phone_code);

        if (strpos($digits_only, $code_digits) === 0) {
            $digits_only = substr($digits_only, strlen($code_digits));
        }

        $codes_with_leading_zero = array(
            '+30', '+31', '+32', '+33', '+36', '+39', '+40', '+41',
            '+44', '+45', '+46', '+47', '+48', '+49', '+351', '+358',
            '+370', '+371', '+372', '+380', '+420', '+421'
        );

        if (in_array($phone_code, $codes_with_leading_zero)) {
            $digits_only = ltrim($digits_only, '0');
        }

        bna_debug('Phone number formatted', array(
            'original' => $phone_value,
            'phone_code' => $phone_code,
            'result' => $digits_only
        ));

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
            return $matches[1];
        }

        if (preg_match('/(.+)\s+(\d+[a-zA-Z]?)$/', $address_string, $matches)) {
            return $matches[2];
        }

        if (preg_match('/(\d+[a-zA-Z]?)/', $address_string, $matches)) {
            return $matches[1];
        }

        return '1';
    }

    private function clean_street_name($address_string, $street_number) {
        $street_name = trim($address_string);
        $street_name = preg_replace('/^' . preg_quote($street_number, '/') . '\s*/', '', $street_name);
        $street_name = preg_replace('/\s*' . preg_quote($street_number, '/') . '$/', '', $street_name);
        $street_name = trim($street_name);

        if (empty($street_name)) {
            $street_name = 'Main Street';
        }

        return $street_name;
    }

    private function clean_city_name($city) {
        return trim(ucwords(strtolower($city)));
    }

    private function clean_name($name) {
        return trim(ucwords(strtolower($name)));
    }

    private function map_country_code($country_code) {
        return isset(self::$COUNTRY_CODE_MAPPING[$country_code])
            ? self::$COUNTRY_CODE_MAPPING[$country_code]
            : $country_code;
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
            bna_error('JSON encode failed', array('error' => json_last_error_msg()));
            return serialize($data);
        }

        return $json;
    }

    public function test_generate_customer_hash($customer_data) {
        return $this->generate_customer_data_hash($customer_data);
    }

    public function test_determine_phone_country($digits_only, $billing_country) {
        return $this->determine_phone_country_code($digits_only, $billing_country);
    }

    public function test_format_phone($digits_only, $phone_code) {
        return $this->format_phone_number($digits_only, $phone_code);
    }

    public function test_extract_street_number($address_string) {
        return $this->extract_street_number($address_string);
    }

    public function test_clean_street_name($address_string, $street_number) {
        return $this->clean_street_name($address_string, $street_number);
    }
}