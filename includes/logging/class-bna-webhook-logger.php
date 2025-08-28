<?php
/**
 * BNA Webhook Logger
 * Handles webhook events logging
 */

if (!defined('ABSPATH')) exit;

class BNA_Webhook_Logger extends BNA_Abstract_Logger {
    
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        parent::__construct('webhooks');
    }
    
    /**
     * Log webhook received
     */
    public function log_received($event_type, $payload_size, $ip = null) {
        $this->info("Webhook received", [
            'event_type' => $event_type,
            'payload_size' => $payload_size,
            'ip' => $ip ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        ]);
    }
    
    /**
     * Log webhook processing
     */
    public function log_processing($event_type, $order_id = null, $data = []) {
        $processing_data = [
            'event_type' => $event_type,
            'order_id' => $order_id
        ];
        
        $this->debug("Webhook processing", array_merge($processing_data, $data));
    }
    
    /**
     * Log webhook success
     */
    public function log_success($event_type, $result = [], $processing_time = null) {
        $success_data = [
            'event_type' => $event_type,
            'result' => $result
        ];
        
        if ($processing_time !== null) {
            $success_data['processing_time_ms'] = $processing_time;
        }
        
        $this->info("Webhook success", $success_data);
    }
    
    /**
     * Log webhook error
     */
    public function log_error($event_type, $error, $context = [], $processing_time = null) {
        $error_data = [
            'event_type' => $event_type,
            'error' => $error,
            'context' => $context
        ];
        
        if ($processing_time !== null) {
            $error_data['processing_time_ms'] = $processing_time;
        }
        
        $this->error("Webhook failed", $error_data);
    }
    
    /**
     * Log validation events
     */
    public function log_validation($event, $data = []) {
        if ($event === 'failed') {
            $this->error("Webhook validation failed", $data);
        } else {
            $this->debug("Webhook validation {$event}", $data);
        }
    }
    
    /**
     * Log routing events
     */
    public function log_routing($event_type, $handler, $data = []) {
        $this->debug("Webhook routed", [
            'event_type' => $event_type,
            'handler' => $handler,
            'data' => $data
        ]);
    }
}

// Global helper functions
function bna_webhook_log($message, $data = []) {
    BNA_Webhook_Logger::instance()->info($message, $data);
}

function bna_webhook_debug($message, $data = []) {
    BNA_Webhook_Logger::instance()->debug($message, $data);
}

function bna_webhook_error($message, $data = []) {
    BNA_Webhook_Logger::instance()->error($message, $data);
}
