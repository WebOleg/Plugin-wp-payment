<?php
if (!defined('ABSPATH')) exit;

trait BNA_Webhook_Logger {
    
    private $start_time;

    protected function start_processing_timer() {
        $this->start_time = microtime(true);
    }

    protected function log_webhook_start($event_type, $payload) {
        $this->start_processing_timer();
        
        BNA_Logger::info('Webhook started', [
            'event_type' => $event_type,
            'payload_size' => strlen(json_encode($payload)),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    protected function log_webhook_success($event_type, $result = null) {
        BNA_Logger::info('Webhook success', [
            'event_type' => $event_type,
            'result' => $result,
            'time_ms' => $this->get_processing_time()
        ]);
    }

    protected function log_webhook_error($event_type, $error, $context = []) {
        BNA_Logger::error('Webhook failed', array_merge([
            'event_type' => $event_type,
            'error' => $error,
            'time_ms' => $this->get_processing_time()
        ], $context));
    }

    protected function get_processing_time() {
        return $this->start_time ? round((microtime(true) - $this->start_time) * 1000, 2) : 0;
    }
}
