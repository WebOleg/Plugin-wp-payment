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

    /**
     * Update existing customer via BNA API
     * Note: Email cannot be updated for existing customers
     */
    public function update_customer($customer_id, $customer_data) {
        if (empty($customer_id)) {
            return new WP_Error('missing_customer_id', 'Customer ID is required for update');
        }

        // Prepare data for PATCH request - remove email as it cannot be updated
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

        try {
            $response = $this->make_request("v1/customers/{$customer_id}", 'PATCH', $update_data);

            if (is_wp_error($response)) {
                bna_error('Customer update failed', array(
                    'customer_id' => $customer_id,
                    'error' => $response->get_error_message(),
                    'error_data' => $response->get_error_data()
                ));
                return $response;
            }

            bna_log('Customer updated successfully', array(
                'customer_id' => $customer_id,
                'updated_fields' => array_keys($update_data)
            ));

            return $response;

        } catch (Exception $e) {
            bna_error('Exception in update_customer', array(
                'customer_id' => $customer_id,
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ));

            return new WP_Error('update_exception', $e->getMessage());
        }
    }

    /**
     * Generate hash for customer data comparison
     * Fixed: Use proper JSON flags that work across PHP versions
     */
    private function generate_customer_data_hash($customer_data) {
        try {
            bna_debug('Generating hash for customer data', array(
                'data_keys' => array_keys($customer_data)
            ));

            $relevant_data = array();

            // Fields to include in hash comparison
            $fields_to_check = array(
                'firstName', 'lastName', 'phoneCode', 'phoneNumber',
                'birthDate', 'address', 'shippingAddress', 'type'
                // Note: email excluded as it cannot be updated
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

            // Fixed: Use JSON_UNESCAPED_UNICODE only, removed JSON_SORT_KEYS for compatibility
            $json_flags = JSON_UNESCAPED_UNICODE;

            // Add JSON_SORT_KEYS if available (PHP 5.4+)
            if (defined('JSON_SORT_KEYS')) {
                $json_flags |= JSON_SORT_KEYS;
            }

            $hash = md5(wp_json_encode($relevant_data, $json_flags));

            bna_debug('Generated customer data hash', array(
                'hash' => $hash,
                'data_keys' => array_keys($relevant_data)
            ));

            return $hash;

        } catch (Exception $e) {
            bna_error('Exception in generate_customer_data_hash', array(
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'customer_data_keys' => array_keys($customer_data)
            ));

            // Fallback to simple JSON encoding
            return md5(wp_json_encode($customer_data));
        }
    }

    /**
     * Check if customer data has changed since last update
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

            return true; // If we can't determine, assume changed
        }
    }

    /**
     * Generate checkout token for iframe
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

            // Save customer ID and hash to order
            if (!empty($customer_result['customer_id'])) {
                $order->add_meta_data('_bna_customer_id', $customer_result['customer_id']);

                $customer_data = $this->build_customer_info($order);
                if ($customer_data) {
                    $data_hash = $this->generate_customer_data_hash($customer_data);
                    $order->update_meta_data('_bna_customer_data_hash', $data_hash);
                }

                // Save to user meta if logged in
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
                'file' => basename($e->getFile())
            ));

            return new WP_Error('checkout_exception', 'Token generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Get existing customer or create new one
     */
    private function get_or_create_customer($order) {
        try {
            bna_debug('Starting get_or_create_customer', array(
                'order_id' => $order->get_id()
            ));

            // Look for existing customer ID
            $existing_customer_id = $order->get_meta('_bna_customer_id');

            // If no customer ID on order, check user meta
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

            // Build customer data
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

            // If we have existing customer, check if update is needed
            if (!empty($existing_customer_id)) {
                if ($this->has_customer_data_changed($order, $customer_data)) {
                    bna_log('Customer data changed, updating', array(
                        'customer_id' => $existing_customer_id,
                        'order_id' => $order->get_id()
                    ));

                    $update_result = $this->update_customer($existing_customer_id, $customer_data);

                    if (is_wp_error($update_result)) {
                        bna_error('Customer update failed, creating new', array(
                            'customer_id' => $existing_customer_id,
                            'error' => $update_result->get_error_message()
                        ));

                        // If update fails, create new customer
                        return $this->create_new_customer($customer_data, $order);
                    }

                    // Update the hash
                    $current_hash = $this->generate_customer_data_hash($customer_data);
                    $order->update_meta_data('_bna_customer_data_hash', $current_hash);
                    $order->save();

                    bna_log('Customer updated successfully', array(
                        'customer_id' => $existing_customer_id,
                        'order_id' => $order->get_id()
                    ));

                    return array(
                        'customer_id' => $existing_customer_id,
                        'is_existing' => true,
                        'was_updated' => true
                    );
                } else {
                    bna_debug('Customer data unchanged, using existing', array(
                        'customer_id' => $existing_customer_id
                    ));

                    return array(
                        'customer_id' => $existing_customer_id,
                        'is_existing' => true,
                        'was_updated' => false
                    );
                }
            }

            // No existing customer, create new one
            bna_debug('No existing customer, creating new', array(
                'order_id' => $order->get_id()
            ));

            return $this->create_new_customer($customer_data, $order);

        } catch (Exception $e) {
            bna_error('Exception in get_or_create_customer', array(
                'order_id' => $order->get_id(),
                'exception' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ));

            return new WP_Error('customer_exception', 'Customer processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Create new customer via BNA API
     */
    private function create_new_customer($customer_data, $order = null) {
        try {
            bna_debug('Creating new customer', array(
                'customer_email' => $customer_data['email']
            ));

            $response = $this->make_request('v1/customers', 'POST', $customer_data);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $error_data = $response->get_error_data();

                // Check if customer already exists
                if ($this->is_customer_exists_error($error_message, $error_data)) {
                    bna_debug('Customer exists, searching by email', array(
                        'customer_email' => $customer_data['email']
                    ));
                    return $this->find_existing_customer($customer_data['email'], $order);
                }

                return $response;
            }

            if (empty($response['id'])) {
                return new WP_Error('invalid_customer_response', 'Customer ID not found in response');
            }

            bna_log('New customer created successfully', array(
                'customer_id' => $response['id'],
                'customer_email' => $customer_data['email']
            ));

            // Save hash and customer ID to order and user meta
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

                    // Save to user meta if logged in
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
     * Create checkout payload for iframe
     */
    private function create_checkout_payload($order, $customer_result) {
        $payload = array(
            'iframeId' => get_option('bna_smart_payment_iframe_id'),
            'subtotal' => (float) $order->get_total(),
            'items' => $this->get_order_items($order)
        );

        // Use customer ID if available, otherwise include customer info
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

        return $payload;
    }

    /**
     * Build customer information for BNA API - UPDATED VERSION
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

            // Add phone if enabled and valid
            if (get_option('bna_smart_payment_enable_phone') === 'yes') {
                $phone_data = $this->get_clean_phone_with_country($order);
                if ($phone_data) {
                    $customer_info['phoneCode'] = $phone_data['code'];
                    $customer_info['phoneNumber'] = $phone_data['number'];

                    bna_log('Phone data added to customer', array(
                        'phone_code' => $phone_data['code'],
                        'phone_number' => $phone_data['number']
                    ));
                } else {
                    bna_log('Phone number skipped - invalid format', array(
                        'original_phone' => $order->get_billing_phone()
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

            // Add billing address
            $customer_info['address'] = $this->build_address($order);

            // Add shipping address if enabled and different from billing
            if (get_option('bna_smart_payment_enable_shipping_address') === 'yes') {
                $shipping_address = $this->build_shipping_address($order);
                if ($shipping_address) {
                    $customer_info['shippingAddress'] = $shipping_address;
                }
            }

            bna_log('Customer info built successfully', array(
                'fields_count' => count($customer_info),
                'has_shipping' => isset($customer_info['shippingAddress']),
                'has_phone' => isset($customer_info['phoneNumber']),
                'address_street' => $customer_info['address']['streetName'] ?? 'unknown',
                'address_number' => $customer_info['address']['streetNumber'] ?? 'unknown'
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
     * Get clean phone with proper country detection - NEW VERSION
     */
    private function get_clean_phone_with_country($order) {
        $raw_phone = trim($order->get_billing_phone());

        if (empty($raw_phone)) {
            return false;
        }

        // Log original phone for debugging
        bna_debug('Processing phone number', array(
            'original_phone' => $raw_phone,
            'billing_country' => $order->get_billing_country()
        ));

        // Remove all non-digits
        $digits_only = preg_replace('/\D/', '', $raw_phone);

        if (empty($digits_only)) {
            return false;
        }

        // Detect country and format phone
        $phone_result = $this->detect_phone_country($digits_only, $order->get_billing_country());

        if (!$phone_result) {
            bna_debug('Phone number rejected - invalid format', array(
                'digits_only' => $digits_only,
                'length' => strlen($digits_only)
            ));
            return false;
        }

        bna_debug('Phone number processed successfully', array(
            'original' => $raw_phone,
            'digits_only' => $digits_only,
            'result_code' => $phone_result['code'],
            'result_number' => $phone_result['number']
        ));

        return $phone_result;
    }

    /**
     * Detect phone country and format number - NEW METHOD
     */
    private function detect_phone_country($digits_only, $billing_country) {
        $length = strlen($digits_only);

        // Canadian/US phone: 10 digits or 11 digits starting with 1
        if (($length === 10) || ($length === 11 && substr($digits_only, 0, 1) === '1')) {
            $phone_number = $length === 11 ? substr($digits_only, 1) : $digits_only;

            // Validate format: area code can't start with 0 or 1
            if (strlen($phone_number) === 10 && !in_array(substr($phone_number, 0, 1), ['0', '1'])) {
                return array(
                    'code' => '+1',
                    'number' => $phone_number
                );
            }
        }

        // Ukrainian phone: typically starts with 38 (country code) or 0
        if ($length >= 12 && substr($digits_only, 0, 2) === '38') {
            // Ukrainian number with country code
            return array(
                'code' => '+38',
                'number' => substr($digits_only, 2)
            );
        }

        if ($length >= 10 && substr($digits_only, 0, 1) === '0') {
            // Ukrainian number without country code (starts with 0)
            return array(
                'code' => '+38',
                'number' => $digits_only
            );
        }

        // Other international numbers - try to guess by billing country
        if ($billing_country && $billing_country !== 'CA' && $billing_country !== 'US') {
            $country_codes = array(
                'UA' => '+38',
                'GB' => '+44',
                'DE' => '+49',
                'FR' => '+33',
                'PL' => '+48',
                // Add more as needed
            );

            if (isset($country_codes[$billing_country])) {
                return array(
                    'code' => $country_codes[$billing_country],
                    'number' => $digits_only
                );
            }
        }

        // Default: don't send invalid phone numbers
        return false;
    }

    /**
     * Build address with improved parsing - UPDATED VERSION
     */
    private function build_address($order) {
        $enable_billing_address = get_option('bna_smart_payment_enable_billing_address', 'no');

        if ($enable_billing_address !== 'yes') {
            return array(
                'postalCode' => $this->format_postal_code($order->get_billing_postcode())
            );
        }

        // Get raw address data
        $street = trim($order->get_billing_address_1());
        $city = trim($order->get_billing_city());
        $country = $order->get_billing_country();
        $province = $order->get_billing_state();
        $postal_code = $order->get_billing_postcode();

        bna_debug('Building address', array(
            'original_street' => $street,
            'city' => $city,
            'country' => $country,
            'province' => $province,
            'postal_code' => $postal_code
        ));

        // Parse street address
        $street_number = $this->extract_street_number($street);
        $street_name = $this->clean_street_name($street, $street_number);

        $address = array(
            'streetNumber' => $street_number,
            'streetName' => $street_name,
            'city' => $this->clean_city_name($city),
            'province' => $province ?: 'ON',
            'country' => $country ?: 'CA',
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

    /**
     * Extract street number from address - IMPROVED VERSION
     */
    private function extract_street_number($street) {
        if (empty($street)) {
            return '1';
        }

        $street = trim($street);

        bna_debug('Extracting street number', array(
            'original_street' => $street
        ));

        // Pattern 1: Number at the beginning: "123 Main St" -> "123"
        if (preg_match('/^(\d+)\s+/', $street, $matches)) {
            $number = $matches[1];
            bna_debug('Street number found at beginning', array(
                'number' => $number,
                'pattern' => 'start'
            ));
            return $number;
        }

        // Pattern 2: Number at the end: "Main St 123" -> "123"
        if (preg_match('/\s+(\d+)$/', $street, $matches)) {
            $number = $matches[1];
            bna_debug('Street number found at end', array(
                'number' => $number,
                'pattern' => 'end'
            ));
            return $number;
        }

        // Pattern 3: Any number in the string: "Apt 5 Main 123 St" -> "123" (last one)
        if (preg_match_all('/(\d+)/', $street, $matches)) {
            $numbers = $matches[1];
            $number = end($numbers); // Take the last number
            bna_debug('Street number found in middle', array(
                'all_numbers' => $numbers,
                'selected_number' => $number,
                'pattern' => 'middle'
            ));
            return $number;
        }

        bna_debug('No street number found, using default', array(
            'original_street' => $street,
            'default' => '1'
        ));

        return '1'; // Default
    }

    /**
     * Clean street name after removing number - IMPROVED VERSION
     */
    private function clean_street_name($street, $street_number) {
        if (empty($street)) {
            return 'Main Street';
        }

        $original_street = $street;
        $street = trim($street);

        bna_debug('Cleaning street name', array(
            'original_street' => $original_street,
            'street_number' => $street_number
        ));

        // Remove the extracted number from different positions
        $patterns_to_remove = array(
            '/^' . preg_quote($street_number, '/') . '\s+/',  // Number at start
            '/\s+' . preg_quote($street_number, '/') . '$/',  // Number at end
            '/\s+' . preg_quote($street_number, '/') . '\s+/' // Number in middle
        );

        foreach ($patterns_to_remove as $pattern) {
            $cleaned = preg_replace($pattern, ' ', $street);
            if ($cleaned !== $street) {
                $street = trim($cleaned);
                break;
            }
        }

        // Clean up extra spaces
        $street = preg_replace('/\s+/', ' ', trim($street));

        if (empty($street) || $street === $street_number) {
            $street = 'Main Street';
        }

        bna_debug('Street name cleaned', array(
            'original' => $original_street,
            'number_removed' => $street_number,
            'final_street_name' => $street
        ));

        return $street;
    }

    /**
     * Build shipping address if different from billing
     */
    private function build_shipping_address($order) {
        if (get_option('bna_smart_payment_enable_shipping_address') !== 'yes') {
            return null;
        }

        $same_as_billing = $order->get_meta('_bna_shipping_same_as_billing');
        if ($same_as_billing === 'yes') {
            return null; // Don't send shipping address if same as billing
        }

        // Get shipping address from order meta
        $shipping_country = $order->get_meta('_bna_shipping_country');
        $shipping_address_1 = $order->get_meta('_bna_shipping_address_1');
        $shipping_city = $order->get_meta('_bna_shipping_city');
        $shipping_state = $order->get_meta('_bna_shipping_state');
        $shipping_postcode = $order->get_meta('_bna_shipping_postcode');

        if (empty($shipping_country) || empty($shipping_address_1) || empty($shipping_city)) {
            return null; // Missing required shipping info
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

    /**
     * Get valid birthdate from order
     */
    private function get_valid_birthdate($order) {
        $birthdate = $order->get_meta('_billing_birthdate');
        if ($this->is_valid_birthdate($birthdate)) {
            return $birthdate;
        }
        return '1990-01-01'; // Default if not provided
    }

    /**
     * Validate birthdate format and age
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
     * Clean name removing special characters
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
     * Format postal code for Canadian format
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
     * Make HTTP request to BNA API
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
            $args['body'] = wp_json_encode($data);
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
     * Get API base URL for current environment
     */
    private function get_api_url() {
        return self::ENVIRONMENTS[$this->environment] ?? self::ENVIRONMENTS['staging'];
    }

    /**
     * Get authorization headers
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
     * Check if API credentials are configured
     */
    private function has_credentials() {
        return !empty($this->credentials['access_key']) && !empty($this->credentials['secret_key']);
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        $result = $this->make_request('v1/account', 'GET');
        return !is_wp_error($result);
    }
}