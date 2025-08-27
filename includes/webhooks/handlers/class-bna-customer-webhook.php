<?php
/**
 * BNA Customer Webhook Handler
 * Handles customer-related webhook events
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Customer_Webhook {

    use BNA_Webhook_Logger;

    /**
     * Handle customer webhook
     */
    public function handle($event_type, $payload) {
        $this->log_webhook_start($event_type, $payload);

        // Process based on event type
        switch ($event_type) {
            case 'customer.created':
                return $this->handle_created($payload);
                
            case 'customer.updated':
                return $this->handle_updated($payload);
                
            case 'customer.delete':
                return $this->handle_deleted($payload);
                
            case 'payment_method.created':
                return $this->handle_payment_method_created($payload);
                
            case 'payment_method.delete':
                return $this->handle_payment_method_deleted($payload);
                
            default:
                $error = "Unsupported customer event: {$event_type}";
                $this->log_webhook_error($event_type, $error);
                return new WP_Error('unsupported_event', $error);
        }
    }

    /**
     * Handle customer created
     */
    private function handle_created($payload) {
        $customer_id = $payload['id'] ?? '';
        $email = $payload['email'] ?? '';
        $name = ($payload['firstName'] ?? '') . ' ' . ($payload['lastName'] ?? '');

        $this->log_webhook_success('customer.created', [
            'customer_id' => $customer_id,
            'email' => $email,
            'name' => trim($name)
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_customer_created', $payload);

        return [
            'status' => 'success',
            'customer_id' => $customer_id,
            'email' => $email,
            'message' => 'Customer created successfully'
        ];
    }

    /**
     * Handle customer updated
     */
    private function handle_updated($payload) {
        $customer_id = $payload['id'] ?? '';
        $email = $payload['email'] ?? '';

        $this->log_webhook_success('customer.updated', [
            'customer_id' => $customer_id,
            'email' => $email
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_customer_updated', $payload);

        return [
            'status' => 'success',
            'customer_id' => $customer_id,
            'message' => 'Customer updated successfully'
        ];
    }

    /**
     * Handle customer deleted
     */
    private function handle_deleted($payload) {
        $customer_id = $payload['id'] ?? '';

        $this->log_webhook_success('customer.delete', [
            'customer_id' => $customer_id
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_customer_deleted', $payload);

        return [
            'status' => 'success',
            'customer_id' => $customer_id,
            'message' => 'Customer deleted successfully'
        ];
    }

    /**
     * Handle payment method created
     */
    private function handle_payment_method_created($payload) {
        $method_id = $payload['paymentMethodId'] ?? '';
        $customer_id = $payload['customerId'] ?? '';

        $this->log_webhook_success('payment_method.created', [
            'method_id' => $method_id,
            'customer_id' => $customer_id
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_payment_method_created', $payload);

        return [
            'status' => 'success',
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'message' => 'Payment method created successfully'
        ];
    }

    /**
     * Handle payment method deleted
     */
    private function handle_payment_method_deleted($payload) {
        $method_id = $payload['paymentMethodId'] ?? '';
        $customer_id = $payload['customerId'] ?? '';

        $this->log_webhook_success('payment_method.delete', [
            'method_id' => $method_id,
            'customer_id' => $customer_id
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_payment_method_deleted', $payload);

        return [
            'status' => 'success',
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'message' => 'Payment method deleted successfully'
        ];
    }
}
