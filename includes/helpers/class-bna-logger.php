<?php
/**
 * Simple BNA Logger
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Logger {
    
    private static $log_file = null;
    
    public static function init() {
        if (defined('BNA_SMART_PAYMENT_PLUGIN_PATH')) {
            self::$log_file = BNA_SMART_PAYMENT_PLUGIN_PATH . 'logs/debug.log';
        } else {
            self::$log_file = plugin_dir_path(__FILE__) . '../../logs/debug.log';
        }
        
        $log_dir = dirname(self::$log_file);
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
    }
    
    public static function log($message) {
        if (self::$log_file === null) {
            self::init();
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    public static function info($message) {
        self::log("INFO: {$message}");
    }
    
    public static function error($message) {
        self::log("ERROR: {$message}");
    }
    
    public static function debug($message) {
        self::log("DEBUG: {$message}");
    }
}
