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

        add_rewrite_endpoint('payment-methods', EP_ROOT | EP_PAGES);
        add_filter('woocommerce_account_menu_items', array($this, 'add_payment_methods_tab'), 40);
        add_action('woocommerce_account_payment-methods_endpoint', array($this, 'payment_methods_content'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_bna_delete_payment_method', array($this, 'handle_delete_payment_method'));
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));

        bna_log('BNA My Account initialized', array(
            'endpoints_added' => true,
            'hooks_registered' => true
        ));
    }

    public function add_query_vars($vars) {
        $vars['payment-methods'] = 'payment-methods';
        return $vars;
    }

    public function add_payment_methods_tab($items) {
        $new_items = array();

        foreach ($items as $key => $item) {
            $new_items[$key] = $item;

            if ($key === 'edit-address') {
                $new_items['payment-methods'] = __('Payment Methods', 'bna-smart-payment');
            }
        }

        if (!isset($items['edit-address'])) {
            $new_items['payment-methods'] = __('Payment Methods', 'bna-smart-payment');
        }

        bna_debug('Payment methods tab added to My Account', array(
            'menu_items' => array_keys($new_items)
        ));

        return $new_items;
    }

    public function payment_methods_content() {
        if (!is_user_logged_in()) {
            bna_error('Unauthorized access to payment methods page');
            echo '<p>' . __('You must be logged in to view payment methods.', 'bna-smart-payment') . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $payment_methods = $this->payment_methods->get_user_payment_methods($user_id);

        bna_log('Loading payment methods page', array(
            'user_id' => $user_id,
            'methods_count' => count($payment_methods),
            'bna_customer_id' => get_user_meta($user_id, '_bna_customer_id', true)
        ));

        $template_file = BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/my-account-payment-methods.php';

        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="woocommerce-error">';
            echo '<p>' . __('Payment methods template not found.', 'bna-smart-payment') . '</p>';
            echo '</div>';

            bna_error('Payment methods template not found', array(
                'template_path' => $template_file
            ));
        }
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

        bna_debug('My Account scripts enqueued', array(
            'page' => 'account',
            'script_url' => BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/my-account-payment-methods.js'
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

        bna_log('Processing payment method deletion request', array(
            'user_id' => $user_id,
            'payment_method_id' => $payment_method_id
        ));

        $result = $this->payment_methods->delete_payment_method($user_id, $payment_method_id);

        if (is_wp_error($result)) {
            bna_error('Payment method deletion failed', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id,
                'error' => $result->get_error_message()
            ));

            wp_send_json_error($result->get_error_message());
        }

        if (is_array($result)) {
            $status = $result['status'] ?? 'unknown';
            $message = $result['message'] ?? 'Unknown status';

            if ($status === 'success') {
                bna_log('Payment method deleted successfully via My Account', array(
                    'user_id' => $user_id,
                    'payment_method_id' => $payment_method_id
                ));

                wp_send_json_success($message);
            } else {
                bna_error('Payment method deletion not completed', array(
                    'user_id' => $user_id,
                    'payment_method_id' => $payment_method_id,
                    'status' => $status,
                    'message' => $message
                ));

                wp_send_json_error($message);
            }
        }

        wp_send_json_error('Unexpected response format');
    }

    public function get_payment_method_display_name($method) {
        $type = strtolower($method['type'] ?? 'unknown');
        $last4 = $method['last4'] ?? '****';
        $brand = ucfirst(strtolower($method['brand'] ?? 'Unknown'));

        switch ($type) {
            case 'credit':
                return sprintf('%s Credit Card **** %s', $brand, $last4);

            case 'debit':
                return sprintf('%s Debit Card **** %s', $brand, $last4);

            case 'eft':
                return sprintf('Bank Transfer **** %s', $last4);

            case 'e_transfer':
                return __('E-Transfer', 'bna-smart-payment');

            default:
                if ($brand !== 'Unknown') {
                    return sprintf('%s **** %s', $brand, $last4);
                }
                return sprintf('Payment Method **** %s', $last4);
        }
    }

    public function get_payment_method_icon($method) {
        $type = strtolower($method['type'] ?? 'unknown');
        $brand = strtolower($method['brand'] ?? 'unknown');

        $icon_map = array(
            'visa' => '💳',
            'mastercard' => '💳',
            'amex' => '💳',
            'american express' => '💳',
            'discover' => '💳',
            'jcb' => '💳',
            'diners' => '💳',
            'credit' => '💳',
            'debit' => '💳',
            'eft' => '🏦',
            'e_transfer' => '✉️',
            'cheque' => '📝',
            'cash' => '💵'
        );

        if (isset($icon_map[$brand])) {
            return $icon_map[$brand];
        }

        if (isset($icon_map[$type])) {
            return $icon_map[$type];
        }

        return '💳';
    }
}