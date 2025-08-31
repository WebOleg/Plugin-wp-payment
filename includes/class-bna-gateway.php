<?php
/**
 * BNA Gateway
 * Complete WooCommerce payment gateway for BNA Smart Payment
 */

if (!defined('ABSPATH')) exit;

class BNA_Gateway extends WC_Payment_Gateway {

    private $api;

    public function __construct() {
        $this->id = 'bna_smart_payment';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'BNA Smart Payment';
        $this->method_description = 'Accept payments through BNA Smart Payment system.';
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();
        $this->load_settings();
        $this->init_hooks();

        // Initialize API
        $this->api = new BNA_API();

        bna_debug('BNA Gateway initialized', array(
            'gateway_id' => $this->id,
            'enabled' => $this->enabled,
            'has_credentials' => $this->has_required_settings()
        ));
    }

    /**
     * Load settings from options
     */
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
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'checkout_scripts'));

        // Add birthdate field if enabled
        if ($this->get_option('enable_birthdate') === 'yes') {
            add_filter('woocommerce_billing_fields', array($this, 'add_birthdate_field'));
            add_action('woocommerce_checkout_process', array($this, 'validate_birthdate'));
            add_action('woocommerce_checkout_update_order_meta', array($this, 'save_birthdate'));
        }
    }

    /**
     * Initialize form fields for admin
     */
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

    /**
     * Process admin options and save global settings
     */
    public function process_admin_options() {
        bna_log('Processing gateway admin options update');
        $saved = parent::process_admin_options();

        if ($saved) {
            // Save global options for other classes to use
            update_option('bna_smart_payment_environment', $this->get_option('environment'));
            update_option('bna_smart_payment_access_key', $this->get_option('access_key'));
            update_option('bna_smart_payment_secret_key', $this->get_option('secret_key'));
            update_option('bna_smart_payment_iframe_id', $this->get_option('iframe_id'));
            update_option('bna_smart_payment_enable_phone', $this->get_option('enable_phone'));
            update_option('bna_smart_payment_enable_billing_address', $this->get_option('enable_billing_address'));
            update_option('bna_smart_payment_enable_birthdate', $this->get_option('enable_birthdate'));

            bna_log('Gateway admin options saved successfully');
        }

        return $saved;
    }

    /**
     * Add birthdate field to checkout
     */
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

    /**
     * Validate birthdate field
     */
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
    }

    /**
     * Save birthdate to order meta
     */
    public function save_birthdate($order_id) {
        if (!empty($_POST['billing_birthdate'])) {
            $order = wc_get_order($order_id);
            $birthdate = sanitize_text_field($_POST['billing_birthdate']);
            $order->add_meta_data('_billing_birthdate', $birthdate);
            $order->save();
        }
    }

    /**
     * Public method to display payment page (called from main plugin)
     */
    public function display_payment_page_public($order) {
        try {
            // Check if order is already paid
            if ($order->is_paid()) {
                bna_log('Order already paid, redirecting to thank you page', array(
                    'order_id' => $order->get_id()
                ));
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            $payment_data = $this->get_payment_data($order);

            if (!$payment_data) {
                bna_error('Payment data generation failed', array(
                    'order_id' => $order->get_id()
                ));
                wc_add_notice('Unable to process payment. Please try again.', 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            bna_log('Payment page displayed', array(
                'order_id' => $order->get_id(),
                'iframe_url_length' => strlen($payment_data['iframe_url'])
            ));

            $this->render_payment_template($order, $payment_data['iframe_url']);

        } catch (Exception $e) {
            bna_error('Payment page display error', array(
                'order_id' => $order->get_id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));

            wc_add_notice('Payment processing error. Please try again.', 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /**
     * Get payment data (token and iframe URL)
     */
    private function get_payment_data($order) {
        // Check for valid existing token
        $existing_token = $this->get_existing_token($order);

        if ($existing_token && $this->is_token_valid($existing_token, $order)) {
            bna_debug('Using existing token', array(
                'order_id' => $order->get_id(),
                'token_age_minutes' => $this->get_token_age_minutes($order)
            ));

            return array(
                'token' => $existing_token,
                'iframe_url' => $this->api->get_iframe_url($existing_token),
                'source' => 'existing'
            );
        }

        // Generate new token
        return $this->generate_new_token($order);
    }

    /**
     * Get existing token from order
     */
    private function get_existing_token($order) {
        return $order->get_meta('_bna_checkout_token');
    }

    /**
     * Check if token is valid (not expired)
     */
    private function is_token_valid($token, $order) {
        if (empty($token)) {
            return false;
        }

        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        if (!$generated_at) {
            return false;
        }

        $age_minutes = (current_time('timestamp') - $generated_at) / 60;
        return $age_minutes <= 30; // 30 minutes max
    }

    /**
     * Get token age in minutes
     */
    private function get_token_age_minutes($order) {
        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        if (!$generated_at) {
            return null;
        }

        return round((current_time('timestamp') - $generated_at) / 60, 2);
    }

    /**
     * Generate new checkout token
     */
    private function generate_new_token($order) {
        bna_log('Generating new checkout token', array(
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total()
        ));

        // Clear old token
        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');

        // Generate via API
        $response = $this->api->generate_checkout_token($order);

        if (is_wp_error($response)) {
            bna_error('Token generation failed', array(
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message()
            ));
            return false;
        }

        if (!isset($response['token'])) {
            bna_error('Invalid token response', array(
                'order_id' => $order->get_id(),
                'response_keys' => array_keys($response)
            ));
            return false;
        }

        $token = $response['token'];

        // Store new token
        $order->add_meta_data('_bna_checkout_token', $token);
        $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
        $order->save();

        bna_log('New token generated and stored', array(
            'order_id' => $order->get_id(),
            'token_length' => strlen($token)
        ));

        return array(
            'token' => $token,
            'iframe_url' => $this->api->get_iframe_url($token),
            'source' => 'new'
        );
    }

    /**
     * Render payment template
     */
    private function render_payment_template($order, $iframe_url) {
        // Clean output buffer if exists
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Start fresh output buffer
        ob_start();

        get_header();
        ?>
        <div class="container" style="margin: 20px auto; max-width: 1200px; padding: 0 20px;">
            <script>
                window.bnaPaymentData = {
                    thankYouUrl: '<?php echo esc_js($order->get_checkout_order_received_url()); ?>',
                    orderId: <?php echo $order->get_id(); ?>
                };
            </script>

            <div class="bna-payment-container">
                <div class="bna-payment-header">
                    <h2>Complete Your Payment</h2>
                    <div class="bna-order-summary">
                        <p><strong>Order:</strong> #<?php echo esc_html($order->get_order_number()); ?></p>
                        <p><strong>Total:</strong> <?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></p>
                    </div>
                </div>

                <div id="bna-payment-loading" class="bna-loading">
                    <div class="bna-spinner"></div>
                    <p>Loading secure payment form...</p>
                </div>

                <div id="bna-payment-error" class="bna-error" style="display: none;">
                    <div class="bna-error-icon">⚠️</div>
                    <h3>Payment Form Error</h3>
                    <p id="bna-error-message">Unable to load payment form.</p>
                    <div class="bna-error-actions">
                        <button onclick="window.location.reload()" class="bna-btn bna-btn-primary">Refresh Page</button>
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn bna-btn-secondary">Back to Checkout</a>
                    </div>
                </div>

                <div id="bna-iframe-container">
                    <iframe
                            id="bna-payment-iframe"
                            src="<?php echo esc_url($iframe_url); ?>"
                            width="100%"
                            height="600"
                            frameborder="0"
                            style="border: 1px solid #ddd; border-radius: 8px;"
                            allow="payment"
                            title="BNA Payment Form">
                    </iframe>
                </div>
            </div>
        </div>
        <?php
        get_footer();

        // Output buffer contents and exit
        ob_end_flush();
        exit;
    }

    /**
     * Generate payment URL for order
     */
    private function get_payment_url($order) {
        return home_url('/bna-payment/' . $order->get_id() . '/' . $order->get_order_key() . '/');
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        return $this->enabled === 'yes' &&
            class_exists('WooCommerce') &&
            $this->has_required_settings();
    }

    /**
     * Check if all required settings are configured
     */
    private function has_required_settings() {
        return !empty($this->access_key) &&
            !empty($this->secret_key) &&
            !empty($this->iframe_id);
    }

    /**
     * Process payment (WooCommerce hook)
     */
    public function process_payment($order_id) {
        bna_log('Processing payment started', array('order_id' => $order_id));

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice('Order not found.', 'error');
            return array('result' => 'fail');
        }

        try {
            $validation = $this->validate_order_for_payment($order);

            if (!$validation['valid']) {
                foreach ($validation['errors'] as $error) {
                    wc_add_notice($error, 'error');
                }
                return array('result' => 'fail');
            }

            $this->prepare_order($order);
            $redirect_url = $this->get_payment_url($order);

            bna_log('Payment process completed', array(
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

            wc_add_notice('Payment processing error. Please try again.', 'error');
            return array('result' => 'fail');
        }
    }

    /**
     * Validate order for payment processing
     */
    private function validate_order_for_payment($order) {
        $errors = array();

        if ($order->is_paid()) {
            $errors[] = 'Order is already paid';
        }

        if ($order->get_total() <= 0) {
            $errors[] = 'Order total must be greater than 0';
        }

        if (empty($order->get_billing_email())) {
            $errors[] = 'Customer email is required';
        }

        $allowed_statuses = array('pending', 'on-hold', 'failed');
        if (!in_array($order->get_status(), $allowed_statuses)) {
            $errors[] = 'Order status not valid for payment processing';
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    /**
     * Prepare order for payment
     */
    private function prepare_order($order) {
        if ($order->get_status() !== 'pending') {
            $order->update_status('pending', 'Awaiting BNA Smart Payment.');
        }

        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->add_meta_data('_bna_payment_started_at', current_time('timestamp'));
        $order->save();
    }

    /**
     * Load checkout scripts
     */
    public function checkout_scripts() {
        if (is_admin()) return;

        wp_enqueue_style('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css', array(), BNA_SMART_PAYMENT_VERSION);
        wp_enqueue_script('bna-payment', BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js', array('jquery'), BNA_SMART_PAYMENT_VERSION, true);
    }

    /**
     * Admin options page
     */
    public function admin_options() {
        echo '<h3>BNA Smart Payment</h3>';
        echo '<p>Accept payments through BNA Smart Payment gateway with secure iFrame integration.</p>';

        $missing = $this->get_missing_settings();
        if (empty($missing)) {
            echo '<div class="notice notice-success"><p><strong>Status:</strong> ✅ Gateway configured and ready</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>Missing:</strong> ' . implode(', ', $missing) . '</p></div>';
        }

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    /**
     * Get missing required settings
     */
    private function get_missing_settings() {
        $missing = array();
        if (empty($this->access_key)) $missing[] = 'Access Key';
        if (empty($this->secret_key)) $missing[] = 'Secret Key';
        if (empty($this->iframe_id)) $missing[] = 'iFrame ID';
        return $missing;
    }
}