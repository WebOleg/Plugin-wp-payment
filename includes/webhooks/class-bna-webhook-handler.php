<?php
/**
 * BNA Webhook Handler V2
 * Main webhook processor with enhanced logging
 */

if (!defined('ABSPATH')) exit;

class BNA_Webhook_Handler {
    
    use BNA_Webhook_Timing;
    
    public function process_webhook(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $this->log_webhook_start('webhook_received', $payload ?: []);
        
        if (empty($payload)) {
            $this->log_webhook_error('webhook_received', 'No payload received');
            return $this->error_response('empty_payload', 'No payload received');
        }
        
        // Handle BNA webhook structure
        $transaction = null;
        $customer = null;
        
        if (isset($payload['data']['transaction'])) {
            $transaction = $payload['data']['transaction'];
            $customer = $payload['data']['customer'] ?? null;
        } elseif (isset($payload['transaction'])) {
            $transaction = $payload['transaction'];
            $customer = $payload['customer'] ?? null;
        } else {
            $transaction = $payload;
        }
        
        if (empty($transaction) || !isset($transaction['id'])) {
            $this->log_webhook_error('webhook_received', 'Invalid transaction structure', [
                'payload_keys' => array_keys($payload)
            ]);
            return $this->error_response('invalid_transaction', 'Invalid transaction structure');
        }
        
        // Extract data
        $transaction_id = $transaction['id'];
        $status = strtolower($transaction['status'] ?? 'unknown');
        $customer_id = $transaction['customerId'] ?? ($customer['id'] ?? null);
        
        $this->log_webhook_processing('transaction_processing', null, [
            'transaction_id' => $transaction_id,
            'status' => $status,
            'customer_id' => $customer_id
        ]);
        
        // Find order
        $order = null;
        if ($customer_id) {
            $orders = wc_get_orders([
                'meta_key' => '_bna_customer_id',
                'meta_value' => $customer_id,
                'status' => ['pending', 'on-hold'],
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC'
            ]);
            $order = !empty($orders) ? $orders[0] : null;
        }
        
        if (!$order) {
            bna_wc_debug('Order not found for webhook', [
                'transaction_id' => $transaction_id,
                'customer_id' => $customer_id,
                'status' => $status
            ]);
            
            $this->log_webhook_success('webhook_received', [
                'message' => 'Order not found but acknowledged',
                'transaction_id' => $transaction_id
            ]);
            
            return $this->success_response([
                'message' => 'Order not found but acknowledged',
                'transaction_id' => $transaction_id
            ]);
        }
        
        $this->log_webhook_processing('order_found', $order->get_id(), [
            'order_status' => $order->get_status(),
            'is_paid' => $order->is_paid(),
            'transaction_id' => $transaction_id
        ]);
        
        // Prevent duplicate payments
        if ($order->is_paid()) {
            bna_wc_log('Duplicate payment attempt prevented', [
                'order_id' => $order->get_id(),
                'transaction_id' => $transaction_id,
                'current_status' => $order->get_status()
            ]);
            
            $this->log_webhook_success('webhook_received', [
                'message' => 'Duplicate payment prevented',
                'order_id' => $order->get_id()
            ]);
            
            return $this->success_response([
                'message' => 'Order already paid',
                'order_id' => $order->get_id()
            ]);
        }
        
        // Process payment based on status
        if ($status === 'approved') {
            return $this->handle_approved_payment($order, $transaction);
        } elseif (in_array($status, ['declined', 'canceled', 'failed'])) {
            return $this->handle_failed_payment($order, $transaction, $status);
        } else {
            bna_wc_debug('Unhandled transaction status', [
                'order_id' => $order->get_id(),
                'status' => $status,
                'transaction_id' => $transaction_id
            ]);
            
            return $this->success_response([
                'message' => 'Status acknowledged but not processed',
                'order_id' => $order->get_id(),
                'status' => $status
            ]);
        }
    }
    
    /**
     * Handle approved payment
     */
    private function handle_approved_payment($order, $transaction) {
        $transaction_id = $transaction['id'];
        
        // Complete payment
        $order->payment_complete($transaction_id);
        
        // Add comprehensive order note
        $amount = $transaction['total'] ?? $order->get_total();
        $currency = $transaction['currency'] ?? $order->get_currency();
        $note = sprintf(
            'Payment completed via BNA Smart Payment. Transaction ID: %s. Amount: %s %s',
            $transaction_id,
            $amount,
            $currency
        );
        $order->add_order_note($note);
        
        // IMPORTANT: Remove checkout token to prevent duplicate payments
        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');
        $order->add_meta_data('_bna_payment_completed_at', current_time('timestamp'));
        $order->add_meta_data('_bna_transaction_id', $transaction_id);
        $order->save();
        
        // Log successful payment
        bna_wc_log('Payment completed and token removed', [
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'currency' => $currency,
            'new_status' => $order->get_status()
        ]);
        
        $this->log_webhook_success('payment_completed', [
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'amount' => $amount
        ]);
        
        return $this->success_response([
            'order_id' => $order->get_id(),
            'status' => 'completed',
            'transaction_id' => $transaction_id,
            'new_order_status' => $order->get_status()
        ]);
    }
    
    /**
     * Handle failed payment
     */
    private function handle_failed_payment($order, $transaction, $status) {
        $transaction_id = $transaction['id'];
        $reason = $transaction['declineReason'] ?? $transaction['cancelReason'] ?? "Payment {$status}";
        
        // Update order status
        $order->update_status('failed', "BNA Payment {$status}: {$reason}");
        
        // Remove token on failed payment
        $order->delete_meta_data('_bna_checkout_token');
        $order->add_meta_data('_bna_payment_failed_at', current_time('timestamp'));
        $order->add_meta_data('_bna_failure_reason', $reason);
        $order->save();
        
        // Restore stock if needed
        wc_maybe_increase_stock_levels($order);
        
        // Log failed payment
        bna_wc_error('Payment failed and token removed', [
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'status' => $status,
            'reason' => $reason
        ]);
        
        $this->log_webhook_success('payment_failed', [
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'status' => $status,
            'reason' => $reason
        ]);
        
        return $this->success_response([
            'order_id' => $order->get_id(),
            'status' => $status,
            'transaction_id' => $transaction_id,
            'reason' => $reason,
            'new_order_status' => 'failed'
        ]);
    }
    
    /**
     * Success response
     */
    private function success_response($data) {
        return new WP_REST_Response([
            'status' => 'success', 
            'data' => $data,
            'timestamp' => current_time('c')
        ], 200);
    }
    
    /**
     * Error response
     */
    private function error_response($code, $message) {
        return new WP_REST_Response([
            'status' => 'error', 
            'code' => $code, 
            'message' => $message,
            'timestamp' => current_time('c')
        ], 400);
    }
}
