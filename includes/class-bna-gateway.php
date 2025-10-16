<?php

if (!defined('ABSPATH')) exit;

class BNA_Gateway extends WC_Payment_Gateway {

    private $api;

    public function __construct() {
        $this->id = 'bna_smart_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment system with secure iframe integration, automatic customer data sync and subscription support using product meta fields.';
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();
        $this->init_hooks();

        $this->api = new BNA_API();

        bna_debug('BNA Gateway initialized', array(
            'gateway_id' => $this->id,
            'enabled' => $this->enabled,
            'subscription_system' => 'meta_fields'
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

        $this->enable_subscriptions = $this->get_option('enable_subscriptions');
        $this->allow_subscription_trials = $this->get_option('allow_subscription_trials');
        $this->allow_signup_fees = $this->get_option('allow_signup_fees');

        $this->apply_fees = $this->get_option('apply_fees');
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

        if ($this->get_option('enable_subscriptions') === 'yes') {
            add_action('woocommerce_checkout_process', array($this, 'validate_subscription_checkout'));
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
                        <p style="margin-bottom: 0;"><strong>📋 Required Events:</strong> <code>transaction.*</code>, <code>payment_method.*</code>, <code>customer.*</code>, <code>subscription.*</code></p>
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
            'fees_settings' => array(
                'title' => 'Payment Processing Fees',
                'type' => 'title',
                'description' => 'Configure whether to apply BNA payment processing fees to transactions.',
            ),
            'apply_fees' => array(
                'title' => 'Apply Payment Fees',
                'type' => 'checkbox',
                'label' => 'Apply BNA payment processing fees',
                'default' => 'no',
                'description' => 'When enabled, BNA will automatically add processing fees in the payment form based on the payment method selected by the customer. Fees are configured in your BNA Merchant Portal.',
            ),
            'subscription_settings' => array(
                'title' => 'Subscription Settings',
                'type' => 'title',
                'description' => '
                    <div style="background: #f0f8ff; padding: 15px; border-left: 4px solid #0073aa; margin: 10px 0;">
                        <h4 style="margin-top: 0;">📋 BNA Subscription Features</h4>
                        <p style="margin-bottom: 10px;">Enable recurring payments and subscription products. This allows you to:</p>
                        <ul style="margin-left: 20px; margin-bottom: 10px;">
                            <li>Add subscription options to any WooCommerce product</li>
                            <li>Offer free trials and sign-up fees</li>
                            <li>Let customers manage subscriptions in My Account</li>
                            <li>Automatic recurring billing through BNA API</li>
                        </ul>
                        <p style="margin-bottom: 0;"><strong>Note:</strong> Uses product meta fields for better compatibility.</p>
                    </div>
                '
            ),
            'enable_subscriptions' => array(
                'title' => 'Enable Subscriptions',
                'type' => 'checkbox',
                'label' => 'Enable subscription options for products',
                'default' => 'no',
                'description' => 'Allow products to have subscription billing options.',
            ),
            'allow_subscription_trials' => array(
                'title' => 'Allow Free Trials',
                'type' => 'checkbox',
                'label' => 'Allow products to offer free trial periods',
                'default' => 'yes',
                'description' => 'Enable free trial periods for subscription products.',
            ),
            'allow_signup_fees' => array(
                'title' => 'Allow Sign-up Fees',
                'type' => 'checkbox',
                'label' => 'Allow products to charge one-time sign-up fees',
                'default' => 'yes',
                'description' => 'Enable one-time sign-up fees for subscription products.',
            ),
        );
    }

    public function process_admin_options() {
        $saved = parent::process_admin_options();

        $subscription_options = array(
            'bna_smart_payment_enable_subscriptions' => $this->get_option('enable_subscriptions', 'no'),
            'bna_smart_payment_allow_subscription_trials' => $this->get_option('allow_subscription_trials', 'yes'),
            'bna_smart_payment_allow_signup_fees' => $this->get_option('allow_signup_fees', 'yes'),
            'bna_smart_payment_apply_fees' => $this->get_option('apply_fees', 'no')
        );

        foreach ($subscription_options as $option_name => $option_value) {
            update_option($option_name, $option_value);
        }

        if ($saved) {
            bna_log('Gateway settings saved', array(
                'subscriptions_enabled' => $this->get_option('enable_subscriptions', 'no'),
                'subscription_system' => 'meta_fields',
                'apply_fees' => $this->get_option('apply_fees', 'no')
            ));
        }

        return $saved;
    }

    public function validate_subscription_checkout() {
        bna_debug('=== SUBSCRIPTION CHECKOUT VALIDATION START ===', array(
            'payment_method' => $_POST['payment_method'] ?? 'not_set',
            'our_gateway_id' => $this->id,
            'is_our_method' => ($_POST['payment_method'] ?? '') === $this->id,
            'subscriptions_enabled' => $this->get_option('enable_subscriptions'),
            'global_subscriptions_enabled' => get_option('bna_smart_payment_enable_subscriptions')
        ));

        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            bna_debug('SUBSCRIPTION VALIDATION SKIPPED - not our payment method');
            return;
        }

        $has_subscription = false;
        $subscription_products = array();

        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                $is_subscription = get_post_meta($product->get_id(), '_bna_is_subscription', true) === 'yes';

                if ($is_subscription) {
                    $has_subscription = true;
                    $subscription_products[] = array(
                        'id' => $product->get_id(),
                        'name' => $product->get_name(),
                        'frequency' => get_post_meta($product->get_id(), '_bna_subscription_frequency', true)
                    );
                }
            }
        }

        bna_debug('=== SUBSCRIPTION PRODUCTS CHECK ===', array(
            'has_subscription' => $has_subscription,
            'subscription_products_count' => count($subscription_products),
            'subscription_products' => $subscription_products
        ));

        if (!$has_subscription) {
            bna_debug('SUBSCRIPTION VALIDATION SKIPPED - no subscription products');
            return;
        }

        $validation_errors = array();

        if (empty($_POST['billing_email'])) {
            $validation_errors[] = 'missing_email';
            wc_add_notice(__('Email address is required for subscription orders.', 'bna-smart-payment'), 'error');
        }

        if (!is_user_logged_in() && !WC()->checkout()->is_registration_enabled()) {
            $validation_errors[] = 'registration_required';
            wc_add_notice(__('You must create an account to purchase subscription products.', 'bna-smart-payment'), 'error');
        }

        if (!empty($validation_errors)) {
            bna_error('SUBSCRIPTION CHECKOUT VALIDATION FAILED', array(
                'errors' => $validation_errors,
                'is_logged_in' => is_user_logged_in(),
                'registration_enabled' => WC()->checkout()->is_registration_enabled()
            ));
        } else {
            bna_debug('SUBSCRIPTION CHECKOUT VALIDATION PASSED');
        }
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
        bna_debug('=== BIRTHDATE VALIDATION START ===', array(
            'payment_method' => $_POST['payment_method'] ?? 'not_set',
            'our_gateway_id' => $this->id,
            'is_our_method' => ($_POST['payment_method'] ?? '') === $this->id,
            'birthdate_enabled' => $this->get_option('enable_birthdate')
        ));

        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            bna_debug('BIRTHDATE VALIDATION SKIPPED - not our payment method');
            return;
        }

        bna_debug('=== BIRTHDATE FIELD CHECK ===', array(
            'birthdate_value' => $_POST['billing_birthdate'] ?? 'not_set',
            'is_empty' => empty($_POST['billing_birthdate'])
        ));

        if (empty($_POST['billing_birthdate'])) {
            wc_add_notice(__('Date of birth is a required field.', 'bna-smart-payment'), 'error');
            bna_error('BIRTHDATE VALIDATION FAILED: field empty');
            return;
        }

        $birthdate = sanitize_text_field($_POST['billing_birthdate']);
        $birth_timestamp = strtotime($birthdate);

        bna_debug('=== BIRTHDATE VALIDATION DETAILS ===', array(
            'birthdate' => $birthdate,
            'birth_timestamp' => $birth_timestamp,
            'is_valid_date' => $birth_timestamp !== false,
            'current_time' => time(),
            'eighteen_years_ago' => strtotime('-18 years')
        ));

        if ($birth_timestamp === false) {
            wc_add_notice(__('Please enter a valid birth date.', 'bna-smart-payment'), 'error');
            bna_error('BIRTHDATE VALIDATION FAILED: invalid format', array('birthdate' => $birthdate));
            return;
        }

        if ($birth_timestamp > strtotime('-18 years') || $birth_timestamp > time()) {
            $age_years = floor((time() - $birth_timestamp) / (365.25 * 24 * 60 * 60));
            wc_add_notice(__('Please enter a valid birth date. You must be at least 18 years old.', 'bna-smart-payment'), 'error');
            bna_error('BIRTHDATE VALIDATION FAILED: age requirement', array(
                'birthdate' => $birthdate,
                'age_years' => $age_years,
                'required_age' => 18
            ));
            return;
        }

        bna_debug('BIRTHDATE VALIDATION PASSED', array('birthdate' => $birthdate));
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
        bna_debug('=== SHIPPING VALIDATION START ===', array(
            'payment_method' => $_POST['payment_method'] ?? 'not_set',
            'our_gateway_id' => $this->id,
            'is_our_method' => ($_POST['payment_method'] ?? '') === $this->id,
            'shipping_enabled' => $this->get_option('enable_shipping_address')
        ));

        if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
            bna_debug('SHIPPING VALIDATION SKIPPED - not our payment method');
            return;
        }

        $same_as_billing = !empty($_POST['bna_shipping_same_as_billing']);

        bna_debug('=== SHIPPING ADDRESS CHECK ===', array(
            'same_as_billing' => $same_as_billing,
            'bna_shipping_country' => $_POST['bna_shipping_country'] ?? 'not_set',
            'bna_shipping_address_1' => $_POST['bna_shipping_address_1'] ?? 'not_set',
            'bna_shipping_city' => $_POST['bna_shipping_city'] ?? 'not_set',
            'bna_shipping_postcode' => $_POST['bna_shipping_postcode'] ?? 'not_set'
        ));

        if (!$same_as_billing) {
            $required_fields = array(
                'bna_shipping_country' => __('Country', 'bna-smart-payment'),
                'bna_shipping_address_1' => __('Street address', 'bna-smart-payment'),
                'bna_shipping_city' => __('City', 'bna-smart-payment'),
                'bna_shipping_postcode' => __('Postal code', 'bna-smart-payment')
            );

            $missing_fields = array();
            foreach ($required_fields as $field => $label) {
                if (empty($_POST[$field])) {
                    $missing_fields[] = $label;
                    wc_add_notice(sprintf(__('%s is a required field.', 'bna-smart-payment'), $label), 'error');
                }
            }

            if (!empty($missing_fields)) {
                bna_error('SHIPPING VALIDATION FAILED: missing fields', array(
                    'missing_fields' => $missing_fields
                ));
            } else {
                bna_debug('SHIPPING VALIDATION PASSED - all fields provided');
            }
        } else {
            bna_debug('SHIPPING VALIDATION SKIPPED - using billing address');
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
        bna_debug('=== PAYMENT PROCESS START ===', array(
            'order_id' => $order_id,
            'payment_method' => $_POST['payment_method'] ?? 'not_set',
            'timestamp' => current_time('c')
        ));

        bna_debug('=== SUBSCRIPTION SETTINGS ===', array(
            'gateway_subscriptions_enabled' => $this->get_option('enable_subscriptions'),
            'global_subscriptions_enabled' => get_option('bna_smart_payment_enable_subscriptions'),
            'birthdate_enabled' => $this->get_option('enable_birthdate'),
            'shipping_enabled' => $this->get_option('enable_shipping_address')
        ));

        $cart_debug = array();
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $cart_debug[] = array(
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'is_subscription_meta' => get_post_meta($product->get_id(), '_bna_is_subscription', true),
                    'subscription_frequency' => get_post_meta($product->get_id(), '_bna_subscription_frequency', true),
                    'has_bna_subscription_flag' => isset($cart_item['bna_subscription'])
                );
            }
        }
        bna_debug('=== CART CONTENTS ===', $cart_debug);

        $post_debug = array(
            'billing_email' => $_POST['billing_email'] ?? 'not_set',
            'billing_first_name' => $_POST['billing_first_name'] ?? 'not_set',
            'billing_last_name' => $_POST['billing_last_name'] ?? 'not_set',
            'billing_birthdate' => $_POST['billing_birthdate'] ?? 'not_set',
            'payment_method' => $_POST['payment_method'] ?? 'not_set',
            'has_shipping_fields' => isset($_POST['bna_shipping_country']),
            'bna_shipping_same_as_billing' => $_POST['bna_shipping_same_as_billing'] ?? 'not_set'
        );
        bna_debug('=== CHECKOUT POST DATA ===', $post_debug);

        bna_log('Processing payment for order', array('order_id' => $order_id));

        $order = wc_get_order($order_id);
        if (!$order) {
            bna_error('Order not found', array('order_id' => $order_id));
            wc_add_notice(__('Order not found. Please try again.', 'bna-smart-payment'), 'error');
            return array('result' => 'failure');
        }

        $validation = $this->validate_order_for_payment($order);
        if (!$validation['valid']) {
            bna_error('Order validation failed', array(
                'order_id' => $order_id,
                'validation_errors' => $validation['errors']
            ));
            foreach ($validation['errors'] as $error) {
                wc_add_notice($error, 'error');
            }
            return array('result' => 'failure');
        }

        $this->prepare_order_for_payment($order);

        $payment_url = home_url('/bna-payment/' . $order->get_id() . '/' . $order->get_order_key());

        bna_log('Payment process initiated', array(
            'order_id' => $order_id,
            'payment_url' => $payment_url,
            'has_subscription' => BNA_Subscriptions::order_has_subscription($order)
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

        if (BNA_Subscriptions::order_has_subscription($order)) {
            bna_debug('Validating subscription order', array('order_id' => $order->get_id()));

            if (!BNA_Subscriptions::is_enabled()) {
                $errors[] = __('Subscription payments are currently disabled.', 'bna-smart-payment');
            }
        }

        bna_debug('=== ORDER VALIDATION RESULT ===', array(
            'is_valid' => empty($errors),
            'errors_count' => count($errors),
            'errors' => $errors,
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total(),
            'billing_email' => $order->get_billing_email(),
            'billing_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'has_subscription_check' => BNA_Subscriptions::order_has_subscription($order)
        ));

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    private function prepare_order_for_payment($order) {
        $order->update_status('pending', __('Awaiting BNA payment.', 'bna-smart-payment'));
        $order->add_meta_data('_bna_payment_initiated', current_time('mysql'));

        if (BNA_Subscriptions::order_has_subscription($order)) {
            $order->add_meta_data('_bna_order_type', 'subscription');
        }

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