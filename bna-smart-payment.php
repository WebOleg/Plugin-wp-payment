<?php
/**
 * Plugin Name: BNA Smart Payment Gateway
 * Plugin URI: https://bnasmartpayment.com
 * Description: WooCommerce payment gateway for BNA Smart Payment with iframe, HMAC webhooks, shipping address support, customer data sync, payment methods management and subscriptions.
 * Version: 1.9.0
 * Author: BNA Smart Payment
 * Text Domain: bna-smart-payment
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @since 1.9.0 Added subscription support and custom product types
 * @since 1.8.0 Added HMAC webhook signature verification and enhanced security
 * @since 1.7.0 Payment methods management and auto-saving
 * @since 1.6.0 Customer data sync and enhanced shipping address support
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BNA_SMART_PAYMENT_VERSION', '1.9.0');
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
        $this->load_core_dependencies();
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    private function load_core_dependencies() {
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-logger.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-api.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-payment-methods.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-webhooks.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-template.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-my-account.php';

        // Load subscription classes (v1.9.0)
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-subscriptions.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-subscription-product.php';

        if (is_admin()) {
            require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-admin.php';
            BNA_Admin::init();
        }

        bna_debug('Core dependencies loaded', array('version' => BNA_SMART_PAYMENT_VERSION));
    }

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
        BNA_Payment_Methods::get_instance();
        BNA_My_Account::get_instance();

        // Initialize subscriptions (v1.9.0)
        BNA_Subscriptions::get_instance();

        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));
        add_action('wp_enqueue_scripts', array($this, 'load_frontend_assets'));
        add_action('wp', array($this, 'handle_payment_request'));

        add_action('wp_ajax_bna_test_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_nopriv_bna_test_connection', array($this, 'test_api_connection'));

        bna_log('BNA Smart Payment initialized successfully');
    }

    private function load_woocommerce_dependencies() {
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/class-bna-gateway.php';
    }

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

            // Load subscription styles (v1.9.0)
            wp_enqueue_style(
                'bna-subscriptions-css',
                BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/subscriptions.css',
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

            if (is_checkout()) {
                $this->load_shipping_assets();
            }
        }
    }

    private function load_shipping_assets() {
        $shipping_enabled = get_option('bna_smart_payment_enable_shipping_address', 'no');

        if ($shipping_enabled === 'yes') {
            wp_enqueue_script(
                'bna-shipping-address',
                BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/shipping-address.js',
                array('jquery', 'bna-payment-js'),
                BNA_SMART_PAYMENT_VERSION,
                true
            );

            wp_localize_script('bna-shipping-address', 'bna_shipping', array(
                'gateway_id' => 'bna_smart_payment',
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bna_shipping_nonce'),
                'i18n' => array(
                    'same_as_billing' => __('Same as billing address', 'bna-smart-payment'),
                    'shipping_required' => __('Shipping address is required when different from billing.', 'bna-smart-payment'),
                    'select_option' => __('Select an option…', 'bna-smart-payment'),
                    'invalid_address' => __('Please complete all required shipping address fields.', 'bna-smart-payment')
                )
            ));

            bna_debug('Shipping address assets loaded');
        }
    }

    private function should_load_assets() {
        if (is_checkout() || is_account_page()) {
            return true;
        }

        // Load on product pages with subscription products (v1.9.0)
        if (is_product()) {
            global $product;
            if ($product && BNA_Subscriptions::is_subscription_product($product)) {
                return true;
            }
        }

        // Load on admin product edit pages (v1.9.0)
        if (is_admin() && isset($_GET['post']) && get_post_type($_GET['post']) === 'product') {
            return true;
        }

        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        return preg_match('/^bna-payment\/\d+\/[a-zA-Z0-9_-]+\/?$/', $request_uri);
    }

    public function handle_payment_request() {
        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');

        if (!preg_match('/^bna-payment\/(\d+)\/([a-zA-Z0-9_-]+)\/?$/', $request_uri, $matches)) {
            return;
        }

        $order_id = (int) $matches[1];
        $order_key = $matches[2];
        $order = wc_get_order($order_id);
        $gateway = new BNA_Gateway();

        if (!$order || !$gateway) {
            bna_error('Payment request - order or gateway not found', array(
                'order_id' => $order_id,
                'order_exists' => $order ? true : false,
                'order_key_matches' => $order ? ($order->get_order_key() === $order_key) : false,
                'payment_method' => $order ? $order->get_payment_method() : 'unknown'
            ));
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if (method_exists($gateway, 'display_payment_page_public')) {
            $gateway->display_payment_page_public($order);
        } else {
            bna_error('Payment page display method not found');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    public function test_api_connection() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('bna_test_connection', 'nonce');

        $api = new BNA_API();
        $result = $api->test_connection();

        wp_send_json(array(
            'success' => $result,
            'message' => $result ? 'Connection successful' : 'Connection failed'
        ));
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

        // Note: Webhook secret is no longer auto-generated
        // Users must get it from BNA Portal and configure manually for security

        $this->maybe_upgrade();
        flush_rewrite_rules();
    }

    public function deactivate() {
        bna_log('Plugin deactivated');
        wp_clear_scheduled_hook('bna_smart_payment_cleanup');
        flush_rewrite_rules();
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
            'bna_smart_payment_webhook_secret' => '', // Added in v1.8.0 - must be configured manually
            'bna_smart_payment_enable_phone' => 'no',
            'bna_smart_payment_enable_billing_address' => 'no',
            'bna_smart_payment_enable_birthdate' => 'yes',
            'bna_smart_payment_enable_shipping_address' => 'no',
            'bna_smart_payment_debug_mode' => 'no',

            // Subscription options (v1.9.0)
            'bna_smart_payment_enable_subscriptions' => 'no',
            'bna_smart_payment_subscription_frequencies' => 'monthly,quarterly,annual',
            'bna_smart_payment_allow_subscription_trials' => 'yes',
            'bna_smart_payment_allow_signup_fees' => 'yes'
        );

        foreach ($defaults as $option_name => $option_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $option_value);
            }
        }
    }

    private function maybe_upgrade() {
        $installed_version = get_option('bna_smart_payment_version', '0.0.0');

        if (version_compare($installed_version, BNA_SMART_PAYMENT_VERSION, '<')) {
            bna_log('Running plugin upgrade', array(
                'from_version' => $installed_version,
                'to_version' => BNA_SMART_PAYMENT_VERSION
            ));

            if (version_compare($installed_version, '1.5.0', '<')) {
                $this->upgrade_to_1_5_0();
            }

            if (version_compare($installed_version, '1.6.0', '<')) {
                $this->upgrade_to_1_6_0();
            }

            if (version_compare($installed_version, '1.6.1', '<')) {
                $this->upgrade_to_1_6_1();
            }

            if (version_compare($installed_version, '1.7.0', '<')) {
                $this->upgrade_to_1_7_0();
            }

            if (version_compare($installed_version, '1.8.0', '<')) {
                $this->upgrade_to_1_8_0();
            }

            if (version_compare($installed_version, '1.9.0', '<')) {
                $this->upgrade_to_1_9_0();
            }

            update_option('bna_smart_payment_version', BNA_SMART_PAYMENT_VERSION);
            bna_log('Plugin upgrade completed', array('version' => BNA_SMART_PAYMENT_VERSION));
        }
    }

    private function upgrade_to_1_5_0() {
        bna_log('Upgrading to version 1.5.0');
        // Legacy upgrade logic
    }

    private function upgrade_to_1_6_0() {
        bna_log('Upgrading to version 1.6.0 - Customer sync features');

        if (false === get_option('bna_smart_payment_enable_birthdate')) {
            add_option('bna_smart_payment_enable_birthdate', 'yes');
        }
    }

    private function upgrade_to_1_6_1() {
        bna_log('Upgrading to version 1.6.1 - Enhanced error handling');
        // Enhanced error handling and country mapping improvements
    }

    private function upgrade_to_1_7_0() {
        bna_log('Upgrading to version 1.7.0 - Payment methods management');
        // Payment methods management features added
    }

    private function upgrade_to_1_8_0() {
        bna_log('Upgrading to version 1.8.0 - HMAC webhook security');

        // Add webhook secret option (empty by default - must be configured manually)
        if (false === get_option('bna_smart_payment_webhook_secret')) {
            add_option('bna_smart_payment_webhook_secret', '');
        }

        // Remove any auto-generated webhook secret from previous versions
        delete_option('bna_smart_payment_webhook_secret_auto');
    }

    private function upgrade_to_1_9_0() {
        bna_log('Upgrading to version 1.9.0 - Subscription support');

        // Add subscription options
        $subscription_defaults = array(
            'bna_smart_payment_enable_subscriptions' => 'no',
            'bna_smart_payment_subscription_frequencies' => 'monthly,quarterly,annual',
            'bna_smart_payment_allow_subscription_trials' => 'yes',
            'bna_smart_payment_allow_signup_fees' => 'yes'
        );

        foreach ($subscription_defaults as $option_name => $option_value) {
            if (false === get_option($option_name)) {
                add_option($option_name, $option_value);
            }
        }
    }

    // ==========================================
    // PLUGIN INFO AND SYSTEM HEALTH
    // ==========================================

    public static function get_plugin_info() {
        return array(
            'version' => BNA_SMART_PAYMENT_VERSION,
            'plugin_url' => BNA_SMART_PAYMENT_PLUGIN_URL,
            'plugin_path' => BNA_SMART_PAYMENT_PLUGIN_PATH,
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'wp_version' => get_bloginfo('version'),
            'wc_version' => class_exists('WooCommerce') ? WC()->version : 'Not installed',
            'php_version' => PHP_VERSION,
            'shipping_enabled' => get_option('bna_smart_payment_enable_shipping_address', 'no'),
            'customer_sync_enabled' => true,
            'error_handling_improved' => true,
            'country_mapping_improved' => true,
            'payment_methods_management' => true,
            'hmac_webhooks_enabled' => true, // New in v1.8.0
            'subscriptions_enabled' => get_option('bna_smart_payment_enable_subscriptions', 'no'), // New in v1.9.0
            'webhook_secret_configured' => !empty(get_option('bna_smart_payment_webhook_secret', ''))
        );
    }

    public static function get_config() {
        return array(
            'environment' => get_option('bna_smart_payment_environment', 'staging'),
            'shipping_enabled' => get_option('bna_smart_payment_enable_shipping_address', 'no'),
            'billing_enabled' => get_option('bna_smart_payment_enable_billing_address', 'no'),
            'phone_enabled' => get_option('bna_smart_payment_enable_phone', 'no'),
            'birthdate_enabled' => get_option('bna_smart_payment_enable_birthdate', 'yes'),
            'debug_mode' => get_option('bna_smart_payment_debug_mode', 'no'),
            'webhook_secret_configured' => !empty(get_option('bna_smart_payment_webhook_secret', '')),
            'subscriptions_enabled' => get_option('bna_smart_payment_enable_subscriptions', 'no'), // New in v1.9.0
            'features' => array(
                'customer_sync' => '1.6.0',
                'error_handling' => '1.6.1',
                'payment_methods' => '1.7.0',
                'hmac_webhooks' => '1.8.0',
                'subscriptions' => '1.9.0' // New in v1.9.0
            )
        );
    }

    public static function get_system_health() {
        $health = array(
            'wp_version_ok' => version_compare(get_bloginfo('version'), '5.0', '>='),
            'wc_installed' => class_exists('WooCommerce'),
            'wc_version_ok' => false,
            'php_version_ok' => version_compare(PHP_VERSION, '7.0', '>='),
            'php_recommended' => version_compare(PHP_VERSION, '7.4', '>='),
            'ssl_enabled' => is_ssl(),
            'permalinks_ok' => get_option('permalink_structure') !== '',
            'curl_available' => function_exists('curl_init'),
            'json_available' => function_exists('json_encode') && function_exists('json_decode'),
            'json_constants_ok' => defined('JSON_UNESCAPED_UNICODE') && defined('JSON_SORT_KEYS'),
            'credentials_configured' => false,
            'iframe_id_configured' => false,
            'webhook_secret_configured' => false, // New in v1.8.0
            'subscriptions_enabled' => bna_subscriptions_enabled() // New in v1.9.0
        );

        if (class_exists('WooCommerce')) {
            $health['wc_version_ok'] = version_compare(WC()->version, '5.0', '>=');
        }

        $access_key = get_option('bna_smart_payment_access_key', '');
        $secret_key = get_option('bna_smart_payment_secret_key', '');
        $iframe_id = get_option('bna_smart_payment_iframe_id', '');
        $webhook_secret = get_option('bna_smart_payment_webhook_secret', '');

        $health['credentials_configured'] = !empty($access_key) && !empty($secret_key);
        $health['iframe_id_configured'] = !empty($iframe_id);
        $health['webhook_secret_configured'] = !empty($webhook_secret);

        return $health;
    }
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================

/**
 * Initialize the plugin
 */
function bna_smart_payment_init() {
    return BNA_Smart_Payment::get_instance();
}

bna_smart_payment_init();

/**
 * Get plugin instance
 */
function bna_smart_payment() {
    return BNA_Smart_Payment::get_instance();
}

/**
 * Get plugin information
 */
function bna_get_plugin_info() {
    return BNA_Smart_Payment::get_plugin_info();
}

/**
 * Get plugin configuration
 */
function bna_get_config() {
    return BNA_Smart_Payment::get_config();
}

/**
 * Get system health status
 */
function bna_get_system_health() {
    return BNA_Smart_Payment::get_system_health();
}

/**
 * Check if shipping address is enabled
 */
function bna_is_shipping_enabled() {
    return get_option('bna_smart_payment_enable_shipping_address', 'no') === 'yes';
}

/**
 * Check if debug mode is enabled
 */
function bna_is_debug_mode() {
    return get_option('bna_smart_payment_debug_mode', 'no') === 'yes';
}

/**
 * Check if subscriptions are enabled (NEW in v1.9.0)
 */
function bna_subscriptions_enabled() {
    return get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
}

/**
 * Get webhook secret key (NEW in v1.8.0)
 *
 * @return string Webhook secret key for HMAC verification
 */
function bna_get_webhook_secret() {
    return get_option('bna_smart_payment_webhook_secret', '');
}

/**
 * Check if webhook HMAC security is configured (NEW in v1.8.0)
 *
 * @return bool True if webhook secret is configured
 */
function bna_is_webhook_secure() {
    return !empty(bna_get_webhook_secret());
}

/**
 * Get customer sync status for an order
 */
function bna_get_customer_sync_status($order) {
    if (!$order) {
        return array('error' => 'Order not found');
    }

    return array(
        'customer_id' => $order->get_meta('_bna_customer_id'),
        'data_hash' => $order->get_meta('_bna_customer_data_hash'),
        'has_customer' => !empty($order->get_meta('_bna_customer_id')),
        'sync_available' => true,
        'last_update_attempt' => $order->get_meta('_bna_last_sync_attempt'),
        'last_successful_sync' => $order->get_meta('_bna_last_successful_sync')
    );
}

/**
 * Debug logging helper
 */
function bna_debug_log($message, $data = array()) {
    if (bna_is_debug_mode()) {
        bna_log('[DEBUG MODE] ' . $message, $data);
    }
}
