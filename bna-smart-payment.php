<?php
/**
 * Plugin Name: BNA Smart Payment Gateway
 * Plugin URI: https://bnasmartpayment.com
 * Description: WooCommerce payment gateway for BNA Smart Payment with iframe and webhooks.
 * Version: 1.3.0
 * Author: BNA Smart Payment
 * Text Domain: bna-smart-payment
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BNA_SMART_PAYMENT_VERSION', '1.3.0');
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
        add_action('init', array($this, 'init_url_rewrites'));
        add_action('init', array($this, 'handle_payment_request'));
        add_filter('query_vars', array($this, 'add_query_vars'));
    }

    private function load_dependencies() {
        // Load new logging system
        $this->load_logging_system();
        
        // Load core classes
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-activator.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-woocommerce-helper.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-url-handler.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/helpers/class-bna-template-handler.php';
        
        // Load services
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-api-service.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/services/class-bna-iframe-service.php';
        
        // Load schemas
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/schemas/class-bna-checkout-payload.php';
        
        // Load controllers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/controllers/class-bna-payment-controller.php';
        
        // Load webhooks
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/class-bna-webhook-init.php';

        // Load admin interfaces
        if (is_admin()) {
            require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/admin/class-bna-debug-admin-v2.php';
            BNA_Debug_Admin_V2::init();
        }

        bna_api_debug('All dependencies loaded', ['version' => BNA_SMART_PAYMENT_VERSION]);
    }

    /**
     * Load new logging system
     */
    private function load_logging_system() {
        // Core logging files
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/logging/interface-bna-logger.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/logging/abstract-bna-logger.php';
        
        // Specific loggers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/logging/class-bna-woocommerce-logger.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/logging/class-bna-api-logger.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/logging/class-bna-webhook-logger.php';
        
        // Manager and traits
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/logging/class-bna-logger-manager.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/logging/trait-bna-webhook-timing.php';
    }

    public function add_query_vars($vars) {
        $vars[] = 'bna_payment';
        $vars[] = 'order_id';
        $vars[] = 'order_key';
        return $vars;
    }

    public function init_url_rewrites() {
        add_rewrite_rule(
            '^bna-payment/([0-9]+)/([a-zA-Z0-9_]+)/?$',
            'index.php?bna_payment=process&order_id=$matches[1]&order_key=$matches[2]',
            'top'
        );
        bna_api_debug('URL rewrite rules initialized');
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        bna_wc_debug('WooCommerce found, loading gateway');

        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        add_action('wp_enqueue_scripts', array($this, 'load_frontend_assets'));

        bna_wc_log('BNA Smart Payment plugin loaded', [
            'version' => BNA_SMART_PAYMENT_VERSION,
            'webhook_enabled' => true,
            'loggers_enabled' => bna_logger()->is_any_enabled()
        ]);
    }

    public function handle_payment_request() {
        if (!class_exists('WooCommerce') || !BNA_URL_Handler::is_payment_request()) {
            return;
        }

        $order = BNA_URL_Handler::get_order_from_request();

        if (!$order || !BNA_WooCommerce_Helper::is_bna_order($order)) {
            bna_wc_error('Invalid payment request', [
                'has_order' => !empty($order),
                'is_bna_order' => $order ? BNA_WooCommerce_Helper::is_bna_order($order) : false
            ]);
            BNA_URL_Handler::redirect_to_checkout();
            return;
        }

        bna_wc_log('Processing payment request', ['order_id' => $order->get_id()]);
        $this->display_payment_page($order);
    }

    private function display_payment_page($order) {
        try {
            $payment_controller = new BNA_Payment_Controller();
            $payment_data = $payment_controller->get_payment_data($order);

            if (!$payment_data) {
                bna_wc_error('Payment data generation failed', ['order_id' => $order->get_id()]);
                wc_add_notice('Unable to process payment. Please try again.', 'error');
                BNA_URL_Handler::redirect_to_checkout();
                return;
            }

            bna_wc_log('Payment page displayed', ['order_id' => $order->get_id()]);
            $this->load_payment_template($order, $payment_data);

        } catch (Exception $e) {
            bna_wc_error('Payment page display error', [
                'order_id' => $order->get_id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            wc_add_notice('Payment processing error. Please try again.', 'error');
            BNA_URL_Handler::redirect_to_checkout();
        }
    }

    private function load_payment_template($order, $payment_data) {
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
        if (BNA_URL_Handler::is_payment_request() || is_checkout()) {
            wp_enqueue_style('bna-payment-css', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css', array(), BNA_SMART_PAYMENT_VERSION);
            wp_enqueue_script('bna-payment-js', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), BNA_SMART_PAYMENT_VERSION, true);
            
            bna_api_debug('Frontend assets loaded');
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>BNA Smart Payment Gateway</strong> requires WooCommerce to be installed and active.</p></div>';
    }

    public function add_gateway_class($gateways) {
        $gateways[] = 'BNA_Gateway';
        return $gateways;
    }

    public function activate() {
        BNA_Activator::activate();
        
        // Initialize logging system
        $manager = BNA_Logger_Manager::instance();
        $manager->enable_all(); // Enable all loggers on activation
        
        if (false === get_option('bna_smart_payment_webhook_secret')) {
            add_option('bna_smart_payment_webhook_secret', wp_generate_password(32, false));
        }
        
        flush_rewrite_rules();
        
        bna_wc_log('BNA Smart Payment plugin activated', [
            'version' => BNA_SMART_PAYMENT_VERSION,
            'loggers_enabled' => true
        ]);
    }

    public function deactivate() {
        BNA_Activator::deactivate();
        flush_rewrite_rules();
        
        bna_wc_log('BNA Smart Payment plugin deactivated');
    }
}

BNA_Smart_Payment::get_instance();
