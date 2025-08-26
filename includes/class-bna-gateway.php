<?php
/**
 * BNA Smart Payment Gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'bna_smart_payment';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment system.';
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();
        $this->init_hooks();
    }

    private function load_settings() {
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->environment = $this->get_option('environment');
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->iframe_id = $this->get_option('iframe_id');
    }

    private function init_hooks() {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'checkout_scripts'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => 'Enable/Disable',
                'type' => 'checkbox',
                'label' => 'Enable BNA Smart Payment',
                'default' => 'no'
            ),
            'title' => array(
                'title' => 'Title',
                'type' => 'text',
                'description' => 'This controls the title customers see during checkout.',
                'default' => 'BNA Smart Payment',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description.',
                'default' => 'Secure online payments via BNA Smart Payment',
                'desc_tip' => true,
            ),
            'environment' => array(
                'title' => 'Environment',
                'type' => 'select',
                'default' => 'staging',
                'options' => array(
                    'dev' => 'Development',
                    'staging' => 'Staging (Test)',
                    'production' => 'Production (Live)'
                )
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'Your Access Key from BNA Portal.',
                'default' => '',
            ),
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'description' => 'Your Secret Key from BNA Portal.',
                'default' => '',
            ),
            'iframe_id' => array(
                'title' => 'iFrame ID',
                'type' => 'text',
                'description' => 'Your iFrame ID from BNA Portal.',
                'default' => '',
            ),
        );
    }

    public function process_admin_options() {
        $saved = parent::process_admin_options();
        if ($saved) {
            $this->save_global_options();
        }
        return $saved;
    }

    private function save_global_options() {
        update_option('bna_smart_payment_environment', $this->get_option('environment'));
        update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
        update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
        update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));
    }

    public function is_available() {
        return $this->enabled === 'yes' && $this->has_required_settings();
    }

    private function has_required_settings() {
        return !empty($this->access_key) && !empty($this->secret_key) && !empty($this->iframe_id);
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wc_add_notice('Order not found.', 'error');
            return array('result' => 'fail');
        }

        $this->prepare_order($order);

        return array(
            'result' => 'success',
            'redirect' => BNA_URL_Handler::get_payment_url($order)
        );
    }

    private function prepare_order($order) {
        BNA_WooCommerce_Helper::update_order_status($order, 'pending', 'Awaiting BNA Smart Payment.');
        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->save();
    }

    public function checkout_scripts() {
        wp_enqueue_style('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css', array(), BNA_SMART_PAYMENT_VERSION);
        wp_enqueue_script('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), BNA_SMART_PAYMENT_VERSION, true);
    }

    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }
        echo '<p><small>You will be redirected to complete your payment.</small></p>';
    }

    public function admin_options() {
        echo '<h3>BNA Smart Payment</h3>';
        echo '<p>Accept payments through BNA Smart Payment gateway.</p>';
        
        $this->display_status_notice();

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    private function display_status_notice() {
        $missing = $this->get_missing_settings();
        
        if (empty($missing)) {
            echo '<div class="notice notice-success"><p><strong>Status:</strong> Gateway configured and ready</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>Missing:</strong> ' . implode(', ', $missing) . '</p></div>';
        }
    }

    private function get_missing_settings() {
        $missing = array();
        if (empty($this->access_key)) $missing[] = 'Access Key';
        if (empty($this->secret_key)) $missing[] = 'Secret Key';
        if (empty($this->iframe_id)) $missing[] = 'iFrame ID';
        return $missing;
    }
}
