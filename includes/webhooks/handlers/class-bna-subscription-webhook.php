<?php
/**
 * BNA Subscription Webhook Handler
 * Handles subscription-related webhook events
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Subscription_Webhook {

    use BNA_Webhook_Logger;

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

        $this->log_webhook_success('subscription.created', [
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id
        ]);

        // Fire hook for extensions (WooCommerce Subscriptions integration)
        do_action('bna_webhook_subscription_created', $payload);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'message' => 'Subscription created successfully'
        ];
    }

    /**
     * Handle subscription processed (recurring payment)
     */
    private function handle_processed($payload) {
        $subscription_id = $payload['subscriptionId'] ?? '';
        $transaction_id = $payload['transactionId'] ?? '';

        $this->log_webhook_success('subscription.processed', [
            'subscription_id' => $subscription_id,
            'transaction_id' => $transaction_id
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_subscription_processed', $payload);

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

        $this->log_webhook_success('subscription.will_expire', [
            'subscription_id' => $subscription_id,
            'expire_date' => $expire_date
        ]);

        // Fire hook for extensions (send notification emails etc)
        do_action('bna_webhook_subscription_will_expire', $payload);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'message' => 'Subscription expiration notification processed'
        ];
    }

    /**
     * Handle subscription updated
     */
    private function handle_updated($payload) {
        $subscription_id = $payload['id'] ?? '';

        $this->log_webhook_success('subscription.updated', [
            'subscription_id' => $subscription_id
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_subscription_updated', $payload);

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

        $this->log_webhook_success('subscription.deleted', [
            'subscription_id' => $subscription_id
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_subscription_deleted', $payload);

        return [
            'status' => 'success',
            'subscription_id' => $subscription_id,
            'message' => 'Subscription deleted successfully'
        ];
    }
}
