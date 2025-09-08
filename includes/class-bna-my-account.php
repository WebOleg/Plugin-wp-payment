<?php

if (!defined('ABSPATH')) exit;

class BNA_My_Account {

    private static $instance = null;
    private $payment_methods;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->payment_methods = BNA_Payment_Methods::get_instance();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('woocommerce_account_menu_items', array($this, 'add_payment_methods_tab'), 40);
        add_action('woocommerce_account_payment-methods_endpoint', array($this, 'payment_methods_content'));
        add_action('init', array($this, 'add_endpoint'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_bna_delete_payment_method', array($this, 'handle_delete_payment_method'));
    }

    public function add_endpoint() {
        add_rewrite_endpoint('payment-methods', EP_ROOT | EP_PAGES);
    }

    public function add_payment_methods_tab($items) {
        $new_items = array();
        
        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            
            if ($key === 'edit-address') {
                $new_items['payment-methods'] = __('Payment Methods', 'bna-smart-payment');
            }
        }
        
        return $new_items;
    }

    public function payment_methods_content() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $payment_methods = $this->payment_methods->get_user_payment_methods($user_id);

        BNA_Template::load('my-account-payment-methods', array(
            'payment_methods' => $payment_methods,
            'user_id' => $user_id
        ));
    }

    public function enqueue_scripts() {
        if (!is_account_page()) {
            return;
        }

        wp_enqueue_script(
            'bna-my-account-payment-methods',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/my-account-payment-methods.js',
            array('jquery'),
            BNA_SMART_PAYMENT_VERSION,
            true
        );

        wp_localize_script('bna-my-account-payment-methods', 'bna_my_account', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bna_delete_payment_method'),
            'messages' => array(
                'confirm_delete' => __('Are you sure you want to delete this payment method?', 'bna-smart-payment'),
                'deleting' => __('Deleting...', 'bna-smart-payment'),
                'error' => __('Error deleting payment method. Please try again.', 'bna-smart-payment'),
                'success' => __('Payment method deleted successfully.', 'bna-smart-payment')
            )
        ));
    }

    public function handle_delete_payment_method() {
        check_ajax_referer('bna_delete_payment_method', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not logged in');
        }

        $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
        if (empty($payment_method_id)) {
            wp_send_json_error('Invalid payment method ID');
        }

        $user_id = get_current_user_id();
        $result = $this->payment_methods->delete_payment_method($user_id, $payment_method_id);

        if (is_wp_error($result)) {
            bna_error('Payment method deletion failed', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id,
                'error' => $result->get_error_message()
            ));
            
            wp_send_json_error($result->get_error_message());
        }

        bna_log('Payment method deleted successfully', array(
            'user_id' => $user_id,
            'payment_method_id' => $payment_method_id
        ));

        wp_send_json_success('Payment method deleted successfully');
    }

    public function get_payment_method_display_name($method) {
        $type = strtolower($method['type']);
        $last4 = $method['last4'];
        $brand = $method['brand'];

        switch ($type) {
            case 'credit':
            case 'debit':
                return sprintf('%s **** %s', $brand, $last4);
            case 'eft':
                return sprintf('Bank Transfer **** %s', $last4);
            case 'e_transfer':
                return 'E-Transfer';
            default:
                return sprintf('%s **** %s', $brand, $last4);
        }
    }

    public function get_payment_method_icon($method) {
        $type = strtolower($method['type']);
        $brand = strtolower($method['brand']);

        $icons = array(
            'visa' => '💳',
            'mastercard' => '💳',
            'amex' => '💳',
            'discover' => '💳',
            'eft' => '🏦',
            'e_transfer' => '📧'
        );

        if (isset($icons[$brand])) {
            return $icons[$brand];
        }

        if (in_array($type, array('credit', 'debit'))) {
            return '💳';
        }

        if ($type === 'eft') {
            return '🏦';
        }

        if ($type === 'e_transfer') {
            return '📧';
        }

        return '💳';
    }
}
