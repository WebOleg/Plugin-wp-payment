<?php
/**
 * Plugin Name: BNA Smart Payment Gateway
 * Plugin URI: https://bnasmartpayment.com
 * Description: Simple WooCommerce payment gateway for BNA Smart Payment with iframe.
 * Version: 1.0.0
 * Author: BNA Smart Payment
 * Text Domain: bna-smart-payment
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BNA_SMART_PAYMENT_VERSION', '1.0.0');
define('BNA_SMART_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BNA_SMART_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BNA_SMART_PAYMENT_PLUGIN_BASENAME', plugin_basename(__FILE__));

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
        add_action('init', array($this, 'handle_payment_request'));
    }

    private function load_dependencies() {
        // Core classes
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-activator.php';
        
        // Helpers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-woocommerce-helper.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-url-handler.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-template-handler.php';
        
        // Services
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-api-service.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-iframe-service.php';
        
        // Controllers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/controllers/class-bna-payment-controller.php';
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
    }

    public function handle_payment_request() {
        if (!BNA_URL_Handler::is_payment_request()) {
            return;
        }

        $order = BNA_URL_Handler::get_order_from_request();
        
        if (!$order) {
            BNA_URL_Handler::redirect_to_checkout();
        }

        $this->display_payment_page($order);
    }

    private function display_payment_page($order) {
        $payment_controller = new BNA_Payment_Controller();
        $payment_data = $payment_controller->get_payment_data($order);
        
        if (!$payment_data) {
            BNA_URL_Handler::redirect_to_checkout();
        }

        get_header();
        
        BNA_Template_Handler::load('payment-iframe', array(
            'order' => $order,
            'iframe_url' => $payment_data['iframe_url']
        ));
        
        get_footer();
        exit;
    }

    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>BNA Smart Payment Gateway потребує активний WooCommerce плагін.</strong></p></div>';
    }

    public function add_gateway_class($gateways) {
        $gateways[] = 'BNA_Gateway';
        return $gateways;
    }

    public function activate() {
        BNA_Activator::activate();
    }

    public function deactivate() {
        BNA_Activator::deactivate();
    }
}

BNA_Smart_Payment::get_instance();
