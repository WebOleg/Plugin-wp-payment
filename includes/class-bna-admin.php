<?php
/**
 * BNA Admin Interface
 * Handles admin pages and functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Admin {
    
    /**
     * Initialize admin functionality
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'handle_actions'));
    }
    
    /**
     * Add admin menu under WooCommerce
     */
    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'BNA Payment Logs',
            'BNA Logs',
            'manage_woocommerce',
            'bna-logs',
            array(__CLASS__, 'logs_page')
        );
    }
    
    /**
     * Handle admin actions
     */
    public static function handle_actions() {
        if (!isset($_GET['bna_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        
        $action = sanitize_text_field($_GET['bna_action']);
        
        switch ($action) {
            case 'clear_logs':
                BNA_Logger::clear();
                wp_redirect(admin_url('admin.php?page=bna-logs&message=logs_cleared'));
                exit;
                
            case 'download_logs':
                self::download_logs();
                exit;
        }
    }
    
    /**
     * Download logs as text file
     */
    private static function download_logs() {
        $logs = BNA_Logger::get_logs(0);
        $filename = 'bna-payment-logs-' . date('Y-m-d-H-i-s') . '.txt';
        
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($logs));
        
        echo $logs;
    }
    
    /**
     * Display logs page
     */
    public static function logs_page() {
        $data = array(
            'logs' => BNA_Logger::get_logs(1000),
            'log_size' => BNA_Logger::get_log_size(),
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'plugin_version' => defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'Unknown',
            'wp_version' => get_bloginfo('version'),
            'wc_version' => class_exists('WooCommerce') ? WC()->version : 'Not installed',
            'php_version' => PHP_VERSION,
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'message' => isset($_GET['message']) ? sanitize_text_field($_GET['message']) : ''
        );
        
        BNA_Template::render_admin_logs($data);
    }
}
