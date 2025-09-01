<?php
/**
 * BNA Logger
 * Simple logging functionality for BNA Smart Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Logger {
    
    private static $log_file = null;
    
    /**
     * Get log file path
     * @return string
     */
    private static function get_log_file() {
        if (self::$log_file === null) {
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/bna-logs/';
            
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
                file_put_contents($log_dir . '.htaccess', "deny from all\n");
                file_put_contents($log_dir . 'index.php', "<?php // Silence\n");
            }
            
            self::$log_file = $log_dir . 'bna-payment.log';
        }
        
        return self::$log_file;
    }
    
    /**
     * Write log entry
     * @param string $message Log message
     * @param array $data Additional data to log
     * @param string $level Log level (INFO, DEBUG, ERROR)
     */
    public static function log($message, $data = [], $level = 'INFO') {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_line = "[{$timestamp}] [{$level}] {$message}";
        
        if (!empty($data)) {
            $log_line .= ' | ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        
        $log_line .= "\n";
        
        file_put_contents(self::get_log_file(), $log_line, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Debug log (only when WP_DEBUG is enabled)
     * @param string $message
     * @param array $data
     */
    public static function debug($message, $data = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log($message, $data, 'DEBUG');
        }
    }
    
    /**
     * Info log
     * @param string $message
     * @param array $data
     */
    public static function info($message, $data = []) {
        self::log($message, $data, 'INFO');
    }
    
    /**
     * Error log
     * @param string $message
     * @param array $data
     */
    public static function error($message, $data = []) {
        self::log($message, $data, 'ERROR');
    }
    
    /**
     * Get recent log contents
     * @param int $lines Number of lines to retrieve
     * @return string
     */
    public static function get_logs($lines = 500) {
        $log_file = self::get_log_file();
        
        if (!file_exists($log_file)) {
            return 'No logs found.';
        }
        
        $file_lines = file($log_file);
        if (!$file_lines) {
            return 'Unable to read log file.';
        }
        
        $total_lines = count($file_lines);
        $start = max(0, $total_lines - $lines);
        
        return implode('', array_slice($file_lines, $start));
    }
    
    /**
     * Clear all logs
     * @return bool
     */
    public static function clear() {
        $log_file = self::get_log_file();
        if (file_exists($log_file)) {
            unlink($log_file);
        }
        return true;
    }
    
    /**
     * Get log file size in bytes
     * @return int
     */
    public static function get_log_size() {
        $log_file = self::get_log_file();
        return file_exists($log_file) ? filesize($log_file) : 0;
    }
}

/**
 * Global helper functions for easy logging
 */
function bna_log($message, $data = []) {
    BNA_Logger::info($message, $data);
}

function bna_debug($message, $data = []) {
    BNA_Logger::debug($message, $data);
}

function bna_error($message, $data = []) {
    BNA_Logger::error($message, $data);
}
