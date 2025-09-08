<?php

if (!defined('ABSPATH')) exit;

class BNA_Payment_Methods {

    private static $instance = null;
    private $api;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api = new BNA_API();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
    }

    public function save_payment_method($user_id, $payment_method_data) {
        if (!$user_id || empty($payment_method_data)) {
            return false;
        }

        $existing_methods = $this->get_user_payment_methods($user_id);
        
        $new_method = array(
            'id' => $payment_method_data['id'],
            'type' => $payment_method_data['type'] ?? 'UNKNOWN',
            'last4' => $this->extract_last4($payment_method_data),
            'brand' => $this->extract_brand($payment_method_data),
            'created_at' => current_time('Y-m-d H:i:s')
        );

        if (!$this->method_exists($existing_methods, $new_method['id'])) {
            $existing_methods[] = $new_method;
            return update_user_meta($user_id, '_bna_payment_methods', $existing_methods);
        }

        return true;
    }

    public function get_user_payment_methods($user_id) {
        if (!$user_id) {
            return array();
        }

        $methods = get_user_meta($user_id, '_bna_payment_methods', true);
        return is_array($methods) ? $methods : array();
    }

    public function delete_payment_method($user_id, $payment_method_id) {
        if (!$user_id || !$payment_method_id) {
            return new WP_Error('invalid_params', 'Invalid parameters');
        }

        $customer_id = get_user_meta($user_id, '_bna_customer_id', true);
        if (!$customer_id) {
            return new WP_Error('no_customer_id', 'BNA customer ID not found');
        }

        $existing_methods = $this->get_user_payment_methods($user_id);
        $method_found = false;
        $method_type = '';

        foreach ($existing_methods as $method) {
            if ($method['id'] === $payment_method_id) {
                $method_found = true;
                $method_type = strtolower($method['type']);
                break;
            }
        }

        if (!$method_found) {
            return new WP_Error('method_not_found', 'Payment method not found');
        }

        $api_result = $this->delete_from_bna_portal($customer_id, $payment_method_id, $method_type);
        
        if (is_wp_error($api_result)) {
            $error_message = $api_result->get_error_message();
            
            if (strpos($error_message, 'Internal Server Error') !== false || 
                strpos($error_message, '500') !== false) {
                
                bna_log('API error during deletion, removing locally only', array(
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                    'error' => $error_message
                ));
                
                $updated_methods = array_filter($existing_methods, function($method) use ($payment_method_id) {
                    return $method['id'] !== $payment_method_id;
                });

                $local_delete_result = update_user_meta($user_id, '_bna_payment_methods', array_values($updated_methods));
                
                if ($local_delete_result) {
                    bna_log('Payment method removed locally due to API error', array(
                        'user_id' => $user_id,
                        'payment_method_id' => $payment_method_id
                    ));
                    return true;
                }
            }
            
            return $api_result;
        }

        $updated_methods = array_filter($existing_methods, function($method) use ($payment_method_id) {
            return $method['id'] !== $payment_method_id;
        });

        $result = update_user_meta($user_id, '_bna_payment_methods', array_values($updated_methods));
        
        if ($result) {
            bna_log('Payment method deleted successfully', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id,
                'deleted_from_api' => true
            ));
        }

        return $result;
    }

    private function delete_from_bna_portal($customer_id, $payment_method_id, $method_type) {
        $endpoint_map = array(
            'credit' => "v1/customers/{$customer_id}/card/{$payment_method_id}",
            'debit' => "v1/customers/{$customer_id}/card/{$payment_method_id}",
            'eft' => "v1/customers/{$customer_id}/eft/{$payment_method_id}",
            'e_transfer' => "v1/customers/{$customer_id}/e-transfer/{$payment_method_id}"
        );

        $endpoint = $endpoint_map[$method_type] ?? null;
        
        if (!$endpoint) {
            return new WP_Error('unsupported_type', 'Unsupported payment method type');
        }

        bna_log('Attempting to delete payment method from BNA portal', array(
            'customer_id' => $customer_id,
            'payment_method_id' => $payment_method_id,
            'method_type' => $method_type,
            'endpoint' => $endpoint
        ));

        return $this->api->make_request($endpoint, 'DELETE');
    }

    private function method_exists($methods, $method_id) {
        foreach ($methods as $method) {
            if ($method['id'] === $method_id) {
                return true;
            }
        }
        return false;
    }

    private function extract_last4($payment_data) {
        if (isset($payment_data['cardNumber'])) {
            return substr($payment_data['cardNumber'], -4);
        }
        if (isset($payment_data['accountNumber'])) {
            return substr($payment_data['accountNumber'], -4);
        }
        return '****';
    }

    private function extract_brand($payment_data) {
        if (isset($payment_data['cardType'])) {
            return ucfirst(strtolower($payment_data['cardType']));
        }
        if (isset($payment_data['bankNumber'])) {
            return 'Bank Transfer';
        }
        if (isset($payment_data['email'])) {
            return 'E-Transfer';
        }
        return 'Unknown';
    }
}
