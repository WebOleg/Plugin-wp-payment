<?php
/**
 * Plugin Name: BNA Smart Payment Gateway
 * Plugin URI: https://bnasmartpayment.com
 * Description: WooCommerce payment gateway for BNA Smart Payment with iframe and webhooks.
 * Version: 1.4.1
 * Author: BNA Smart Payment
 * Text Domain: bna-smart-payment
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('BNA_SMART_PAYMENT_VERSION', '1.4.1');
define('BNA_SMART_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BNA_SMART_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BNA_SMART_PAYMENT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main BNA Smart Payment Plugin Class
 */
class BNA_Smart_Payment {

    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_core_dependencies();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Load core dependencies (not WooCommerce dependent)
     */
    private function load_core_dependencies() {
        // Core classes that don't depend on WooCommerce
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-logger.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-api.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-webhooks.php';

        // Admin interface
        if (is_admin()) {
            require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-admin.php';
            BNA_Admin::init();
        }

        bna_debug('BNA Smart Payment core dependencies loaded', array(
            'version' => BNA_SMART_PAYMENT_VERSION,
            'core_files_loaded' => 4
        ));
    }

    /**
     * Initialize plugin after WooCommerce is loaded
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        bna_log('BNA Smart Payment initializing', array(
            'version' => BNA_SMART_PAYMENT_VERSION,
            'wc_version' => WC()->version,
            'wp_version' => get_bloginfo('version')
        ));

        // Now load WooCommerce-dependent classes
        $this->load_woocommerce_dependencies();

        // Initialize webhooks
        BNA_Webhooks::init();

        // Add gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));

        // Load frontend assets
        add_action('wp_enqueue_scripts', array($this, 'load_frontend_assets'));

        // Handle payment requests at plugin level
        add_action('wp', array($this, 'handle_payment_request'));

        bna_log('BNA Smart Payment initialized successfully');
    }

    /**
     * Load WooCommerce-dependent classes
     */
    private function load_woocommerce_dependencies() {
        // Load Gateway class only after WooCommerce is available
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';

        bna_debug('WooCommerce dependencies loaded', array(
            'gateway_loaded' => class_exists('BNA_Gateway')
        ));
    }

    /**
     * Add BNA Gateway to WooCommerce
     */
    public function add_gateway_class($gateways) {
        $gateways[] = 'BNA_Gateway';
        return $gateways;
    }

    /**
     * Load frontend assets
     */
    public function load_frontend_assets() {
        // Only load on payment pages and checkout
        if ($this->should_load_assets()) {
            wp_enqueue_style(
                'bna-payment-css',
                BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css',
                array(),
                BNA_SMART_PAYMENT_VERSION
            );

            wp_enqueue_script(
                'bna-payment-js',
                BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js',
                array('jquery'),
                BNA_SMART_PAYMENT_VERSION,
                true
            );

            bna_debug('Frontend assets loaded');
        }
    }

    /**
     * Check if we should load assets
     */
    private function should_load_assets() {
        // Load on checkout page
        if (is_checkout()) {
            return true;
        }

        // Load on BNA payment pages (check URL directly) - allow dashes in order key
        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        if (preg_match('/^bna-payment\/\d+\/[a-zA-Z0-9_-]+\/?$/', $request_uri)) {
            return true;
        }

        return false;
    }

    /**
     * Show WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong>BNA Smart Payment Gateway</strong> requires WooCommerce to be installed and active.
                <a href="<?php echo admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'); ?>">Install WooCommerce</a>
            </p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate() {
        bna_log('BNA Smart Payment plugin activated', array(
            'version' => BNA_SMART_PAYMENT_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION
        ));

        // Check WordPress and WooCommerce versions
        $this->check_requirements();

        // Set default options
        $this->set_default_options();

        // Generate webhook secret if not exists
        if (false === get_option('bna_smart_payment_webhook_secret')) {
            add_option('bna_smart_payment_webhook_secret', wp_generate_password(32, false));
        }

        bna_log('Plugin activation completed');
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        bna_log('BNA Smart Payment plugin deactivated');

        // Clear scheduled hooks if any
        wp_clear_scheduled_hook('bna_smart_payment_cleanup');
    }

    /**
     * Check system requirements
     */
    private function check_requirements() {
        global $wp_version;

        // Check WordPress version
        if (version_compare($wp_version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(__('BNA Smart Payment requires WordPress version 5.0 or higher.', 'bna-smart-payment'));
        }

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            // Don't deactivate here, just show notice
            bna_error('WooCommerce not found during activation');
            return;
        }

        // Check WooCommerce version
        if (version_compare(WC()->version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(__('BNA Smart Payment requires WooCommerce version 5.0 or higher.', 'bna-smart-payment'));
        }
    }

    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $default_options = array(
            'bna_smart_payment_environment' => 'staging',
            'bna_smart_payment_access_key' => '',
            'bna_smart_payment_secret_key' => '',
            'bna_smart_payment_iframe_id' => '',
            'bna_smart_payment_enable_phone' => 'no',
            'bna_smart_payment_enable_billing_address' => 'no',
            'bna_smart_payment_enable_birthdate' => 'yes'
        );

        foreach ($default_options as $option_name => $option_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $option_value);
            }
        }
    }

    /**
     * Handle payment page requests
     */
    public function handle_payment_request() {
        // Check if this is a BNA payment request via URL parsing
        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');

        // Debug: log what URI we're checking
        bna_debug('Checking payment request', array(
            'request_uri' => $request_uri,
            'hook' => current_action()
        ));

        // Match pattern: bna-payment/ORDER_ID/ORDER_KEY (allow dashes in order key)
        if (!preg_match('/^bna-payment\/(\d+)\/([a-zA-Z0-9_-]+)\/?$/', $request_uri, $matches)) {
            return; // Not a BNA payment request
        }

        $order_id = intval($matches[1]);
        $order_key = sanitize_text_field($matches[2]);

        bna_log('Payment request detected', array(
            'request_uri' => $request_uri,
            'order_id' => $order_id,
            'order_key' => $order_key,
            'order_key_length' => strlen($order_key)
        ));

        // Get gateway instance
        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        $gateway = isset($gateways['bna_smart_payment']) ? $gateways['bna_smart_payment'] : null;

        if (!$gateway) {
            bna_error('BNA Gateway not found');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Validate order
        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            bna_error('Order validation failed', array(
                'order_id' => $order_id,
                'order_exists' => !empty($order),
                'key_match' => $order ? ($order->get_order_key() === $order_key) : false
            ));
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if ($order->get_payment_method() !== 'bna_smart_payment') {
            bna_error('Invalid payment method', array(
                'payment_method' => $order->get_payment_method(),
                'expected_method' => 'bna_smart_payment'
            ));
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        bna_log('Processing payment request', array('order_id' => $order->get_id()));

        // Call gateway's display method
        if (method_exists($gateway, 'display_payment_page_public')) {
            $gateway->display_payment_page_public($order);
        } else {
            bna_error('Gateway display method not found');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /**
     * Get plugin info for debugging
     */
    public static function get_plugin_info() {
        return array(
            'version' => BNA_SMART_PAYMENT_VERSION,
            'plugin_url' => BNA_SMART_PAYMENT_PLUGIN_URL,
            'plugin_path' => BNA_SMART_PAYMENT_PLUGIN_PATH,
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'wp_version' => get_bloginfo('version'),
            'wc_version' => class_exists('WooCommerce') ? WC()->version : 'Not installed',
            'php_version' => PHP_VERSION
        );
    }
}

/**
 * Initialize the plugin
 */
function bna_smart_payment_init() {
    return BNA_Smart_Payment::get_instance();
}

// Start the plugin
bna_smart_payment_init();

/**
 * Global helper function to get plugin instance
 */
function bna_smart_payment() {
    return BNA_Smart_Payment::get_instance();
}

/**
 * Plugin info helper
 */
function bna_get_plugin_info() {
    return BNA_Smart_Payment::get_plugin_info();
}