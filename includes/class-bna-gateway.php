<?php
/**
 * BNA Gateway V2
 * Enhanced with new logging system and customer detail toggles
 */

if (!defined('ABSPATH')) exit;

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
            'has_access_key' => !empty($this->access_key),
            'has_secret_key' => !empty($this->secret_key),
            'has_iframe_id' => !empty($this->iframe_id),
            'title' => $this->title,
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

        bna_wc_debug('Gateway hooks initialized');
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
                'description' => 'Configure which customer details to collect in the payment form.',
            ),
            'enable_phone' => array(
                'title' => 'Phone Number',
                'type' => 'checkbox',
                'label' => 'Enable phone number field',
                'default' => 'no',
                'description' => 'Show phone number field in the payment form.'
            ),
            'enable_billing_address' => array(
                'title' => 'Billing Address',
                'type' => 'checkbox',
                'label' => 'Enable billing address fields',
                'default' => 'no',
                'description' => 'Show billing address fields in the payment form.'
            ),
            'enable_birthdate' => array(
                'title' => 'Birth Date',
                'type' => 'checkbox',
                'label' => 'Enable birthdate field',
                'default' => 'yes',
                'description' => 'Show birthdate field in the payment form.'
            ),
        );
    }

    public function process_admin_options() {
        bna_wc_log('Processing gateway admin options update');

        $saved = parent::process_admin_options();

        if ($saved) {
            $this->save_global_options();

            bna_wc_log('Gateway admin options saved successfully', [
                'environment' => $this->get_option('environment'),
                'has_credentials' => !empty($this->get_option('access_key')) && !empty($this->get_option('secret_key')),
                'has_iframe_id' => !empty($this->get_option('iframe_id')),
                'customer_toggles' => [
                    'phone' => $this->get_option('enable_phone'),
                    'billing_address' => $this->get_option('enable_billing_address'),
                    'birthdate' => $this->get_option('enable_birthdate')
                ]
            ]);
        } else {
            bna_wc_error('Failed to save gateway admin options');
        }

        return $saved;
    }

    private function save_global_options() {
        update_option('bna_smart_payment_environment', $this->get_option('environment'));
        update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
        update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
        update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));

        // Save customer detail toggles
        update_option('bna_smart_payment_enable_phone', $this->get_option('enable_phone'));
        update_option('bna_smart_payment_enable_billing_address', $this->get_option('enable_billing_address'));
        update_option('bna_smart_payment_enable_birthdate', $this->get_option('enable_birthdate'));

        bna_wc_debug('Global gateway options updated');
    }

    public function is_available() {
        $has_wc = class_exists('WooCommerce');
        $is_enabled = $this->enabled === 'yes';
        $has_settings = $this->has_required_settings();
        $is_available = $has_wc && $is_enabled && $has_settings;

        bna_wc_debug('Gateway availability check', [
            'has_woocommerce' => $has_wc,
            'is_enabled' => $is_enabled,
            'has_required_settings' => $has_settings,
            'is_available' => $is_available
        ]);

        if (!$is_available && $is_enabled) {
            bna_wc_error('Gateway not available despite being enabled', [
                'missing_settings' => $this->get_missing_settings()
            ]);
        }

        return $is_available;
    }

    private function has_required_settings() {
        $required = [
            'access_key' => !empty($this->access_key),
            'secret_key' => !empty($this->secret_key),
            'iframe_id' => !empty($this->iframe_id)
        ];

        return array_reduce($required, function($carry, $item) {
            return $carry && $item;
        }, true);
    }

    public function process_payment($order_id) {
        bna_wc_log('Processing payment started', [
            'order_id' => $order_id,
            'gateway_id' => $this->id,
            'method' => 'iframe'
        ]);

        $order = wc_get_order($order_id);

        if (!$order) {
            bna_wc_error('Order not found for payment processing', [
                'order_id' => $order_id
            ]);

            wc_add_notice('Order not found.', 'error');
            return array('result' => 'fail');
        }

        // Log order details
        bna_wc_debug('Order found for processing', [
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'order_status' => $order->get_status(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
        ]);

        try {
            // Validate order first
            $payment_controller = new BNA_Payment_Controller();
            $validation = $payment_controller->validate_order_for_payment($order);

            if (!$validation['valid']) {
                bna_wc_error('Order validation failed', [
                    'order_id' => $order->get_id(),
                    'errors' => $validation['errors']
                ]);

                foreach ($validation['errors'] as $error) {
                    wc_add_notice($error, 'error');
                }
                return array('result' => 'fail');
            }

            // Prepare order for BNA processing
            $this->prepare_order($order);

            $redirect_url = BNA_URL_Handler::get_payment_url($order);

            bna_wc_log('Payment process completed, redirecting to payment page', [
                'order_id' => $order->get_id(),
                'redirect_url' => $redirect_url,
                'success' => true
            ]);

            return array(
                'result' => 'success',
                'redirect' => $redirect_url
            );

        } catch (Exception $e) {
            bna_wc_error('Payment processing exception', [
                'order_id' => $order->get_id(),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            wc_add_notice('Payment processing error. Please try again.', 'error');
            return array('result' => 'fail');
        }
    }

    private function prepare_order($order) {
        bna_wc_debug('Preparing order for BNA payment', [
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status()
        ]);

        // Update order status if needed
        if ($order->get_status() !== 'pending') {
            $order->update_status('pending', 'Awaiting BNA Smart Payment.');
        }

        // Add meta data
        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->add_meta_data('_bna_payment_started_at', current_time('timestamp'));
        $order->save();

        bna_wc_log('Order prepared for BNA payment', [
            'order_id' => $order->get_id(),
            'new_status' => $order->get_status(),
            'payment_method' => 'iframe'
        ]);
    }

    public function checkout_scripts() {
        if (is_admin()) return;

        wp_enqueue_style('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css', array(), BNA_SMART_PAYMENT_VERSION);
        wp_enqueue_script('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), BNA_SMART_PAYMENT_VERSION, true);

        bna_wc_debug('Gateway checkout scripts enqueued');
    }

    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }
        echo '<p><small>You will be redirected to complete your payment securely.</small></p>';

        bna_wc_debug('Payment fields displayed');
    }

    public function admin_options() {
        echo '<h3>BNA Smart Payment</h3>';
        echo '<p>Accept payments through BNA Smart Payment gateway with secure iFrame integration.</p>';

        $this->display_status_notice();
        $this->display_debug_info();

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';

        bna_wc_debug('Admin options page displayed');
    }

    private function display_status_notice() {
        $missing = $this->get_missing_settings();

        if (empty($missing)) {
            echo '<div class="notice notice-success"><p><strong>Status:</strong> ✅ Gateway configured and ready</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>Missing:</strong> ' . implode(', ', $missing) . '</p></div>';
        }
    }

    private function display_debug_info() {
        if (bna_logger('woocommerce')->is_enabled()) {
            echo '<div class="notice notice-info">';
            echo '<p><strong>Debug:</strong> Logging is enabled. ';
            echo '<a href="' . admin_url('admin.php?page=bna-debug-v2&tab=woocommerce') . '">View Logs</a></p>';
            echo '</div>';
        }
    }

    private function get_missing_settings() {
        $missing = array();
        if (empty($this->access_key)) $missing[] = 'Access Key';
        if (empty($this->secret_key)) $missing[] = 'Secret Key';
        if (empty($this->iframe_id)) $missing[] = 'iFrame ID';
        return $missing;
    }

    /**
     * Get customer detail toggles for API
     */
    public function get_customer_detail_settings() {
        return [
            'phone' => $this->enable_phone === 'yes',
            'billing_address' => $this->enable_billing_address === 'yes',
            'birthdate' => $this->enable_birthdate === 'yes'
        ];
    }
}