<?php
/**
 * Plugin activation and deactivation handler
 *
 * @package BnaSmartPayment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BNA_Activator
 * Handles plugin activation and deactivation
 */
class BNA_Activator {

    /**
     * Plugin activation hook
     * Sets up initial plugin settings and database tables if needed
     */
    public static function activate() {
        // Check WordPress and WooCommerce versions
        self::check_requirements();
        
        // Set default plugin options
        self::set_default_options();
        
        // Create any necessary database tables
        self::create_tables();
        
        // Set activation timestamp
        update_option('bna_smart_payment_activated_time', current_time('timestamp'));
        
        // Clear any caches
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     * Clean up temporary data and caches
     */
    public static function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('bna_smart_payment_cleanup');
        
        // Clear any caches
        flush_rewrite_rules();
        
        // Note: We don't delete plugin options here to preserve settings
        // Options will be deleted only on plugin uninstall
    }

    /**
     * Check if system requirements are met
     */
    private static function check_requirements() {
        global $wp_version;
        
        // Check WordPress version
        if (version_compare($wp_version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment потребує WordPress версії 5.0 або вище.', 'bna-smart-payment'));
        }
        
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment потребує активний WooCommerce плагін.', 'bna-smart-payment'));
        }
        
        // Check WooCommerce version
        if (version_compare(WC()->version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment потребує WooCommerce версії 5.0 або вище.', 'bna-smart-payment'));
        }
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $default_options = array(
            'bna_smart_payment_environment' => 'staging',
            'bna_smart_payment_access_key' => '',
            'bna_smart_payment_secret_key' => '',
            'bna_smart_payment_enabled' => 'no',
            'bna_smart_payment_title' => 'BNA Smart Payment',
            'bna_smart_payment_description' => 'Безпечні онлайн платежі через BNA Smart Payment',
        );

        foreach ($default_options as $option_name => $option_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $option_value);
            }
        }
    }

    /**
     * Create necessary database tables
     * Currently not needed, but prepared for future use
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Example: Transaction logs table (for future use)
        /*
        $table_name = $wpdb->prefix . 'bna_transaction_logs';
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(100) NOT NULL,
            order_id bigint(20) NOT NULL,
            status varchar(50) NOT NULL,
            amount decimal(10,2) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY transaction_id (transaction_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        */
    }
}
