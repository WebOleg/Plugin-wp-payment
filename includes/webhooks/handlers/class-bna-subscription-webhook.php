<?php
/**
 * BNA Subscription Webhook Handler
 * Handles subscription-related webhook events - Updated V2
 */

if (!defined('ABSPATH')) exit;

class BNA_Subscription_Webhook {

    use BNA_Webhook_Timing;

    /**
     * Handle subscription webhook
     */
    public function handle($event_type, $payload) {
        $this->log_webhook_start($event_type, $payload);

        // Process based on event type
        switch ($event_type) {
            case 'subscription.created':
                return $this->handle_created($payload);
                
            case 'subscription.processed':
                return $this->handle_processed($payload);
                
            case 'subscription.will_expire':
                return $this->handle_will_expire($payload);
                
            case 'subscription.updated':
                return $this->handle_updated($payload);
                
            case 'subscription.deleted':
                return $this->handle_deleted($payload);
                
            default:
                $error = "Unsupported subscription event: {$event_type}";
                $this->log_webhook_error($event_type, $error);
                return new WP_Error('unsupported_event', $error);
        }
    }

    /**
     * Handle subscription created
     */
    private function handle_created($payload) {
        $subscription_id = $payload['id'] ?? '';
        $customer_id = $payload['customerId'] ?? '';
        $recurrence = $payload['recurrence'] ?? '';

        $this->log_webhook_processing('subscription.created', null, [
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id,
            'recurrence' => $recurrence
        ]);

        // Log in WooCommerce logger
        bna_wc_log('Subscription created', [
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id,
            'recurrence' => $recurrence,
            'amount' => $payload['amount'] ?? 'unknown'
        ]);

        // Fire hook for extensions (WooCommerce Subscriptions integration)
        do_action('bna_webhook_subscription_created', $payload);

        $this->log_webhook_success('subscription.created', [
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id
        ]);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id,
            'message' => 'Subscription created successfully'
        ];
    }

    /**
     * Handle subscription processed (recurring payment)
     */
    private function handle_processed($payload) {
        $subscription_id = $payload['subscriptionId'] ?? $payload['id'] ?? '';
        $transaction_id = $payload['transactionId'] ?? '';
        $amount = $payload['amount'] ?? '';
        $status = $payload['status'] ?? 'processed';

        $this->log_webhook_processing('subscription.processed', null, [
            'subscription_id' => $subscription_id,
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'status' => $status
        ]);

        // Try to find related order for logging
        $order = $this->find_related_order($payload);
        if ($order) {
            bna_wc_log('Subscription payment processed', [
                'order_id' => $order->get_id(),
                'subscription_id' => $subscription_id,
                'transaction_id' => $transaction_id,
                'amount' => $amount
            ]);
        } else {
            bna_wc_debug('Subscription payment processed (no related order)', [
                'subscription_id' => $subscription_id,
                'transaction_id' => $transaction_id,
                'amount' => $amount
            ]);
        }

        // Fire hook for extensions
        do_action('bna_webhook_subscription_processed', $payload);

        $this->log_webhook_success('subscription.processed', [
            'subscription_id' => $subscription_id,
            'transaction_id' => $transaction_id
        ]);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'transaction_id' => $transaction_id,
            'message' => 'Subscription payment processed'
        ];
    }

    /**
     * Handle subscription will expire notification
     */
    private function handle_will_expire($payload) {
        $subscription_id = $payload['id'] ?? '';
        $expire_date = $payload['expireDate'] ?? '';
        $days_remaining = $payload['daysRemaining'] ?? 10;

        $this->log_webhook_processing('subscription.will_expire', null, [
            'subscription_id' => $subscription_id,
            'expire_date' => $expire_date,
            'days_remaining' => $days_remaining
        ]);

        bna_wc_log('Subscription expiring soon', [
            'subscription_id' => $subscription_id,
            'expire_date' => $expire_date,
            'days_remaining' => $days_remaining
        ]);

        // Fire hook for extensions (send notification emails etc)
        do_action('bna_webhook_subscription_will_expire', $payload);

        $this->log_webhook_success('subscription.will_expire', [
            'subscription_id' => $subscription_id,
            'expire_date' => $expire_date
        ]);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'expire_date' => $expire_date,
            'message' => 'Subscription expiration notification processed'
        ];
    }

    /**
     * Handle subscription updated
     */
    private function handle_updated($payload) {
        $subscription_id = $payload['id'] ?? '';
        $changes = $payload['changes'] ?? [];

        $this->log_webhook_processing('subscription.updated', null, [
            'subscription_id' => $subscription_id,
            'changes' => $changes
        ]);

        bna_wc_log('Subscription updated', [
            'subscription_id' => $subscription_id,
            'changes' => $changes,
            'status' => $payload['status'] ?? 'unknown'
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_subscription_updated', $payload);

        $this->log_webhook_success('subscription.updated', [
            'subscription_id' => $subscription_id
        ]);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'message' => 'Subscription updated successfully'
        ];
    }

    /**
     * Handle subscription deleted
     */
    private function handle_deleted($payload) {
        $subscription_id = $payload['id'] ?? '';
        $reason = $payload['reason'] ?? 'Manual deletion';

        $this->log_webhook_processing('subscription.deleted', null, [
            'subscription_id' => $subscription_id,
            'reason' => $reason
        ]);

        bna_wc_log('Subscription deleted', [
            'subscription_id' => $subscription_id,
            'reason' => $reason
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_subscription_deleted', $payload);

        $this->log_webhook_success('subscription.deleted', [
            'subscription_id' => $subscription_id
        ]);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'message' => 'Subscription deleted successfully'
        ];
    }

    /**
     * Try to find related order for better logging
     */
    private function find_related_order($payload) {
        $customer_id = $payload['customerId'] ?? '';
        
        if (empty($customer_id)) {
            return null;
        }

        $orders = wc_get_orders([
            'meta_query' => [
                [
                    'key' => '_bna_customer_id',
                    'value' => $customer_id,
                    'compare' => '='
                ]
            ],
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);

        return !empty($orders) ? $orders[0] : null;
    }
}
