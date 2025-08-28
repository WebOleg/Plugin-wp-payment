<?php
/**
 * BNA Customer Webhook Handler
 * Handles customer-related webhook events - Updated V2
 */

if (!defined('ABSPATH')) exit;

class BNA_Customer_Webhook {

    use BNA_Webhook_Timing;

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
        $name = trim(($payload['firstName'] ?? '') . ' ' . ($payload['lastName'] ?? ''));
        $type = $payload['type'] ?? 'Personal';

        $this->log_webhook_processing('customer.created', null, [
            'customer_id' => $customer_id,
            'email' => $email,
            'name' => $name,
            'type' => $type
        ]);

        // Log in API logger as this is customer management
        bna_api_log('Customer created via webhook', [
            'customer_id' => $customer_id,
            'email' => $email,
            'name' => $name,
            'type' => $type
        ]);

        // Try to find related WooCommerce customer
        $wc_customer = $this->find_wc_customer_by_email($email);
        if ($wc_customer) {
            bna_wc_debug('Associated with existing WC customer', [
                'wc_customer_id' => $wc_customer->get_id(),
                'bna_customer_id' => $customer_id
            ]);
        }

        // Fire hook for extensions
        do_action('bna_webhook_customer_created', $payload);

        $this->log_webhook_success('customer.created', [
            'customer_id' => $customer_id,
            'email' => $email,
            'name' => $name
        ]);

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
        $changes = $payload['changes'] ?? [];

        $this->log_webhook_processing('customer.updated', null, [
            'customer_id' => $customer_id,
            'email' => $email,
            'changes' => $changes
        ]);

        bna_api_log('Customer updated via webhook', [
            'customer_id' => $customer_id,
            'email' => $email,
            'changes_count' => count($changes)
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_customer_updated', $payload);

        $this->log_webhook_success('customer.updated', [
            'customer_id' => $customer_id,
            'email' => $email
        ]);

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
        $email = $payload['email'] ?? '';

        $this->log_webhook_processing('customer.delete', null, [
            'customer_id' => $customer_id,
            'email' => $email
        ]);

        bna_api_log('Customer deleted via webhook', [
            'customer_id' => $customer_id,
            'email' => $email
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_customer_deleted', $payload);

        $this->log_webhook_success('customer.delete', [
            'customer_id' => $customer_id
        ]);

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
        $method_id = $payload['paymentMethodId'] ?? $payload['id'] ?? '';
        $customer_id = $payload['customerId'] ?? '';
        $method_type = $payload['type'] ?? 'unknown';
        $last_four = $payload['lastFour'] ?? '';

        $this->log_webhook_processing('payment_method.created', null, [
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'method_type' => $method_type,
            'last_four' => $last_four
        ]);

        bna_api_log('Payment method created', [
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'method_type' => $method_type,
            'last_four' => $last_four
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_payment_method_created', $payload);

        $this->log_webhook_success('payment_method.created', [
            'method_id' => $method_id,
            'customer_id' => $customer_id
        ]);

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
        $method_id = $payload['paymentMethodId'] ?? $payload['id'] ?? '';
        $customer_id = $payload['customerId'] ?? '';
        $method_type = $payload['type'] ?? 'unknown';

        $this->log_webhook_processing('payment_method.delete', null, [
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'method_type' => $method_type
        ]);

        bna_api_log('Payment method deleted', [
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'method_type' => $method_type
        ]);

        // Fire hook for extensions
        do_action('bna_webhook_payment_method_deleted', $payload);

        $this->log_webhook_success('payment_method.delete', [
            'method_id' => $method_id,
            'customer_id' => $customer_id
        ]);

        return [
            'status' => 'success',
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'message' => 'Payment method deleted successfully'
        ];
    }

    /**
     * Find WooCommerce customer by email
     */
    private function find_wc_customer_by_email($email) {
        if (empty($email)) {
            return null;
        }

        $customer = get_user_by('email', $email);
        
        if ($customer && $customer->exists()) {
            return new WC_Customer($customer->ID);
        }

        return null;
    }
}
