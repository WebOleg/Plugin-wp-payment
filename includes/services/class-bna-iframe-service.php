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

    public function generate_checkout_token($order) {
        BNA_Logger::info('Generating checkout token for order: ' . $order->get_id());

        if (!$order || !$order instanceof WC_Order) {
            BNA_Logger::error('Invalid order provided');
            return new WP_Error('invalid_order', 'Invalid order provided');
        }

        $iframe_id = get_option('bna_smart_payment_iframe_id');
        if (empty($iframe_id)) {
            BNA_Logger::error('iFrame ID not configured');
            return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
        }

        // Prepare checkout payload - removed currency and metadata per API validation
        $checkout_data = array(
            'iframeId' => $iframe_id,
            'customerInfo' => $this->prepare_customer_data($order),
            'items' => $this->prepare_order_items($order),
            'subtotal' => (float) $order->get_total()
        );

        BNA_Logger::debug('Checkout data: ' . wp_json_encode($checkout_data));

        $response = $this->api_service->make_request('v1/checkout', 'POST', $checkout_data);

        if (is_wp_error($response)) {
            BNA_Logger::error('Token generation failed: ' . $response->get_error_message());
            return $response;
        }

        if (isset($response['token'])) {
            $order->add_meta_data('_bna_checkout_token', $response['token']);
            $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
            $order->save();
            BNA_Logger::info('Token generated successfully: ' . substr($response['token'], 0, 10) . '...');
        } else {
            BNA_Logger::error('No token in API response');
        }

        return $response;
    }

    public function get_iframe_url($token) {
        if (empty($token)) {
            return '';
        }

        $base_url = $this->api_service->get_api_url();
        return $base_url . '/v1/checkout/' . $token;
    }

    private function prepare_customer_data($order) {
        $customer_info = array(
            'type' => 'Personal',
            'email' => $order->get_billing_email(),
            'firstName' => $order->get_billing_first_name(),
            'lastName' => $order->get_billing_last_name(),
        );

        // Add phone if available
        if ($order->get_billing_phone()) {
            $customer_info['phoneNumber'] = $order->get_billing_phone();
            $customer_info['phoneCode'] = '+1';
        }

        // Add address - streetNumber CANNOT be empty per API validation
        if ($order->get_billing_address_1()) {
            $street_address = $order->get_billing_address_1();
            $street_number = $this->extract_street_number($street_address);
            
            $customer_info['address'] = array(
                'streetName' => $street_address,
                'streetNumber' => $street_number, // Must not be empty
                'apartment' => $order->get_billing_address_2() ?: '',
                'city' => $order->get_billing_city(),
                'province' => $order->get_billing_state(),
                'country' => $order->get_billing_country(),
                'postalCode' => $order->get_billing_postcode()
            );
        }

        return $customer_info;
    }

    /**
     * Extract street number from address or return default
     */
    private function extract_street_number($address) {
        // Try to extract number from beginning of address
        if (preg_match('/^(\d+)/', $address, $matches)) {
            return $matches[1];
        }
        
        // If no number found, use default - cannot be empty
        return '1';
    }

    private function prepare_order_items($order) {
        $items = array();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            // SKU cannot be empty per API validation
            $sku = '';
            if ($product && $product->get_sku()) {
                $sku = $product->get_sku();
            } else {
                $sku = 'ITEM-' . $item_id; // Generate SKU if missing
            }
            
            $items[] = array(
                'sku' => $sku, // Must not be empty
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
