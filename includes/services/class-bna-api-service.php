<?php
/**
 * BNA API Service V2
 * Enhanced with new logging system
 */

if (!defined('ABSPATH')) exit;

class BNA_API_Service {

    const ENVIRONMENTS = array(
        'dev' => 'https://dev-api-service.bnasmartpayment.com',
        'staging' => 'https://stage-api-service.bnasmartpayment.com',
        'production' => 'https://api.bnasmartpayment.com'
    );

    private $environment;
    private $credentials;

    public function __construct() {
        $this->environment = get_option('bna_smart_payment_environment', 'staging');
        $this->credentials = array(
            'access_key' => get_option('bna_smart_payment_access_key', ''),
            'secret_key' => get_option('bna_smart_payment_secret_key', '')
        );

        bna_api_debug('API Service initialized', [
            'environment' => $this->environment,
            'has_credentials' => !empty($this->credentials['access_key']) && !empty($this->credentials['secret_key'])
        ]);
    }

    public function get_api_url() {
        return self::ENVIRONMENTS[$this->environment] ?? self::ENVIRONMENTS['staging'];
    }

    private function get_auth_headers() {
        $credentials_string = $this->credentials['access_key'] . ':' . $this->credentials['secret_key'];
        $encoded_credentials = base64_encode($credentials_string);

        return array(
            'Authorization' => 'Basic ' . $encoded_credentials,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        );
    }

    public function make_request($endpoint, $method = 'GET', $data = array()) {
        $start_time = microtime(true);
        
        bna_api_debug('API Request started', [
            'method' => $method,
            'endpoint' => $endpoint,
            'has_data' => !empty($data),
            'data_size' => !empty($data) ? strlen(wp_json_encode($data)) : 0
        ]);

        // Validate credentials
        if (empty($this->credentials['access_key']) || empty($this->credentials['secret_key'])) {
            bna_api_error('API request failed - missing credentials', [
                'endpoint' => $endpoint,
                'method' => $method
            ]);
            return new WP_Error('missing_credentials', 'API credentials are not configured');
        }

        $url = $this->get_api_url() . '/' . ltrim($endpoint, '/');
        bna_api_debug('API Request URL built', [
            'url' => $url,
            'environment' => $this->environment
        ]);

        $args = array(
            'method' => strtoupper($method),
            'headers' => $this->get_auth_headers(),
            'timeout' => 30,
            'sslverify' => true
        );

        // Add body data for POST/PUT requests
        if (in_array($method, array('POST', 'PUT', 'PATCH')) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
            bna_api_debug('API Request body prepared', [
                'body_size' => strlen($args['body']),
                'content_type' => 'application/json'
            ]);
        }

        // Add query parameters for GET requests
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
            bna_api_debug('GET parameters added to URL', [
                'params_count' => count($data)
            ]);
        }

        // Make the request
        $response = wp_remote_request($url, $args);
        $request_time = round((microtime(true) - $start_time) * 1000, 2);

        // Handle request errors
        if (is_wp_error($response)) {
            bna_api_error('API request failed with WP_Error', [
                'endpoint' => $endpoint,
                'method' => $method,
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
                'request_time_ms' => $request_time
            ]);
            return $response;
        }

        // Get response data
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        bna_api_debug('API response received', [
            'endpoint' => $endpoint,
            'method' => $method,
            'response_code' => $response_code,
            'response_size' => strlen($response_body),
            'request_time_ms' => $request_time
        ]);

        // Parse JSON response
        $parsed_response = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'Invalid JSON response: ' . json_last_error_msg();
            bna_api_error('API response JSON parse failed', [
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'response_body_preview' => substr($response_body, 0, 200),
                'response_code' => $response_code
            ]);
            return new WP_Error('invalid_response', $error_message);
        }

        // Handle API errors
        if ($response_code >= 400) {
            $error_message = isset($parsed_response['message']) ? $parsed_response['message'] : 'API request failed';
            bna_api_error('API request failed with HTTP error', [
                'endpoint' => $endpoint,
                'method' => $method,
                'response_code' => $response_code,
                'error_message' => $error_message,
                'request_time_ms' => $request_time,
                'response_data' => $parsed_response
            ]);
            return new WP_Error('api_error', $error_message, array('status' => $response_code));
        }

        bna_api_log('API request successful', [
            'endpoint' => $endpoint,
            'method' => $method,
            'response_code' => $response_code,
            'request_time_ms' => $request_time,
            'success' => true
        ]);

        return $parsed_response;
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        bna_api_debug('Testing API connection');
        
        $result = $this->make_request('v1/account', 'GET');
        
        if (is_wp_error($result)) {
            bna_api_error('API connection test failed', [
                'error_code' => $result->get_error_code(),
                'error_message' => $result->get_error_message()
            ]);
            return false;
        }

        bna_api_log('API connection test successful', [
            'has_company_name' => isset($result['companyName']),
            'environment' => $this->environment
        ]);

        return true;
    }

    /**
     * Get API status
     */
    public function get_status() {
        return [
            'environment' => $this->environment,
            'api_url' => $this->get_api_url(),
            'has_credentials' => !empty($this->credentials['access_key']) && !empty($this->credentials['secret_key']),
            'credentials_length' => [
                'access_key' => strlen($this->credentials['access_key']),
                'secret_key' => strlen($this->credentials['secret_key'])
            ]
        ];
    }
}
