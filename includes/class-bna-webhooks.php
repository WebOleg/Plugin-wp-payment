<?php
/**
 * BNA Webhooks Handler
 * Simple webhook processing for BNA Smart Payment
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
        
        // Test endpoint
        register_rest_route('bna/v1', '/webhook/test', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'test_endpoint'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Main webhook handler
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
            // Extract transaction data
            $transaction = null;
            if (isset($payload['data']['transaction'])) {
                $transaction = $payload['data']['transaction'];
            } elseif (isset($payload['transaction'])) {
                $transaction = $payload['transaction'];
            } else {
                $transaction = $payload;
            }
            
            if (empty($transaction['id'])) {
                throw new Exception('Invalid transaction structure');
            }
            
            $result = self::process_webhook($transaction, $payload);
            
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            bna_log('Webhook processed successfully', array(
                'transaction_id' => $transaction['id'],
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
    
    /**
     * Process webhook based on transaction status
     */
    private static function process_webhook($transaction, $full_payload) {
        $transaction_id = $transaction['id'];
        $status = strtolower($transaction['status'] ?? 'unknown');
        $customer_id = $transaction['customerId'] ?? null;
        
        // Find order by customer ID
        $order = self::find_order($customer_id, $transaction_id);
        
        if (!$order) {
            return array(
                'status' => 'success',
                'message' => 'Order not found but acknowledged',
                'transaction_id' => $transaction_id
            );
        }
        
        // Prevent duplicate processing
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
        
        // Process by status
        switch ($status) {
            case 'approved':
                return self::handle_approved($order, $transaction);
                
            case 'declined':
            case 'canceled':
            case 'failed':
                return self::handle_failed($order, $transaction, $status);
                
            default:
                bna_debug('Unhandled transaction status', array(
                    'order_id' => $order->get_id(),
                    'status' => $status,
                    'transaction_id' => $transaction_id
                ));
                
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
     */
    private static function handle_approved($order, $transaction) {
        $transaction_id = $transaction['id'];
        
        // Complete payment
        $order->payment_complete($transaction_id);
        
        // Add order note
        $amount = $transaction['total'] ?? $order->get_total();
        $currency = $transaction['currency'] ?? $order->get_currency();
        $note = sprintf(
            'BNA Payment approved. Transaction ID: %s. Amount: %s %s',
            $transaction_id,
            $amount,
            $currency
        );
        $order->add_order_note($note);
        
        // Clear token to prevent reuse
        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');
        $order->add_meta_data('_bna_payment_completed_at', current_time('timestamp'));
        $order->add_meta_data('_bna_transaction_id', $transaction_id);
        $order->save();
        
        bna_log('Payment completed', array(
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'new_status' => $order->get_status()
        ));
        
        return array(
            'status' => 'success',
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'new_status' => $order->get_status(),
            'message' => 'Payment completed'
        );
    }
    
    /**
     * Handle failed payment
     */
    private static function handle_failed($order, $transaction, $status) {
        $transaction_id = $transaction['id'];
        $reason = $transaction['declineReason'] ?? $transaction['cancelReason'] ?? "Payment {$status}";
        
        // Update order status
        $order->update_status('failed', "BNA Payment {$status}: {$reason}");
        
        // Clear token
        $order->delete_meta_data('_bna_checkout_token');
        $order->add_meta_data('_bna_payment_failed_at', current_time('timestamp'));
        $order->add_meta_data('_bna_failure_reason', $reason);
        $order->save();
        
        // Restore stock
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
     * Find order by customer ID or transaction data
     */
    private static function find_order($customer_id, $transaction_id) {
        if (empty($customer_id)) {
            return false;
        }
        
        // Find by BNA customer ID
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_customer_id',
            'meta_value' => $customer_id,
            'status' => array('pending', 'on-hold', 'processing'),
            'payment_method' => 'bna_smart_payment',
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        if (!empty($orders)) {
            bna_debug('Order found by BNA customer ID', array(
                'customer_id' => $customer_id,
                'order_id' => $orders[0]->get_id()
            ));
            return $orders[0];
        }
        
        bna_debug('Order not found', array(
            'customer_id' => $customer_id,
            'transaction_id' => $transaction_id
        ));
        
        return false;
    }
    
    /**
     * Test endpoint
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
     */
    public static function get_webhook_url() {
        return home_url('/wp-json/bna/v1/webhook');
    }
}
