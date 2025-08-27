<?php
/**
 * BNA Smart Payment iFrame Service with endpoint validation
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_iFrame_Service {

    private $api_service;

    public function __construct() {
        $this->api_service = new BNA_API_Service();
        BNA_Logger::debug('iFrame Service initialized');
    }

    /**
     * Generate checkout token for order
     *
     * @param WC_Order $order
     * @return array|WP_Error
     */
    public function generate_checkout_token($order) {
        BNA_Logger::info('Generating checkout token started', [
            'order_id' => $order ? $order->get_id() : 'null',
            'order_total' => $order ? $order->get_total() : 'null'
        ]);

        if (!$order || !$order instanceof WC_Order) {
            BNA_Logger::error('Invalid order provided for token generation');
            return new WP_Error('invalid_order', 'Invalid order provided');
        }

        $iframe_id = get_option('bna_smart_payment_iframe_id');
        if (empty($iframe_id)) {
            BNA_Logger::error('iFrame ID not configured', [
                'order_id' => $order->get_id()
            ]);
            return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
        }

        BNA_Logger::debug('iFrame ID found', ['iframe_id' => $iframe_id]);

        // Prepare checkout payload
        $checkout_data = array(
            'iframeId' => $iframe_id,
            'customerInfo' => $this->prepare_customer_data($order),
            'items' => $this->prepare_order_items($order),
            'subtotal' => (float) $order->get_total()
        );

        BNA_Logger::info('Making API request to generate token', [
            'order_id' => $order->get_id(),
            'endpoint' => 'v1/checkout',
            'iframe_id' => $iframe_id,
            'subtotal' => $checkout_data['subtotal']
        ]);

        $response = $this->api_service->make_request('v1/checkout', 'POST', $checkout_data);

        if (is_wp_error($response)) {
            BNA_Logger::error('Checkout token generation failed - API Error', [
                'order_id' => $order->get_id(),
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message()
            ]);
            return $response;
        }

        if (isset($response['token'])) {
            BNA_Logger::info('Checkout token generated successfully', [
                'order_id' => $order->get_id(),
                'token_length' => strlen($response['token'])
            ]);

            $order->add_meta_data('_bna_checkout_token', $response['token']);
            $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
            $order->save();

            BNA_Logger::debug('Token saved to order meta', [
                'order_id' => $order->get_id()
            ]);

            // Test the generated URL immediately
            $this->test_iframe_url_accessibility($response['token'], $order->get_id());

        } else {
            BNA_Logger::error('No token in API response', [
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response)
            ]);
        }

        return $response;
    }

    /**
     * Test if iframe URL is accessible
     */
    private function test_iframe_url_accessibility($token, $order_id) {
        $iframe_url = $this->get_iframe_url($token);
        
        BNA_Logger::debug('Testing iframe URL accessibility', [
            'order_id' => $order_id,
            'iframe_url' => $iframe_url
        ]);

        // Make a HEAD request to test if URL exists
        $test_response = wp_remote_head($iframe_url, [
            'timeout' => 10,
            'sslverify' => false // For dev environment
        ]);

        if (is_wp_error($test_response)) {
            BNA_Logger::error('iFrame URL test failed - Request Error', [
                'order_id' => $order_id,
                'iframe_url' => $iframe_url,
                'error_code' => $test_response->get_error_code(),
                'error_message' => $test_response->get_error_message()
            ]);
        } else {
            $status_code = wp_remote_retrieve_response_code($test_response);
            BNA_Logger::info('iFrame URL test result', [
                'order_id' => $order_id,
                'iframe_url' => $iframe_url,
                'status_code' => $status_code,
                'is_accessible' => $status_code == 200
            ]);

            if ($status_code == 404) {
                BNA_Logger::error('iFrame URL returns 404 - Token may be invalid or endpoint missing', [
                    'order_id' => $order_id,
                    'iframe_url' => $iframe_url,
                    'token_length' => strlen($token)
                ]);
            }
        }
    }

    /**
     * Get iframe URL with token
     *
     * @param string $token
     * @return string
     */
    public function get_iframe_url($token) {
        if (empty($token)) {
            BNA_Logger::error('Empty token provided for iframe URL');
            return '';
        }

        $base_url = $this->api_service->get_api_url();
        $iframe_url = $base_url . '/v1/checkout/' . $token;

        BNA_Logger::debug('iFrame URL generated', [
            'base_url' => $base_url,
            'token_length' => strlen($token),
            'full_url_length' => strlen($iframe_url)
        ]);

        return $iframe_url;
    }

    // ... rest of the methods remain the same ...

    /**
     * Prepare customer data for API
     *
     * @param WC_Order $order
     * @return array
     */
    private function prepare_customer_data($order) {
        BNA_Logger::debug('Preparing customer data', [
            'order_id' => $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
        ]);

        $customer_info = array(
            'type' => 'Personal',
            'email' => $order->get_billing_email(),
            'firstName' => $order->get_billing_first_name() ?: 'Customer',
            'lastName' => $order->get_billing_last_name() ?: 'Customer',
            'birthDate' => $this->get_customer_birth_date($order),
        );

        // Add phone if available
        if ($order->get_billing_phone()) {
            $customer_info['phoneNumber'] = $order->get_billing_phone();
            $customer_info['phoneCode'] = '+1';
            BNA_Logger::debug('Phone number added to customer data');
        }

        // Add address if available
        if ($order->get_billing_address_1()) {
            $customer_info['address'] = array(
                'streetName' => $order->get_billing_address_1(),
                'streetNumber' => $this->extract_street_number($order->get_billing_address_1()),
                'apartment' => $order->get_billing_address_2() ?: '',
                'city' => $order->get_billing_city() ?: 'Unknown',
                'province' => $order->get_billing_state() ?: 'Unknown',
                'country' => $order->get_billing_country() ?: 'US',
                'postalCode' => $order->get_billing_postcode() ?: '00000'
            );
            BNA_Logger::debug('Address added to customer data', [
                'city' => $customer_info['address']['city'],
                'country' => $customer_info['address']['country']
            ]);
        }

        BNA_Logger::debug('Customer data prepared successfully');
        return $customer_info;
    }

    /**
     * Get customer birth date or default
     *
     * @param WC_Order $order
     * @return string
     */
    private function get_customer_birth_date($order) {
        // Try to get from order meta
        $birth_date = $order->get_meta('_billing_birth_date');

        if (!empty($birth_date)) {
            BNA_Logger::debug('Birth date found in order meta');
            return date('Y-m-d', strtotime($birth_date));
        }

        // Try to get from user meta
        $user_id = $order->get_user_id();
        if ($user_id) {
            $birth_date = get_user_meta($user_id, 'billing_birth_date', true);
            if (!empty($birth_date)) {
                BNA_Logger::debug('Birth date found in user meta');
                return date('Y-m-d', strtotime($birth_date));
            }
        }

        // Default birth date (25 years ago)
        $default_date = date('Y-m-d', strtotime('-25 years'));
        BNA_Logger::debug('Using default birth date', ['default_date' => $default_date]);
        return $default_date;
    }

    /**
     * Extract street number from address or return default
     *
     * @param string $address
     * @return string
     */
    private function extract_street_number($address) {
        // Try to extract number from beginning of address
        if (preg_match('/^(\d+)/', $address, $matches)) {
            BNA_Logger::debug('Street number extracted from address', ['number' => $matches[1]]);
            return $matches[1];
        }

        // If no number found, use default
        BNA_Logger::debug('No street number found, using default');
        return '1';
    }

    /**
     * Prepare order items for API
     *
     * @param WC_Order $order
     * @return array
     */
    private function prepare_order_items($order) {
        BNA_Logger::debug('Preparing order items', [
            'order_id' => $order->get_id(),
            'items_count' => count($order->get_items())
        ]);

        $items = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();

            // Generate SKU if missing
            $sku = 'ITEM-' . $item_id;
            if ($product && $product->get_sku()) {
                $sku = $product->get_sku();
            }

            $item_data = array(
                'sku' => $sku,
                'description' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'price' => (float) $order->get_item_total($item),
                'amount' => (float) $order->get_line_total($item)
            );

            $items[] = $item_data;

            BNA_Logger::debug('Order item prepared', [
                'sku' => $sku,
                'name' => $item->get_name(),
                'quantity' => $item_data['quantity'],
                'amount' => $item_data['amount']
            ]);
        }

        // Add shipping if present
        if ($order->get_shipping_total() > 0) {
            $items[] = array(
                'sku' => 'SHIPPING',
                'description' => 'Shipping',
                'quantity' => 1,
                'price' => (float) $order->get_shipping_total(),
                'amount' => (float) $order->get_shipping_total()
            );
            BNA_Logger::debug('Shipping item added', ['amount' => $order->get_shipping_total()]);
        }

        // Add taxes if present
        if ($order->get_total_tax() > 0) {
            $items[] = array(
                'sku' => 'TAX',
                'description' => 'Tax',
                'quantity' => 1,
                'price' => (float) $order->get_total_tax(),
                'amount' => (float) $order->get_total_tax()
            );
            BNA_Logger::debug('Tax item added', ['amount' => $order->get_total_tax()]);
        }

        BNA_Logger::info('Order items prepared successfully', [
            'total_items' => count($items),
            'total_amount' => array_sum(array_column($items, 'amount'))
        ]);

        return $items;
    }
}
