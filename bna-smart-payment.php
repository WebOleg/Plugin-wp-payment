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

        // Helpers - load first as other classes depend on them
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-woocommerce-helper.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-url-handler.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-template-handler.php';

        // Services - load after helpers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-api-service.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-iframe-service.php';

        // Controllers - load last as they depend on services
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/controllers/class-bna-payment-controller.php';
    }

    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Load gateway class only if WooCommerce is active
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';

        // Add our gateway to WooCommerce
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));

        // Load frontend styles and scripts
        add_action('wp_enqueue_scripts', array($this, 'load_frontend_assets'));
    }

    public function handle_payment_request() {
        // Only handle if WooCommerce is active and this is a payment request
        if (!class_exists('WooCommerce') || !BNA_URL_Handler::is_payment_request()) {
            return;
        }

        $order = BNA_URL_Handler::get_order_from_request();

        if (!$order) {
            BNA_URL_Handler::redirect_to_checkout();
            return;
        }

        $this->display_payment_page($order);
    }

    private function display_payment_page($order) {
        try {
            $payment_controller = new BNA_Payment_Controller();
            $payment_data = $payment_controller->get_payment_data($order);

            if (!$payment_data) {
                wc_add_notice('Unable to process payment. Please try again.', 'error');
                BNA_URL_Handler::redirect_to_checkout();
                return;
            }

            // Load payment page template
            $this->load_payment_template($order, $payment_data);

        } catch (Exception $e) {
            wc_add_notice('Payment processing error. Please try again.', 'error');
            BNA_URL_Handler::redirect_to_checkout();
        }
    }

    private function load_payment_template($order, $payment_data) {
        // Use WordPress template hierarchy
        get_header();

        echo '<div class="container" style="margin: 20px auto; max-width: 1200px; padding: 0 20px;">';

        BNA_Template_Handler::load('payment-iframe', array(
            'order' => $order,
            'iframe_url' => $payment_data['iframe_url']
        ));

        echo '</div>';

        get_footer();
        exit;
    }

    public function load_frontend_assets() {
        // Only load on payment pages
        if (BNA_URL_Handler::is_payment_request()) {
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
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BNA Smart Payment Gateway</strong> requires WooCommerce to be installed and active.';
        echo '</p></div>';
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

// Initialize the plugin
BNA_Smart_Payment::get_instance();