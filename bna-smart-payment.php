<?php
/**
 * Plugin Name: BNA Smart Payment Gateway
 * Plugin URI: https://bnasmartpayment.com
 * Description: WooCommerce payment gateway integration for BNA Smart Payment system with iframe support.
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

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
    }

    private function load_dependencies() {
        // Load logger first
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-logger.php';
        BNA_Logger::init();
        
        // Core classes
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-activator.php';
        
        // Helpers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-woocommerce-helper.php';
        
        // Services
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-api-service.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-iframe-service.php';
        
        // Controllers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/controllers/class-bna-payment-controller.php';
    }

    public function init() {
        BNA_Logger::info('BNA Plugin initializing');

        if (!$this->is_woocommerce_active()) {
            BNA_Logger::error('WooCommerce not active');
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        
        new BNA_Payment_Controller();
        BNA_Logger::info('BNA Plugin initialized successfully');
    }

    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>BNA Smart Payment Gateway requires WooCommerce to be installed and active.</strong></p></div>';
    }

    public function add_gateway_class($gateways) {
        $gateways[] = 'BNA_Gateway';
        return $gateways;
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'bna-smart-payment', 
            false, 
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    public function activate() {
        BNA_Logger::info('BNA Plugin activated');
        BNA_Activator::activate();
    }

    public function deactivate() {
        BNA_Logger::info('BNA Plugin deactivated');
        BNA_Activator::deactivate();
    }
}

// Initialize plugin
BNA_Smart_Payment::get_instance();
