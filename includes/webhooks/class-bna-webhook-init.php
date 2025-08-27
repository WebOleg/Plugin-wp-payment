<?php
/**
 * BNA Webhook Initialization
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Webhook_Init {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        // Load trait first
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/traits/trait-bna-webhook-logger.php';

        // Load core classes
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/class-bna-webhook-validator.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/class-bna-webhook-router.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/class-bna-webhook-handler.php';

        // Load handlers
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/handlers/class-bna-transaction-webhook.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/handlers/class-bna-subscription-webhook.php';
        require_once BNA_SMART_PAYMENT_PLUGIN_PATH . 'includes/webhooks/handlers/class-bna-customer-webhook.php';

        BNA_Logger::debug('Webhook dependencies loaded');
    }

    private function init_hooks() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        BNA_Logger::debug('Webhook hooks initialized');
    }

    public function register_rest_routes() {
        // Main webhook endpoint (public access for BNA to call)
        register_rest_route('bna/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook_request'],
            'permission_callback' => '__return_true',
            'args' => []
        ]);

        // Test endpoint (public when debug enabled)
        register_rest_route('bna/v1', '/webhook/test', [
            'methods' => 'GET',
            'callback' => [$this, 'test_webhook_endpoint'],
            'permission_callback' => '__return_true'
        ]);

        // Status endpoint
        register_rest_route('bna/v1', '/webhook/status', [
            'methods' => 'GET',
            'callback' => [$this, 'webhook_status'],
            'permission_callback' => '__return_true'
        ]);

        BNA_Logger::debug('Webhook REST routes registered', [
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'test_url' => home_url('/wp-json/bna/v1/webhook/test'),
            'status_url' => home_url('/wp-json/bna/v1/webhook/status')
        ]);
    }

    public function handle_webhook_request(WP_REST_Request $request) {
        $handler = new BNA_Webhook_Handler();
        return $handler->process_webhook($request);
    }

    public function test_webhook_endpoint(WP_REST_Request $request) {
        $debug_enabled = get_option('bna_debug_enabled', false);
        
        if (!$debug_enabled) {
            return [
                'status' => 'info',
                'message' => 'Debug mode is disabled. Enable it in BNA Debug settings.',
                'debug_url' => admin_url('admin.php?page=bna-debug'),
                'webhook_url' => self::get_webhook_url()
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Webhook test endpoint is working!',
            'webhook_url' => self::get_webhook_url(),
            'debug_enabled' => true,
            'server_time' => current_time('c'),
            'wordpress_version' => get_bloginfo('version'),
            'plugin_version' => BNA_SMART_PAYMENT_VERSION,
            'test_data' => [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ]
        ];
    }

    public function webhook_status(WP_REST_Request $request) {
        return [
            'status' => 'operational',
            'webhook_url' => self::get_webhook_url(),
            'plugin_version' => BNA_SMART_PAYMENT_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'server_time' => current_time('c'),
            'supported_events' => self::get_webhook_config()['events']
        ];
    }

    public static function get_webhook_url() {
        return home_url('/wp-json/bna/v1/webhook');
    }

    public static function get_webhook_config() {
        return [
            'url' => self::get_webhook_url(),
            'events' => [
                'transaction.approved',
                'transaction.declined', 
                'transaction.canceled',
                'subscription.created',
                'customer.created'
            ],
            'secret' => get_option('bna_smart_payment_webhook_secret', '')
        ];
    }
}

// Initialize webhooks
BNA_Webhook_Init::get_instance();
