<?php
if (!defined('ABSPATH')) exit;

class BNA_Gateway extends WC_Payment_Gateway {

    private $api;

    public function __construct() {
        $this->id = 'bna_smart_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment system with secure iframe integration.';
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
                'title' => 'Customer Data Collection',
                'type' => 'title',
                'description' => 'Configure which customer details to collect during checkout.',
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
                'description' => 'Collect and send shipping address to BNA API when different from billing address. Adds shipping fields to checkout.'
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

        wp_localize_script('bna-shipping-address', 'bna_shipping_data', array(
            'gateway_id' => $this->id,
            'countries' => $countries,
            'states' => $states,
            'i18n' => array(
                'select_country' => __('Select Country...', 'bna-smart-payment'),
                'select_state' => __('Select State/Province...', 'bna-smart-payment'),
                'no_states' => __('No states available', 'bna-smart-payment')
            )
        ));
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

        if ($birth_timestamp === false) {
            wc_add_notice(__('Please enter a valid date of birth.', 'bna-smart-payment'), 'error');
            return;
        }

        $eighteen_years_ago = strtotime('-18 years');
        if ($birth_timestamp > $eighteen_years_ago) {
            wc_add_notice(__('You must be at least 18 years old to use BNA Smart Payment.', 'bna-smart-payment'), 'error');
            return;
        }

        if ($birth_timestamp > time()) {
            wc_add_notice(__('Birth date cannot be in the future.', 'bna-smart-payment'), 'error');
            return;
        }
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

    public function save_shipping_address($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);
        $order->add_meta_data('_bna_shipping_same_as_billing', $same_as_billing ? 'yes' : 'no');

        if (!$same_as_billing) {
            $shipping_fields = array(
                'address_1' => 'bna_shipping_address_1',
                'address_2' => 'bna_shipping_address_2',
                'city' => 'bna_shipping_city',
                'state' => 'bna_shipping_state',
                'postcode' => 'bna_shipping_postcode',
                'country' => 'bna_shipping_country'
            );

            foreach ($shipping_fields as $meta_key => $post_key) {
                if (isset($_POST[$post_key])) {
                    $value = sanitize_text_field($_POST[$post_key]);
                    $order->add_meta_data('_bna_shipping_' . $meta_key, $value);
                }
            }
        }

        $order->save();
    }

    public function display_payment_page_public($order) {
        try {
            if ($order->is_paid()) {
                bna_log('Order already paid, redirecting', array('order_id' => $order->get_id()));
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

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
                'redirect_url' => $redirect_url
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
        echo '<p>' . __('Accept payments through BNA Smart Payment with secure iframe integration.', 'bna-smart-payment') . '</p>';

        $missing = $this->get_missing_settings();
        if (empty($missing)) {
            echo '<div class="notice notice-success inline"><p><strong>' . __('Status:', 'bna-smart-payment') . '</strong> ✅ ' . __('Gateway configured and ready', 'bna-smart-payment') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>' . __('Missing configuration:', 'bna-smart-payment') . '</strong> ' . implode(', ', $missing) . '</p></div>';
        }

        echo '<div class="notice notice-info inline">';
        echo '<p><strong>' . __('Webhook URL (configure in BNA Portal):', 'bna-smart-payment') . '</strong><br>';
        echo '<code>' . home_url('/wp-json/bna/v1/webhook') . '</code></p>';
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
