<?php
/**
 * BNA Smart Payment API handler
 *
 * @package BnaSmartPayment
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BNA_API
 * Handles all API communications with BNA Smart Payment service
 */
class BNA_API {

    /**
     * API endpoints for different environments
     */
    const ENVIRONMENTS = array(
        'dev' => 'https://dev-api-service.bnasmartpayment.com',
        'staging' => 'https://stage-api-service.bnasmartpayment.com',
        'production' => 'https://api.bnasmartpayment.com'
    );

    /**
     * Current environment
     * @var string
     */
    private $environment;

    /**
     * API credentials
     * @var array
     */
    private $credentials;

    /**
     * Constructor
     */
    public function __construct() {
        $this->environment = get_option('bna_smart_payment_environment', 'staging');
        $this->credentials = array(
            'access_key' => get_option('bna_smart_payment_access_key', ''),
            'secret_key' => get_option('bna_smart_payment_secret_key', '')
        );
    }

    /**
     * Get API base URL for current environment
     * @return string
     */
    public function get_api_url() {
        return self::ENVIRONMENTS[$this->environment] ?? self::ENVIRONMENTS['staging'];
    }

    /**
     * Get authorization headers for API requests
     * @return array
     */
    private function get_auth_headers() {
        $credentials_string = $this->credentials['access_key'] . ':' . $this->credentials['secret_key'];
        $encoded_credentials = base64_encode($credentials_string);
        
        return array(
            'Authorization' => 'Basic ' . $encoded_credentials,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );
    }

    /**
     * Make API request
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array $data Request data
     * @return array|WP_Error
     */
    public function make_request($endpoint, $method = 'GET', $data = array()) {
        // Validate credentials
        if (empty($this->credentials['access_key']) || empty($this->credentials['secret_key'])) {
            return new WP_Error('missing_credentials', 'API credentials are not configured');
        }

        $url = $this->get_api_url() . '/' . ltrim($endpoint, '/');
        
        $args = array(
            'method' => strtoupper($method),
            'headers' => $this->get_auth_headers(),
            'timeout' => 30,
            'sslverify' => true
        );

        // Add body data for POST/PUT requests
        if (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        // Add query parameters for GET requests
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        // Make the request
        $response = wp_remote_request($url, $args);

        // Handle request errors
        if (is_wp_error($response)) {
            return $response;
        }

        // Get response data
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Parse JSON response
        $parsed_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Invalid JSON response: ' . json_last_error_msg();
            return new WP_Error('invalid_response', $error_message);
        }

        // Handle API errors
        if ($response_code >= 400) {
            $error_message = isset($parsed_response['message']) ? $parsed_response['message'] : 'API request failed';
            return new WP_Error('api_error', $error_message, array('status' => $response_code));
        }

        return $parsed_response;
    }
}
