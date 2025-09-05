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

    // Mapping WooCommerce country codes to BNA API country codes
    const COUNTRY_CODE_MAPPING = array(
        'CA' => 'Canada',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'FR' => 'France',
        'DE' => 'Germany',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'JP' => 'Japan',
        'CN' => 'China',
        'IN' => 'India',
        'BR' => 'Brazil',
        'MX' => 'Mexico',
        'UA' => 'Ukraine'
    );

    // Phone country codes mapping
    const PHONE_COUNTRY_CODES = array(
        'CA' => '+1',
        'US' => '+1',
        'GB' => '+44',
        'AU' => '+61',
        'FR' => '+33',
        'DE' => '+49',
        'UA' => '+380',
        'PL' => '+48',
        'NL' => '+31',
        'BE' => '+32',
        'SE' => '+46',
        'NO' => '+47',
        'DK' => '+45',
        'FI' => '+358',
        'JP' => '+81',
        'CN' => '+86',
        'IN' => '+91',
        'BR' => '+55',
        'MX' => '+52'
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
     * Safe JSON encoding that works on all PHP versions
     */
    private function safe_json_encode($data) {
        // Use basic wp_json_encode without any flags for maximum compatibility
        $json = wp_json_encode($data);

        // If wp_json_encode fails, fallback to json_encode
        if ($json === false && function_exists('json_encode')) {
            $json = json_encode($data);
        }

        // If still failed, return a simple string representation
        if ($json === false) {
            $json = serialize($data);
        }

        return $json;
    }

    /**
     * Update customer with improved error handling
     */
    public function update_customer($customer_id, $customer_data) {
        if (empty($customer_id)) {
            return new WP_Error('missing_customer_id', 'Customer ID is required for update');
        }

        // Validate customer data before sending
        $validation_result = $this->validate_customer_data($customer_data);
        if (is_wp_error($validation_result)) {
            bna_error('Customer data validation failed', array(
                'customer_id' => $customer_id,
                'validation_errors' => $validation_result->get_error_messages()
            ));
            return $validation_result;
        }

        // Remove email from update data - emails cannot be updated
        if (isset($customer_data['email'])) {
            unset($customer_data['email']);
            bna_debug('Removed email from update data - email cannot be updated for existing customers');
        }

        $fields_to_update = array_keys($customer_data);
        bna_log('Updating customer', array(
            'customer_id' => $customer_id,
            'fields_to_update' => $fields_to_update,
            'has_shipping_address' => isset($customer_data['shippingAddress'])
        ));

        try {
            $response = $this->make_request("v1/customers/{$customer_id}", 'PATCH', $customer_data);

            if (is_wp_error($response)) {
                bna_error('Customer update failed', array(
                    'customer_id' => $customer_id,
                    'error' => $response->get_error_message(),
                    'error_data' => $response->get_error_data()
                ));
                return $response;
            }

            bna_log('Customer updated successfully', array(
                'customer_id' => $customer_id
            ));

            return $response;

        } catch (Exception $e) {
            bna_error('Exception in update_customer', array(
                'customer_id' => $customer_id,
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ));

            return new WP_Error('update_exception', $e->getMessage());
        }
    }

    /**
     * Validate customer data before API requests
     */
    private function validate_customer_data($customer_data) {
        $errors = array();

        // Validate required fields
        $required_fields = array('firstName', 'lastName', 'type');
        foreach ($required_fields as $field) {
            if (empty($customer_data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate email format if present
        if (isset($customer_data['email']) && !filter_var($customer_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        // Validate country codes in addresses
        if (isset($customer_data['address']['country'])) {
            $country = $customer_data['address']['country'];
            if (!$this->is_valid_country_code($country)) {
                $errors[] = "Invalid country code in address: {$country}";
            }
        }

        if (isset($customer_data['shippingAddress']['country'])) {
            $country = $customer_data['shippingAddress']['country'];
            if (!$this->is_valid_country_code($country)) {
                $errors[] = "Invalid country code in shipping address: {$country}";
            }
        }

        // Validate phone number format
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

    /**
     * Check if country code is valid for BNA API
     */
    private function is_valid_country_code($country_code) {
        return isset(self::COUNTRY_CODE_MAPPING[$country_code]) ||
            in_array($country_code, self::COUNTRY_CODE_MAPPING);
    }

    /**
     * Validate phone number format
     */
    private function is_valid_phone_number($phone) {
        // Remove all non-digits
        $digits_only = preg_replace('/\D/', '', $phone);

        // Phone should be between 7 and 15 digits
        return strlen($digits_only) >= 7 && strlen($digits_only) <= 15;
    }

    /**
     * Generate customer data hash for change detection - SAFE VERSION
     */
    private function generate_customer_data_hash($customer_data) {
        try {
            bna_debug('Generating hash for customer data', array(
                'data_keys' => array_keys($customer_data)
            ));

            $relevant_data = array();

            $fields_to_check = array(
                'firstName', 'lastName', 'email', 'phoneCode', 'phoneNumber',
                'birthDate', 'address', 'shippingAddress', 'type'
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

            // Use safe JSON encoding without any flags that might not exist
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

            // Fallback to a simple hash of serialized data
            return md5(serialize($customer_data));
        }
    }

    /**
     * Check if customer data has changed
     */
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

    /**
     * Generate checkout token with improved error handling
     */
    public function generate_checkout_token($order) {
        try {
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
                bna_error('Customer creation/retrieval failed', array(
                    'order_id' => $order->get_id(),
                    'error' => $customer_result->get_error_message()
                ));
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
                bna_error('Invalid checkout response - no token', array('order_id' => $order->get_id()));
                return new WP_Error('invalid_response', 'Token not found in response');
            }

            // Save customer data and hash
            if (!empty($customer_result['customer_id'])) {
                $order->add_meta_data('_bna_customer_id', $customer_result['customer_id']);

                $customer_data = $this->build_customer_info($order);
                if ($customer_data) {
                    $data_hash = $this->generate_customer_data_hash($customer_data);
                    $order->update_meta_data('_bna_customer_data_hash', $data_hash);
                }

                if (is_user_logged_in()) {
                    $wp_customer_id = $order->get_customer_id();
                    if ($wp_customer_id) {
                        update_user_meta($wp_customer_id, '_bna_customer_id', $customer_result['customer_id']);
                    }
                }

                $order->save();
            }

            bna_log('Checkout token generated successfully', array(
                'order_id' => $order->get_id(),
                'customer_id' => $customer_result['customer_id'] ?? 'new',
                'was_updated' => $customer_result['was_updated'] ?? false,
                'token_length' => strlen($response['token'])
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

    /**
     * Get or create customer with improved handling
     */
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

                    $update_result = $this->update_customer($existing_customer_id, $customer_data);

                    if (is_wp_error($update_result)) {
                        bna_error('Customer update failed, will proceed with existing customer', array(
                            'customer_id' => $existing_customer_id,
                            'error' => $update_result->get_error_message()
                        ));

                        // Instead of creating new customer, proceed with existing one
                        return array(
                            'customer_id' => $existing_customer_id,
                            'is_existing' => true,
                            'was_updated' => false,
                            'update_failed' => true
                        );
                    }

                    $current_hash = $this->generate_customer_data_hash($customer_data);
                    $order->update_meta_data('_bna_customer_data_hash', $current_hash);
                    $order->save();

                    bna_log('Customer update completed successfully', array(
                        'customer_id' => $existing_customer_id,
                        'order_id' => $order->get_id()
                    ));

                    return array(
                        'customer_id' => $existing_customer_id,
                        'is_existing' => true,
                        'was_updated' => true
                    );
                }

                bna_debug('Customer data unchanged, using existing', array(
                    'customer_id' => $existing_customer_id
                ));

                return array(
                    'customer_id' => $existing_customer_id,
                    'is_existing' => true,
                    'was_updated' => false
                );
            }

            bna_debug('No existing customer, creating new', array(
                'order_id' => $order->get_id()
            ));

            return $this->create_new_customer($customer_data, $order);

        } catch (Exception $e) {
            bna_error('Exception in get_or_create_customer', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ));

            return new WP_Error('customer_exception', 'Customer processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Create new customer with validation
     */
    private function create_new_customer($customer_data, $order = null) {
        try {
            // Validate before creating
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

            return new WP_Error('create_customer_exception', 'Customer creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if error indicates customer already exists
     */
    private function is_customer_exists_error($error_message, $error_data) {
        if (isset($error_data['status']) && ($error_data['status'] === 400 || $error_data['status'] === 409)) {
            $exists_patterns = array(
                'customer already exist',
                'customer already exists',
                'email already exists',
                'duplicate',
                'already registered'
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

    /**
     * Find existing customer by email
     */
    private function find_existing_customer($email, $order = null) {
        try {
            $search_params = array('email' => $email, 'limit' => 1);
            $response = $this->make_request('v1/customers', 'GET', $search_params);

            if (is_wp_error($response)) {
                return $response;
            }

            $customers = isset($response['data']) ? $response['data'] : array();

            foreach ($customers as $customer) {
                if (isset($customer['email'], $customer['id']) && $customer['email'] === $email) {

                    if ($order && is_user_logged_in()) {
                        $wp_customer_id = $order->get_customer_id();
                        if ($wp_customer_id) {
                            update_user_meta($wp_customer_id, '_bna_customer_id', $customer['id']);

                            bna_log('Saved found BNA customer ID to user meta', array(
                                'wp_customer_id' => $wp_customer_id,
                                'bna_customer_id' => $customer['id']
                            ));
                        }
                    }

                    return array(
                        'customer_id' => $customer['id'],
                        'is_existing' => true
                    );
                }
            }

            return new WP_Error('customer_search_failed', 'Customer exists but could not be located');

        } catch (Exception $e) {
            bna_error('Exception in find_existing_customer', array(
                'email' => $email,
                'exception' => $e->getMessage()
            ));

            return new WP_Error('find_customer_exception', 'Customer search failed: ' . $e->getMessage());
        }
    }

    /**
     * Create checkout payload
     */
    private function create_checkout_payload($order, $customer_result) {
        $payload = array(
            'iframeId' => get_option('bna_smart_payment_iframe_id'),
            'subtotal' => (float) $order->get_total(),
            'items' => $this->get_order_items($order)
        );

        if (!empty($customer_result['customer_id'])) {
            $payload['customerId'] = $customer_result['customer_id'];
        } else {
            $customer_info = $this->build_customer_info($order);
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

    /**
     * Build customer info with improved phone and country handling
     */
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

            // Add phone if enabled
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

            // Add birthdate if enabled
            if (get_option('bna_smart_payment_enable_birthdate') === 'yes') {
                $birthdate = $this->get_valid_birthdate($order);
                if ($birthdate) {
                    $customer_info['birthDate'] = $birthdate;
                }
            }

            // Build address
            $customer_info['address'] = $this->build_address($order);

            // Build shipping address if enabled
            $shipping_address = $this->build_shipping_address($order);
            if ($shipping_address) {
                $customer_info['shippingAddress'] = $shipping_address;
            }

            $has_phone = isset($customer_info['phoneCode']) && isset($customer_info['phoneNumber']);
            $address_street = $customer_info['address']['streetName'] ?? 'unknown';
            $address_number = $customer_info['address']['streetNumber'] ?? 'unknown';

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

    /**
     * Process phone number with improved country detection
     */
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

        // Remove all non-digits
        $digits_only = preg_replace('/\D/', '', $phone);

        // Determine country code based on phone number patterns and billing country
        $phone_code = $this->determine_phone_country_code($digits_only, $billing_country);
        $phone_number = $this->format_phone_number($digits_only, $phone_code);

        if (!$phone_number) {
            bna_debug('Phone number could not be processed', array(
                'original' => $phone,
                'digits_only' => $digits_only
            ));
            return null;
        }

        bna_debug('Phone number processed successfully', array(
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

    /**
     * Determine phone country code based on number and billing country
     */
    private function determine_phone_country_code($digits_only, $billing_country) {
        // First, try to detect by phone number patterns
        if (strlen($digits_only) === 12 && substr($digits_only, 0, 3) === '380') {
            return '+380'; // Ukraine
        }

        if (strlen($digits_only) === 11 && substr($digits_only, 0, 1) === '1') {
            return '+1'; // US/Canada
        }

        if (strlen($digits_only) === 10) {
            // Could be US/Canada without country code
            if (in_array($billing_country, array('US', 'CA'))) {
                return '+1';
            }
        }

        // For Ukrainian numbers that might be missing country code
        if (strlen($digits_only) === 10 && substr($digits_only, 0, 1) === '0') {
            return '+380'; // Ukraine mobile numbers often start with 0
        }

        // Fallback to billing country
        if (isset(self::PHONE_COUNTRY_CODES[$billing_country])) {
            return self::PHONE_COUNTRY_CODES[$billing_country];
        }

        // Default fallback
        return '+1';
    }

    /**
     * Format phone number based on country code
     */
    private function format_phone_number($digits_only, $phone_code) {
        switch ($phone_code) {
            case '+380': // Ukraine
                if (strlen($digits_only) === 12 && substr($digits_only, 0, 3) === '380') {
                    return substr($digits_only, 3); // Remove country code
                }
                if (strlen($digits_only) === 10 && substr($digits_only, 0, 1) === '0') {
                    return $digits_only; // Keep as is
                }
                break;

            case '+1': // US/Canada
                if (strlen($digits_only) === 11 && substr($digits_only, 0, 1) === '1') {
                    return substr($digits_only, 1); // Remove country code
                }
                if (strlen($digits_only) === 10) {
                    return $digits_only; // Keep as is
                }
                break;
        }

        // For other countries, try to keep reasonable length
        if (strlen($digits_only) >= 7 && strlen($digits_only) <= 15) {
            return $digits_only;
        }

        return null;
    }

    /**
     * Build address with proper country mapping
     */
    private function build_address($order) {
        $enable_billing_address = get_option('bna_smart_payment_enable_billing_address', 'no');

        if ($enable_billing_address !== 'yes') {
            return array(
                'postalCode' => $this->format_postal_code($order->get_billing_postcode())
            );
        }

        $street = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());
        $country = $order->get_billing_country();
        $province = $order->get_billing_state();

        bna_debug('Building address', array(
            'original_street' => $street,
            'city' => $city,
            'country' => $country,
            'province' => $province,
            'postal_code' => $order->get_billing_postcode()
        ));

        $street_number = $this->extract_street_number($street);
        $street_name = $this->clean_street_name($street, $street_number);

        $address = array(
            'streetNumber' => $street_number,
            'streetName' => $street_name,
            'city' => $this->clean_city_name($city),
            'province' => $province ?: 'ON',
            'country' => $this->map_country_code($country),
            'postalCode' => $this->format_postal_code($order->get_billing_postcode())
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

    /**
     * Build shipping address with proper country mapping
     */
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
            'country' => $this->map_country_code($shipping_country),
            'postalCode' => $this->format_postal_code($shipping_postcode)
        );

        $shipping_address_2 = $order->get_meta('_bna_shipping_address_2');
        if (!empty($shipping_address_2)) {
            $shipping_address['apartment'] = trim($shipping_address_2);
        }

        return $shipping_address;
    }

    /**
     * Map WooCommerce country code to BNA API country name
     */
    private function map_country_code($wc_country_code) {
        if (isset(self::COUNTRY_CODE_MAPPING[$wc_country_code])) {
            return self::COUNTRY_CODE_MAPPING[$wc_country_code];
        }

        // Default to Canada if country not found
        return 'Canada';
    }

    /**
     * Extract street number with improved patterns
     */
    private function extract_street_number($street) {
        if (empty($street)) {
            return '1';
        }

        $street = trim($street);

        bna_debug('Extracting street number', array(
            'original_street' => $street
        ));

        // Pattern 1: Number at the beginning (e.g., "123 Main St")
        if (preg_match('/^(\d+)/', $street, $matches)) {
            bna_debug('Street number found at beginning', array(
                'number' => $matches[1],
                'pattern' => 'beginning'
            ));
            return $matches[1];
        }

        // Pattern 2: Number at the end (e.g., "Main St 123" or "Win 5")
        if (preg_match('/\s(\d+)$/', $street, $matches)) {
            bna_debug('Street number found at end', array(
                'number' => $matches[1],
                'pattern' => 'end'
            ));
            return $matches[1];
        }

        // Pattern 3: Any number in the string
        if (preg_match('/(\d+)/', $street, $matches)) {
            bna_debug('Street number found in middle', array(
                'number' => $matches[1],
                'pattern' => 'middle'
            ));
            return $matches[1];
        }

        bna_debug('No street number found, using default', array(
            'default' => '1'
        ));
        return '1';
    }

    /**
     * Clean street name by removing the street number
     */
    private function clean_street_name($street, $street_number) {
        if (empty($street)) {
            return 'Main Street';
        }

        bna_debug('Cleaning street name', array(
            'original_street' => $street,
            'street_number' => $street_number
        ));

        // Remove the street number from the beginning
        $cleaned = preg_replace('/^' . preg_quote($street_number, '/') . '\s*/', '', trim($street));

        // Remove the street number from the end
        $cleaned = preg_replace('/\s*' . preg_quote($street_number, '/') . '$/', '', $cleaned);

        $cleaned = trim($cleaned);

        bna_debug('Street name cleaned', array(
            'original' => $street,
            'number_removed' => $street_number,
            'final_street_name' => $cleaned
        ));

        return empty($cleaned) ? 'Main Street' : $cleaned;
    }

    /**
     * Get clean phone number (legacy method for compatibility)
     */
    private function get_clean_phone($order) {
        $phone_data = $this->process_phone_number($order);
        return $phone_data ? $phone_data['number'] : false;
    }

    /**
     * Get valid birthdate
     */
    private function get_valid_birthdate($order) {
        $birthdate = $order->get_meta('_billing_birthdate');
        if ($this->is_valid_birthdate($birthdate)) {
            return $birthdate;
        }
        return '1990-01-01';
    }

    /**
     * Validate birthdate
     */
    private function is_valid_birthdate($birthdate) {
        if (empty($birthdate)) return false;
        $date_obj = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $birthdate) {
            return false;
        }
        $eighteen_years_ago = new DateTime('-18 years');
        return $date_obj <= $eighteen_years_ago;
    }

    /**
     * Clean name
     */
    private function clean_name($name) {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-zA-ZÀ-ÿ\s\'-]/u', '', trim($name))));
    }

    /**
     * Clean city name
     */
    private function clean_city_name($city) {
        if (empty($city)) return 'Unknown';
        $clean = trim(preg_replace('/[^\da-zA-ZÀ-ÖØ-öø-ÿ\s-]/u', '', $city));
        return empty($clean) ? 'Unknown' : $clean;
    }

    /**
     * Format postal code
     */
    private function format_postal_code($postal_code) {
        if (empty($postal_code)) return 'A1A 1A1';
        $postal = strtoupper(str_replace(' ', '', $postal_code));
        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $postal)) {
            return substr($postal, 0, 3) . ' ' . substr($postal, 3, 3);
        }
        return $postal_code ?: 'A1A 1A1';
    }

    /**
     * Get order items
     */
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
        return $items;
    }

    /**
     * Get iframe URL
     */
    public function get_iframe_url($token) {
        return $this->get_api_url() . '/v1/checkout/' . $token;
    }

    /**
     * Make API request with improved error handling
     */
    public function make_request($endpoint, $method = 'GET', $data = array()) {
        if (!$this->has_credentials()) {
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
            $args['body'] = $this->safe_json_encode($data);
            $args['headers']['Content-Type'] = 'application/json';
        }

        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        bna_debug('Making API request', array(
            'method' => $method,
            'endpoint' => $endpoint,
            'url' => $url,
            'has_data' => !empty($data)
        ));

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            bna_error('HTTP request failed', array(
                'endpoint' => $endpoint,
                'error' => $response->get_error_message()
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
                'response_body' => substr($response_body, 0, 500)
            ));
            return new WP_Error('invalid_response', 'Invalid JSON response');
        }

        if ($response_code >= 400) {
            $error_message = isset($parsed_response['message'])
                ? $parsed_response['message']
                : "API request failed with status $response_code";

            bna_error('API error response', array(
                'endpoint' => $endpoint,
                'status_code' => $response_code,
                'error_message' => $error_message,
                'response' => $parsed_response
            ));

            return new WP_Error('api_error', $error_message, array(
                'status' => $response_code,
                'response' => $parsed_response
            ));
        }

        bna_debug('API request successful', array(
            'endpoint' => $endpoint,
            'status_code' => $response_code
        ));

        return $parsed_response;
    }

    /**
     * Get API URL
     */
    private function get_api_url() {
        return self::ENVIRONMENTS[$this->environment] ?? self::ENVIRONMENTS['staging'];
    }

    /**
     * Get auth headers
     */
    private function get_auth_headers() {
        $credentials_string = $this->credentials['access_key'] . ':' . $this->credentials['secret_key'];
        return array(
            'Authorization' => 'Basic ' . base64_encode($credentials_string),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );
    }

    /**
     * Check if credentials are available
     */
    private function has_credentials() {
        return !empty($this->credentials['access_key']) && !empty($this->credentials['secret_key']);
    }

    /**
     * Test connection
     */
    public function test_connection() {
        $result = $this->make_request('v1/account', 'GET');
        return !is_wp_error($result);
    }
}