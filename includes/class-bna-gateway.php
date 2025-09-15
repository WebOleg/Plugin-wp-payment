<?php

if (!defined('ABSPATH')) exit;

class BNA_Gateway extends WC_Payment_Gateway {

    private $api;

    public function __construct() {
        $this->id = 'bna_smart_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment system with secure iframe integration and automatic customer data sync.';
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();
        $this->init_hooks();

        $this->api = new BNA_API();

        bna_debug('BNA Gateway initialized', array(
            'gateway_id' => $this->id,
            'enabled' => $this->enabled
        ));
    }

    private function load_settings() {
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->environment = $this->get_option('environment');
        $this->access_key = $this->get_option('access_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->iframe_id = $this->get_option('iframe_id');
        $this->webhook_secret = $this->get_option('webhook_secret');
        $this->enable_phone = $this->get_option('enable_phone');
        $this->enable_billing_address = $this->get_option('enable_billing_address');
        $this->enable_birthdate = $this->get_option('enable_birthdate');
        $this->enable_shipping_address = $this->get_option('enable_shipping_address');
    }

    private function init_hooks() {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_checkout_update_customer_data', array($this, 'save_updated_billing_data'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'update_customer_from_order'), 20);

        if ($this->get_option('enable_birthdate') === 'yes') {
            add_filter('woocommerce_billing_fields', array($this, 'add_birthdate_field'));
            add_action('woocommerce_checkout_process', array($this, 'validate_birthdate'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_birthdate'));
            add_filter('woocommerce_checkout_get_value', array($this, 'populate_birthdate_field'), 10, 2);
        }

        if ($this->get_option('enable_shipping_address') === 'yes') {
            add_action('woocommerce_after_checkout_billing_form', array($this, 'add_shipping_address_section'));
            add_action('woocommerce_checkout_process', array($this, 'validate_shipping_address'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_shipping_address'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_shipping_scripts'));
            add_action('woocommerce_checkout_update_customer_data', array($this, 'save_customer_shipping_data'));
            add_filter('woocommerce_checkout_get_value', array($this, 'populate_checkout_shipping_fields'), 10, 2);
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
                'description' => 'Payment method title shown during checkout.',
                'default' => 'BNA Smart Payment',
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => 'Description',
                'type' => 'textarea',
                'description' => 'Payment method description shown to customers.',
                'default' => 'Secure online payments via BNA Smart Payment',
                'desc_tip' => true,
            ),
            'environment_settings' => array(
                'title' => 'API Configuration',
                'type' => 'title',
                'description' => 'Configure your BNA API credentials and environment. All credentials can be found in your BNA Merchant Portal.',
            ),
            'environment' => array(
                'title' => 'Environment',
                'type' => 'select',
                'default' => 'staging',
                'options' => array(
                    'staging' => 'Staging (Test)',
                    'production' => 'Production (Live)'
                ),
                'description' => 'Select staging for testing or production for live payments.',
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'Your BNA API access key from the Merchant Portal.',
                'default' => '',
            ),
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'description' => 'Your BNA API secret key from the Merchant Portal.',
                'default' => '',
            ),
            'iframe_id' => array(
                'title' => 'iFrame ID',
                'type' => 'text',
                'description' => 'Your BNA iFrame ID from the Merchant Portal.',
                'default' => '',
            ),
            'webhook_settings' => array(
                'title' => 'Webhook Configuration',
                'type' => 'title',
                'description' => '
                    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin: 10px 0;">
                        <h4 style="margin-top: 0;">📡 Webhook Setup Instructions</h4>
                        <ol style="margin-left: 20px;">
                            <li><strong>Copy webhook URL:</strong> <code>' . home_url('/wp-json/bna/v1/webhook') . '</code></li>
                            <li><strong>Login to BNA Portal</strong> → Merchant Profile → Webhooks</li>
                            <li><strong>Add webhook URL</strong> and configure events</li>
                            <li><strong>Generate webhook secret</strong> and copy it to field above</li>
                            <li><strong>Test webhook:</strong> <a href="' . home_url('/wp-json/bna/v1/webhook/test') . '" target="_blank">Test Endpoint</a></li>
                        </ol>
                        <p style="margin-bottom: 0;"><strong>📋 Required Events:</strong> <code>transaction.*</code>, <code>payment_method.*</code>, <code>customer.*</code></p>
                    </div>
                '
            ),
            'webhook_secret' => array(
                'title' => 'Webhook Secret Key',
                'type' => 'password',
                'description' => 'Secret key for webhook HMAC signature verification. Get this from your BNA Merchant Portal webhook settings.',
                'default' => '',
            ),
            'customer_data_settings' => array(
                'title' => 'Customer Data Collection',
                'type' => 'title',
                'description' => 'Configure what customer information to collect during checkout. These settings should match your BNA Portal iFrame configuration.',
            ),
            'enable_phone' => array(
                'title' => 'Enable Phone Collection',
                'type' => 'checkbox',
                'label' => 'Collect customer phone numbers',
                'default' => 'yes',
                'description' => 'Collect and sync customer phone numbers with BNA Portal.',
            ),
            'enable_billing_address' => array(
                'title' => 'Enable Billing Address',
                'type' => 'checkbox',
                'label' => 'Collect billing address information',
                'default' => 'yes',
                'description' => 'Collect and sync billing address with BNA Portal.',
            ),
            'enable_birthdate' => array(
                'title' => 'Enable Birthdate Collection',
                'type' => 'checkbox',
                'label' => 'Collect customer birthdate',
                'default' => 'no',
                'description' => 'Collect customer birthdate during checkout.',
            ),
            'enable_shipping_address' => array(
                'title' => 'Enable Shipping Address',
                'type' => 'checkbox',
                'label' => 'Collect shipping address information',
                'default' => 'yes',
                'description' => 'Collect and sync shipping address with BNA Portal.',
            ),
        );
    }

    public function add_birthdate_field($fields) {
        $fields['billing_birthdate'] = array(
            'label' => __('Date of Birth', 'bna-smart-payment'),
            'type' => 'date',
            'required' => true,
            'priority' => 25,
            'class' => array('form-row-wide'),
            'validate' => array('required'),
            'custom_attributes' => array(
                'max' => date('Y-m-d', strtotime('-18 years'))
            )
        );
        return $fields;
    }

    public function populate_birthdate_field($value, $key) {
        if ($key === 'billing_birthdate' && is_user_logged_in()) {
            $customer_id = get_current_user_id();
            $birthdate = get_user_meta($customer_id, 'billing_birthdate', true);
            return $birthdate ?: $value;
        }
        return $value;
    }

    public function validate_birthdate() {
        if (empty($_POST['billing_birthdate'])) {
            wc_add_notice(__('Date of birth is a required field.', 'bna-smart-payment'), 'error');
            return;
        }

        $birthdate = sanitize_text_field($_POST['billing_birthdate']);
        $birth_timestamp = strtotime($birthdate);

        if ($birth_timestamp === false || $birth_timestamp > strtotime('-18 years') || $birth_timestamp > time()) {
            wc_add_notice(__('Please enter a valid birth date. You must be at least 18 years old.', 'bna-smart-payment'), 'error');
        }
    }

    public function save_birthdate($order_id) {
        if (!empty($_POST['billing_birthdate'])) {
            $order = wc_get_order($order_id);
            if ($order) {
                $birthdate = sanitize_text_field($_POST['billing_birthdate']);
                $order->add_meta_data('_billing_birthdate', $birthdate);
                $order->save();
            }
        }
    }

    public function add_shipping_address_section() {
        BNA_Template::load('shipping-address-fields');
    }

    public function enqueue_shipping_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'bna-shipping-address',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/shipping-address.js',
            array('jquery'),
            BNA_SMART_PAYMENT_VERSION,
            true
        );

        $countries_obj = new WC_Countries();
        $countries = $countries_obj->get_countries();
        $states = $countries_obj->get_states();

        $saved_shipping = array();
        if (is_user_logged_in()) {
            $customer_id = get_current_user_id();
            $saved_shipping = array(
                'country' => get_user_meta($customer_id, 'shipping_country', true),
                'address_1' => get_user_meta($customer_id, 'shipping_address_1', true),
                'address_2' => get_user_meta($customer_id, 'shipping_address_2', true),
                'city' => get_user_meta($customer_id, 'shipping_city', true),
                'state' => get_user_meta($customer_id, 'shipping_state', true),
                'postcode' => get_user_meta($customer_id, 'shipping_postcode', true)
            );

            $billing_country = get_user_meta($customer_id, 'billing_country', true);
            $billing_address_1 = get_user_meta($customer_id, 'billing_address_1', true);
            $billing_address_2 = get_user_meta($customer_id, 'billing_address_2', true);
            $billing_city = get_user_meta($customer_id, 'billing_city', true);
            $billing_state = get_user_meta($customer_id, 'billing_state', true);
            $billing_postcode = get_user_meta($customer_id, 'billing_postcode', true);

            $billing_data = array(
                'country' => $billing_country,
                'address_1' => $billing_address_1,
                'address_2' => $billing_address_2,
                'city' => $billing_city,
                'state' => $billing_state,
                'postcode' => $billing_postcode
            );
        } else {
            $billing_data = array();
        }

        wp_localize_script('bna-shipping-address', 'bna_shipping', array(
            'countries' => $countries,
            'states' => $states,
            'gateway_id' => $this->id,
            'saved_shipping' => $saved_shipping,
            'billing_data' => $billing_data,
            'i18n' => array(
                'same_as_billing' => __('Same as billing address', 'bna-smart-payment'),
                'select_country' => __('Select Country...', 'bna-smart-payment'),
                'select_state' => __('Select State...', 'bna-smart-payment'),
                'no_states' => __('No states available', 'bna-smart-payment'),
                'shipping_required' => __('Shipping address is required when different from billing.', 'bna-smart-payment'),
                'country_required' => __('Country is required.', 'bna-smart-payment'),
                'address_required' => __('Street address is required.', 'bna-smart-payment'),
                'city_required' => __('City is required.', 'bna-smart-payment'),
                'postcode_required' => __('Postal code is required.', 'bna-smart-payment')
            )
        ));

        bna_debug('Shipping address assets loaded');
    }

    public function validate_shipping_address() {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);

        if (!$same_as_billing) {
            $required_fields = array(
                'bna_shipping_country' => __('Country', 'bna-smart-payment'),
                'bna_shipping_address_1' => __('Street address', 'bna-smart-payment'),
                'bna_shipping_city' => __('City', 'bna-smart-payment'),
                'bna_shipping_postcode' => __('Postal code', 'bna-smart-payment')
            );

            foreach ($required_fields as $field => $label) {
                if (empty($_POST[$field])) {
                    wc_add_notice(sprintf(__('%s is a required field.', 'bna-smart-payment'), $label), 'error');
                }
            }
        }
    }

    public function save_shipping_address($order_id) {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);
        $order->add_meta_data('_bna_shipping_same_as_billing', $same_as_billing ? '1' : '0');

        if ($same_as_billing) {
            $order->set_shipping_first_name($order->get_billing_first_name());
            $order->set_shipping_last_name($order->get_billing_last_name());
            $order->set_shipping_company($order->get_billing_company());
            $order->set_shipping_address_1($order->get_billing_address_1());
            $order->set_shipping_address_2($order->get_billing_address_2());
            $order->set_shipping_city($order->get_billing_city());
            $order->set_shipping_state($order->get_billing_state());
            $order->set_shipping_postcode($order->get_billing_postcode());
            $order->set_shipping_country($order->get_billing_country());
        } else {
            $shipping_fields = array(
                'shipping_country' => 'bna_shipping_country',
                'shipping_address_1' => 'bna_shipping_address_1',
                'shipping_address_2' => 'bna_shipping_address_2',
                'shipping_city' => 'bna_shipping_city',
                'shipping_state' => 'bna_shipping_state',
                'shipping_postcode' => 'bna_shipping_postcode'
            );

            foreach ($shipping_fields as $order_field => $post_field) {
                if (isset($_POST[$post_field])) {
                    $setter = 'set_' . $order_field;
                    $order->$setter(sanitize_text_field($_POST[$post_field]));
                }
            }

            $order->set_shipping_first_name($order->get_billing_first_name());
            $order->set_shipping_last_name($order->get_billing_last_name());
            $order->set_shipping_company($order->get_billing_company());
        }

        $order->save();
    }

    public function save_updated_billing_data($customer_id) {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        $actual_customer_id = 0;

        if (is_numeric($customer_id)) {
            $actual_customer_id = (int) $customer_id;
        } elseif (is_user_logged_in()) {
            $actual_customer_id = get_current_user_id();
        }

        if (!$actual_customer_id) {
            return;
        }

        $billing_data = array(
            'billing_first_name' => sanitize_text_field($_POST['billing_first_name'] ?? ''),
            'billing_last_name' => sanitize_text_field($_POST['billing_last_name'] ?? ''),
            'billing_company' => sanitize_text_field($_POST['billing_company'] ?? ''),
            'billing_address_1' => sanitize_text_field($_POST['billing_address_1'] ?? ''),
            'billing_address_2' => sanitize_text_field($_POST['billing_address_2'] ?? ''),
            'billing_city' => sanitize_text_field($_POST['billing_city'] ?? ''),
            'billing_state' => sanitize_text_field($_POST['billing_state'] ?? ''),
            'billing_postcode' => sanitize_text_field($_POST['billing_postcode'] ?? ''),
            'billing_country' => sanitize_text_field($_POST['billing_country'] ?? ''),
            'billing_phone' => sanitize_text_field($_POST['billing_phone'] ?? ''),
            'billing_email' => sanitize_email($_POST['billing_email'] ?? '')
        );

        if (!empty($_POST['billing_birthdate'])) {
            $billing_data['billing_birthdate'] = sanitize_text_field($_POST['billing_birthdate']);
        }

        foreach ($billing_data as $meta_key => $value) {
            if ($value) {
                update_user_meta($actual_customer_id, $meta_key, $value);
            }
        }

        bna_log('Updated customer billing data', array(
            'customer_id' => $actual_customer_id,
            'fields_updated' => array_keys(array_filter($billing_data))
        ));
    }

    public function save_customer_shipping_data($customer_id) {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        $actual_customer_id = 0;

        if (is_numeric($customer_id)) {
            $actual_customer_id = (int) $customer_id;
        } elseif (is_user_logged_in()) {
            $actual_customer_id = get_current_user_id();
        }

        if (!$actual_customer_id) {
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);

        if ($same_as_billing) {
            update_user_meta($actual_customer_id, 'shipping_country', sanitize_text_field($_POST['billing_country'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_address_1', sanitize_text_field($_POST['billing_address_1'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_address_2', sanitize_text_field($_POST['billing_address_2'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_city', sanitize_text_field($_POST['billing_city'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_state', sanitize_text_field($_POST['billing_state'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_postcode', sanitize_text_field($_POST['billing_postcode'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_first_name', sanitize_text_field($_POST['billing_first_name'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_last_name', sanitize_text_field($_POST['billing_last_name'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_company', sanitize_text_field($_POST['billing_company'] ?? ''));
        } else {
            $shipping_fields = array(
                'shipping_country' => 'bna_shipping_country',
                'shipping_address_1' => 'bna_shipping_address_1',
                'shipping_address_2' => 'bna_shipping_address_2',
                'shipping_city' => 'bna_shipping_city',
                'shipping_state' => 'bna_shipping_state',
                'shipping_postcode' => 'bna_shipping_postcode'
            );

            foreach ($shipping_fields as $meta_key => $post_key) {
                if (isset($_POST[$post_key])) {
                    update_user_meta($actual_customer_id, $meta_key, sanitize_text_field($_POST[$post_key]));
                }
            }

            update_user_meta($actual_customer_id, 'shipping_first_name', sanitize_text_field($_POST['billing_first_name'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_last_name', sanitize_text_field($_POST['billing_last_name'] ?? ''));
            update_user_meta($actual_customer_id, 'shipping_company', sanitize_text_field($_POST['billing_company'] ?? ''));
        }

        bna_log('Shipping data saved locally', array(
            'wp_customer_id' => $actual_customer_id
        ));
    }

    public function populate_checkout_shipping_fields($value, $key) {
        if (strpos($key, 'bna_shipping_') !== 0 || $value) {
            return $value;
        }

        $customer = WC()->customer;
        if (!$customer) {
            return $value;
        }

        $field_mapping = array(
            'bna_shipping_country' => 'get_shipping_country',
            'bna_shipping_address_1' => 'get_shipping_address_1',
            'bna_shipping_address_2' => 'get_shipping_address_2',
            'bna_shipping_city' => 'get_shipping_city',
            'bna_shipping_state' => 'get_shipping_state',
            'bna_shipping_postcode' => 'get_shipping_postcode'
        );

        if (isset($field_mapping[$key]) && method_exists($customer, $field_mapping[$key])) {
            $method = $field_mapping[$key];
            return $customer->$method();
        }

        return $value;
    }

    public function update_customer_from_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !is_user_logged_in()) {
            return;
        }

        $customer_id = $order->get_customer_id();
        if (!$customer_id) {
            return;
        }

        $billing_data = array(
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_company' => $order->get_billing_company(),
            'billing_address_1' => $order->get_billing_address_1(),
            'billing_address_2' => $order->get_billing_address_2(),
            'billing_city' => $order->get_billing_city(),
            'billing_state' => $order->get_billing_state(),
            'billing_postcode' => $order->get_billing_postcode(),
            'billing_country' => $order->get_billing_country(),
            'billing_phone' => $order->get_billing_phone(),
            'billing_email' => $order->get_billing_email()
        );

        $birthdate = $order->get_meta('_billing_birthdate');
        if ($birthdate) {
            $billing_data['billing_birthdate'] = $birthdate;
        }

        foreach ($billing_data as $meta_key => $value) {
            if ($value) {
                update_user_meta($customer_id, $meta_key, $value);
            }
        }

        bna_log('Updated customer billing data from order', array(
            'customer_id' => $customer_id,
            'order_id' => $order_id
        ));
    }

    public function process_payment($order_id) {
        bna_log('Processing payment for order', array('order_id' => $order_id));

        $order = wc_get_order($order_id);
        if (!$order) {
            bna_error('Order not found', array('order_id' => $order_id));
            wc_add_notice(__('Order not found. Please try again.', 'bna-smart-payment'), 'error');
            return array('result' => 'failure');
        }

        $validation = $this->validate_order_for_payment($order);
        if (!$validation['valid']) {
            foreach ($validation['errors'] as $error) {
                wc_add_notice($error, 'error');
            }
            return array('result' => 'failure');
        }

        $this->prepare_order_for_payment($order);

        $payment_url = home_url('/bna-payment/' . $order->get_id() . '/' . $order->get_order_key());

        bna_log('Payment process initiated', array(
            'order_id' => $order_id,
            'payment_url' => $payment_url
        ));

        return array(
            'result' => 'success',
            'redirect' => $payment_url
        );
    }

    public function display_payment_page_public($order) {
        try {
            if ($order->is_paid()) {
                bna_log('Order already paid, redirecting', array('order_id' => $order->get_id()));
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            $customer_sync_status = bna_get_customer_sync_status($order);
            bna_debug('Customer sync status for payment', array(
                'order_id' => $order->get_id(),
                'sync_status' => $customer_sync_status
            ));

            $iframe_url = $this->get_or_create_iframe_url($order);

            if (!$iframe_url) {
                bna_error('Failed to get iframe URL', array('order_id' => $order->get_id()));
                $this->redirect_with_error($order, 'Unable to load payment form. Please try again.');
                return;
            }

            BNA_Template::render_payment_page($order, $iframe_url);

        } catch (Exception $e) {
            bna_error('Payment page display exception', array(
                'order_id' => $order->get_id(),
                'error' => $e->getMessage()
            ));

            $this->redirect_with_error($order, 'Payment processing error. Please try again.');
        }
    }

    private function validate_order_for_payment($order) {
        $errors = array();

        if ($order->get_total() <= 0) {
            $errors[] = __('Order total must be greater than zero.', 'bna-smart-payment');
        }

        if (empty($order->get_billing_email())) {
            $errors[] = __('Billing email is required.', 'bna-smart-payment');
        }

        if (empty($order->get_billing_first_name()) || empty($order->get_billing_last_name())) {
            $errors[] = __('Billing name is required.', 'bna-smart-payment');
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    private function prepare_order_for_payment($order) {
        $order->update_status('pending', __('Awaiting BNA payment.', 'bna-smart-payment'));
        $order->add_meta_data('_bna_payment_initiated', current_time('mysql'));
        $order->save();
    }

    private function get_or_create_iframe_url($order) {
        $stored_token = $order->get_meta('_bna_checkout_token');
        $token_created = $order->get_meta('_bna_token_created');

        if ($stored_token && $token_created && (time() - strtotime($token_created)) < 1800) {
            bna_debug('Using existing token', array(
                'order_id' => $order->get_id(),
                'token_age_seconds' => time() - strtotime($token_created)
            ));
            return $this->api->get_iframe_url($stored_token);
        }

        $token_result = $this->api->generate_checkout_token($order);
        if (is_wp_error($token_result)) {
            bna_error('Token generation failed', array(
                'order_id' => $order->get_id(),
                'error' => $token_result->get_error_message()
            ));
            return false;
        }

        $order->update_meta_data('_bna_checkout_token', $token_result['token']);
        $order->update_meta_data('_bna_token_created', current_time('mysql'));
        $order->save();

        return $this->api->get_iframe_url($token_result['token']);
    }

    private function redirect_with_error($order, $message) {
        wc_add_notice($message, 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}