<?php
/**
 * BNA Gateway
 * Simplified and fixed WooCommerce payment gateway
 */

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

        // Customer detail settings
        $this->enable_phone = $this->get_option('enable_phone');
        $this->enable_billing_address = $this->get_option('enable_billing_address');
        $this->enable_birthdate = $this->get_option('enable_birthdate');
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

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
        );
    }

    /**
     * Process admin options and save global settings
     */
    public function process_admin_options() {
        bna_log('Processing gateway admin options update');
        $saved = parent::process_admin_options();

        if ($saved) {
            // Save global options for other classes to access
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
     * Validate birthdate field during checkout
     */
    public function validate_birthdate() {
        // Only validate if this payment method is selected
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
            if ($order) {
                $birthdate = sanitize_text_field($_POST['billing_birthdate']);
                $order->add_meta_data('_billing_birthdate', $birthdate);
                $order->save();

                bna_debug('Birthdate saved to order', array(
                    'order_id' => $order_id,
                    'birthdate' => $birthdate
                ));
            }
        }
    }

    /**
     * Display payment page (called from main plugin class)
     */
    public function display_payment_page_public($order) {
        try {
            // Перевіряємо чи замовлення вже оплачено
            if ($order->is_paid()) {
                bna_log('Order already paid, redirecting to thank you page', array(
                    'order_id' => $order->get_id(),
                    'order_status' => $order->get_status()
                ));
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            // Отримуємо iframe URL
            $iframe_url = $this->get_or_create_iframe_url($order);

            if (!$iframe_url) {
                bna_error('Failed to get iframe URL', array('order_id' => $order->get_id()));
                $this->redirect_with_error($order, 'Unable to load payment form. Please try again.');
                return;
            }

            bna_log('Payment page displayed successfully', array(
                'order_id' => $order->get_id(),
                'iframe_url_length' => strlen($iframe_url)
            ));

            // Рендеримо сторінку оплати
            $this->render_payment_page($order, $iframe_url);

        } catch (Exception $e) {
            bna_error('Payment page display exception', array(
                'order_id' => $order->get_id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));

            $this->redirect_with_error($order, 'Payment processing error. Please try again.');
        }
    }

    /**
     * Get or create iframe URL for order
     */
    private function get_or_create_iframe_url($order) {
        // Перевіряємо існуючий токен
        $existing_token = $order->get_meta('_bna_checkout_token');
        $token_generated_at = $order->get_meta('_bna_checkout_generated_at');

        // Якщо токен існує і не старше 25 хвилин - використовуємо його
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

        // Створюємо новий токен
        return $this->create_new_iframe_url($order);
    }

    /**
     * Create new iframe URL for order
     */
    private function create_new_iframe_url($order) {
        bna_log('Creating new checkout token', array(
            'order_id' => $order->get_id(),
            'order_total' => $order->get_total()
        ));

        // Видаляємо старий токен
        $order->delete_meta_data('_bna_checkout_token');
        $order->delete_meta_data('_bna_checkout_generated_at');

        // Генеруємо новий токен через API
        $response = $this->api->generate_checkout_token($order);

        if (is_wp_error($response)) {
            bna_error('Token generation failed', array(
                'order_id' => $order->get_id(),
                'error' => $response->get_error_message(),
                'error_data' => $response->get_error_data()
            ));
            return false;
        }

        if (empty($response['token'])) {
            bna_error('No token in response', array(
                'order_id' => $order->get_id(),
                'response' => $response
            ));
            return false;
        }

        $token = $response['token'];

        // Зберігаємо новий токен
        $order->add_meta_data('_bna_checkout_token', $token);
        $order->add_meta_data('_bna_checkout_generated_at', current_time('timestamp'));
        $order->save();

        bna_log('New token created and saved', array(
            'order_id' => $order->get_id(),
            'token_length' => strlen($token)
        ));

        return $this->api->get_iframe_url($token);
    }

    /**
     * Render payment page template
     */
    private function render_payment_page($order, $iframe_url) {
        // Clean output buffer
        while (ob_get_level()) {
            ob_end_clean();
        }

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
                    <h2><?php _e('Complete Your Payment', 'bna-smart-payment'); ?></h2>
                    <div class="bna-order-summary">
                        <p><strong><?php _e('Order:', 'bna-smart-payment'); ?></strong> #<?php echo esc_html($order->get_order_number()); ?></p>
                        <p><strong><?php _e('Total:', 'bna-smart-payment'); ?></strong> <?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></p>
                    </div>
                </div>

                <!-- Loading State -->
                <div id="bna-payment-loading" class="bna-loading">
                    <div class="bna-spinner"></div>
                    <p><?php _e('Loading secure payment form...', 'bna-smart-payment'); ?></p>
                </div>

                <!-- Error State -->
                <div id="bna-payment-error" class="bna-error" style="display: none;">
                    <div class="bna-error-icon">⚠️</div>
                    <h3><?php _e('Payment Form Error', 'bna-smart-payment'); ?></h3>
                    <p id="bna-error-message"><?php _e('Unable to load payment form.', 'bna-smart-payment'); ?></p>
                    <div class="bna-error-actions">
                        <button onclick="window.location.reload()" class="bna-btn bna-btn-primary">
                            <?php _e('Refresh Page', 'bna-smart-payment'); ?>
                        </button>
                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn bna-btn-secondary">
                            <?php _e('Back to Checkout', 'bna-smart-payment'); ?>
                        </a>
                    </div>
                </div>

                <!-- Payment iFrame -->
                <div id="bna-iframe-container">
                    <iframe
                            id="bna-payment-iframe"
                            src="<?php echo esc_url($iframe_url); ?>"
                            width="100%"
                            height="600"
                            frameborder="0"
                            style="border: 1px solid #ddd; border-radius: 8px;"
                            allow="payment"
                            title="<?php esc_attr_e('BNA Payment Form', 'bna-smart-payment'); ?>">
                    </iframe>
                </div>
            </div>
        </div>
        <?php
        get_footer();

        ob_end_flush();
        exit;
    }

    /**
     * Redirect with error message
     */
    private function redirect_with_error($order, $message) {
        wc_add_notice($message, 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Generate payment URL for order
     */
    private function get_payment_url($order) {
        return home_url('/bna-payment/' . $order->get_id() . '/' . $order->get_order_key() . '/');
    }

    /**
     * Check if gateway is available for checkout
     */
    public function is_available() {
        if ($this->enabled !== 'yes') {
            return false;
        }

        if (!class_exists('WooCommerce')) {
            return false;
        }

        if (!$this->has_required_settings()) {
            return false;
        }

        return true;
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
     * Process payment (WooCommerce checkout hook)
     */
    public function process_payment($order_id) {
        bna_log('Processing payment started', array('order_id' => $order_id));

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'bna-smart-payment'), 'error');
            return array('result' => 'fail');
        }

        try {
            // Валідуємо замовлення
            $validation_result = $this->validate_order_for_payment($order);
            if (!$validation_result['valid']) {
                foreach ($validation_result['errors'] as $error) {
                    wc_add_notice($error, 'error');
                }
                return array('result' => 'fail');
            }

            // Підготовляємо замовлення
            $this->prepare_order_for_payment($order);

            // Отримуємо URL для перенаправлення
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));

            wc_add_notice(__('Payment processing error. Please try again.', 'bna-smart-payment'), 'error');
            return array('result' => 'fail');
        }
    }

    /**
     * Validate order before payment processing
     */
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

    /**
     * Prepare order for payment
     */
    private function prepare_order_for_payment($order) {
        // Set order status to pending if not already
        if ($order->get_status() !== 'pending') {
            $order->update_status('pending', __('Awaiting BNA Smart Payment.', 'bna-smart-payment'));
        }

        // Add payment metadata
        $order->add_meta_data('_bna_payment_method', 'iframe');
        $order->add_meta_data('_bna_payment_started_at', current_time('timestamp'));
        $order->save();

        bna_debug('Order prepared for payment', array(
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status()
        ));
    }

    /**
     * Admin options page
     */
    public function admin_options() {
        echo '<h2>BNA Smart Payment Gateway</h2>';
        echo '<p>' . __('Accept payments through BNA Smart Payment with secure iframe integration.', 'bna-smart-payment') . '</p>';

        // Show configuration status
        $missing = $this->get_missing_settings();
        if (empty($missing)) {
            echo '<div class="notice notice-success inline"><p><strong>' . __('Status:', 'bna-smart-payment') . '</strong> ✅ ' . __('Gateway configured and ready', 'bna-smart-payment') . '</p></div>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>' . __('Missing configuration:', 'bna-smart-payment') . '</strong> ' . implode(', ', $missing) . '</p></div>';
        }

        // Show webhook URL info
        echo '<div class="notice notice-info inline">';
        echo '<p><strong>' . __('Webhook URL (configure in BNA Portal):', 'bna-smart-payment') . '</strong><br>';
        echo '<code>' . home_url('/wp-json/bna/v1/webhook') . '</code></p>';
        echo '</div>';

        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    /**
     * Get missing required settings for status display
     */
    private function get_missing_settings() {
        $missing = array();
        if (empty($this->access_key)) $missing[] = __('Access Key', 'bna-smart-payment');
        if (empty($this->secret_key)) $missing[] = __('Secret Key', 'bna-smart-payment');
        if (empty($this->iframe_id)) $missing[] = __('iFrame ID', 'bna-smart-payment');
        return $missing;
    }
}