<?php
/**
 * BNA Webhook Router
 * Routes webhook events to appropriate handlers
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Webhook_Router {

    use BNA_Webhook_Logger;

    private $handlers = [];

    public function __construct() {
        $this->register_handlers();
    }

    /**
     * Register all webhook handlers
     */
    private function register_handlers() {
        $this->handlers = [
            'transaction' => new BNA_Transaction_Webhook(),
            'subscription' => new BNA_Subscription_Webhook(), 
            'customer' => new BNA_Customer_Webhook()
        ];

        BNA_Logger::debug('Webhook handlers registered', [
            'handlers' => array_keys($this->handlers)
        ]);
    }

    /**
     * Route webhook to appropriate handler
     */
    public function route_webhook($event_type, $payload) {
        $this->log_webhook_start('routing', $payload);

        // Extract handler type from event
        $handler_type = $this->get_handler_type($event_type);
        
        if (!isset($this->handlers[$handler_type])) {
            $error = "No handler found for event type: {$event_type}";
            $this->log_webhook_error('routing', $error);
            return new WP_Error('no_handler', $error);
        }

        BNA_Logger::debug('Routing webhook to handler', [
            'event_type' => $event_type,
            'handler_type' => $handler_type
        ]);

        // Route to specific handler
        $handler = $this->handlers[$handler_type];
        $result = $handler->handle($event_type, $payload);

        if (is_wp_error($result)) {
            $this->log_webhook_error('routing', $result->get_error_message(), [
                'event_type' => $event_type,
                'handler_type' => $handler_type
            ]);
        } else {
            $this->log_webhook_success('routing', [
                'event_type' => $event_type,
                'handler_type' => $handler_type,
                'result' => $result
            ]);
        }

        return $result;
    }

    /**
     * Get handler type from event type
     */
    private function get_handler_type($event_type) {
        if (strpos($event_type, 'transaction.') === 0) {
            return 'transaction';
        }
        
        if (strpos($event_type, 'subscription.') === 0) {
            return 'subscription';
        }
        
        if (strpos($event_type, 'customer.') === 0) {
            return 'customer';
        }

        return 'unknown';
    }

    /**
     * Get available handlers
     */
    public function get_handlers() {
        return array_keys($this->handlers);
    }

    /**
     * Check if handler exists for event type
     */
    public function has_handler($event_type) {
        $handler_type = $this->get_handler_type($event_type);
        return isset($this->handlers[$handler_type]);
    }
}
