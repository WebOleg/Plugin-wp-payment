<?php
/**
 * Plugin Name: BNA Smart Payment Gateway
 * Plugin URI: https://bnasmartpayment.com
 * Description: WooCommerce payment gateway integration for BNA Smart Payment system with iframe support and webhooks.
 * Version: 1.0.0
 * Author: BNA Smart Payment
 * Author URI: https://bnasmartpayment.com
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 * Text Domain: bna-smart-payment
 * Domain Path: /languages
 * License: GPL v2 or later
 * Network: false
 *
 * @package BnaSmartPayment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BNA_SMART_PAYMENT_VERSION', '1.0.0');
define('BNA_SMART_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BNA_SMART_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BNA_SMART_PAYMENT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class BNA_Smart_Payment {

    /**
     * Plugin instance
     * @var BNA_Smart_Payment
     */
    private static $instance = null;

    /**
     * Get plugin instance
     * @return BNA_Smart_Payment
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
        $this->load_dependencies();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-activator.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-api.php';
    }

    /**
     * Initialize the plugin after all plugins are loaded
     */
    public function init() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load gateway class
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';

        // Register payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
    }

    /**
     * Check if WooCommerce is active
     * @return bool
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Display notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>BNA Smart Payment Gateway requires active WooCommerce plugin to work.</strong></p></div>';
    }

    /**
     * Add gateway to WooCommerce
     * @param array $gateways
     * @return array
     */
    public function add_gateway_class($gateways) {
        $gateways[] = 'BNA_Gateway';
        return $gateways;
    }

    /**
     * Load text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bna-smart-payment', 
            false, 
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        BNA_Activator::activate();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        BNA_Activator::deactivate();
    }
}

// Initialize the plugin
BNA_Smart_Payment::get_instance();
