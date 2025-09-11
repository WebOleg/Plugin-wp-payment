<?php
/**
 * Plugin activation and deactivation handler
 *
 * @since 1.8.0 Removed auto-generation of webhook secret for security reasons
 * @since 1.7.0 Payment methods management support
 * @since 1.6.0 Customer sync and enhanced settings
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Activator {

    /**
     * Plugin activation hook
     * Sets up initial plugin settings without auto-generating sensitive keys
     */
    public static function activate() {
        self::check_requirements();
        self::set_default_options();
        self::create_tables();

        update_option('bna_smart_payment_activated_time', current_time('timestamp'));

        // Log activation
        if (class_exists('BNA_Logger')) {
            bna_log('Plugin activated successfully', array(
                'version' => defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'unknown',
                'timestamp' => current_time('c')
            ));
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     * Clean up temporary data and caches
     */
    public static function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('bna_smart_payment_cleanup');

        // Log deactivation
        if (class_exists('BNA_Logger')) {
            bna_log('Plugin deactivated', array(
                'timestamp' => current_time('c')
            ));
        }

        flush_rewrite_rules();
    }

    /**
     * Check if system requirements are met
     * Deactivates plugin if requirements not satisfied
     */
    private static function check_requirements() {
        global $wp_version;

        // Check WordPress version
        if (version_compare($wp_version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment requires WordPress version 5.0 or higher.', 'bna-smart-payment'));
        }

        // Check if WooCommerce is installed
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__(
                'BNA Smart Payment requires WooCommerce to be installed and active. Please install WooCommerce first.',
                'bna-smart-payment'
            ));
        }

        // Check WooCommerce version
        if (version_compare(WC()->version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment requires WooCommerce version 5.0 or higher.', 'bna-smart-payment'));
        }

        // Check PHP version (recommended 7.4+)
        if (version_compare(PHP_VERSION, '7.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(esc_html__('BNA Smart Payment requires PHP version 7.0 or higher.', 'bna-smart-payment'));
        }

        // Check essential PHP functions
        $required_functions = array('curl_init', 'json_encode', 'json_decode', 'hash_hmac');
        foreach ($required_functions as $function) {
            if (!function_exists($function)) {
                deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
                wp_die(sprintf(
                    esc_html__('BNA Smart Payment requires PHP function %s to be available.', 'bna-smart-payment'),
                    $function
                ));
            }
        }
    }

    /**
     * Set default plugin options
     *
     * SECURITY NOTE: Webhook secret is intentionally left empty and must be
     * configured manually from BNA Portal for security reasons.
     */
    private static function set_default_options() {
        $default_options = array(
            // Basic plugin settings
            'bna_smart_payment_environment' => 'staging',
            'bna_smart_payment_enabled' => 'no',
            'bna_smart_payment_title' => 'BNA Smart Payment',
            'bna_smart_payment_description' => 'Secure online payments via BNA Smart Payment',

            // API credentials - must be configured manually from BNA Portal
            'bna_smart_payment_access_key' => '',
            'bna_smart_payment_secret_key' => '',
            'bna_smart_payment_iframe_id' => '',

            // Webhook security - MUST be configured manually for security (v1.8.0)
            'bna_smart_payment_webhook_secret' => '', // Empty by default - get from BNA Portal

            // Customer data collection options
            'bna_smart_payment_enable_phone' => 'no',
            'bna_smart_payment_enable_billing_address' => 'no',
            'bna_smart_payment_enable_birthdate' => 'yes', // Required for age verification
            'bna_smart_payment_enable_shipping_address' => 'no',

            // Debug and development
            'bna_smart_payment_debug_mode' => 'no'
        );

        foreach ($default_options as $option_name => $option_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $option_value);
            }
        }

        // Set plugin version for upgrade tracking
        $current_version = defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : '1.8.0';
        update_option('bna_smart_payment_version', $current_version);
    }

    /**
     * Create necessary database tables
     * Currently not needed, but prepared for future use
     */
    private static function create_tables() {
        global $wpdb;

        // Reserved for future database tables if needed
        // Example: payment method tokens, transaction logs, etc.

        // Charset and collation for tables
        $charset_collate = $wpdb->get_charset_collate();

        // Future table creation would go here
        // require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        // dbDelta($sql);
    }

    /**
     * Create secure upload directory for BNA logs
     * Ensures logs are protected from direct access
     */
    private static function create_secure_log_directory() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/bna-logs/';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);

            // Create .htaccess to deny direct access
            $htaccess_content = "deny from all\n";
            file_put_contents($log_dir . '.htaccess', $htaccess_content);

            // Create index.php to prevent directory listing
            $index_content = "<?php\n// Silence is golden\n";
            file_put_contents($log_dir . 'index.php', $index_content);
        }
    }

    /**
     * Security check: Validate SSL and permalinks
     * Logs warnings for security recommendations
     */
    private static function check_security_recommendations() {
        $warnings = array();

        // SSL check
        if (!is_ssl()) {
            $warnings[] = 'SSL certificate not detected. HTTPS is strongly recommended for payment processing.';
        }

        // Permalinks check
        if (empty(get_option('permalink_structure'))) {
            $warnings[] = 'Pretty permalinks are disabled. Enable them for better webhook URL structure.';
        }

        // PHP version recommendation
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $warnings[] = 'PHP version ' . PHP_VERSION . ' detected. PHP 7.4+ is recommended for better security and performance.';
        }

        // Log warnings if any
        if (!empty($warnings) && class_exists('BNA_Logger')) {
            foreach ($warnings as $warning) {
                bna_log('Security recommendation', array('warning' => $warning));
            }
        }
    }

    /**
     * Setup activation notice for admin
     * Guides user through initial configuration
     */
    private static function setup_activation_notice() {
        // Set transient for activation notice
        set_transient('bna_smart_payment_activation_notice', true, 30);

        // Hook for displaying the notice
        add_action('admin_notices', array(__CLASS__, 'display_activation_notice'));
    }

    /**
     * Display activation notice with setup instructions
     */
    public static function display_activation_notice() {
        if (!get_transient('bna_smart_payment_activation_notice')) {
            return;
        }

        $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=bna_smart_payment');

        ?>
        <div class="notice notice-info is-dismissible">
            <h3>🎉 BNA Smart Payment Gateway Activated!</h3>
            <p><strong>Next steps to complete setup:</strong></p>
            <ol>
                <li>Get your API credentials from <strong>BNA Merchant Portal</strong></li>
                <li>Configure webhook secret from <strong>BNA Portal → Merchant Profile → Webhooks</strong></li>
                <li><a href="<?php echo esc_url($settings_url); ?>">Configure the gateway settings</a></li>
                <li>Test with staging environment before going live</li>
            </ol>
            <p><small><strong>Webhook URL:</strong> <code><?php echo esc_html(home_url('/wp-json/bna/v1/webhook')); ?></code></small></p>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $(document).on('click', '.notice-dismiss', function() {
                    // Clear the transient when notice is dismissed
                    $.post(ajaxurl, {
                        action: 'bna_dismiss_activation_notice',
                        nonce: '<?php echo wp_create_nonce('bna_dismiss_notice'); ?>'
                    });
                });
            });
        </script>
        <?php

        // Clear transient after showing
        delete_transient('bna_smart_payment_activation_notice');
    }
}