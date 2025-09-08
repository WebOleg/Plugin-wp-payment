<?php
/**
 * Plugin Name: BNA Smart Payment Gateway
 * Plugin URI: https://bnasmartpayment.com
 * Description: WooCommerce payment gateway for BNA Smart Payment with iframe, webhooks, shipping address support, customer data sync and payment methods management.
 * Version: 1.7.0
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

define('BNA_SMART_PAYMENT_VERSION', '1.7.0');
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

        $request_uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        return preg_match('/^bna-payment\/\d+\/[a-zA-Z0-9_-]+\/?$/', $request_uri);
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

        if (false === get_option('bna_smart_payment_webhook_secret')) {
            add_option('bna_smart_payment_webhook_secret', wp_generate_password(32, false));
        }

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
            'bna_smart_payment_enable_phone' => 'no',
            'bna_smart_payment_enable_billing_address' => 'no',
            'bna_smart_payment_enable_birthdate' => 'yes',
            'bna_smart_payment_enable_shipping_address' => 'no',
            'bna_smart_payment_debug_mode' => 'no'
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

            update_option('bna_smart_payment_version', BNA_SMART_PAYMENT_VERSION);
            bna_log('Plugin upgrade completed', array('new_version' => BNA_SMART_PAYMENT_VERSION));
        }
    }

    private function upgrade_to_1_5_0() {
        if (false === get_option('bna_smart_payment_enable_shipping_address')) {
            add_option('bna_smart_payment_enable_shipping_address', 'no');
        }
    }

    private function upgrade_to_1_6_0() {
        bna_log('Upgrade to 1.6.0: Customer data sync feature added');

        $orders = wc_get_orders(array(
            'limit' => 10,
            'status' => array('pending', 'on-hold', 'processing'),
            'payment_method' => 'bna_smart_payment'
        ));

        foreach ($orders as $order) {
            if (!$order->get_meta('_bna_customer_data_hash') && $order->get_meta('_bna_customer_id')) {
                $order->add_meta_data('_bna_customer_data_hash', '');
                $order->save();
            }
        }

        bna_log('Upgrade to 1.6.0 completed', array(
            'processed_orders' => count($orders)
        ));
    }

    private function upgrade_to_1_6_1() {
        bna_log('Upgrade to 1.6.1: Improved error handling and country mapping');

        if (false === get_option('bna_smart_payment_debug_mode')) {
            add_option('bna_smart_payment_debug_mode', 'no');
        }

        $orders = wc_get_orders(array(
            'limit' => 50,
            'status' => array('pending', 'on-hold'),
            'payment_method' => 'bna_smart_payment',
            'meta_query' => array(
                array(
                    'key' => '_bna_customer_data_hash',
                    'compare' => 'EXISTS'
                )
            )
        ));

        $cleared_count = 0;
        foreach ($orders as $order) {
            $order->update_meta_data('_bna_customer_data_hash', '');
            $order->save();
            $cleared_count++;
        }

        bna_log('Upgrade to 1.6.1 completed', array(
            'cleared_hashes' => $cleared_count,
            'improvements' => array(
                'country_code_mapping',
                'phone_number_processing',
                'api_error_handling',
                'address_validation'
            )
        ));
    }

    private function upgrade_to_1_7_0() {
        bna_log('Upgrade to 1.7.0: Payment methods management added');

        $users = get_users(array(
            'meta_key' => '_bna_customer_id',
            'meta_compare' => 'EXISTS',
            'number' => 100
        ));

        foreach ($users as $user) {
            if (!get_user_meta($user->ID, '_bna_payment_methods', true)) {
                update_user_meta($user->ID, '_bna_payment_methods', array());
            }
        }

        bna_log('Upgrade to 1.7.0 completed', array(
            'processed_users' => count($users),
            'features_added' => array(
                'payment_methods_management',
                'my_account_integration',
                'webhook_payment_method_saving'
            )
        ));
    }

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
            bna_error('Gateway not available for payment request', array(
                'order_id' => $order_id
            ));
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key || $order->get_payment_method() !== 'bna_smart_payment') {
            bna_error('Order validation failed', array(
                'order_id' => $order_id,
                'order_exists' => !empty($order),
                'key_match' => $order ? ($order->get_order_key() === $order_key) : false,
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
            'payment_methods_management' => true
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
            'customer_sync' => '1.6.0',
            'error_handling' => '1.6.1',
            'payment_methods' => '1.7.0'
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
            'iframe_id_configured' => false
        );

        if (class_exists('WooCommerce')) {
            $health['wc_version_ok'] = version_compare(WC()->version, '5.0', '>=');
        }

        $access_key = get_option('bna_smart_payment_access_key', '');
        $secret_key = get_option('bna_smart_payment_secret_key', '');
        $iframe_id = get_option('bna_smart_payment_iframe_id', '');

        $health['credentials_configured'] = !empty($access_key) && !empty($secret_key);
        $health['iframe_id_configured'] = !empty($iframe_id);

        return $health;
    }
}

function bna_smart_payment_init() {
    return BNA_Smart_Payment::get_instance();
}

bna_smart_payment_init();

function bna_smart_payment() {
    return BNA_Smart_Payment::get_instance();
}

function bna_get_plugin_info() {
    return BNA_Smart_Payment::get_plugin_info();
}

function bna_get_config() {
    return BNA_Smart_Payment::get_config();
}

function bna_get_system_health() {
    return BNA_Smart_Payment::get_system_health();
}

function bna_is_shipping_enabled() {
    return get_option('bna_smart_payment_enable_shipping_address', 'no') === 'yes';
}

function bna_is_debug_mode() {
    return get_option('bna_smart_payment_debug_mode', 'no') === 'yes';
}

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

function bna_debug_log($message, $data = array()) {
    if (bna_is_debug_mode()) {
        bna_log('[DEBUG MODE] ' . $message, $data);
    }
}

function bna_validate_customer_data($customer_data) {
    $required_fields = array('firstName', 'lastName', 'email', 'type');
    $missing_fields = array();

    foreach ($required_fields as $field) {
        if (empty($customer_data[$field])) {
            $missing_fields[] = $field;
        }
    }

    if (!empty($missing_fields)) {
        return new WP_Error(
            'missing_required_fields',
            'Missing required customer fields: ' . implode(', ', $missing_fields),
            $missing_fields
        );
    }

    if (!filter_var($customer_data['email'], FILTER_VALIDATE_EMAIL)) {
        return new WP_Error('invalid_email', 'Invalid email format');
    }

    return true;
}

function bna_format_error_message($error) {
    if (is_wp_error($error)) {
        $message = $error->get_error_message();

        $translations = array(
            'Invalid country code' => __('Please check your address information and try again.', 'bna-smart-payment'),
            'Internal Server Error' => __('Payment system temporarily unavailable. Please try again.', 'bna-smart-payment'),
            'Customer already exist' => __('Account already exists. Please continue with payment.', 'bna-smart-payment'),
            'Invalid phone number' => __('Please check your phone number and try again.', 'bna-smart-payment')
        );

        foreach ($translations as $api_error => $user_message) {
            if (stripos($message, $api_error) !== false) {
                return $user_message;
            }
        }

        return $message;
    }

    return (string) $error;
}
