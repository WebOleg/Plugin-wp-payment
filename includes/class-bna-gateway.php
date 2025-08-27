<?php
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

        BNA_Logger::debug('BNA Gateway initialized', [
            'gateway_id' => $this->id,
            'enabled' => $this->enabled
        ]);
    }

    private function load_settings() {
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->environment = $this->get_option('environment');
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->iframe_id = $this->get_option('iframe_id');

        BNA_Logger::debug('Gateway settings loaded', [
            'enabled' => $this->enabled,
            'environment' => $this->environment,
            'has_access_key' => !empty($this->access_key),
            'has_secret_key' => !empty($this->secret_key),
            'has_iframe_id' => !empty($this->iframe_id)
        ]);
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
        BNA_Logger::info('Processing admin options update');

        $saved = parent::process_admin_options();
        
        if ($saved) {
            $this->save_global_options();
            BNA_Logger::info('Admin options saved successfully', [
                'environment' => $this->get_option('environment'),
                'has_credentials' => !empty($this->get_option('access_key')) && !empty($this->get_option('secret_key'))
            ]);
        } else {
            BNA_Logger::error('Failed to save admin options');
        }

        return $saved;
    }

    private function save_global_options() {
        update_option('bna_smart_payment_environment', $this->get_option('environment'));
        update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
        update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
        update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));

        BNA_Logger::debug('Global options updated');
    }

    public function is_available() {
        $is_available = $this->enabled === 'yes' && $this->has_required_settings();
        
        BNA_Logger::debug('Gateway availability check', [
            'enabled' => $this->enabled === 'yes',
            'has_required_settings' => $this->has_required_settings(),
            'is_available' => $is_available
        ]);

        return $is_available;
    }

    private function has_required_settings() {
        $has_settings = !empty($this->access_key) && !empty($this->secret_key) && !empty($this->iframe_id);
        
        if (!$has_settings) {
            BNA_Logger::warning('Missing required gateway settings', [
                'has_access_key' => !empty($this->access_key),
                'has_secret_key' => !empty($this->secret_key),
                'has_iframe_id' => !empty($this->iframe_id)
            ]);
        }

        return $has_settings;
    }

    public function process_payment($order_id) {
        BNA_Logger::info('Processing payment started', [
            'order_id' => $order_id,
            'gateway_id' => $this->id
        ]);

        $order = wc_get_order($order_id);
        
        if (!$order) {
            BNA_Logger::error('Order not found for payment processing', [
                'order_id' => $order_id
            ]);
            
            wc_add_notice('Order not found.', 'error');
            return array('result' => 'fail');
        }

        BNA_Logger::debug('Order found for processing', [
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'order_status' => $order->get_status(),
            'customer_email' => $order->get_billing_email()
        ]);

        try {
            // Prepare order for BNA processing
            $this->prepare_order($order);

            $redirect_url = BNA_URL_Handler::get_payment_url($order);

            BNA_Logger::info('Payment process completed, redirecting to payment page', [
                'order_id' => $order->get_id(),
                'redirect_url' => $redirect_url
            ]);

            return array(
                'result' => 'success',
                'redirect' => $redirect_url
            );

        } catch (Exception $e) {
            BNA_Logger::error('Payment processing error', [
                'order_id' => $order->get_id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            wc_add_notice('Payment processing error. Please try again.', 'error');
            return array('result' => 'fail');
        }
    }

    private function prepare_order($order) {
        BNA_Logger::debug('Preparing order for BNA payment', [
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status()
        ]);

        // Update order status
        BNA_WooCommerce_Helper::update_order_status($order, 'pending', 'Awaiting BNA Smart Payment.');
        
        // Add meta data
        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->add_meta_data('_bna_payment_started_at', current_time('timestamp'));
        $order->save();

        BNA_Logger::info('Order prepared for BNA payment', [
            'order_id' => $order->get_id(),
            'new_status' => $order->get_status()
        ]);
    }

    public function checkout_scripts() {
        wp_enqueue_style('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css', array(), BNA_SMART_PAYMENT_VERSION);
        wp_enqueue_script('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), BNA_SMART_PAYMENT_VERSION, true);

        BNA_Logger::debug('Checkout scripts enqueued');
    }

    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }
        echo '<p><small>You will be redirected to complete your payment.</small></p>';

        BNA_Logger::debug('Payment fields displayed');
    }

    public function admin_options() {
        echo '<h3>BNA Smart Payment</h3>';
        echo '<p>Accept payments through BNA Smart Payment gateway.</p>';
        
        $this->display_status_notice();

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        BNA_Logger::debug('Admin options page displayed');
    }

    private function display_status_notice() {
        $missing = $this->get_missing_settings();
        
        if (empty($missing)) {
            echo '<div class="notice notice-success"><p><strong>Status:</strong> Gateway configured and ready</p></div>';
            BNA_Logger::debug('Gateway status: configured and ready');
        } else {
            echo '<div class="notice notice-warning"><p><strong>Missing:</strong> ' . implode(', ', $missing) . '</p></div>';
            BNA_Logger::warning('Gateway status: missing configuration', [
                'missing_settings' => $missing
            ]);
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
