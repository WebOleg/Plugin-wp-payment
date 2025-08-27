<?php
if (!defined('ABSPATH')) exit;

class BNA_iFrame_Service {
    private $api_service;

    public function __construct() {
        $this->api_service = new BNA_API_Service();
        BNA_Logger::debug('iFrame Service initialized');
    }

    public function generate_checkout_token($order) {
        $iframe_id = get_option('bna_smart_payment_iframe_id');
        if (empty($iframe_id)) {
            return new WP_Error('missing_iframe_id', 'iFrame ID not configured');
        }

        $customer_result = $this->get_or_create_customer($order);
        
        if (is_wp_error($customer_result)) {
            return $customer_result;
        }

        $checkout_data = [
            'iframeId' => $iframe_id,
            'customerId' => $customer_result['id'],
            'items' => $this->prepare_order_items($order),
            'subtotal' => (float) $order->get_total()
        ];

        BNA_Logger::info('Creating checkout token', [
            'customer_id' => $customer_result['id'],
            'customer_action' => $customer_result['action']
        ]);

        $response = $this->api_service->make_request('v1/checkout', 'POST', $checkout_data);

        if (!is_wp_error($response) && isset($response['token'])) {
            $order->add_meta_data('_bna_customer_id', $customer_result['id']);
            $order->save();
        }

        return $response;
    }

    private function get_or_create_customer($order) {
        $email = $order->get_billing_email();
        
        // Search existing customers
        $customers = $this->api_service->make_request('v1/customers', 'GET', [
            'email' => $email
        ]);

        if (!is_wp_error($customers) && isset($customers['data']) && !empty($customers['data'])) {
            foreach ($customers['data'] as $customer) {
                if (isset($customer['id']) && isset($customer['email'])) {
                    if (strtolower($customer['email']) === strtolower($email)) {
                        BNA_Logger::info('Found existing customer', [
                            'id' => $customer['id']
                        ]);
                        return ['id' => $customer['id'], 'action' => 'found'];
                    }
                }
            }
        }

        // Create new customer
        BNA_Logger::info('Creating new customer', ['email' => $email]);
        
        $customer_data = [
            'type' => 'Personal',
            'email' => $email,
            'firstName' => $order->get_billing_first_name() ?: 'Customer',
            'lastName' => $order->get_billing_last_name() ?: 'Customer',
            'phoneCode' => '+1',
            'phoneNumber' => $order->get_billing_phone() ?: '1234567890',
            'birthDate' => date('Y-m-d', strtotime('-25 years'))
        ];

        $create_result = $this->api_service->make_request('v1/customers', 'POST', $customer_data);

        if (is_wp_error($create_result)) {
            return $create_result;
        }

        if (isset($create_result['id'])) {
            BNA_Logger::info('Customer created successfully', [
                'id' => $create_result['id']
            ]);
            return ['id' => $create_result['id'], 'action' => 'created'];
        }

        return new WP_Error('customer_failed', 'Customer setup failed');
    }

    private function prepare_order_items($order) {
        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $items[] = [
                'sku' => 'ITEM-' . $item_id,
                'description' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => (float) $order->get_item_total($item),
                'amount' => (float) $order->get_line_total($item)
            ];
        }
        return $items;
    }

    public function get_iframe_url($token) {
        return $this->api_service->get_api_url() . '/v1/checkout/' . $token;
    }
}
