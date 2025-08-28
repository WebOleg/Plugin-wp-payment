<?php
/**
 * BNA Webhook Timing Trait
 * Updated to use new webhook logger
 */

if (!defined('ABSPATH')) exit;

trait BNA_Webhook_Timing {
    
    private $start_time;
    private $webhook_logger;

    protected function init_webhook_logging() {
        $this->webhook_logger = BNA_Webhook_Logger::instance();
        $this->start_processing_timer();
    }

    protected function start_processing_timer() {
        $this->start_time = microtime(true);
    }

    protected function log_webhook_start($event_type, $payload) {
        $this->init_webhook_logging();
        
        $payload_size = is_array($payload) ? strlen(wp_json_encode($payload)) : strlen($payload);
        $this->webhook_logger->log_received($event_type, $payload_size);
    }

    protected function log_webhook_processing($event_type, $order_id = null, $data = []) {
        if (!$this->webhook_logger) {
            $this->init_webhook_logging();
        }
        
        $this->webhook_logger->log_processing($event_type, $order_id, $data);
    }

    protected function log_webhook_success($event_type, $result = null) {
        if (!$this->webhook_logger) {
            $this->init_webhook_logging();
        }
        
        $this->webhook_logger->log_success($event_type, $result, $this->get_processing_time());
    }

    protected function log_webhook_error($event_type, $error, $context = []) {
        if (!$this->webhook_logger) {
            $this->init_webhook_logging();
        }
        
        $this->webhook_logger->log_error($event_type, $error, $context, $this->get_processing_time());
    }

    protected function log_webhook_validation($event, $data = []) {
        if (!$this->webhook_logger) {
            $this->init_webhook_logging();
        }
        
        $this->webhook_logger->log_validation($event, $data);
    }

    protected function get_processing_time() {
        return $this->start_time ? round((microtime(true) - $this->start_time) * 1000, 2) : 0;
    }
}
