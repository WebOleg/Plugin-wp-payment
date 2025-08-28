<?php
/**
 * BNA API Logger
 * Handles API calls, iFrame, tokens logging
 */

if (!defined('ABSPATH')) exit;

class BNA_API_Logger extends BNA_Abstract_Logger {
    
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        parent::__construct('api');
    }
    
    /**
     * Log API request
     */
    public function log_request($method, $endpoint, $data = [], $response_code = null, $response_time = null) {
        $request_data = [
            'method' => $method,
            'endpoint' => $endpoint,
            'has_data' => !empty($data),
            'data_size' => !empty($data) ? strlen(wp_json_encode($data)) : 0
        ];
        
        if ($response_code !== null) {
            $request_data['response_code'] = $response_code;
        }
        
        if ($response_time !== null) {
            $request_data['response_time_ms'] = $response_time;
        }
        
        if ($response_code >= 400) {
            $this->error("API request failed", $request_data);
        } else {
            $this->info("API request", $request_data);
        }
    }
    
    /**
     * Log token operations
     */
    public function log_token($event, $order_id, $data = []) {
        $token_data = [
            'event' => $event,
            'order_id' => $order_id
        ];
        
        if ($event === 'generated') {
            $this->info("Token generated", array_merge($token_data, $data));
        } elseif ($event === 'expired') {
            $this->debug("Token expired", array_merge($token_data, $data));
        } elseif ($event === 'reused') {
            $this->debug("Token reused", array_merge($token_data, $data));
        } else {
            $this->debug("Token {$event}", array_merge($token_data, $data));
        }
    }
    
    /**
     * Log iFrame operations
     */
    public function log_iframe($event, $data = []) {
        $this->info("iFrame {$event}", $data);
    }
    
    /**
     * Log customer operations
     */
    public function log_customer($event, $customer_data = []) {
        if ($event === 'created') {
            $this->info("Customer created", $customer_data);
        } elseif ($event === 'found') {
            $this->debug("Customer found", $customer_data);
        } else {
            $this->debug("Customer {$event}", $customer_data);
        }
    }
    
    /**
     * Log authentication
     */
    public function log_auth($event, $data = []) {
        if ($event === 'failed') {
            $this->error("Authentication failed", $data);
        } else {
            $this->debug("Authentication {$event}", $data);
        }
    }
}

// Global helper functions
function bna_api_log($message, $data = []) {
    BNA_API_Logger::instance()->info($message, $data);
}

function bna_api_debug($message, $data = []) {
    BNA_API_Logger::instance()->debug($message, $data);
}

function bna_api_error($message, $data = []) {
    BNA_API_Logger::instance()->error($message, $data);
}
