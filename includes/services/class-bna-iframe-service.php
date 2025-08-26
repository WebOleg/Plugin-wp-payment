<?php
/**
 * BNA Smart Payment iFrame Service
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_iFrame_Service {

    private $api_service;

    public function __construct() {
        $this->api_service = new BNA_API_Service();
    }

    /**
     * Generate checkout token for order
     *
     * @param WC_Order $order
     * @return array|WP_Error
     */
    public function generate_checkout_token($order) {
        if (!$order || !$order instanceof WC_Order) {
            return new WP_Error('invalid_order', 'Invalid order provided');
        }

        $iframe_id = get_option('bna_smart_payment_iframe_id');
        if (empty($iframe_id)) {
            return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
        }

        // Prepare checkout payload according to API requirements
        $checkout_data = array(
            'iframeId' => $iframe_id,
            'customerInfo' => $this->prepare_customer_data($order),
            'items' => $this->prepare_order_items($order),
            'subtotal' => (float) $order->get_total()
        );

        $response = $this->api_service->make_request('v1/checkout', 'POST', $checkout_data);

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['token'])) {
            $order->add_meta_data('_bna_checkout_token', $response['token']);
            $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
            $order->save();
        }

        return $response;
    }

    /**
     * Get iframe URL with token
     *
     * @param string $token
     * @return string
     */
    public function get_iframe_url($token) {
        if (empty($token)) {
            return '';
        }

        $base_url = $this->api_service->get_api_url();
        return $base_url . '/v1/checkout/' . $token;
    }

    /**
     * Prepare customer data for API
     *
     * @param WC_Order $order
     * @return array
     */
    private function prepare_customer_data($order) {
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
        }

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
            return date('Y-m-d', strtotime($birth_date));
        }

        // Try to get from user meta
        $user_id = $order->get_user_id();
        if ($user_id) {
            $birth_date = get_user_meta($user_id, 'billing_birth_date', true);
            if (!empty($birth_date)) {
                return date('Y-m-d', strtotime($birth_date));
            }
        }

        // Default birth date (25 years ago)
        return date('Y-m-d', strtotime('-25 years'));
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
            return $matches[1];
        }

        // If no number found, use default
        return '1';
    }

    /**
     * Prepare order items for API
     *
     * @param WC_Order $order
     * @return array
     */
    private function prepare_order_items($order) {
        $items = array();

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();

            // Generate SKU if missing
            $sku = 'ITEM-' . $item_id;
            if ($product && $product->get_sku()) {
                $sku = $product->get_sku();
            }

            $items[] = array(
                'sku' => $sku,
                'description' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'price' => (float) $order->get_item_total($item),
                'amount' => (float) $order->get_line_total($item)
            );
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
        }

        return $items;
    }
}