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
            bna_error('Invalid parameters for save_payment_method', array(
                'user_id' => $user_id,
                'has_data' => !empty($payment_method_data)
            ));
            return false;
        }

        if (!isset($payment_method_data['id']) || !isset($payment_method_data['type'])) {
            bna_error('Missing required payment method fields', array(
                'has_id' => isset($payment_method_data['id']),
                'has_type' => isset($payment_method_data['type']),
                'data_keys' => array_keys($payment_method_data)
            ));
            return false;
        }

        $existing_methods = $this->get_user_payment_methods($user_id);

        $new_method = array(
            'id' => $payment_method_data['id'],
            'type' => $this->normalize_method_type($payment_method_data['type']),
            'last4' => $this->extract_last4($payment_method_data),
            'brand' => $this->extract_brand($payment_method_data),
            'created_at' => $payment_method_data['created_at'] ?? current_time('Y-m-d H:i:s')
        );

        if ($this->card_already_exists($existing_methods, $new_method)) {
            bna_log('Payment method already exists, skipping save', array(
                'user_id' => $user_id,
                'type' => $new_method['type'],
                'last4' => $new_method['last4'],
                'brand' => $new_method['brand']
            ));
            return true;
        }

        if (!$this->method_exists($existing_methods, $new_method['id'])) {
            $existing_methods[] = $new_method;

            bna_log('Saving new payment method', array(
                'user_id' => $user_id,
                'method_id' => $new_method['id'],
                'type' => $new_method['type'],
                'brand' => $new_method['brand'],
                'last4' => $new_method['last4']
            ));

            return update_user_meta($user_id, '_bna_payment_methods', $existing_methods);
        } else {
            bna_log('Payment method already exists, skipping save', array(
                'user_id' => $user_id,
                'method_id' => $new_method['id']
            ));
            return true;
        }
    }

    private function card_already_exists($existing_methods, $new_method) {
        foreach ($existing_methods as $existing_method) {
            if ($existing_method['type'] === $new_method['type'] &&
                $existing_method['last4'] === $new_method['last4'] &&
                $existing_method['brand'] === $new_method['brand']) {

                if (isset($existing_method['expiryMonth']) && isset($new_method['expiryMonth']) &&
                    isset($existing_method['expiryYear']) && isset($new_method['expiryYear'])) {

                    if ($existing_method['expiryMonth'] === $new_method['expiryMonth'] &&
                        $existing_method['expiryYear'] === $new_method['expiryYear']) {
                        return true;
                    }
                } else {
                    return true;
                }
            }
        }

        return false;
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

        bna_log('Processing payment method deletion request', array(
            'user_id' => $user_id,
            'payment_method_id' => $payment_method_id
        ));

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
            $error_data = $api_result->get_error_data();

            bna_error('Payment method API deletion failed', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id,
                'error' => $error_message,
                'customer_id' => $customer_id,
                'method_type' => $method_type,
                'error_data' => $error_data
            ));

            if ($this->is_not_found_error($error_message, $error_data)) {
                bna_log('Payment method not found in API - removing from local storage', array(
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                    'error' => $error_message
                ));

                $updated_methods = array_filter($existing_methods, function($method) use ($payment_method_id) {
                    return $method['id'] !== $payment_method_id;
                });

                update_user_meta($user_id, '_bna_payment_methods', array_values($updated_methods));

                return array(
                    'status' => 'success',
                    'message' => 'Payment method removed (was already deleted from server)'
                );
            }

            if ($this->is_unclear_api_error($error_message)) {
                bna_log('API deletion response unclear - webhook will handle cleanup', array(
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                    'error' => $error_message
                ));

                return array(
                    'status' => 'pending',
                    'message' => 'Delete request sent - waiting for confirmation'
                );
            }

            return $api_result;
        }

        $updated_methods = array_filter($existing_methods, function($method) use ($payment_method_id) {
            return $method['id'] !== $payment_method_id;
        });

        update_user_meta($user_id, '_bna_payment_methods', array_values($updated_methods));

        bna_log('Payment method deleted successfully', array(
            'user_id' => $user_id,
            'payment_method_id' => $payment_method_id,
            'method_type' => $method_type
        ));

        return array(
            'status' => 'success',
            'message' => 'Payment method deleted successfully'
        );
    }

    public function delete_payment_method_by_id($user_id, $payment_method_id) {
        if (!$user_id || !$payment_method_id) {
            return false;
        }

        $existing_methods = $this->get_user_payment_methods($user_id);
        $method_found = false;

        foreach ($existing_methods as $method) {
            if ($method['id'] === $payment_method_id) {
                $method_found = true;
                break;
            }
        }

        if (!$method_found) {
            bna_log('Payment method not found for deletion by ID', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id
            ));
            return true;
        }

        $updated_methods = array_filter($existing_methods, function($method) use ($payment_method_id) {
            return $method['id'] !== $payment_method_id;
        });

        $result = update_user_meta($user_id, '_bna_payment_methods', array_values($updated_methods));

        if ($result) {
            bna_log('Payment method deleted by ID from webhook', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id,
                'remaining_methods' => count($updated_methods)
            ));
        }

        return $result;
    }

    private function delete_from_bna_portal($customer_id, $payment_method_id, $method_type) {
        $endpoint = "v1/customers/{$customer_id}/payment-methods/{$payment_method_id}";

        bna_log('Attempting to delete payment method from BNA Portal', array(
            'customer_id' => $customer_id,
            'payment_method_id' => $payment_method_id,
            'method_type' => $method_type,
            'endpoint' => $endpoint
        ));

        $result = $this->api->make_request($endpoint, 'DELETE');

        if (is_wp_error($result)) {
            bna_error('BNA Portal deletion failed', array(
                'endpoint' => $endpoint,
                'error' => $result->get_error_message()
            ));
        } else {
            bna_log('Payment method successfully deleted from BNA Portal', array(
                'payment_method_id' => $payment_method_id,
                'method_type' => $method_type,
                'endpoint' => $endpoint
            ));
        }

        return $result;
    }

    private function is_unclear_api_error($error_message) {
        $unclear_patterns = array(
            'Invalid JSON response',
            'Internal Server Error',
            'Syntax error',
            'unexpected token',
            'timeout',
            'connection reset',
            'network error'
        );

        foreach ($unclear_patterns as $pattern) {
            if (stripos($error_message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function method_exists($methods, $method_id) {
        foreach ($methods as $method) {
            if (isset($method['id']) && $method['id'] === $method_id) {
                return true;
            }
        }
        return false;
    }

    private function extract_last4($payment_data) {
        if (isset($payment_data['cardNumber'])) {
            $cardNumber = $payment_data['cardNumber'];
            $digits_only = preg_replace('/\D/', '', $cardNumber);
            return substr($digits_only, -4);
        }

        if (isset($payment_data['accountNumber'])) {
            $accountNumber = $payment_data['accountNumber'];
            $digits_only = preg_replace('/\D/', '', $accountNumber);
            return substr($digits_only, -4);
        }

        if (isset($payment_data['last4'])) {
            return $payment_data['last4'];
        }

        return '****';
    }

    private function extract_brand($payment_data) {
        if (isset($payment_data['cardBrand']) && !empty($payment_data['cardBrand'])) {
            return $this->format_brand_name($payment_data['cardBrand']);
        }

        if (isset($payment_data['cardType']) && !empty($payment_data['cardType'])) {
            return $this->format_brand_name($payment_data['cardType']);
        }

        if (isset($payment_data['bankName']) && !empty($payment_data['bankName'])) {
            return $payment_data['bankName'];
        }

        if (isset($payment_data['bankNumber']) || isset($payment_data['transitNumber'])) {
            return 'Bank Transfer';
        }

        if (isset($payment_data['email']) || isset($payment_data['deliveryType'])) {
            return 'E-Transfer';
        }

        return 'Unknown';
    }

    private function format_brand_name($brand) {
        $brand = strtoupper(trim($brand));

        $brand_map = array(
            'VISA' => 'Visa',
            'MASTERCARD' => 'Mastercard',
            'AMEX' => 'American Express',
            'DISCOVER' => 'Discover',
            'CREDIT' => 'Credit Card',
            'DEBIT' => 'Debit Card'
        );

        return $brand_map[$brand] ?? ucfirst(strtolower($brand));
    }

    private function normalize_method_type($type) {
        return strtolower(trim($type));
    }

    public function get_payment_methods_stats($user_id) {
        $methods = $this->get_user_payment_methods($user_id);

        $stats = array(
            'total' => count($methods),
            'by_type' => array(),
            'by_brand' => array()
        );

        foreach ($methods as $method) {
            $type = $method['type'] ?? 'unknown';
            $brand = $method['brand'] ?? 'unknown';

            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            $stats['by_brand'][$brand] = ($stats['by_brand'][$brand] ?? 0) + 1;
        }

        return $stats;
    }

    public function validate_payment_method_structure($method) {
        $required_fields = array('id', 'type');
        $errors = array();

        foreach ($required_fields as $field) {
            if (!isset($method[$field]) || empty($method[$field])) {
                $errors[] = sprintf('Missing required field: %s', $field);
            }
        }

        if (isset($method['type'])) {
            $valid_types = array('card', 'credit', 'debit', 'eft', 'e_transfer', 'cheque', 'cash');
            $normalized_type = $this->normalize_method_type($method['type']);
            if (!in_array($normalized_type, $valid_types)) {
                $errors[] = sprintf('Invalid payment method type: %s', $method['type']);
            }
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    private function is_not_found_error($error_message, $error_data = null) {
        if (is_array($error_data) && isset($error_data['status']) && $error_data['status'] === 404) {
            return true;
        }

        $not_found_patterns = array(
            'not found',
            'endpoint not found',
            'resource not found',
            '404',
            'NOT_FOUND_ERROR'
        );

        foreach ($not_found_patterns as $pattern) {
            if (stripos($error_message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}