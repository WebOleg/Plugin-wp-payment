<?php
/**
 * BNA Transaction Webhook Handler
 * Handles transaction-related webhook events - Updated V2
 */

if (!defined('ABSPATH')) exit;

class BNA_Transaction_Webhook {

    use BNA_Webhook_Timing;

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

        $this->log_webhook_processing($event_type, $order->get_id(), [
            'order_status' => $order->get_status(),
            'is_paid' => $order->is_paid()
        ]);

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
        // Prevent duplicate processing
        if ($order->is_paid()) {
            bna_wc_log('Duplicate payment attempt prevented', [
                'order_id' => $order->get_id(),
                'transaction_id' => $payload['id'] ?? 'unknown'
            ]);
            
            $this->log_webhook_success('transaction.approved', [
                'order_id' => $order->get_id(),
                'status' => 'duplicate_prevented'
            ]);
            
            return [
                'status' => 'success',
                'order_id' => $order->get_id(),
                'message' => 'Duplicate payment prevented'
            ];
        }

        // Complete payment
        $transaction_id = $payload['id'] ?? '';
        $order->payment_complete($transaction_id);

        // Add order note
        $amount = $payload['amount'] ?? $order->get_total();
        $currency = $payload['currency'] ?? $order->get_currency();
        $note = sprintf('BNA Payment approved. Transaction ID: %s. Amount: %s %s', 
            $transaction_id, $amount, $currency);
        $order->add_order_note($note);

        // Log successful payment
        bna_wc_log('Payment completed successfully', [
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'currency' => $currency
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_transaction_approved', $order, $payload);

        $this->log_webhook_success('transaction.approved', [
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'new_status' => $order->get_status()
        ]);

        return [
            'status' => 'success',
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'new_status' => $order->get_status()
        ];
    }

    /**
     * Handle declined transaction
     */
    private function handle_declined($order, $payload) {
        // Update order status
        $reason = $payload['declineReason'] ?? 'Payment declined';
        $order->update_status('failed', "BNA Payment declined: {$reason}");

        // Restore stock
        wc_maybe_increase_stock_levels($order);

        // Log declined payment
        bna_wc_error('Payment declined', [
            'order_id' => $order->get_id(),
            'reason' => $reason,
            'transaction_id' => $payload['id'] ?? 'unknown'
        ]);

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
        // Update order status
        $reason = $payload['cancelReason'] ?? 'Payment canceled';
        $order->update_status('cancelled', "BNA Payment canceled: {$reason}");

        // Restore stock
        wc_maybe_increase_stock_levels($order);

        // Log canceled payment
        bna_wc_log('Payment canceled', [
            'order_id' => $order->get_id(),
            'reason' => $reason,
            'transaction_id' => $payload['id'] ?? 'unknown'
        ]);

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
                bna_wc_debug('Order found by transaction ID', [
                    'transaction_id' => $payload['id'],
                    'order_id' => $orders[0]->get_id()
                ]);
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
                bna_wc_debug('Order found by reference UUID', [
                    'reference_uuid' => $payload['referenceUUID'],
                    'order_id' => $orders[0]->get_id()
                ]);
                return $orders[0];
            }
        }

        // Try to find by customer ID (BNA customer)
        if (isset($payload['customerId'])) {
            $orders = wc_get_orders([
                'meta_query' => [
                    [
                        'key' => '_bna_customer_id',
                        'value' => $payload['customerId'],
                        'compare' => '='
                    ]
                ],
                'status' => ['pending', 'on-hold', 'processing'],
                'payment_method' => 'bna_smart_payment',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC'
            ]);
            
            if (!empty($orders)) {
                bna_wc_debug('Order found by BNA customer ID', [
                    'customer_id' => $payload['customerId'],
                    'order_id' => $orders[0]->get_id()
                ]);
                return $orders[0];
            }
        }

        bna_wc_error('Order not found for webhook', [
            'transaction_id' => $payload['id'] ?? 'missing',
            'reference_uuid' => $payload['referenceUUID'] ?? 'missing',
            'customer_id' => $payload['customerId'] ?? 'missing'
        ]);

        return false;
    }
}
