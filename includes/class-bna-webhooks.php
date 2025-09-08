<?php

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Webhooks {
    
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }
    
    public static function register_routes() {
        register_rest_route('bna/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('bna/v1', '/webhook/test', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'test_endpoint'),
            'permission_callback' => '__return_true'
        ));
    }
    
    public static function handle_webhook($request) {
        $start_time = microtime(true);
        $payload = $request->get_json_params();
        
        bna_log('Webhook received', array(
            'payload_size' => strlen(wp_json_encode($payload)),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ));
        
        if (empty($payload)) {
            bna_error('Empty webhook payload');
            return new WP_REST_Response(array('error' => 'Empty payload'), 400);
        }
        
        try {
            // Determine webhook type based on payload structure
            $result = self::process_webhook($payload);
            
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            bna_log('Webhook processed successfully', array(
                'processing_time_ms' => $processing_time,
                'result' => $result
            ));
            
            return new WP_REST_Response($result, 200);
            
        } catch (Exception $e) {
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            bna_error('Webhook processing failed', array(
                'error' => $e->getMessage(),
                'processing_time_ms' => $processing_time
            ));
            
            return new WP_REST_Response(array('error' => $e->getMessage()), 500);
        }
    }
    
    private static function process_webhook($payload) {
        // Check if this is a payment method webhook
        if (isset($payload['payment_method']) || isset($payload['paymentMethod'])) {
            return self::handle_payment_method_webhook($payload);
        }
        
        // Check if this is a transaction webhook
        if (isset($payload['transaction']) || isset($payload['data']['transaction'])) {
            return self::handle_transaction_webhook($payload);
        }
        
        // Check if this is a subscription webhook
        if (isset($payload['subscription'])) {
            return self::handle_subscription_webhook($payload);
        }
        
        // Check if this is a customer webhook
        if (isset($payload['customer']) && !isset($payload['transaction'])) {
            return self::handle_customer_webhook($payload);
        }
        
        // Default to transaction processing for backward compatibility
        return self::handle_transaction_webhook($payload);
    }
    
    private static function handle_payment_method_webhook($payload) {
        bna_log('Processing payment method webhook', array(
            'payload_keys' => array_keys($payload)
        ));
        
        $payment_method = $payload['payment_method'] ?? $payload['paymentMethod'] ?? $payload['data'] ?? array();
        $action = $payload['action'] ?? $payload['event'] ?? 'unknown';
        
        if (empty($payment_method['customerId'])) {
            return array(
                'status' => 'error',
                'message' => 'Customer ID missing in payment method webhook'
            );
        }
        
        $customer_id = $payment_method['customerId'];
        
        switch (strtolower($action)) {
            case 'created':
            case 'payment_method.created':
                return self::handle_payment_method_created($payment_method, $customer_id);
                
            case 'deleted':
            case 'delete':
            case 'payment_method.delete':
                return self::handle_payment_method_deleted($payment_method, $customer_id);
                
            default:
                bna_log('Unknown payment method action', array(
                    'action' => $action,
                    'customer_id' => $customer_id
                ));
                
                return array(
                    'status' => 'success',
                    'message' => 'Payment method action acknowledged but not processed',
                    'action' => $action
                );
        }
    }
    
    private static function handle_payment_method_created($payment_method, $bna_customer_id) {
        bna_log('Processing payment method created', array(
            'payment_method_id' => $payment_method['id'] ?? 'unknown',
            'customer_id' => $bna_customer_id
        ));
        
        // Find WordPress user by BNA customer ID
        $users = get_users(array(
            'meta_key' => '_bna_customer_id',
            'meta_value' => $bna_customer_id,
            'number' => 1
        ));
        
        if (empty($users)) {
            bna_log('WordPress user not found for BNA customer', array(
                'bna_customer_id' => $bna_customer_id
            ));
            
            return array(
                'status' => 'success',
                'message' => 'Payment method created but user not found locally'
            );
        }
        
        $wp_user = $users[0];
        $user_id = $wp_user->ID;
        
        // Extract payment method data
        $payment_method_data = array(
            'id' => $payment_method['id'],
            'type' => self::determine_payment_type($payment_method),
            'last4' => self::extract_last4($payment_method),
            'brand' => self::extract_brand($payment_method),
            'created_at' => current_time('Y-m-d H:i:s')
        );
        
        // Save payment method
        $payment_methods = BNA_Payment_Methods::get_instance();
        $result = $payment_methods->save_payment_method($user_id, $payment_method_data);
        
        if ($result) {
            bna_log('Payment method saved from webhook', array(
                'user_id' => $user_id,
                'method_type' => $payment_method_data['type'],
                'method_id' => $payment_method_data['id']
            ));
            
            return array(
                'status' => 'success',
                'message' => 'Payment method saved successfully',
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_data['id']
            );
        }
        
        return array(
            'status' => 'error',
            'message' => 'Failed to save payment method'
        );
    }
    
    private static function handle_payment_method_deleted($payment_method, $bna_customer_id) {
        bna_log('Processing payment method deleted', array(
            'payment_method_id' => $payment_method['id'] ?? 'unknown',
            'customer_id' => $bna_customer_id
        ));
        
        // Find WordPress user by BNA customer ID
        $users = get_users(array(
            'meta_key' => '_bna_customer_id',
            'meta_value' => $bna_customer_id,
            'number' => 1
        ));
        
        if (empty($users)) {
            bna_log('WordPress user not found for payment method deletion', array(
                'bna_customer_id' => $bna_customer_id
            ));
            
            return array(
                'status' => 'success',
                'message' => 'Payment method deleted but user not found locally'
            );
        }
        
        $wp_user = $users[0];
        $user_id = $wp_user->ID;
        
        // Delete payment method
        $payment_methods = BNA_Payment_Methods::get_instance();
        $payment_method_id = $payment_method['id'];
        $result = $payment_methods->delete_payment_method_by_id($user_id, $payment_method_id);
        
        if ($result) {
            bna_log('Payment method deleted from webhook', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id
            ));
            
            return array(
                'status' => 'success',
                'message' => 'Payment method deleted successfully',
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id
            );
        }
        
        return array(
            'status' => 'error',
            'message' => 'Failed to delete payment method'
        );
    }
    
    private static function handle_transaction_webhook($payload) {
        $transaction = self::extract_transaction_data($payload);
        
        if (empty($transaction['id'])) {
            throw new Exception('Invalid transaction structure');
        }
        
        $transaction_id = $transaction['id'];
        $status = strtolower($transaction['status'] ?? 'unknown');
        $customer_id = $transaction['customerId'] ?? null;
        
        $order = self::find_order($customer_id, $transaction_id);
        
        if (!$order) {
            return array(
                'status' => 'success',
                'message' => 'Order not found but acknowledged',
                'transaction_id' => $transaction_id
            );
        }
        
        if ($order->is_paid()) {
            bna_log('Duplicate payment prevented', array(
                'order_id' => $order->get_id(),
                'transaction_id' => $transaction_id
            ));
            
            return array(
                'status' => 'success',
                'message' => 'Order already paid',
                'order_id' => $order->get_id()
            );
        }
        
        switch ($status) {
            case 'approved':
                return self::handle_approved($order, $transaction);
                
            case 'declined':
            case 'canceled':
            case 'failed':
                return self::handle_failed($order, $transaction, $status);
                
            default:
                return array(
                    'status' => 'success',
                    'message' => 'Status acknowledged but not processed',
                    'order_id' => $order->get_id(),
                    'transaction_status' => $status
                );
        }
    }
    
    private static function handle_subscription_webhook($payload) {
        bna_log('Processing subscription webhook', array(
            'payload_keys' => array_keys($payload)
        ));
        
        // Implementation for subscription webhooks
        return array(
            'status' => 'success',
            'message' => 'Subscription webhook processed'
        );
    }
    
    private static function handle_customer_webhook($payload) {
        bna_log('Processing customer webhook', array(
            'payload_keys' => array_keys($payload)
        ));
        
        // Implementation for customer webhooks
        return array(
            'status' => 'success',
            'message' => 'Customer webhook processed'
        );
    }
    
    private static function extract_transaction_data($payload) {
        if (isset($payload['data']['transaction'])) {
            return $payload['data']['transaction'];
        }
        
        if (isset($payload['transaction'])) {
            return $payload['transaction'];
        }
        
        return $payload;
    }
    
    private static function handle_approved($order, $transaction) {
        $transaction_id = $transaction['id'];
        
        $order->payment_complete($transaction_id);
        
        $amount = $transaction['total'] ?? $order->get_total();
        $currency = $transaction['currency'] ?? $order->get_currency();
        $note = sprintf(
            'BNA Payment approved. Transaction ID: %s. Amount: %s %s',
            $transaction_id,
            $amount,
            $currency
        );
        $order->add_order_note($note);
        
        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');
        $order->add_meta_data('_bna_payment_completed_at', current_time('timestamp'));
        $order->add_meta_data('_bna_transaction_id', $transaction_id);
        $order->save();
        
        bna_log('Payment completed', array(
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'amount' => $amount
        ));
        
        return array(
            'status' => 'success',
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'message' => 'Payment completed'
        );
    }
    
    private static function handle_failed($order, $transaction, $status) {
        $transaction_id = $transaction['id'];
        $reason = $transaction['declineReason'] ?? $transaction['cancelReason'] ?? "Payment {$status}";
        
        $order->update_status('failed', "BNA Payment {$status}: {$reason}");
        
        $order->delete_meta_data('_bna_checkout_token');
        $order->add_meta_data('_bna_payment_failed_at', current_time('timestamp'));
        $order->add_meta_data('_bna_failure_reason', $reason);
        $order->save();
        
        wc_maybe_increase_stock_levels($order);
        
        bna_error('Payment failed', array(
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'status' => $status,
            'reason' => $reason
        ));
        
        return array(
            'status' => 'success',
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'new_status' => 'failed',
            'reason' => $reason,
            'message' => 'Payment failed processed'
        );
    }
    
    private static function find_order($customer_id, $transaction_id) {
        if (empty($customer_id)) {
            return false;
        }
        
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_customer_id',
            'meta_value' => $customer_id,
            'status' => array('pending', 'on-hold', 'processing'),
            'payment_method' => 'bna_smart_payment',
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        return !empty($orders) ? $orders[0] : false;
    }
    
    private static function determine_payment_type($payment_data) {
        if (isset($payment_data['cardType'])) {
            return strtoupper($payment_data['cardType']);
        }
        if (isset($payment_data['bankNumber'])) {
            return 'EFT';
        }
        if (isset($payment_data['email'])) {
            return 'E_TRANSFER';
        }
        if (isset($payment_data['type'])) {
            return strtoupper($payment_data['type']);
        }
        return 'UNKNOWN';
    }
    
    private static function extract_last4($payment_data) {
        if (isset($payment_data['cardNumber'])) {
            return substr($payment_data['cardNumber'], -4);
        }
        if (isset($payment_data['accountNumber'])) {
            return substr($payment_data['accountNumber'], -4);
        }
        if (isset($payment_data['last4'])) {
            return $payment_data['last4'];
        }
        return '****';
    }
    
    private static function extract_brand($payment_data) {
        if (isset($payment_data['cardBrand'])) {
            return ucfirst(strtolower($payment_data['cardBrand']));
        }
        if (isset($payment_data['cardType'])) {
            return ucfirst(strtolower($payment_data['cardType']));
        }
        if (isset($payment_data['bankNumber'])) {
            return 'Bank Transfer';
        }
        if (isset($payment_data['email'])) {
            return 'E-Transfer';
        }
        if (isset($payment_data['brand'])) {
            return ucfirst(strtolower($payment_data['brand']));
        }
        return 'Unknown';
    }
    
    public static function test_endpoint($request) {
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'BNA Webhook endpoint is working',
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'server_time' => current_time('c'),
            'plugin_version' => defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'unknown'
        ), 200);
    }
    
    public static function get_webhook_url() {
        return home_url('/wp-json/bna/v1/webhook');
    }
}
