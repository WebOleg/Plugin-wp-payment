<?php
/**
 * BNA Logger Interface
 * Contract for all loggers
 */

if (!defined('ABSPATH')) exit;

interface BNA_Logger_Interface {
    
    /**
     * Write debug message
     */
    public function debug($message, $data = []);
    
    /**
     * Write info message
     */
    public function info($message, $data = []);
    
    /**
     * Write error message
     */
    public function error($message, $data = []);
    
    /**
     * Enable logging
     */
    public function enable();
    
    /**
     * Disable logging
     */
    public function disable();
    
    /**
     * Check if enabled
     */
    public function is_enabled();
    
    /**
     * Get log files
     */
    public function get_files();
    
    /**
     * Read log file
     */
    public function read_file($filename, $lines = 100);
    
    /**
     * Clear logs
     */
    public function clear();
}
