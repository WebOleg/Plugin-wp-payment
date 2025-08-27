<?php
/**
 * BNA Transaction Webhook Handler
 * Handles transaction-related webhook events
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Transaction_Webhook {

    use BNA_Webhook_Logger;

    /**
     * Handle transaction webhook
     */
    public function handle($event_type, $payload) {
        $this->log_webhook_start($event_type, $payload);

        // Find order by transaction reference
        $order = $this->find_order($payload);
        
        if (!$order) {
            $error = 'Order not found for transaction';
            $this->log_webhook_error($event_type, $error, [
                'transaction_id' => $payload['id'] ?? 'unknown',
                'reference_uuid' => $payload['referenceUUID'] ?? 'unknown'
            ]);
            return new WP_Error('order_not_found', $error);
        }

        // Process based on event type
        switch ($event_type) {
            case 'transaction.approved':
                return $this->handle_approved($order, $payload);
                
            case 'transaction.declined':
                return $this->handle_declined($order, $payload);
                
            case 'transaction.canceled':
                return $this->handle_canceled($order, $payload);
                
            default:
                $error = "Unsupported transaction event: {$event_type}";
                $this->log_webhook_error($event_type, $error);
                return new WP_Error('unsupported_event', $error);
        }
    }

    /**
     * Handle approved transaction
     */
    private function handle_approved($order, $payload) {
        $this->log_order_processing($order, 'approved', $payload);

        // Complete payment
        $transaction_id = $payload['id'] ?? '';
        $order->payment_complete($transaction_id);

        // Add order note
        $note = sprintf('BNA Payment approved. Transaction ID: %s', $transaction_id);
        $order->add_order_note($note);

        // Fire hook for extensions
        do_action('bna_webhook_transaction_approved', $order, $payload);

        $this->log_webhook_success('transaction.approved', [
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id
        ]);

        return [
            'status' => 'success',
            'order_id' => $order->get_id(),
            'new_status' => $order->get_status()
        ];
    }

    /**
     * Handle declined transaction
     */
    private function handle_declined($order, $payload) {
        $this->log_order_processing($order, 'declined', $payload);

        // Update order status
        $reason = $payload['declineReason'] ?? 'Payment declined';
        $order->update_status('failed', "BNA Payment declined: {$reason}");

        // Restore stock
        wc_maybe_increase_stock_levels($order);

        // Fire hook for extensions
        do_action('bna_webhook_transaction_declined', $order, $payload);

        $this->log_webhook_success('transaction.declined', [
            'order_id' => $order->get_id(),
            'reason' => $reason
        ]);

        return [
            'status' => 'success',
            'order_id' => $order->get_id(),
            'new_status' => 'failed',
            'reason' => $reason
        ];
    }

    /**
     * Handle canceled transaction
     */
    private function handle_canceled($order, $payload) {
        $this->log_order_processing($order, 'canceled', $payload);

        // Update order status
        $reason = $payload['cancelReason'] ?? 'Payment canceled';
        $order->update_status('cancelled', "BNA Payment canceled: {$reason}");

        // Restore stock
        wc_maybe_increase_stock_levels($order);

        // Fire hook for extensions
        do_action('bna_webhook_transaction_canceled', $order, $payload);

        $this->log_webhook_success('transaction.canceled', [
            'order_id' => $order->get_id(),
            'reason' => $reason
        ]);

        return [
            'status' => 'success', 
            'order_id' => $order->get_id(),
            'new_status' => 'cancelled',
            'reason' => $reason
        ];
    }

    /**
     * Find order by transaction data
     */
    private function find_order($payload) {
        // Try to find by transaction ID meta
        if (isset($payload['id'])) {
            $orders = wc_get_orders([
                'meta_query' => [
                    [
                        'key' => '_transaction_id',
                        'value' => $payload['id'],
                        'compare' => '='
                    ]
                ],
                'limit' => 1
            ]);
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }

        // Try to find by reference UUID
        if (isset($payload['referenceUUID'])) {
            $orders = wc_get_orders([
                'meta_query' => [
                    [
                        'key' => '_bna_reference_uuid', 
                        'value' => $payload['referenceUUID'],
                        'compare' => '='
                    ]
                ],
                'limit' => 1
            ]);
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }

        // Try to find by customer email and amount
        if (isset($payload['customerInfo']['email']) && isset($payload['amount'])) {
            $orders = wc_get_orders([
                'billing_email' => $payload['customerInfo']['email'],
                'total' => $payload['amount'],
                'payment_method' => 'bna_smart_payment',
                'limit' => 1,
                'status' => ['pending', 'on-hold']
            ]);
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }

        return false;
    }
}
