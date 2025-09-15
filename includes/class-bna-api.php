<?php
/**
 * BNA Smart Payment API Handler
 *
 * Handles all API communication with BNA Smart Payment service
 * FIXED VERSION 1.8.1 - Shipping Address Update Fix + PHP 7.0 Compatibility
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_API {

    private $access_key;
    private $secret_key;
    private $environment;
    private $base_url;

    // ВИПРАВЛЕНО: Змінено з "private const" на "private static" для сумісності з PHP 7.0
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
        'EE' => 'Estonia',
        'FI' => 'Finland',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'IS' => 'Iceland',
        'IE' => 'Ireland',
        'PT' => 'Portugal',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'LU' => 'Luxembourg',
        'CH' => 'Switzerland',
        'AT' => 'Austria',
        'GR' => 'Greece',
        'CY' => 'Cyprus',
        'MT' => 'Malta',
        'TR' => 'Turkey',
        'RU' => 'Russia',
        'KZ' => 'Kazakhstan',
        'UZ' => 'Uzbekistan',
        'TM' => 'Turkmenistan',
        'TJ' => 'Tajikistan',
        'KG' => 'Kyrgyzstan',
        'AF' => 'Afghanistan',
        'PK' => 'Pakistan',
        'BD' => 'Bangladesh',
        'LK' => 'Sri Lanka',
        'MV' => 'Maldives',
        'BT' => 'Bhutan',
        'NP' => 'Nepal',
        'MM' => 'Myanmar',
        'TH' => 'Thailand',
        'KH' => 'Cambodia',
        'LA' => 'Laos',
        'VN' => 'Vietnam',
        'MY' => 'Malaysia',
        'SG' => 'Singapore',
        'BN' => 'Brunei',
        'ID' => 'Indonesia',
        'TL' => 'East Timor',
        'PH' => 'Philippines',
        'TW' => 'Taiwan',
        'KR' => 'South Korea',
        'KP' => 'North Korea',
        'MN' => 'Mongolia',
    );

    public function __construct() {
        $this->load_configuration();
    }

    private function load_configuration() {
        $this->access_key = get_option('bna_smart_payment_access_key', '');
        $this->secret_key = get_option('bna_smart_payment_secret_key', '');
        $this->environment = get_option('bna_smart_payment_environment', 'dev');

        if ($this->environment === 'prod') {
            $this->base_url = 'https://api-service.bnasmartpayment.com';
        } else {
            $this->base_url = 'https://dev-api-service.bnasmartpayment.com';
        }

        bna_debug('BNA API initialized', array(
            'environment' => $this->environment,
            'has_credentials' => $this->has_credentials()
        ));
    }

    public function has_credentials() {
        return !empty($this->access_key) && !empty($this->secret_key);
    }

    public function get_api_url() {
        return $this->base_url;
    }

    public function test_connection() {
        if (!$this->has_credentials()) {
            return new WP_Error('missing_credentials', 'API credentials are not configured');
        }

        $response = $this->make_request('v1/account', 'GET');

        if (is_wp_error($response)) {
            return $response;
        }

        return array(
            'success' => true,
            'account' => $response
        );
    }

    private function update_customer($customer_id, $customer_data) {
        try {
            $validation_result = $this->validate_customer_data($customer_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }

            // Remove email from update data - email cannot be updated for existing customers
            if (isset($customer_data['email'])) {
                unset($customer_data['email']);
                bna_debug('Removed email from update data - email cannot be updated for existing customers');
            }

            bna_log('Updating customer', array(
                'customer_id' => $customer_id,
                'fields_to_update' => array_keys($customer_data),
                'has_shipping_address' => isset($customer_data['shippingAddress'])
            ));

            $response = $this->make_request('v1/customers/' . $customer_id, 'PATCH', $customer_data);

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

    private function validate_customer_data($customer_data) {
        $errors = array();

        $required_fields = array('firstName', 'lastName', 'type');
        foreach ($required_fields as $field) {
            if (empty($customer_data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (isset($customer_data['email']) && !filter_var($customer_data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }

        // ВИПРАВЛЕНО: перевірка billingAddress замість address
        if (isset($customer_data['billingAddress']['country'])) {
            $country = $customer_data['billingAddress']['country'];
            if (!$this->is_valid_country_code($country)) {
                $errors[] = "Invalid country code in billing address: {$country}";
            }
        }

        if (isset($customer_data['shippingAddress']['country'])) {
            $country = $customer_data['shippingAddress']['country'];
            if (!$this->is_valid_country_code($country)) {
                $errors[] = "Invalid country code in shipping address: {$country}";
            }
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
        // ВИПРАВЛЕНО: додано $ перед COUNTRY_CODE_MAPPING
        return isset(self::$COUNTRY_CODE_MAPPING[$country_code]) ||
            in_array($country_code, self::$COUNTRY_CODE_MAPPING);
    }

    private function is_valid_phone_number($phone) {
        $digits_only = preg_replace('/\D/', '', $phone);
        return strlen($digits_only) >= 7 && strlen($digits_only) <= 15;
    }

    private function generate_customer_data_hash($customer_data) {
        try {
            bna_debug('Generating hash for customer data', array(
                'data_keys' => array_keys($customer_data)
            ));

            $relevant_data = array();

            // ОНОВЛЕНО: включаємо billingAddress замість address
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

            // Store customer data hash for future comparisons
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

    // ГОЛОВНЕ ВИПРАВЛЕННЯ: оновлена структура для build_customer_info
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

            // ВИПРАВЛЕНО: використовуємо billingAddress замість address
            $billing_address = $this->build_address($order);
            if (!empty($billing_address)) {
                $customer_info['billingAddress'] = $billing_address;
            }

            $shipping_address = $this->build_shipping_address($order);
            if ($shipping_address) {
                $customer_info['shippingAddress'] = $shipping_address;
            }

            $has_phone = isset($customer_info['phoneCode']) && isset($customer_info['phoneNumber']);
            // ВИПРАВЛЕНО: оновлені посилання на billingAddress
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

        // Handle Ukrainian mobile numbers first (common patterns)
        $ukraine_mobile_prefixes = array('050', '063', '066', '067', '068', '073', '093', '095', '096', '097', '098', '099');
        $first_three = substr($digits_only, 0, 3);

        if (in_array($first_three, $ukraine_mobile_prefixes) && strlen($digits_only) == 10) {
            bna_log('Detected Ukrainian mobile number', array(
                'prefix' => $first_three,
                'number' => $digits_only
            ));
            return '+380';
        }

        // Handle Canadian/US numbers
        if (in_array($billing_country, array('CA', 'US')) && strlen($digits_only) == 10) {
            return '+1';
        }

        // Handle numbers that start with country codes
        if (strlen($digits_only) >= 11) {
            $first_digit = substr($digits_only, 0, 1);

            // North American numbers starting with 1
            if ($first_digit === '1' && strlen($digits_only) == 11) {
                return '+1';
            }

            // European numbers
            $european_codes = array('44', '49', '33', '39', '34', '31', '32', '41', '43', '48', '420', '421');
            foreach ($european_codes as $code) {
                if (substr($digits_only, 0, strlen($code)) === $code) {
                    return '+' . $code;
                }
            }
        }

        // Default fallback based on billing country
        $country_to_code = array(
            'CA' => '+1',
            'US' => '+1',
            'GB' => '+44',
            'DE' => '+49',
            'FR' => '+33',
            'IT' => '+39',
            'ES' => '+34',
            'NL' => '+31',
            'BE' => '+32',
            'CH' => '+41',
            'AT' => '+43',
            'PL' => '+48',
            'CZ' => '+420',
            'SK' => '+421',
            'UA' => '+380'
        );

        if (isset($country_to_code[$billing_country])) {
            return $country_to_code[$billing_country];
        }

        return '+1'; // Default to North America
    }

    private function format_phone_number($digits_only, $phone_code) {
        $code_digits = str_replace('+', '', $phone_code);

        // Remove country code from the beginning if present
        if (strpos($digits_only, $code_digits) === 0) {
            $digits_only = substr($digits_only, strlen($code_digits));
        }

        // For Ukrainian numbers, ensure we have the proper format
        if ($phone_code === '+380' && strlen($digits_only) == 9) {
            return $digits_only;
        }

        // For Ukrainian numbers that start with 0, remove the 0
        if ($phone_code === '+380' && strlen($digits_only) == 10 && substr($digits_only, 0, 1) === '0') {
            return substr($digits_only, 1);
        }

        // For North American numbers
        if ($phone_code === '+1' && strlen($digits_only) == 10) {
            return $digits_only;
        }

        return $digits_only;
    }

    private function get_valid_birthdate($order) {
        $birthdate = $order->get_meta('_billing_birthdate');

        if (empty($birthdate)) {
            return null;
        }

        try {
            $date = new DateTime($birthdate);
            return $date->format('Y-m-d');
        } catch (Exception $e) {
            bna_debug('Invalid birthdate format', array(
                'birthdate' => $birthdate,
                'error' => $e->getMessage()
            ));
            return null;
        }
    }

    private function build_address($order) {
        $street_with_number = $order->get_billing_address_1();
        $city = $order->get_billing_city();
        $country = $order->get_billing_country();
        $province = $order->get_billing_state();
        $postal_code = $order->get_billing_postcode();

        if (empty($street_with_number) || empty($city) || empty($country)) {
            return array();
        }

        bna_debug('Building address', array(
            'original_street' => $street_with_number,
            'city' => $city,
            'country' => $country,
            'province' => $province,
            'postal_code' => $postal_code
        ));

        $street_number = $this->extract_street_number($street_with_number);
        $street_name = $this->clean_street_name($street_with_number, $street_number);

        $address = array(
            'streetNumber' => $street_number,
            'streetName' => $street_name,
            'city' => $this->clean_city_name($city),
            'province' => $province ? $province : 'ON',
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

    // ВИПРАВЛЕННЯ: Повністю переписаний метод build_shipping_address
    private function build_shipping_address($order) {
        if (get_option('bna_smart_payment_enable_shipping_address') !== 'yes') {
            return null;
        }

        $same_as_billing = $order->get_meta('_bna_shipping_same_as_billing');
        // ВИПРАВЛЕНО: порівнюємо з '1' замість 'yes'
        if ($same_as_billing === '1') {
            bna_debug('Shipping same as billing, skipping shipping address', array(
                'order_id' => $order->get_id()
            ));
            return null;
        }

        // ВИПРАВЛЕНО: читаємо з стандартних WC полів замість meta
        $shipping_country = $order->get_shipping_country();
        $shipping_address_1 = $order->get_shipping_address_1();
        $shipping_city = $order->get_shipping_city();
        $shipping_state = $order->get_shipping_state();
        $shipping_postcode = $order->get_shipping_postcode();
        $shipping_address_2 = $order->get_shipping_address_2();

        // Якщо немає shipping даних, спробуємо прочитати з BNA meta полів (fallback)
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

        if (!empty($shipping_address_2)) {
            $shipping_address['apartment'] = trim($shipping_address_2);
        }

        bna_debug('Shipping address built successfully', array(
            'order_id' => $order->get_id(),
            'final_address' => $shipping_address
        ));

        return $shipping_address;
    }

    private function extract_street_number($street_with_number) {
        $street_with_number = trim($street_with_number);

        bna_debug('Extracting street number', array(
            'original_street' => $street_with_number
        ));

        // Pattern 1: Number at the beginning (e.g., "123 Main Street")
        if (preg_match('/^(\d+)\s+(.+)/', $street_with_number, $matches)) {
            bna_debug('Street number found at beginning', array(
                'number' => $matches[1],
                'pattern' => 'beginning'
            ));
            return $matches[1];
        }

        // Pattern 2: Number at the end (e.g., "Main Street 123")
        if (preg_match('/(.+)\s+(\d+)$/', $street_with_number, $matches)) {
            bna_debug('Street number found at end', array(
                'number' => $matches[2],
                'pattern' => 'end'
            ));
            return $matches[2];
        }

        // Pattern 3: Number with letter suffix (e.g., "123A Main Street")
        if (preg_match('/^(\d+[A-Za-z]?)\s+(.+)/', $street_with_number, $matches)) {
            bna_debug('Street number with suffix found', array(
                'number' => $matches[1],
                'pattern' => 'suffix'
            ));
            return $matches[1];
        }

        // Default: return "1" if no number found
        bna_debug('No street number found, using default', array(
            'default' => '1'
        ));
        return '1';
    }

    private function clean_street_name($street_with_number, $street_number) {
        bna_debug('Cleaning street name', array(
            'original_street' => $street_with_number,
            'street_number' => $street_number
        ));

        $street_name = trim($street_with_number);

        // Remove the number from the beginning
        if (strpos($street_name, $street_number . ' ') === 0) {
            $street_name = trim(substr($street_name, strlen($street_number . ' ')));
        }
        // Remove the number from the end
        elseif (substr($street_name, -strlen(' ' . $street_number)) === ' ' . $street_number) {
            $street_name = trim(substr($street_name, 0, -strlen(' ' . $street_number)));
        }

        if (empty($street_name)) {
            $street_name = 'Street';
        }

        bna_debug('Street name cleaned', array(
            'original' => $street_with_number,
            'number_removed' => $street_number,
            'final_street_name' => $street_name
        ));

        return $street_name;
    }

    private function clean_city_name($city) {
        return trim(preg_replace('/[^a-zA-Z0-9\s\-\']/', '', $city));
    }

    private function clean_name($name) {
        return trim(preg_replace('/[^a-zA-Z\s\-\']/', '', $name));
    }

    private function map_country_code($country_code) {
        // ВИПРАВЛЕНО: додано $ перед COUNTRY_CODE_MAPPING
        return isset(self::$COUNTRY_CODE_MAPPING[$country_code])
            ? self::$COUNTRY_CODE_MAPPING[$country_code]
            : $country_code;
    }

    private function format_postal_code($postal_code) {
        return strtoupper(trim($postal_code));
    }

    private function get_order_items($order) {
        $items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();

            // ВИПРАВЛЕНО: Покращена логіка для SKU
            $sku = '';
            if ($product && $product->get_sku()) {
                $sku = $product->get_sku();
            } else {
                // Генеруємо SKU з назви товару якщо немає оригінального SKU
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

            return new WP_Error('create_customer_exception', 'Customer creation failed: ' . $e->getMessage());
        }
    }

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

        // Log request details
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
        $request_duration = (microtime(true) - $request_start_time) * 1000;

        if (is_wp_error($response)) {
            bna_error('HTTP Request Failed', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'error' => $response->get_error_message(),
                'duration_ms' => round($request_duration, 2)
            ));
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        // Log response details
        bna_log('HTTP Response Details', array(
            'endpoint' => $endpoint,
            'method' => $method,
            'duration_ms' => round($request_duration, 2),
            'status_code' => $status_code,
            'status_message' => wp_remote_retrieve_response_message($response),
            'response_headers' => array(),
            'body_size' => strlen($body),
            'content_type' => $response_headers->offsetGet('content-type')
        ));

        if (!empty($body)) {
            bna_debug('Response Body Content', array(
                'status_code' => $status_code,
                'body_preview' => substr($body, 0, 1000) . (strlen($body) > 1000 ? '...' : ''),
                'full_length' => strlen($body),
                'is_empty' => empty(trim($body))
            ));
        }

        if ($status_code < 200 || $status_code >= 300) {
            $error_message = "HTTP {$status_code}: " . wp_remote_retrieve_response_message($response);

            if (!empty($body)) {
                $decoded_body = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded_body['message'])) {
                    $error_message .= ' - ' . $decoded_body['message'];
                } else {
                    $error_message .= ' - ' . $body;
                }
            }

            bna_error('API Request Failed', array(
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $status_code,
                'error_message' => $error_message,
                'duration_ms' => round($request_duration, 2)
            ));

            return new WP_Error('api_error', $error_message, array(
                'status' => $status_code,
                'response' => $body
            ));
        }

        $decoded_response = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('JSON Decode Error', array(
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'raw_body' => substr($body, 0, 500)
            ));
            return new WP_Error('json_decode_error', 'Invalid JSON response: ' . json_last_error_msg());
        }

        bna_log('API Request Successful', array(
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $status_code,
            'duration_ms' => round($request_duration, 2),
            'response_keys' => is_array($decoded_response) ? array_keys($decoded_response) : array()
        ));

        return $decoded_response;
    }

    private function safe_json_encode($data) {
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('JSON encode error', array(
                'error' => json_last_error_msg(),
                'data' => var_export($data, true)
            ));
            return serialize($data);
        }
        return $json;
    }
}