<?php
/**
 * BNA Smart Payment Gateway class
 * 
 * Simplified gateway focused only on WooCommerce integration
 *
 * @package BnaSmartPayment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA_Gateway class extends WooCommerce payment gateway
 */
class BNA_Gateway extends WC_Payment_Gateway {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'bna_smart_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment system with iframe support.';

        // Define supported features
        $this->supports = array(
            'products'
        );

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Get settings values
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->environment = $this->get_option('environment');
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->iframe_id = $this->get_option('iframe_id');

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Save admin options
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Initialize gateway form fields for admin
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable BNA Smart Payment',
                'default' => 'no'
            ),
            'title' => array(
                'title'       => 'Title',
                'type'        => 'text',
                'description' => 'This controls the title customers see during checkout.',
                'default'     => 'BNA Smart Payment',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Description',
                'type'        => 'textarea',
                'description' => 'Payment method description that customers will see on your checkout.',
                'default'     => 'Secure online payments via BNA Smart Payment',
                'desc_tip'    => true,
            ),
            'environment' => array(
                'title'       => 'Environment',
                'type'        => 'select',
                'description' => 'Select the environment for API requests.',
                'default'     => 'staging',
                'desc_tip'    => true,
                'options'     => array(
                    'dev'        => 'Development',
                    'staging'    => 'Staging (Test)',
                    'production' => 'Production (Live)'
                )
            ),
            'access_key' => array(
                'title'       => 'Access Key',
                'type'        => 'text',
                'description' => 'Your Access Key from BNA Merchant Portal.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'secret_key' => array(
                'title'       => 'Secret Key',
                'type'        => 'password',
                'description' => 'Your Secret Key from BNA Merchant Portal.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'iframe_id' => array(
                'title'       => 'iFrame ID',
                'type'        => 'text',
                'description' => 'Your iFrame ID from BNA Merchant Portal.',
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Process admin options and save globally
     */
    public function process_admin_options() {
        $saved = parent::process_admin_options();

        if ($saved) {
            update_option('bna_smart_payment_environment', $this->get_option('environment'));
            update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
            update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
            update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));
        }

        return $saved;
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }

        if (empty($this->access_key) || empty($this->secret_key) || empty($this->iframe_id)) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Process payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wc_add_notice('Order not found.', 'error');
            return array('result' => 'fail');
        }

        // Mark order as pending payment
        BNA_WooCommerce_Helper::update_order_status($order, 'pending', 'Awaiting BNA Smart Payment.');

        // Store payment method info
        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->save();

        // Redirect to payment page
        return array(
            'result'   => 'success',
            'redirect' => BNA_WooCommerce_Helper::get_payment_url($order)
        );
    }

    /**
     * Payment fields on checkout page
     */
    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        echo '<div class="bna-payment-info">';
        echo '<p><small>' . __('You will be redirected to a secure payment form.', 'bna-smart-payment') . '</small></p>';
        echo '</div>';
    }

    /**
     * Admin options display
     */
    public function admin_options() {
        echo '<h3>BNA Smart Payment</h3>';
        echo '<p>Accept payments through BNA Smart Payment gateway with iframe integration.</p>';
        
        // Status check
        $missing = array();
        if (empty($this->access_key)) $missing[] = 'Access Key';
        if (empty($this->secret_key)) $missing[] = 'Secret Key';
        if (empty($this->iframe_id)) $missing[] = 'iFrame ID';
        
        if (empty($missing)) {
            echo '<div class="notice notice-success"><p><strong>Status:</strong> Gateway configured and ready</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>Missing:</strong> ' . implode(', ', $missing) . '</p></div>';
        }

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }
}
