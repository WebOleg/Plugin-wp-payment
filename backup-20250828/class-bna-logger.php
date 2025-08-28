<?php
/**
 * BNA Simple Logger
 * Simple logging for debugging
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Logger {

    /**
     * Log levels
     */
    const DEBUG = 'debug';
    const INFO = 'info';
    const ERROR = 'error';

    /**
     * Log directory
     */
    private static $log_dir = null;

    /**
     * Get log directory
     */
    private static function get_log_dir() {
        if (self::$log_dir === null) {
            $upload_dir = wp_upload_dir();
            self::$log_dir = $upload_dir['basedir'] . '/bna-logs/';
            
            // Create directory if not exists
            if (!file_exists(self::$log_dir)) {
                wp_mkdir_p(self::$log_dir);
                
                // Protect directory
                file_put_contents(self::$log_dir . '.htaccess', "deny from all\n");
                file_put_contents(self::$log_dir . 'index.php', "<?php // Silence\n");
            }
        }
        
        return self::$log_dir;
    }

    /**
     * Check if logging is enabled
     */
    private static function is_enabled() {
        return get_option('bna_debug_enabled', false);
    }

    /**
     * Write log entry
     */
    private static function write_log($level, $message, $data = []) {
        if (!self::is_enabled()) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_line = "[{$timestamp}] [{$level}] {$message}";
        
        if (!empty($data)) {
            $log_line .= ' | Data: ' . wp_json_encode($data);
        }
        
        $log_line .= "\n";

        $log_file = self::get_log_dir() . 'bna-' . current_time('Y-m-d') . '.log';
        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Debug log
     */
    public static function debug($message, $data = []) {
        self::write_log(self::DEBUG, $message, $data);
    }

    /**
     * Info log
     */
    public static function info($message, $data = []) {
        self::write_log(self::INFO, $message, $data);
    }

    /**
     * Error log
     */
    public static function error($message, $data = []) {
        self::write_log(self::ERROR, $message, $data);
    }

    /**
     * Enable logging
     */
    public static function enable() {
        update_option('bna_debug_enabled', true);
    }

    /**
     * Disable logging
     */
    public static function disable() {
        update_option('bna_debug_enabled', false);
    }

    /**
     * Clear all logs
     */
    public static function clear_logs() {
        $log_dir = self::get_log_dir();
        $files = glob($log_dir . 'bna-*.log');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        return true;
    }

    /**
     * Get log files
     */
    public static function get_log_files() {
        $log_dir = self::get_log_dir();
        $files = glob($log_dir . 'bna-*.log');
        $result = [];
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $result[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'path' => $file,
                    'modified' => filemtime($file)
                ];
            }
        }
        
        // Sort by date (newest first)
        usort($result, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $result;
    }

    /**
     * Read log file
     */
    public static function read_log($filename, $lines = 100) {
        $log_dir = self::get_log_dir();
        $file_path = $log_dir . $filename;
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        if ($lines === 0) {
            return file_get_contents($file_path);
        }
        
        // Read last N lines
        $file_lines = file($file_path);
        if (!$file_lines) {
            return '';
        }
        
        $total_lines = count($file_lines);
        $start = max(0, $total_lines - $lines);
        
        return implode('', array_slice($file_lines, $start));
    }
}
