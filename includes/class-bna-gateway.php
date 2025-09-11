<?php
/**
 * BNA Payment Gateway
 * WooCommerce payment gateway for BNA Smart Payment with iframe, webhooks, and customer sync
 *
 * @since 1.8.0 Added webhook secret field for HMAC signature verification
 * @since 1.7.0 Payment methods management
 * @since 1.6.0 Customer data sync and enhanced shipping address support
 */

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
        $this->webhook_secret = $this->get_option('webhook_secret'); // Added in v1.8.0
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
                    'dev' => 'Development',
                    'staging' => 'Staging (Test)',
                    'production' => 'Production (Live)'
                ),
                'description' => 'Select the environment for API calls. Use Staging for testing.',
                'desc_tip' => true,
            ),
            'access_key' => array(
                'title' => 'Access Key',
                'type' => 'text',
                'description' => 'Your Access Key from BNA Merchant Portal → API Settings.',
                'default' => '',
                'desc_tip' => true,
            ),
            'secret_key' => array(
                'title' => 'Secret Key',
                'type' => 'password',
                'description' => 'Your Secret Key from BNA Merchant Portal → API Settings.',
                'default' => '',
                'desc_tip' => true,
            ),
            'iframe_id' => array(
                'title' => 'iFrame ID',
                'type' => 'text',
                'description' => 'Your iFrame ID from BNA Merchant Portal → iFrame Settings.',
                'default' => '',
                'desc_tip' => true,
            ),
            'webhook_secret' => array(
                'title' => 'Webhook Secret Key',
                'type' => 'password',
                'description' => 'Enter the webhook secret key from BNA Portal → Merchant Profile → Webhooks. Required for webhook security verification (HMAC signatures). Generate this key in your BNA Portal and copy it here.',
                'default' => '',
                'desc_tip' => false,
                'custom_attributes' => array(
                    'placeholder' => 'Enter webhook secret from BNA Portal...'
                )
            ),
            'webhook_info' => array(
                'title' => 'Webhook Configuration',
                'type' => 'title',
                'description' => '
                    <div style="background: #f0f8ff; padding: 15px; border: 1px solid #0073aa; border-radius: 4px; margin: 10px 0;">
                        <h4 style="margin-top: 0;">🔗 Webhook Setup Instructions</h4>
                        <ol style="margin: 10px 0;">
                            <li><strong>Copy this URL:</strong> <code style="background: #fff; padding: 2px 6px; border-radius: 3px;">' . home_url('/wp-json/bna/v1/webhook') . '</code></li>
                            <li>Go to <strong>BNA Portal → Merchant Profile → Webhooks</strong></li>
                            <li>Add the webhook URL above</li>
                            <li>Click <strong>"Generate webhook key"</strong> button</li>
                            <li>Copy the generated key and paste it in the "Webhook Secret Key" field above</li>
                            <li>Save both BNA Portal settings and this page</li>
                        </ol>
                        <p style="margin-bottom: 0;"><strong>⚠️ Important:</strong> The webhook secret key is displayed only once in BNA Portal. Save it securely!</p>
                    </div>
                ',
            ),
            'customer_details_section' => array(
                'title' => 'Customer Data Collection & Sync',
                'type' => 'title',
                'description' => 'Configure which customer details to collect and automatically sync with BNA portal when data changes between orders.',
            ),
            'enable_phone' => array(
                'title' => 'Collect Phone Number',
                'type' => 'checkbox',
                'label' => 'Include phone number in payment data',
                'default' => 'no',
                'description' => 'Send customer phone number to BNA API if available.',
                'desc_tip' => true,
            ),
            'enable_billing_address' => array(
                'title' => 'Collect Billing Address',
                'type' => 'checkbox',
                'label' => 'Include billing address in payment data',
                'default' => 'no',
                'description' => 'Send customer billing address to BNA API.',
                'desc_tip' => true,
            ),
            'enable_birthdate' => array(
                'title' => 'Require Birth Date',
                'type' => 'checkbox',
                'label' => 'Add required birthdate field to checkout',
                'default' => 'yes',
                'description' => 'Add birthdate field to checkout form and include in payment data. Required for age verification (18+ years).',
                'desc_tip' => true,
            ),
            'enable_shipping_address' => array(
                'title' => 'Collect Shipping Address',
                'type' => 'checkbox',
                'label' => 'Include shipping address in payment data',
                'default' => 'no',
                'description' => 'Collect and send shipping address to BNA API when different from billing address. Customer data automatically syncs when address changes between orders.',
                'desc_tip' => true,
            ),
        );
    }

    public function process_admin_options() {
        bna_log('Processing gateway admin options update');
        $saved = parent::process_admin_options();

        if ($saved) {
            // Store settings in WordPress options for easy access
            update_option('bna_smart_payment_environment', $this->get_option('environment'));
            update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
            update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
            update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));
            update_option('bna_smart_payment_webhook_secret', $this->get_option('webhook_secret')); // Added in v1.8.0
            update_option('bna_smart_payment_enable_phone', $this->get_option('enable_phone'));
            update_option('bna_smart_payment_enable_billing_address', $this->get_option('enable_billing_address'));
            update_option('bna_smart_payment_enable_birthdate', $this->get_option('enable_birthdate'));
            update_option('bna_smart_payment_enable_shipping_address', $this->get_option('enable_shipping_address'));

            bna_log('Gateway admin options saved successfully', array(
                'webhook_secret_configured' => !empty($this->get_option('webhook_secret'))
            ));
        }

        return $saved;
    }

    public function admin_options() {
        echo '<h2>BNA Smart Payment Gateway</h2>';
        echo '<p>' . __('Accept payments through BNA Smart Payment with secure iframe integration, webhooks, and automatic customer data sync.', 'bna-smart-payment') . '</p>';

        // Check configuration status
        $missing = $this->get_missing_settings();
        $webhook_configured = !empty($this->get_option('webhook_secret'));

        if (empty($missing)) {
            echo '<div class="notice notice-success inline"><p><strong>' . __('Status:', 'bna-smart-payment') . '</strong> ✅ ' . __('Gateway configured and ready', 'bna-smart-payment') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>' . __('Missing configuration:', 'bna-smart-payment') . '</strong> ' . implode(', ', $missing) . '</p></div>';
        }

        // Webhook status
        if ($webhook_configured) {
            echo '<div class="notice notice-success inline"><p><strong>' . __('Webhook Security:', 'bna-smart-payment') . '</strong> ✅ ' . __('HMAC signature verification enabled', 'bna-smart-payment') . '</p></div>';
        } else {
            echo '<div class="notice notice-info inline"><p><strong>' . __('Webhook Security:', 'bna-smart-payment') . '</strong> ⚠️ ' . __('HMAC verification disabled - configure webhook secret for enhanced security', 'bna-smart-payment') . '</p></div>';
        }

        // Plugin features info
        echo '<div class="notice notice-info inline">';
        echo '<p><strong>' . __('Plugin Version:', 'bna-smart-payment') . '</strong> ' . (defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'Unknown') . '</p>';
        echo '<p><strong>' . __('Features:', 'bna-smart-payment') . '</strong> iFrame Integration, HMAC Webhooks (v1.8.0), Payment Methods (v1.7.0), Customer Sync (v1.6.0)</p>';
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
        // Note: webhook_secret is optional but recommended
        return $missing;
    }

    // ==========================================
    // BIRTHDATE FIELD FUNCTIONALITY
    // ==========================================

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

    public function populate_birthdate_field($value, $key) {
        if ($key === 'billing_birthdate' && empty($value) && is_user_logged_in()) {
            $customer_id = get_current_user_id();
            $stored_birthdate = get_user_meta($customer_id, 'billing_birthdate', true);
            if ($stored_birthdate) {
                return $stored_birthdate;
            }
        }
        return $value;
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

    // ==========================================
    // SHIPPING ADDRESS FUNCTIONALITY
    // ==========================================

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

        if (!$same_as_billing) {
            $shipping_data = array(
                '_bna_shipping_country' => sanitize_text_field($_POST['bna_shipping_country'] ?? ''),
                '_bna_shipping_address_1' => sanitize_text_field($_POST['bna_shipping_address_1'] ?? ''),
                '_bna_shipping_address_2' => sanitize_text_field($_POST['bna_shipping_address_2'] ?? ''),
                '_bna_shipping_city' => sanitize_text_field($_POST['bna_shipping_city'] ?? ''),
                '_bna_shipping_state' => sanitize_text_field($_POST['bna_shipping_state'] ?? ''),
                '_bna_shipping_postcode' => sanitize_text_field($_POST['bna_shipping_postcode'] ?? '')
            );

            foreach ($shipping_data as $meta_key => $value) {
                if ($value) {
                    $order->add_meta_data($meta_key, $value);
                }
            }
        }

        $order->save();
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

    // ==========================================
    // CUSTOMER DATA SYNC
    // ==========================================

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

    // ==========================================
    // PAYMENT PROCESSING
    // ==========================================

    public function process_payment($order_id) {
        bna_log('Processing payment for order', array('order_id' => $order_id));

        $order = wc_get_order($order_id);
        if (!$order) {
            bna_error('Order not found', array('order_id' => $order_id));
            wc_add_notice(__('Order not found.', 'bna-smart-payment'), 'error');
            return array('result' => 'failure');
        }

        // Validate order
        $validation = $this->validate_order_for_payment($order);
        if (!$validation['valid']) {
            foreach ($validation['errors'] as $error) {
                wc_add_notice($error, 'error');
            }
            return array('result' => 'failure');
        }

        // Prepare order for payment
        $this->prepare_order_for_payment($order);

        // Generate payment URL
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

    private function get_or_create_iframe_url($order) {
        $existing_url = $order->get_meta('_bna_iframe_url');
        $url_created_at = $order->get_meta('_bna_iframe_url_created_at');

        // Check if existing URL is still valid (30 minutes)
        if ($existing_url && $url_created_at && (time() - $url_created_at < 1800)) {
            bna_debug('Using existing iframe URL', array(
                'order_id' => $order->get_id(),
                'age_minutes' => round((time() - $url_created_at) / 60, 1)
            ));
            return $existing_url;
        }

        // Generate new iframe URL
        $token_result = $this->api->generate_checkout_token($order);
        if (is_wp_error($token_result)) {
            bna_error('Failed to generate checkout token', array(
                'order_id' => $order->get_id(),
                'error' => $token_result->get_error_message()
            ));
            return false;
        }

        $iframe_url = $this->api->get_iframe_url($token_result['token']);

        // Save iframe URL and timestamp
        $order->update_meta_data('_bna_iframe_url', $iframe_url);
        $order->update_meta_data('_bna_iframe_url_created_at', time());
        $order->update_meta_data('_bna_transaction_reference', $token_result['token']);
        $order->save();

        bna_log('Generated new iframe URL', array(
            'order_id' => $order->get_id()
        ));

        return $iframe_url;
    }

    private function redirect_with_error($order, $message) {
        wc_add_notice($message, 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    private function validate_order_for_payment($order) {
        $errors = array();

        if ($order->get_total() <= 0) {
            $errors[] = __('Order total must be greater than zero.', 'bna-smart-payment');
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
}