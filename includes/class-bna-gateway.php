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

    public function save_updated_billing_data($customer_id) {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        if (!$customer_id || !is_user_logged_in()) {
            return;
        }

        $billing_fields = array(
            'billing_first_name',
            'billing_last_name',
            'billing_company',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
            'billing_phone',
            'billing_email'
        );

        foreach ($billing_fields as $field) {
            if (isset($_POST[$field])) {
                $value = sanitize_text_field($_POST[$field]);
                update_user_meta($customer_id, $field, $value);
            }
        }

        bna_log('Updated customer billing data from checkout', array(
            'customer_id' => $customer_id,
            'updated_fields' => count(array_intersect($billing_fields, array_keys($_POST)))
        ));
    }

    public function update_customer_from_order($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_payment_method() !== $this->id) {
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
                'description' => 'Configure your BNA API credentials and environment.',
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
                'description' => 'Select the environment for API calls. Use Staging for testing.'
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'Your Access Key from BNA Merchant Portal.',
                'default' => '',
            ),
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'description' => 'Your Secret Key from BNA Merchant Portal.',
                'default' => '',
            ),
            'iframe_id' => array(
                'title' => 'iFrame ID',
                'type' => 'text',
                'description' => 'Your iFrame ID from BNA Merchant Portal.',
                'default' => '',
            ),
            'customer_details_section' => array(
                'title' => 'Customer Data Collection & Sync',
                'type' => 'title',
                'description' => 'Configure which customer details to collect and automatically sync with BNA portal when data changes.',
            ),
            'enable_phone' => array(
                'title' => 'Collect Phone Number',
                'type' => 'checkbox',
                'label' => 'Include phone number in payment data',
                'default' => 'no',
                'description' => 'Send customer phone number to BNA API if available.'
            ),
            'enable_billing_address' => array(
                'title' => 'Collect Billing Address',
                'type' => 'checkbox',
                'label' => 'Include billing address in payment data',
                'default' => 'no',
                'description' => 'Send customer billing address to BNA API.'
            ),
            'enable_birthdate' => array(
                'title' => 'Require Birth Date',
                'type' => 'checkbox',
                'label' => 'Add required birthdate field to checkout',
                'default' => 'yes',
                'description' => 'Add birthdate field to checkout form and include in payment data. Required for age verification.'
            ),
            'enable_shipping_address' => array(
                'title' => 'Collect Shipping Address',
                'type' => 'checkbox',
                'label' => 'Include shipping address in payment data',
                'default' => 'no',
                'description' => 'Collect and send shipping address to BNA API when different from billing address. Customer data automatically syncs when address changes.'
            ),
        );
    }

    public function process_admin_options() {
        bna_log('Processing gateway admin options update');
        $saved = parent::process_admin_options();

        if ($saved) {
            update_option('bna_smart_payment_environment', $this->get_option('environment'));
            update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
            update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
            update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));
            update_option('bna_smart_payment_enable_phone', $this->get_option('enable_phone'));
            update_option('bna_smart_payment_enable_billing_address', $this->get_option('enable_billing_address'));
            update_option('bna_smart_payment_enable_birthdate', $this->get_option('enable_birthdate'));
            update_option('bna_smart_payment_enable_shipping_address', $this->get_option('enable_shipping_address'));

            bna_log('Gateway admin options saved successfully');
        }

        return $saved;
    }

    public function add_birthdate_field($fields) {
        $fields['billing_birthdate'] = array(
            'label' => __('Date of Birth', 'bna-smart-payment'),
            'placeholder' => __('YYYY-MM-DD', 'bna-smart-payment'),
            'required' => true,
            'class' => array('form-row-wide'),
            'type' => 'date',
            'priority' => 25,
            'validate' => array('required'),
            'custom_attributes' => array(
                'max' => date('Y-m-d', strtotime('-18 years'))
            )
        );
        return $fields;
    }

    public function validate_birthdate() {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        if (empty($_POST['billing_birthdate'])) {
            wc_add_notice(__('Date of birth is required for BNA Smart Payment.', 'bna-smart-payment'), 'error');
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
            $billing_city = get_user_meta($customer_id, 'billing_city', true);
            $billing_state = get_user_meta($customer_id, 'billing_state', true);
            $billing_postcode = get_user_meta($customer_id, 'billing_postcode', true);

            $saved_shipping['is_different_from_billing'] = (
                $saved_shipping['country'] !== $billing_country ||
                $saved_shipping['address_1'] !== $billing_address_1 ||
                $saved_shipping['city'] !== $billing_city ||
                $saved_shipping['state'] !== $billing_state ||
                $saved_shipping['postcode'] !== $billing_postcode
            );
        }

        wp_localize_script('bna-shipping-address', 'bna_shipping_data', array(
            'gateway_id' => $this->id,
            'countries' => $countries,
            'states' => $states,
            'saved_shipping' => $saved_shipping,
            'i18n' => array(
                'select_country' => __('Select Country...', 'bna-smart-payment'),
                'select_state' => __('Select State/Province...', 'bna-smart-payment'),
                'no_states' => __('No states available', 'bna-smart-payment')
            )
        ));
    }

    public function validate_shipping_address() {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);

        if (!$same_as_billing) {
            $required_fields = array(
                'bna_shipping_country' => __('Shipping country', 'bna-smart-payment'),
                'bna_shipping_address_1' => __('Shipping address', 'bna-smart-payment'),
                'bna_shipping_city' => __('Shipping city', 'bna-smart-payment'),
                'bna_shipping_state' => __('Shipping state/province', 'bna-smart-payment'),
                'bna_shipping_postcode' => __('Shipping postal code', 'bna-smart-payment')
            );

            foreach ($required_fields as $field_key => $field_name) {
                if (empty($_POST[$field_key])) {
                    wc_add_notice(sprintf(__('%s is required.', 'bna-smart-payment'), $field_name), 'error');
                }
            }
        }
    }

    public function save_shipping_address($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);
        $order->add_meta_data('_bna_shipping_same_as_billing', $same_as_billing ? 'yes' : 'no');

        if ($same_as_billing) {
            $this->copy_billing_to_shipping($order);
        } else {
            $this->save_custom_shipping_data($order);
        }

        $order->save();
    }

    private function copy_billing_to_shipping($order) {
        $billing_fields = array(
            'country' => $order->get_billing_country(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'company' => $order->get_billing_company(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode()
        );

        foreach ($billing_fields as $field => $value) {
            if ($value) {
                $order->add_meta_data('_bna_shipping_' . $field, $value);
            }
        }

        $this->save_to_wc_shipping_fields($order, $billing_fields);
    }

    private function save_custom_shipping_data($order) {
        $shipping_fields = array(
            'country' => 'bna_shipping_country',
            'address_1' => 'bna_shipping_address_1',
            'address_2' => 'bna_shipping_address_2',
            'city' => 'bna_shipping_city',
            'state' => 'bna_shipping_state',
            'postcode' => 'bna_shipping_postcode'
        );

        $shipping_data = array();

        foreach ($shipping_fields as $meta_key => $post_key) {
            if (isset($_POST[$post_key])) {
                $value = sanitize_text_field($_POST[$post_key]);
                $order->add_meta_data('_bna_shipping_' . $meta_key, $value);
                $shipping_data[$meta_key] = $value;
            }
        }

        $shipping_data['first_name'] = $order->get_billing_first_name();
        $shipping_data['last_name'] = $order->get_billing_last_name();
        $shipping_data['company'] = $order->get_billing_company();

        $this->save_to_wc_shipping_fields($order, $shipping_data);
    }

    private function save_to_wc_shipping_fields($order, $shipping_data) {
        if (isset($shipping_data['country'])) $order->set_shipping_country($shipping_data['country']);
        if (isset($shipping_data['first_name'])) $order->set_shipping_first_name($shipping_data['first_name']);
        if (isset($shipping_data['last_name'])) $order->set_shipping_last_name($shipping_data['last_name']);
        if (isset($shipping_data['company'])) $order->set_shipping_company($shipping_data['company']);
        if (isset($shipping_data['address_1'])) $order->set_shipping_address_1($shipping_data['address_1']);
        if (isset($shipping_data['address_2'])) $order->set_shipping_address_2($shipping_data['address_2']);
        if (isset($shipping_data['city'])) $order->set_shipping_city($shipping_data['city']);
        if (isset($shipping_data['state'])) $order->set_shipping_state($shipping_data['state']);
        if (isset($shipping_data['postcode'])) $order->set_shipping_postcode($shipping_data['postcode']);

        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            $this->save_to_customer_profile($customer_id, $shipping_data);
        }
    }

    private function save_to_customer_profile($customer_id, $shipping_data) {
        $customer_fields = array(
            'shipping_country' => $shipping_data['country'] ?? '',
            'shipping_first_name' => $shipping_data['first_name'] ?? '',
            'shipping_last_name' => $shipping_data['last_name'] ?? '',
            'shipping_company' => $shipping_data['company'] ?? '',
            'shipping_address_1' => $shipping_data['address_1'] ?? '',
            'shipping_address_2' => $shipping_data['address_2'] ?? '',
            'shipping_city' => $shipping_data['city'] ?? '',
            'shipping_state' => $shipping_data['state'] ?? '',
            'shipping_postcode' => $shipping_data['postcode'] ?? ''
        );

        foreach ($customer_fields as $meta_key => $value) {
            if ($value) {
                update_user_meta($customer_id, $meta_key, $value);
            }
        }

        bna_log('Shipping address saved to customer profile', array(
            'customer_id' => $customer_id,
            'fields_saved' => array_keys(array_filter($customer_fields))
        ));
    }

    public function save_customer_shipping_data($customer_id) {
        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);

        if ($same_as_billing) {
            $billing_fields = array(
                'shipping_country' => $_POST['billing_country'] ?? '',
                'shipping_first_name' => $_POST['billing_first_name'] ?? '',
                'shipping_last_name' => $_POST['billing_last_name'] ?? '',
                'shipping_company' => $_POST['billing_company'] ?? '',
                'shipping_address_1' => $_POST['billing_address_1'] ?? '',
                'shipping_address_2' => $_POST['billing_address_2'] ?? '',
                'shipping_city' => $_POST['billing_city'] ?? '',
                'shipping_state' => $_POST['billing_state'] ?? '',
                'shipping_postcode' => $_POST['billing_postcode'] ?? ''
            );

            foreach ($billing_fields as $meta_key => $value) {
                if ($value) {
                    update_user_meta($customer_id, $meta_key, sanitize_text_field($value));
                }
            }
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
                    update_user_meta($customer_id, $meta_key, sanitize_text_field($_POST[$post_key]));
                }
            }

            update_user_meta($customer_id, 'shipping_first_name', sanitize_text_field($_POST['billing_first_name'] ?? ''));
            update_user_meta($customer_id, 'shipping_last_name', sanitize_text_field($_POST['billing_last_name'] ?? ''));
            update_user_meta($customer_id, 'shipping_company', sanitize_text_field($_POST['billing_company'] ?? ''));
        }
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

    private function get_or_create_iframe_url($order) {
        $existing_token = $order->get_meta('_bna_checkout_token');
        $token_generated_at = $order->get_meta('_bna_checkout_generated_at');

        if (!empty($existing_token) && !empty($token_generated_at)) {
            $age_minutes = (current_time('timestamp') - $token_generated_at) / 60;

            if ($age_minutes <= 25) {
                bna_debug('Using existing token', array(
                    'order_id' => $order->get_id(),
                    'token_age_minutes' => round($age_minutes, 2)
                ));

                return $this->api->get_iframe_url($existing_token);
            }
        }

        return $this->create_new_iframe_url($order);
    }

    private function create_new_iframe_url($order) {
        bna_log('Creating new checkout token', array('order_id' => $order->get_id()));

        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');

        $response = $this->api->generate_checkout_token($order);

        if (is_wp_error($response)) {
            bna_error('Token generation failed', array(
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message()
            ));
            return false;
        }

        if (empty($response['token'])) {
            bna_error('No token in response', array('order_id' => $order->get_id()));
            return false;
        }

        $token = $response['token'];

        $order->add_meta_data('_bna_checkout_token', $token);
        $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
        $order->save();

        return $this->api->get_iframe_url($token);
    }

    private function redirect_with_error($order, $message) {
        wc_add_notice($message, 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    private function get_payment_url($order) {
        return home_url('/bna-payment/' . $order->get_id() . '/' . $order->get_order_key() . '/');
    }

    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }

        if (!class_exists('WooCommerce')) {
            return false;
        }

        return $this->has_required_settings();
    }

    private function has_required_settings() {
        return !empty($this->access_key) &&
            !empty($this->secret_key) &&
            !empty($this->iframe_id);
    }

    public function process_payment($order_id) {
        bna_log('Processing payment started', array('order_id' => $order_id));

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'bna-smart-payment'), 'error');
            return array('result' => 'fail');
        }

        try {
            $validation_result = $this->validate_order_for_payment($order);
            if (!$validation_result['valid']) {
                foreach ($validation_result['errors'] as $error) {
                    wc_add_notice($error, 'error');
                }
                return array('result' => 'fail');
            }

            $this->prepare_order_for_payment($order);
            $redirect_url = $this->get_payment_url($order);

            bna_log('Payment process completed successfully', array(
                'order_id' => $order->get_id(),
                'redirect_url' => $redirect_url,
                'customer_sync_ready' => !empty($order->get_meta('_bna_customer_id'))
            ));

            return array(
                'result' => 'success',
                'redirect' => $redirect_url
            );

        } catch (Exception $e) {
            bna_error('Payment processing exception', array(
                'order_id' => $order->get_id(),
                'error' => $e->getMessage()
            ));

            wc_add_notice(__('Payment processing error. Please try again.', 'bna-smart-payment'), 'error');
            return array('result' => 'fail');
        }
    }

    private function validate_order_for_payment($order) {
        $errors = array();

        if ($order->is_paid()) {
            $errors[] = __('Order is already paid.', 'bna-smart-payment');
        }

        if ($order->get_total() <= 0) {
            $errors[] = __('Order total must be greater than 0.', 'bna-smart-payment');
        }

        if (empty($order->get_billing_email())) {
            $errors[] = __('Customer email is required.', 'bna-smart-payment');
        }

        if (empty($order->get_billing_first_name()) || empty($order->get_billing_last_name())) {
            $errors[] = __('Customer name is required.', 'bna-smart-payment');
        }

        $allowed_statuses = array('pending', 'on-hold', 'failed');
        if (!in_array($order->get_status(), $allowed_statuses)) {
            $errors[] = __('Order status is not valid for payment processing.', 'bna-smart-payment');
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    private function prepare_order_for_payment($order) {
        if ($order->get_status() !== 'pending') {
            $order->update_status('pending', __('Awaiting BNA Smart Payment.', 'bna-smart-payment'));
        }

        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->add_meta_data('_bna_payment_started_at', current_time('timestamp'));
        $order->save();
    }

    public function admin_options() {
        echo '<h2>BNA Smart Payment Gateway</h2>';
        echo '<p>' . __('Accept payments through BNA Smart Payment with secure iframe integration and automatic customer data sync.', 'bna-smart-payment') . '</p>';

        $missing = $this->get_missing_settings();
        if (empty($missing)) {
            echo '<div class="notice notice-success inline"><p><strong>' . __('Status:', 'bna-smart-payment') . '</strong> ✅ ' . __('Gateway configured and ready', 'bna-smart-payment') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>' . __('Missing configuration:', 'bna-smart-payment') . '</strong> ' . implode(', ', $missing) . '</p></div>';
        }

        echo '<div class="notice notice-info inline">';
        echo '<p><strong>' . __('Webhook URL (configure in BNA Portal):', 'bna-smart-payment') . '</strong><br>';
        echo '<code>' . home_url('/wp-json/bna/v1/webhook') . '</code></p>';
        echo '<p><strong>' . __('Features:', 'bna-smart-payment') . '</strong> iFrame Integration, Webhooks, Customer Data Sync (v1.6.0)</p>';
        echo '</div>';

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    private function get_missing_settings() {
        $missing = array();
        if (empty($this->access_key)) $missing[] = __('Access Key', 'bna-smart-payment');
        if (empty($this->secret_key)) $missing[] = __('Secret Key', 'bna-smart-payment');
        if (empty($this->iframe_id)) $missing[] = __('iFrame ID', 'bna-smart-payment');
        return $missing;
    }
}