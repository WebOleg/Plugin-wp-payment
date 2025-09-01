<?php
/**
 * Plugin activation and deactivation handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Activator {

    /**
     * Plugin activation hook
     * Sets up initial plugin settings
     */
    public static function activate() {
        self::check_requirements();
        self::set_default_options();
        self::create_tables();
        
        update_option('bna_smart_payment_activated_time', current_time('timestamp'));
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     * Clean up temporary data and caches
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('bna_smart_payment_cleanup');
        flush_rewrite_rules();
    }

    /**
     * Check if system requirements are met
     */
    private static function check_requirements() {
        global $wp_version;
        
        if (version_compare($wp_version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment requires WordPress version 5.0 or higher.', 'bna-smart-payment'));
        }
        
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment requires WooCommerce to be installed and active.', 'bna-smart-payment'));
        }
        
        if (version_compare(WC()->version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment requires WooCommerce version 5.0 or higher.', 'bna-smart-payment'));
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
            'bna_smart_payment_description' => 'Secure online payments via BNA Smart Payment',
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
        // Reserved for future database tables if needed
    }
}
