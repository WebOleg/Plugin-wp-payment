<?php
if (!defined('ABSPATH')) exit;

class BNA_Webhook_Handler {
    
    use BNA_Webhook_Logger;
    
    public function process_webhook(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $this->log_webhook_start('webhook', $payload ?: []);
        
        if (empty($payload)) {
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
            return $this->error_response('invalid_transaction', 'Invalid transaction structure');
        }
        
        // Extract data
        $transaction_id = $transaction['id'];
        $status = strtolower($transaction['status']);
        $customer_id = $transaction['customerId'] ?? ($customer['id'] ?? null);
        
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
            BNA_Logger::info('Order not found for webhook', [
                'transaction_id' => $transaction_id,
                'customer_id' => $customer_id
            ]);
            return $this->success_response(['message' => 'Order not found but acknowledged']);
        }
        
        // Prevent duplicate payments
        if ($order->is_paid()) {
            BNA_Logger::warning('Attempted duplicate payment', [
                'order_id' => $order->get_id(),
                'transaction_id' => $transaction_id,
                'current_status' => $order->get_status()
            ]);
            return $this->success_response(['message' => 'Order already paid']);
        }
        
        // Process payment
        if ($status === 'approved') {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(
                'Payment completed via BNA Smart Payment. Transaction ID: %s. Amount: %s %s',
                $transaction_id,
                $transaction['total'] ?? 'N/A',
                $transaction['currency'] ?? 'CAD'
            ));
            
            // IMPORTANT: Remove checkout token to prevent duplicate payments
            $order->delete_meta_data('_bna_checkout_token');
            $order->delete_meta_data('_bna_checkout_generated_at');
            $order->add_meta_data('_bna_payment_completed_at', current_time('timestamp'));
            $order->save();
            
            BNA_Logger::info('Order marked as paid and token removed', [
                'order_id' => $order->get_id(),
                'transaction_id' => $transaction_id
            ]);
            
        } elseif (in_array($status, ['declined', 'canceled', 'failed'])) {
            $order->update_status('failed', 'Payment ' . $status . ' by BNA Smart Payment.');
            
            // Remove token on failed payment too
            $order->delete_meta_data('_bna_checkout_token');
            $order->save();
            
            BNA_Logger::info('Order marked as failed and token removed', [
                'order_id' => $order->get_id(),
                'status' => $status
            ]);
        }
        
        return $this->success_response([
            'order_id' => $order->get_id(),
            'status' => $status,
            'transaction_id' => $transaction_id
        ]);
    }
    
    private function success_response($data) {
        return new WP_REST_Response(['status' => 'success', 'data' => $data], 200);
    }
    
    private function error_response($code, $message) {
        return new WP_REST_Response(['status' => 'error', 'code' => $code, 'message' => $message], 400);
    }
    
    public function get_webhook_stats() {
        return ['status' => 'ok'];
    }
}
