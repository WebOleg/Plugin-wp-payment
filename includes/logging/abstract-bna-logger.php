<?php
/**
 * BNA Abstract Logger
 * Base logger with common functionality
 */

if (!defined('ABSPATH')) exit;

abstract class BNA_Abstract_Logger implements BNA_Logger_Interface {
    
    protected $type;
    protected $log_dir;
    protected $option_name;
    
    public function __construct($type) {
        $this->type = $type;
        $this->option_name = "bna_{$type}_logging_enabled";
        $this->init_log_dir();
    }
    
    /**
     * Initialize log directory
     */
    private function init_log_dir() {
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/bna-logs/' . $this->type . '/';
        
        if (!file_exists($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
            
            // Protect directory
            file_put_contents($this->log_dir . '.htaccess', "deny from all\n");
            file_put_contents($this->log_dir . 'index.php', "<?php // Silence\n");
        }
    }
    
    /**
     * Write log entry
     */
    protected function write_log($level, $message, $data = []) {
        if (!$this->is_enabled()) return;
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_line = "[{$timestamp}] [{$level}] [{$this->type}] {$message}";
        
        if (!empty($data)) {
            $log_line .= ' | ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        
        $log_line .= "\n";
        $log_file = $this->log_dir . $this->type . '-' . current_time('Y-m-d') . '.log';
        
        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }
    
    public function debug($message, $data = []) {
        $this->write_log('DEBUG', $message, $data);
    }
    
    public function info($message, $data = []) {
        $this->write_log('INFO', $message, $data);
    }
    
    public function error($message, $data = []) {
        $this->write_log('ERROR', $message, $data);
    }
    
    public function enable() {
        update_option($this->option_name, true);
    }
    
    public function disable() {
        update_option($this->option_name, false);
    }
    
    public function is_enabled() {
        return get_option($this->option_name, false);
    }
    
    public function get_files() {
        $files = glob($this->log_dir . $this->type . '-*.log');
        $result = [];
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $result[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'path' => $file,
                    'modified' => filemtime($file),
                    'type' => $this->type
                ];
            }
        }
        
        usort($result, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
        
        return $result;
    }
    
    public function read_file($filename, $lines = 100) {
        $file_path = $this->log_dir . $filename;
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        if ($lines === 0) {
            return file_get_contents($file_path);
        }
        
        $file_lines = file($file_path);
        if (!$file_lines) return '';
        
        $total_lines = count($file_lines);
        $start = max(0, $total_lines - $lines);
        
        return implode('', array_slice($file_lines, $start));
    }
    
    public function clear() {
        $files = glob($this->log_dir . $this->type . '-*.log');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        return true;
    }
}
