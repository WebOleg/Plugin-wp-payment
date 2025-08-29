<?php
/**
 * BNA Gateway V4
 * Birthdate field integrated into WooCommerce checkout fields
 */

if (!defined('ABSPATH')) exit;

class BNA_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'bna_smart_payment';
        $this->icon = '';
        $this->has_fields = false; // No custom payment fields
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment system.';
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();
        $this->init_hooks();

        bna_wc_debug('BNA Gateway initialized', [
            'gateway_id' => $this->id,
            'enabled' => $this->enabled,
            'has_credentials' => $this->has_required_settings()
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

        // Customer detail toggles
        $this->enable_phone = $this->get_option('enable_phone');
        $this->enable_billing_address = $this->get_option('enable_billing_address');
        $this->enable_birthdate = $this->get_option('enable_birthdate');

        bna_wc_debug('Gateway settings loaded', [
            'enabled' => $this->enabled,
            'environment' => $this->environment,
            'customer_toggles' => [
                'phone' => $this->enable_phone,
                'billing_address' => $this->enable_billing_address,
                'birthdate' => $this->enable_birthdate
            ]
        ]);
    }

    private function init_hooks() {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'checkout_scripts'));
        
        // Add birthdate field to checkout if enabled
        if ($this->is_birthdate_enabled()) {
            add_filter('woocommerce_billing_fields', array($this, 'add_birthdate_billing_field'));
            add_action('woocommerce_checkout_process', array($this, 'validate_birthdate_field'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_birthdate_field'));
        }

        bna_wc_debug('Gateway hooks initialized');
    }

    /**
     * Add birthdate field to billing fields
     */
    public function add_birthdate_billing_field($fields) {
        $fields['billing_birthdate'] = array(
            'label'     => __('Date of Birth', 'bna-smart-payment'),
            'placeholder' => __('YYYY-MM-DD', 'bna-smart-payment'),
            'required'  => true,
            'class'     => array('form-row-wide'),
            'type'      => 'date',
            'priority'  => 25, // After email, before phone
            'validate'  => array('required'),
            'custom_attributes' => array(
                'max' => date('Y-m-d', strtotime('-18 years'))
            )
        );

        bna_wc_debug('Birthdate field added to billing fields');
        return $fields;
    }

    /**
     * Validate birthdate field during checkout
     */
    public function validate_birthdate_field() {
        // Only validate if BNA payment method is selected
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        if (empty($_POST['billing_birthdate'])) {
            wc_add_notice(__('Date of birth is required for BNA Smart Payment.', 'bna-smart-payment'), 'error');
            return;
        }

        $birthdate = sanitize_text_field($_POST['billing_birthdate']);
        $birth_timestamp = strtotime($birthdate);

        if ($birth_timestamp === false) {
            wc_add_notice(__('Please enter a valid date of birth.', 'bna-smart-payment'), 'error');
            return;
        }

        // Check minimum age (18 years)
        $eighteen_years_ago = strtotime('-18 years');
        if ($birth_timestamp > $eighteen_years_ago) {
            wc_add_notice(__('You must be at least 18 years old to use BNA Smart Payment.', 'bna-smart-payment'), 'error');
            return;
        }

        // Check if date is not in future
        if ($birth_timestamp > time()) {
            wc_add_notice(__('Birth date cannot be in the future.', 'bna-smart-payment'), 'error');
            return;
        }

        bna_wc_debug('Birthdate validation passed', [
            'birthdate' => $birthdate,
            'age_valid' => true
        ]);
    }

    /**
     * Save birthdate field to order meta
     */
    public function save_birthdate_field($order_id) {
        if (!empty($_POST['billing_birthdate'])) {
            $order = wc_get_order($order_id);
            $birthdate = sanitize_text_field($_POST['billing_birthdate']);
            $order->add_meta_data('_billing_birthdate', $birthdate);
            $order->save();

            bna_wc_debug('Birthdate saved to order', [
                'order_id' => $order_id,
                'birthdate' => $birthdate
            ]);
        }
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
                ),
                'description' => 'Select the environment for API calls.'
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
            'customer_details_section' => array(
                'title' => 'Customer Details',
                'type' => 'title',
                'description' => 'Configure which customer details to collect and send to BNA API.',
            ),
            'enable_phone' => array(
                'title' => 'Phone Number',
                'type' => 'checkbox',
                'label' => 'Send phone number to BNA API',
                'default' => 'no',
                'description' => 'Include phone number in payment data.'
            ),
            'enable_billing_address' => array(
                'title' => 'Billing Address',
                'type' => 'checkbox',
                'label' => 'Send billing address to BNA API',
                'default' => 'no',
                'description' => 'Include billing address in payment data.'
            ),
            'enable_birthdate' => array(
                'title' => 'Birth Date',
                'type' => 'checkbox',
                'label' => 'Require customer birth date',
                'default' => 'yes',
                'description' => 'Add required birthdate field to checkout and send to BNA API.'
            ),
        );
    }

    public function process_admin_options() {
        bna_wc_log('Processing gateway admin options update');
        $saved = parent::process_admin_options();

        if ($saved) {
            $this->save_global_options();
            bna_wc_log('Gateway admin options saved successfully');
        }

        return $saved;
    }

    private function save_global_options() {
        update_option('bna_smart_payment_environment', $this->get_option('environment'));
        update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
        update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
        update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));
        update_option('bna_smart_payment_enable_phone', $this->get_option('enable_phone'));
        update_option('bna_smart_payment_enable_billing_address', $this->get_option('enable_billing_address'));
        update_option('bna_smart_payment_enable_birthdate', $this->get_option('enable_birthdate'));

        bna_wc_debug('Global gateway options updated');
    }

    public function is_available() {
        return $this->enabled === 'yes' && 
               class_exists('WooCommerce') && 
               $this->has_required_settings();
    }

    private function has_required_settings() {
        return !empty($this->access_key) && 
               !empty($this->secret_key) && 
               !empty($this->iframe_id);
    }

    private function is_birthdate_enabled() {
        return get_option('bna_smart_payment_enable_birthdate') === 'yes';
    }

    public function process_payment($order_id) {
        bna_wc_log('Processing payment started', ['order_id' => $order_id]);

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice('Order not found.', 'error');
            return array('result' => 'fail');
        }

        try {
            $payment_controller = new BNA_Payment_Controller();
            $validation = $payment_controller->validate_order_for_payment($order);

            if (!$validation['valid']) {
                foreach ($validation['errors'] as $error) {
                    wc_add_notice($error, 'error');
                }
                return array('result' => 'fail');
            }

            $this->prepare_order($order);
            $redirect_url = BNA_URL_Handler::get_payment_url($order);

            bna_wc_log('Payment process completed', ['order_id' => $order->get_id()]);

            return array(
                'result' => 'success',
                'redirect' => $redirect_url
            );

        } catch (Exception $e) {
            bna_wc_error('Payment processing exception', [
                'order_id' => $order->get_id(),
                'error' => $e->getMessage()
            ]);

            wc_add_notice('Payment processing error. Please try again.', 'error');
            return array('result' => 'fail');
        }
    }

    private function prepare_order($order) {
        if ($order->get_status() !== 'pending') {
            $order->update_status('pending', 'Awaiting BNA Smart Payment.');
        }

        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->add_meta_data('_bna_payment_started_at', current_time('timestamp'));
        $order->save();
    }

    public function checkout_scripts() {
        if (is_admin()) return;

        wp_enqueue_style('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css', array(), BNA_SMART_PAYMENT_VERSION);
        wp_enqueue_script('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), BNA_SMART_PAYMENT_VERSION, true);
    }

    public function admin_options() {
        echo '<h3>BNA Smart Payment</h3>';
        echo '<p>Accept payments through BNA Smart Payment gateway with secure iFrame integration.</p>';
        $this->display_status_notice();
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    private function display_status_notice() {
        $missing = $this->get_missing_settings();
        if (empty($missing)) {
            echo '<div class="notice notice-success"><p><strong>Status:</strong> ✅ Gateway configured and ready</p></div>';
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
