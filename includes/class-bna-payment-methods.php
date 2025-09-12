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

            bna_log('Saving new payment method', array(
                'user_id' => $user_id,
                'method_id' => $new_method['id'],
                'type' => $new_method['type'],
                'brand' => $new_method['brand']
            ));

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

            if (strpos($error_message, 'Invalid JSON response') !== false ||
                strpos($error_message, 'Internal Server Error') !== false ||
                strpos($error_message, '500') !== false ||
                strpos($error_message, 'Syntax error') !== false) {

                bna_log('API deletion response unclear, letting webhook handle cleanup', array(
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                    'error' => $error_message
                ));

                return array(
                    'status' => 'pending',
                    'message' => 'Deletion request sent - confirmation pending'
                );
            }

            bna_error('Payment method deletion failed', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id,
                'error' => $error_message
            ));

            return $api_result;
        }

        $updated_methods = array_filter($existing_methods, function($method) use ($payment_method_id) {
            return $method['id'] !== $payment_method_id;
        });

        update_user_meta($user_id, '_bna_payment_methods', array_values($updated_methods));

        bna_log('Payment method deleted successfully via API', array(
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
                'payment_method_id' => $payment_method_id
            ));
        }

        return $result;
    }

    private function delete_from_bna_portal($customer_id, $payment_method_id, $method_type) {
        // ВИПРАВЛЕНИЙ МАПІНГ ENDPOINTS - додана підтримка CARD
        $endpoint_map = array(
            'credit' => "v1/customers/{$customer_id}/card/{$payment_method_id}",
            'debit' => "v1/customers/{$customer_id}/card/{$payment_method_id}",
            'card' => "v1/customers/{$customer_id}/card/{$payment_method_id}",  // Додано підтримку card
            'eft' => "v1/customers/{$customer_id}/eft/{$payment_method_id}",
            'e_transfer' => "v1/customers/{$customer_id}/e-transfer/{$payment_method_id}",
            'cheque' => "v1/customers/{$customer_id}/cheque/{$payment_method_id}", // Додано cheque
            'cash' => "v1/customers/{$customer_id}/cash/{$payment_method_id}"  // Додано cash
        );

        $endpoint = $endpoint_map[$method_type] ?? null;

        if (!$endpoint) {
            bna_error('Unsupported payment method type for deletion', array(
                'method_type' => $method_type,
                'payment_method_id' => $payment_method_id,
                'available_types' => array_keys($endpoint_map)
            ));

            return new WP_Error('unsupported_type', 'Unsupported payment method type: ' . $method_type);
        }

        bna_log('Attempting to delete payment method from BNA portal', array(
            'customer_id' => $customer_id,
            'payment_method_id' => $payment_method_id,
            'method_type' => $method_type,
            'endpoint' => $endpoint
        ));

        $result = $this->api->make_request($endpoint, 'DELETE');

        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();
            $error_data = $result->get_error_data();

            // Handle 404 - payment method may already be deleted or never existed
            if (isset($error_data['status']) && $error_data['status'] === 404) {
                bna_log('Payment method not found on server - treating as successful deletion', array(
                    'endpoint' => $endpoint,
                    'payment_method_id' => $payment_method_id,
                    'customer_id' => $customer_id,
                    'error' => $error_message
                ));

                return array(
                    'status' => 'success',
                    'message' => 'Payment method not found on server (likely already deleted)'
                );
            }

            // Handle empty response (deletion succeeded but API returned empty body)
            if (strpos($error_message, 'Invalid JSON response') !== false &&
                strpos($error_message, 'response_body":"') !== false &&
                strpos($error_message, '""') !== false) {

                bna_log('DELETE request likely succeeded - empty response is normal', array(
                    'endpoint' => $endpoint,
                    'original_error' => $error_message
                ));

                return array('status' => 'success', 'message' => 'Deletion completed (empty response)');
            }

            // Handle other server errors that might indicate successful deletion
            if (strpos($error_message, 'Internal Server Error') !== false ||
                strpos($error_message, '500') !== false ||
                strpos($error_message, 'Syntax error') !== false) {

                bna_log('Server error during deletion - may have succeeded anyway', array(
                    'endpoint' => $endpoint,
                    'payment_method_id' => $payment_method_id,
                    'error' => $error_message
                ));

                return array(
                    'status' => 'pending',
                    'message' => 'Deletion request sent - server response unclear'
                );
            }

            // Log the error but don't return it immediately
            bna_error('Payment method deletion failed at API level', array(
                'customer_id' => $customer_id,
                'payment_method_id' => $payment_method_id,
                'method_type' => $method_type,
                'endpoint' => $endpoint,
                'error' => $error_message,
                'error_data' => $error_data
            ));

            return $result;
        }

        // Success case
        bna_log('Payment method deleted successfully via API', array(
            'customer_id' => $customer_id,
            'payment_method_id' => $payment_method_id,
            'method_type' => $method_type,
            'endpoint' => $endpoint
        ));

        return $result;
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
            $cardNumber = $payment_data['cardNumber'];
            if (strpos($cardNumber, '*') !== false) {
                return substr(str_replace('*', '', $cardNumber), -4);
            }
            return substr($cardNumber, -4);
        }
        if (isset($payment_data['accountNumber'])) {
            $accountNumber = $payment_data['accountNumber'];
            if (strpos($accountNumber, '*') !== false) {
                return substr(str_replace('*', '', $accountNumber), -4);
            }
            return substr($accountNumber, -4);
        }
        if (isset($payment_data['last4'])) {
            return $payment_data['last4'];
        }
        return '****';
    }

    private function extract_brand($payment_data) {
        if (isset($payment_data['cardBrand'])) {
            return ucfirst(strtolower($payment_data['cardBrand']));
        }
        if (isset($payment_data['cardType'])) {
            return ucfirst(strtolower($payment_data['cardType']));
        }
        if (isset($payment_data['bankNumber']) || isset($payment_data['bankName'])) {
            return isset($payment_data['bankName']) ?
                $payment_data['bankName'] : 'Bank Transfer';
        }
        if (isset($payment_data['email']) || isset($payment_data['deliveryType'])) {
            return 'E-Transfer';
        }
        if (isset($payment_data['chequeNumber'])) {
            return 'Cheque';
        }
        if (isset($payment_data['brand'])) {
            return ucfirst(strtolower($payment_data['brand']));
        }
        return 'Unknown';
    }

    /**
     * Get payment method type for API endpoint mapping
     * Handles different variations of method types
     */
    private function normalize_method_type($type) {
        $type = strtolower(trim($type));

        // Handle various type formats
        switch ($type) {
            case 'card':
            case 'credit':
            case 'debit':
                return $type;

            case 'eft':
            case 'bank_transfer':
            case 'direct_debit':
                return 'eft';

            case 'e_transfer':
            case 'etransfer':
            case 'interac':
                return 'e_transfer';

            case 'cheque':
            case 'check':
                return 'cheque';

            case 'cash':
                return 'cash';

            default:
                return $type;
        }
    }
}