<?php
/**
 * BNA Payment Methods Handler
 * Handles payment method storage, retrieval, and management
 * ПОКРАЩЕНО: кращі функції витягування даних та обробки webhook даних
 *
 * @since 1.8.0 Enhanced webhook integration and method type handling
 * @since 1.7.0 Payment methods management
 */

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

    /**
     * Save payment method to user meta
     * ПОКРАЩЕНО: краще логування та валідація
     */
    public function save_payment_method($user_id, $payment_method_data) {
        if (!$user_id || empty($payment_method_data)) {
            bna_error('Invalid parameters for save_payment_method', array(
                'user_id' => $user_id,
                'has_data' => !empty($payment_method_data)
            ));
            return false;
        }

        // Validate required fields
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

        // Check if method already exists
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
            return true; // Already exists, consider it saved
        }
    }

    /**
     * Get user payment methods from WordPress meta
     */
    public function get_user_payment_methods($user_id) {
        if (!$user_id) {
            return array();
        }

        $methods = get_user_meta($user_id, '_bna_payment_methods', true);
        return is_array($methods) ? $methods : array();
    }

    /**
     * Delete payment method from user account and BNA Portal
     * This is for manual deletion via UI
     */
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

        // Find the method to delete
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

        // Try to delete from BNA Portal first
        $api_result = $this->delete_from_bna_portal($customer_id, $payment_method_id, $method_type);

        if (is_wp_error($api_result)) {
            $error_message = $api_result->get_error_message();

            // Handle unclear API responses
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

        // If API deletion successful, remove from local storage
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

    /**
     * Delete payment method by ID from WordPress only (for webhooks)
     * This is called by webhooks when BNA notifies us of deletion
     */
    public function delete_payment_method_by_id($user_id, $payment_method_id) {
        if (!$user_id || !$payment_method_id) {
            return false;
        }

        $existing_methods = $this->get_user_payment_methods($user_id);
        $method_found = false;

        // Check if method exists
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
            return true; // Already deleted, consider it successful
        }

        // Remove method from local storage
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

    /**
     * Delete payment method from BNA Portal via API
     */
    private function delete_from_bna_portal($customer_id, $payment_method_id, $method_type) {
        // ВИПРАВЛЕНИЙ МАПІНГ ENDPOINTS - додана підтримка всіх типів
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

        bna_log('Attempting to delete payment method from BNA Portal', array(
            'customer_id' => $customer_id,
            'payment_method_id' => $payment_method_id,
            'method_type' => $method_type,
            'endpoint' => $endpoint
        ));

        $result = $this->api->delete($endpoint);

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

    /**
     * Check if method already exists in user's stored methods
     */
    private function method_exists($methods, $method_id) {
        foreach ($methods as $method) {
            if (isset($method['id']) && $method['id'] === $method_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract last 4 digits from payment data
     * ПОКРАЩЕНО: краща обробка різних форматів номерів
     */
    private function extract_last4($payment_data) {
        // For cards
        if (isset($payment_data['cardNumber'])) {
            $cardNumber = $payment_data['cardNumber'];
            // Remove all non-digits and get last 4
            $digits_only = preg_replace('/\D/', '', $cardNumber);
            return substr($digits_only, -4);
        }

        // For bank accounts (EFT)
        if (isset($payment_data['accountNumber'])) {
            $accountNumber = $payment_data['accountNumber'];
            $digits_only = preg_replace('/\D/', '', $accountNumber);
            return substr($digits_only, -4);
        }

        // If already provided
        if (isset($payment_data['last4'])) {
            return $payment_data['last4'];
        }

        return '****';
    }

    /**
     * Extract brand/type information from payment data
     * ПОКРАЩЕНО: краща ієрархія для визначення бренду
     */
    private function extract_brand($payment_data) {
        // For cards - prioritize cardBrand over cardType
        if (isset($payment_data['cardBrand']) && !empty($payment_data['cardBrand'])) {
            return $this->format_brand_name($payment_data['cardBrand']);
        }

        if (isset($payment_data['cardType']) && !empty($payment_data['cardType'])) {
            return $this->format_brand_name($payment_data['cardType']);
        }

        // For bank transfers
        if (isset($payment_data['bankName']) && !empty($payment_data['bankName'])) {
            return $payment_data['bankName'];
        }

        if (isset($payment_data['bankNumber']) || isset($payment_data['transitNumber'])) {
            return 'Bank Transfer';
        }

        // For e-transfer
        if (isset($payment_data['email']) || isset($payment_data['deliveryType'])) {
            return 'E-Transfer';
        }

        // For cheques
        if (isset($payment_data['chequeNumber'])) {
            return 'Cheque';
        }

        // For cash
        if (isset($payment_data['type']) && strtolower($payment_data['type']) === 'cash') {
            return 'Cash';
        }

        // Generic brand
        if (isset($payment_data['brand'])) {
            return $this->format_brand_name($payment_data['brand']);
        }

        return 'Unknown';
    }

    /**
     * НОВА функція - форматування назви бренду
     */
    private function format_brand_name($brand) {
        $brand = trim(strtolower($brand));

        // Special cases for well-known brands
        $special_cases = array(
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            'credit' => 'Credit Card',
            'debit' => 'Debit Card'
        );

        if (isset($special_cases[$brand])) {
            return $special_cases[$brand];
        }

        // General formatting - capitalize first letter
        return ucfirst($brand);
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

    /**
     * НОВА функція - отримання відображуваної назви методу оплати
     */
    public function get_method_display_name($method) {
        if (!is_array($method) || !isset($method['type'])) {
            return 'Unknown Method';
        }

        $type = strtolower($method['type']);
        $brand = $method['brand'] ?? 'Unknown';
        $last4 = $method['last4'] ?? '****';

        switch ($type) {
            case 'credit':
                return sprintf('%s Credit Card •••• %s', $brand, $last4);

            case 'debit':
                return sprintf('%s Debit Card •••• %s', $brand, $last4);

            case 'card':
                return sprintf('%s Card •••• %s', $brand, $last4);

            case 'eft':
                return sprintf('Bank Transfer •••• %s', $last4);

            case 'e_transfer':
                return 'E-Transfer';

            case 'cheque':
                return sprintf('Cheque •••• %s', $last4);

            case 'cash':
                return 'Cash Payment';

            default:
                return sprintf('%s •••• %s', $brand, $last4);
        }
    }

    /**
     * НОВА функція - статистика методів оплати
     */
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

    /**
     * НОВА функція - валідація структури методу оплати
     */
    public function validate_payment_method_structure($method) {
        $required_fields = array('id', 'type');
        $errors = array();

        foreach ($required_fields as $field) {
            if (!isset($method[$field]) || empty($method[$field])) {
                $errors[] = sprintf('Missing required field: %s', $field);
            }
        }

        // Validate type
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
}