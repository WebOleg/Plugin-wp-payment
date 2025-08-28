<?php
/**
 * BNA Logger Manager
 * Central manager for all loggers
 */

if (!defined('ABSPATH')) exit;

class BNA_Logger_Manager {
    
    private static $instance = null;
    private $loggers = [];
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_loggers();
    }
    
    /**
     * Initialize all loggers
     */
    private function init_loggers() {
        $this->loggers = [
            'woocommerce' => BNA_WooCommerce_Logger::instance(),
            'api' => BNA_API_Logger::instance(),
            'webhooks' => BNA_Webhook_Logger::instance()
        ];
    }
    
    /**
     * Get specific logger
     */
    public function get_logger($type) {
        return isset($this->loggers[$type]) ? $this->loggers[$type] : null;
    }
    
    /**
     * Get all loggers
     */
    public function get_all_loggers() {
        return $this->loggers;
    }
    
    /**
     * Enable all loggers
     */
    public function enable_all() {
        foreach ($this->loggers as $logger) {
            $logger->enable();
        }
    }
    
    /**
     * Disable all loggers
     */
    public function disable_all() {
        foreach ($this->loggers as $logger) {
            $logger->disable();
        }
    }
    
    /**
     * Check if any logger is enabled
     */
    public function is_any_enabled() {
        foreach ($this->loggers as $logger) {
            if ($logger->is_enabled()) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get all log files from all loggers
     */
    public function get_all_files() {
        $all_files = [];
        
        foreach ($this->loggers as $type => $logger) {
            $files = $logger->get_files();
            foreach ($files as $file) {
                $file['logger_type'] = $type;
                $all_files[] = $file;
            }
        }
        
        // Sort by date (newest first)
        usort($all_files, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $all_files;
    }
    
    /**
     * Clear all logs
     */
    public function clear_all() {
        foreach ($this->loggers as $logger) {
            $logger->clear();
        }
        return true;
    }
    
    /**
     * Get logger statistics
     */
    public function get_stats() {
        $stats = [
            'total_loggers' => count($this->loggers),
            'enabled_loggers' => 0,
            'total_files' => 0,
            'total_size' => 0,
            'by_type' => []
        ];
        
        foreach ($this->loggers as $type => $logger) {
            $files = $logger->get_files();
            $type_size = 0;
            
            foreach ($files as $file) {
                $type_size += $file['size'];
            }
            
            $stats['by_type'][$type] = [
                'enabled' => $logger->is_enabled(),
                'files_count' => count($files),
                'total_size' => $type_size
            ];
            
            if ($logger->is_enabled()) {
                $stats['enabled_loggers']++;
            }
            
            $stats['total_files'] += count($files);
            $stats['total_size'] += $type_size;
        }
        
        return $stats;
    }
    
    /**
     * Test all loggers
     */
    public function test_all() {
        $timestamp = current_time('c');
        
        foreach ($this->loggers as $type => $logger) {
            if ($logger->is_enabled()) {
                $logger->debug("Test debug message", ['test' => true, 'timestamp' => $timestamp]);
                $logger->info("Test info message", ['test' => true, 'timestamp' => $timestamp]);
                $logger->error("Test error message", ['test' => true, 'timestamp' => $timestamp]);
            }
        }
        
        return true;
    }
}

// Global helper functions
function bna_logger($type = null) {
    $manager = BNA_Logger_Manager::instance();
    
    if ($type) {
        return $manager->get_logger($type);
    }
    
    return $manager;
}

function bna_log_stats() {
    return BNA_Logger_Manager::instance()->get_stats();
}
