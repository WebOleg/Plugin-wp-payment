<?php
/**
 * BNA Smart Payment Controller
 * 
 * Handles payment processing and iframe display
 *
 * @package BnaSmartPayment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BNA_Payment_Controller
 * Controller for payment processing
 */
class BNA_Payment_Controller {

    /**
     * iFrame service instance
     * @var BNA_iFrame_Service
     */
    private $iframe_service;

    /**
     * Constructor
     */
    public function __construct() {
        $this->iframe_service = new BNA_iFrame_Service();
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Handle payment processing page
        add_action('init', array($this, 'handle_payment_request'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add debug endpoint
        add_action('wp_ajax_bna_debug_token', array($this, 'debug_token_generation'));
        add_action('wp_ajax_nopriv_bna_debug_token', array($this, 'debug_token_generation'));
    }

    /**
     * Debug token generation
     */
    public function debug_token_generation() {
        if (!isset($_POST['order_id']) || !wp_verify_nonce($_POST['nonce'], 'bna_payment_nonce')) {
            wp_die('Security check failed');
        }

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        // Check API settings
        $api_settings = array(
            'environment' => get_option('bna_smart_payment_environment'),
            'access_key' => get_option('bna_smart_payment_access_key'),
            'secret_key' => get_option('bna_smart_payment_secret_key') ? 'SET' : 'NOT SET',
            'iframe_id' => get_option('bna_smart_payment_iframe_id')
        );

        // Try to generate token
        $response = $this->iframe_service->generate_checkout_token($order);
        
        wp_send_json_success(array(
            'settings' => $api_settings,
            'token_response' => is_wp_error($response) ? $response->get_error_message() : $response,
            'order_data' => array(
                'id' => $order->get_id(),
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'email' => $order->get_billing_email()
            )
        ));
    }

    /**
     * Handle payment processing requests
     */
    public function handle_payment_request() {
        if (isset($_GET['bna_payment']) && $_GET['bna_payment'] === 'process') {
            $this->display_payment_page();
        }
    }

    /**
     * Display payment page with iframe
     */
    public function display_payment_page() {
        // Verify parameters
        if (!isset($_GET['order_id']) || !isset($_GET['order_key'])) {
            wc_add_notice('Invalid payment request.', 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $order_id = intval($_GET['order_id']);
        $order_key = sanitize_text_field($_GET['order_key']);
        
        // Validate order
        $order = BNA_WooCommerce_Helper::validate_order($order_id, $order_key);
        
        if (!$order) {
            wc_add_notice('Order not found or invalid.', 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Debug: Check settings first
        $this->debug_settings();

        // Get or generate token
        $token = $this->get_or_generate_token($order);
        
        if (!$token) {
            // Show debug info in development
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->show_debug_page($order);
                return;
            }
            
            wc_add_notice('Payment session unavailable. Please try again.', 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Load payment template
        $this->load_payment_template($order, $token);
        exit;
    }

    /**
     * Debug settings
     */
    private function debug_settings() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('BNA Debug - Settings check:');
        error_log('Environment: ' . get_option('bna_smart_payment_environment', 'NOT SET'));
        error_log('Access Key: ' . (get_option('bna_smart_payment_access_key') ? 'SET' : 'NOT SET'));
        error_log('Secret Key: ' . (get_option('bna_smart_payment_secret_key') ? 'SET' : 'NOT SET'));
        error_log('iFrame ID: ' . (get_option('bna_smart_payment_iframe_id') ? get_option('bna_smart_payment_iframe_id') : 'NOT SET'));
    }

    /**
     * Show debug page
     */
    private function show_debug_page($order) {
        get_header();
        ?>
        <div class="container" style="max-width: 800px; margin: 50px auto; padding: 20px;">
            <h2>BNA Payment Debug Information</h2>
            
            <div id="debug-info" style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0;">
                <p><strong>Loading debug information...</strong></p>
            </div>
            
            <div style="margin: 20px 0;">
                <button id="test-token" class="button button-primary">Test Token Generation</button>
                <a href="<?php echo wc_get_checkout_url(); ?>" class="button">Back to Checkout</a>
            </div>
        </div>

        <script>
        document.getElementById('test-token').addEventListener('click', function() {
            var debugDiv = document.getElementById('debug-info');
            debugDiv.innerHTML = '<p>Testing token generation...</p>';
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=bna_debug_token&order_id=<?php echo $order->get_id(); ?>&nonce=<?php echo wp_create_nonce('bna_payment_nonce'); ?>'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Debug response:', data);
                debugDiv.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            })
            .catch(error => {
                debugDiv.innerHTML = '<p style="color: red;">Error: ' + error.message + '</p>';
            });
        });
        </script>
        <?php
        get_footer();
    }

    /**
     * Get existing token or generate new one
     * 
     * @param WC_Order $order
     * @return string|false
     */
    private function get_or_generate_token($order) {
        // Check for existing token
        $existing_token = $order->get_meta('_bna_checkout_token');
        $generated_at = $order->get_meta('_bna_checkout_generated_at');
        
        // Check if token is still valid (tokens expire after 30 minutes)
        if ($existing_token && $generated_at) {
            $token_age = current_time('timestamp') - $generated_at;
            if ($token_age < 25 * 60) { // Use token if less than 25 minutes old
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BNA Debug - Using existing token: ' . substr($existing_token, 0, 10) . '...');
                }
                return $existing_token;
            }
        }

        // Generate new token
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BNA Debug - Generating new token for order: ' . $order->get_id());
        }

        $response = $this->iframe_service->generate_checkout_token($order);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            BNA_WooCommerce_Helper::add_order_note($order, 'Token generation failed: ' . $error_message);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BNA Debug - Token generation error: ' . $error_message);
            }
            
            return false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('BNA Debug - API Response: ' . print_r($response, true));
        }

        $token = $response['token'] ?? false;
        
        if (!$token) {
            BNA_WooCommerce_Helper::add_order_note($order, 'Token generation failed: No token in response');
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('BNA Debug - No token in response');
            }
        }

        return $token;
    }

    /**
     * Load payment template
     * 
     * @param WC_Order $order
     * @param string $token
     */
    private function load_payment_template($order, $token) {
        // Set template data
        $template_data = array(
            'order' => $order,
            'token' => $token,
            'iframe_url' => $this->iframe_service->get_iframe_url($token),
            'return_url' => $order->get_checkout_order_received_url(),
            'checkout_url' => wc_get_checkout_url()
        );

        // Load WordPress header
        get_header();

        // Load payment template
        $this->get_template('payment-iframe', $template_data);

        // Load WordPress footer
        get_footer();
    }

    /**
     * Load template file
     * 
     * @param string $template_name
     * @param array $data
     */
    private function get_template($template_name, $data = array()) {
        $template_path = BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/' . $template_name . '.php';
        
        if (file_exists($template_path)) {
            // Extract data to variables
            extract($data);
            
            // Include template
            include $template_path;
        } else {
            echo '<p>Template not found: ' . esc_html($template_name) . '</p>';
        }
    }

    /**
     * Enqueue necessary scripts and styles
     */
    public function enqueue_scripts() {
        // Only enqueue on payment pages
        if (!isset($_GET['bna_payment'])) {
            return;
        }

        wp_enqueue_script(
            'bna-payment',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/payment.js',
            array('jquery'),
            BNA_SMART_PAYMENT_VERSION,
            true
        );

        wp_enqueue_style(
            'bna-payment',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/payment.css',
            array(),
            BNA_SMART_PAYMENT_VERSION
        );

        // Pass data to JavaScript
        wp_localize_script('bna-payment', 'bna_payment_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'checkout_url' => wc_get_checkout_url(),
            'nonce' => wp_create_nonce('bna_payment_nonce')
        ));
    }
}
