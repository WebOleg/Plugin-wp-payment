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

    private function __construct() {
        $this->init_hooks();
        $this->load_core_dependencies();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Load core dependencies that don't require WooCommerce
     */
    private function load_core_dependencies() {
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-logger.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-api.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-webhooks.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-template.php';

        if (is_admin()) {
            require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-admin.php';
            BNA_Admin::init();
        }

        bna_debug('Core dependencies loaded', array('version' => BNA_SMART_PAYMENT_VERSION));
    }

    /**
     * Initialize plugin after WooCommerce is loaded
     */
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        bna_log('BNA Smart Payment initializing', array(
            'version' => BNA_SMART_PAYMENT_VERSION,
            'wc_version' => WC()->version
        ));

        $this->load_woocommerce_dependencies();
        BNA_Webhooks::init();

        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        add_action('wp_enqueue_scripts', array($this, 'load_frontend_assets'));
        add_action('wp', array($this, 'handle_payment_request'));

        bna_log('BNA Smart Payment initialized successfully');
    }

    private function load_woocommerce_dependencies() {
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';
    }

    /**
     * Add BNA Gateway to WooCommerce payment gateways
     * @param array $gateways
     * @return array
     */
    public function add_gateway_class($gateways) {
        $gateways[] = 'BNA_Gateway';
        return $gateways;
    }

    public function load_frontend_assets() {
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
        }
    }

    /**
     * Check if we should load frontend assets
     * @return bool
     */
    private function should_load_assets() {
        if (is_checkout()) {
            return true;
        }

        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        return preg_match('/^bna-payment\/\d+\/[a-zA-Z0-9_-]+\/?$/', $request_uri);
    }

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

    public function activate() {
        bna_log('Plugin activated', array('version' => BNA_SMART_PAYMENT_VERSION));
        $this->check_requirements();
        $this->set_default_options();
        
        if (false === get_option('bna_smart_payment_webhook_secret')) {
            add_option('bna_smart_payment_webhook_secret', wp_generate_password(32, false));
        }
    }

    public function deactivate() {
        bna_log('Plugin deactivated');
        wp_clear_scheduled_hook('bna_smart_payment_cleanup');
    }

    private function check_requirements() {
        global $wp_version;

        if (version_compare($wp_version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(__('BNA Smart Payment requires WordPress version 5.0 or higher.', 'bna-smart-payment'));
        }

        if (class_exists('WooCommerce') && version_compare(WC()->version, '5.0', '<')) {
            deactivate_plugins(BNA_SMART_PAYMENT_PLUGIN_BASENAME);
            wp_die(__('BNA Smart Payment requires WooCommerce version 5.0 or higher.', 'bna-smart-payment'));
        }
    }

    private function set_default_options() {
        $defaults = array(
            'bna_smart_payment_environment' => 'staging',
            'bna_smart_payment_access_key' => '',
            'bna_smart_payment_secret_key' => '',
            'bna_smart_payment_iframe_id' => '',
            'bna_smart_payment_enable_phone' => 'no',
            'bna_smart_payment_enable_billing_address' => 'no',
            'bna_smart_payment_enable_birthdate' => 'yes'
        );

        foreach ($defaults as $option_name => $option_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $option_value);
            }
        }
    }

    /**
     * Handle payment page requests via URL routing
     */
    public function handle_payment_request() {
        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');

        if (!preg_match('/^bna-payment\/(\d+)\/([a-zA-Z0-9_-]+)\/?$/', $request_uri, $matches)) {
            return;
        }

        $order_id = intval($matches[1]);
        $order_key = sanitize_text_field($matches[2]);

        bna_log('Payment request detected', array(
            'order_id' => $order_id,
            'order_key' => $order_key
        ));

        $gateways = WC()->payment_gateways->get_available_payment_gateways();
        $gateway = isset($gateways['bna_smart_payment']) ? $gateways['bna_smart_payment'] : null;

        if (!$gateway) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key || $order->get_payment_method() !== 'bna_smart_payment') {
            bna_error('Order validation failed', array('order_id' => $order_id));
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if (method_exists($gateway, 'display_payment_page_public')) {
            $gateway->display_payment_page_public($order);
        } else {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /**
     * Get plugin information for debugging
     * @return array
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
 * @return BNA_Smart_Payment
 */
function bna_smart_payment_init() {
    return BNA_Smart_Payment::get_instance();
}

bna_smart_payment_init();

/**
 * Get plugin instance
 * @return BNA_Smart_Payment
 */
function bna_smart_payment() {
    return BNA_Smart_Payment::get_instance();
}

/**
 * Get plugin info
 * @return array
 */
function bna_get_plugin_info() {
    return BNA_Smart_Payment::get_plugin_info();
}
