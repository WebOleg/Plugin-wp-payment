<?php
/**
 * BNA Webhooks Handler
 * Processes webhook notifications from BNA Smart Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Webhooks {
    
    /**
     * Initialize webhooks
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
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
    
    /**
     * Main webhook handler
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
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
            $transaction = self::extract_transaction_data($payload);
            
            if (empty($transaction['id'])) {
                throw new Exception('Invalid transaction structure');
            }
            
            $result = self::process_webhook($transaction, $payload);
            
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            bna_log('Webhook processed successfully', array(
                'transaction_id' => $transaction['id'],
                'processing_time_ms' => $processing_time
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
    
    /**
     * Extract transaction data from webhook payload
     * @param array $payload
     * @return array
     */
    private static function extract_transaction_data($payload) {
        if (isset($payload['data']['transaction'])) {
            return $payload['data']['transaction'];
        }
        
        if (isset($payload['transaction'])) {
            return $payload['transaction'];
        }
        
        return $payload;
    }
    
    /**
     * Process webhook based on transaction status
     * @param array $transaction
     * @param array $full_payload
     * @return array
     */
    private static function process_webhook($transaction, $full_payload) {
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
    
    /**
     * Handle approved payment
     * @param WC_Order $order
     * @param array $transaction
     * @return array
     */
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
    
    /**
     * Handle failed payment
     * @param WC_Order $order
     * @param array $transaction
     * @param string $status
     * @return array
     */
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
    
    /**
     * Find order by customer ID
     * @param string $customer_id
     * @param string $transaction_id
     * @return WC_Order|false
     */
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
    
    /**
     * Test endpoint
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function test_endpoint($request) {
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'BNA Webhook endpoint is working',
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'server_time' => current_time('c'),
            'plugin_version' => defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'unknown'
        ), 200);
    }
    
    /**
     * Get webhook URL for BNA portal configuration
     * @return string
     */
    public static function get_webhook_url() {
        return home_url('/wp-json/bna/v1/webhook');
    }
}
