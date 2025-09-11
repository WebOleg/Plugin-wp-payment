<?php
/**
 * BNA Webhooks Handler
 * Handles incoming webhook requests with HMAC security verification
 * Supports both new event-based format and legacy webhook formats
 *
 * @since 1.8.0 Added HMAC signature verification and new event-based webhook support
 * @since 1.7.0 Payment methods webhook support
 * @since 1.6.0 Customer sync webhook support
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Webhooks {

    /**
     * Maximum allowed timestamp age in seconds (5 minutes)
     */
    const MAX_TIMESTAMP_AGE = 300;

    /**
     * Initialize webhook routes
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * Register REST API routes for webhooks
     */
    public static function register_routes() {
        register_rest_route('bna/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('bna/v1', '/webhook/test', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'test_endpoint'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Main webhook handler with security verification
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_webhook($request) {
        $start_time = microtime(true);

        // Get headers and payload
        $signature = $request->get_header('X-Bna-Signature');
        $timestamp = $request->get_header('X-Bna-Timestamp');
        $payload = $request->get_json_params();
        $raw_body = $request->get_body();

        bna_log('Webhook received', array(
            'has_signature' => !empty($signature),
            'has_timestamp' => !empty($timestamp),
            'payload_size' => strlen($raw_body),
            'payload_keys' => is_array($payload) ? array_keys($payload) : 'invalid',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ));

        // Validate payload
        if (empty($payload) || !is_array($payload)) {
            bna_error('Invalid webhook payload - empty or malformed JSON');
            return new WP_REST_Response(array('error' => 'Invalid payload'), 400);
        }

        try {
            // Verify HMAC signature if provided (new security format)
            if (!empty($signature) && !empty($timestamp)) {
                $verification_result = self::verify_webhook_signature($raw_body, $signature, $timestamp);

                if (is_wp_error($verification_result)) {
                    bna_error('Webhook signature verification failed', array(
                        'error' => $verification_result->get_error_message(),
                        'timestamp' => $timestamp,
                        'has_signature' => !empty($signature)
                    ));
                    return new WP_REST_Response(array('error' => 'Signature verification failed'), 401);
                }

                bna_log('Webhook signature verified successfully');
            } else {
                bna_log('Legacy webhook received (no signature) - processing with basic validation');
            }

            // Process the webhook
            $result = self::process_webhook($payload);

            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            bna_log('Webhook processed successfully', array(
                'processing_time_ms' => $processing_time,
                'result' => $result
            ));

            return new WP_REST_Response($result, 200);

        } catch (Exception $e) {
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            bna_error('Webhook processing failed', array(
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'processing_time_ms' => $processing_time,
                'payload_structure' => array_keys($payload)
            ));

            return new WP_REST_Response(array('error' => $e->getMessage()), 500);
        }
    }

    /**
     * Verify webhook HMAC signature - EXACT BNA ALGORITHM
     * Based on the provided TypeScript code from BNA:
     *
     * const stringData = JSON.stringify(data, null, 0)
     * const hash = crypto.createHash('sha256').update(stringData).digest('hex')
     * const hmac = crypto.createHmac('sha256', secret)
     * hmac.update(`${hash}:${timestamp}`)
     * return hmac.digest('hex')
     *
     * @param string $raw_body Raw request body (exactly as received)
     * @param string $signature Expected signature from X-Bna-Signature header
     * @param string $timestamp Timestamp from X-Bna-Timestamp header
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */

    /**
     * Verify webhook HMAC signature using BNA algorithm - Fixed Version
     *
     * BNA Portal algorithm (TypeScript):
     * 1. JSON.stringify(data, null, 0) - serialize only 'data' part without spaces
     * 2. SHA-256 hash of serialized data
     * 3. Combine hash:timestamp
     * 4. HMAC-SHA256 with secret
     * 5. Convert to hex
     *
     * @param string $raw_body Raw request body
     * @param string $signature Expected signature from X-Bna-Signature header
     * @param string $timestamp Timestamp from X-Bna-Timestamp header
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    private static function verify_webhook_signature($raw_body, $signature, $timestamp)
    {
        // Get webhook secret
        $webhook_secret = get_option('bna_smart_payment_webhook_secret', '');

        if (empty($webhook_secret)) {
            return new WP_Error('missing_secret', 'Webhook secret key not configured. Please set it in BNA Gateway settings.');
        }

        // Validate timestamp format and age
        $timestamp_validation = self::validate_timestamp($timestamp);
        if (is_wp_error($timestamp_validation)) {
            return $timestamp_validation;
        }

        try {
            // Parse JSON data from raw body
            $payload_data = json_decode($raw_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_json', 'Invalid JSON in webhook payload: ' . json_last_error_msg());
            }

            // Extract only 'data' part (same as TypeScript code signs)
            $data_part = isset($payload_data['data']) ? $payload_data['data'] : $payload_data;

            // Prepare test approaches
            $all_tests = array();

            // APPROACH 1: Try raw extraction of data part
            $raw_data_json = self::extract_data_from_raw_json($raw_body);
            if ($raw_data_json !== false) {
                $all_tests['raw_data_manual'] = $raw_data_json;
            }

            // APPROACH 2: Maybe TypeScript signs the whole payload
            $all_tests['full_payload_raw'] = trim($raw_body);

            // APPROACH 3: Test different serialization approaches for data part
            $serialization_tests = array(
                'compact_unescaped' => json_encode($data_part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'compact_default' => json_encode($data_part),
                'compact_numeric' => json_encode($data_part, JSON_NUMERIC_CHECK),
                'compact_preserve_zero' => json_encode($data_part, JSON_PRESERVE_ZERO_FRACTION),
                'compact_combined' => json_encode($data_part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK),
                'compact_no_flags' => json_encode($data_part, 0),
            );

            // APPROACH 4: Test full payload serialization
            $full_payload_tests = array(
                'full_payload_unescaped' => json_encode($payload_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'full_payload_default' => json_encode($payload_data),
            );

            // Combine all approaches
            $all_tests = array_merge($all_tests, $serialization_tests, $full_payload_tests);

            $debug_info = array();
            $successful_approach = null;

            foreach ($all_tests as $approach_name => $serialized_data) {
                if ($serialized_data === false || $serialized_data === null) {
                    $debug_info[$approach_name] = array('error' => 'Serialization failed');
                    continue;
                }

                // Step 1: Create SHA-256 hash of serialized data
                $data_hash = hash('sha256', $serialized_data);

                // Step 2: Combine hash and timestamp with colon (same as TypeScript: `${hash}:${timestamp}`)
                $signing_string = $data_hash . ':' . $timestamp;

                // Step 3: Create HMAC-SHA256 signature
                $computed_signature = hash_hmac('sha256', $signing_string, $webhook_secret);

                // Debug info
                $preview_length = 200;
                $serialized_preview = strlen($serialized_data) > $preview_length
                    ? substr($serialized_data, 0, $preview_length) . '...'
                    : $serialized_data;

                $debug_info[$approach_name] = array(
                    'serialized_preview' => $serialized_preview,
                    'data_hash' => $data_hash,
                    'signing_string' => $signing_string,
                    'computed_signature' => $computed_signature,
                    'matches' => hash_equals($computed_signature, $signature)
                );

                // Step 4: Compare signatures securely
                if (hash_equals($computed_signature, $signature)) {
                    $successful_approach = $approach_name;
                    break;
                }
            }

            // Count raw extraction tests
            $raw_extraction_count = 0;
            if (isset($all_tests['raw_data_manual'])) $raw_extraction_count++;
            if (isset($all_tests['full_payload_raw'])) $raw_extraction_count++;

            // Log debug information for troubleshooting
            bna_log('Webhook signature verification', array(
                'successful_approach' => $successful_approach,
                'provided_signature' => $signature,
                'timestamp' => $timestamp,
                'webhook_secret_length' => strlen($webhook_secret),
                'data_part_keys' => is_array($data_part) ? array_keys($data_part) : 'not_array',
                'raw_extraction_attempted' => $raw_extraction_count > 0,
                'raw_extraction_count' => $raw_extraction_count,
                'raw_body_preview' => substr($raw_body, 0, 100) . '...',
                'total_tests' => count($all_tests),
                'debug_info' => $debug_info
            ));

            if ($successful_approach) {
                bna_log('Webhook signature verified successfully', array(
                    'method' => $successful_approach
                ));
                return true;
            }

            bna_error('Webhook signature verification failed', array(
                'provided_signature' => $signature,
                'all_computed_signatures' => array_column($debug_info, 'computed_signature'),
                'payload_structure' => array_keys($payload_data),
                'data_part_size' => is_array($data_part) ? count($data_part) : 'not_array',
                'tested_approaches' => array_keys($all_tests)
            ));

            return new WP_Error('invalid_signature', 'HMAC signature verification failed. Check webhook secret key.');

        } catch (Exception $e) {
            bna_error('Webhook signature verification exception', array(
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ));
            return new WP_Error('verification_error', 'Signature verification failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract data part from raw JSON string manually
     *
     * @param string $raw_body Raw JSON body
     * @return string|false Extracted data JSON or false on failure
     */
    private static function extract_data_from_raw_json($raw_body)
    {
        try {
            $raw_body_trimmed = trim($raw_body);

            // Find the "data" field in the raw JSON
            $data_pos = strpos($raw_body_trimmed, '"data"');
            if ($data_pos === false) {
                return false;
            }

            // Find the colon after "data"
            $colon_pos = strpos($raw_body_trimmed, ':', $data_pos);
            if ($colon_pos === false) {
                return false;
            }

            // Skip whitespace after colon to find opening brace
            $start_pos = $colon_pos + 1;
            while ($start_pos < strlen($raw_body_trimmed) &&
                in_array($raw_body_trimmed[$start_pos], array(' ', "\t", "\n", "\r"))) {
                $start_pos++;
            }

            // Check if we found opening brace
            if ($start_pos >= strlen($raw_body_trimmed) || $raw_body_trimmed[$start_pos] !== '{') {
                return false;
            }

            // Count braces to find the end of the data object
            $brace_count = 0;
            $end_pos = $start_pos;

            for ($i = $start_pos; $i < strlen($raw_body_trimmed); $i++) {
                if ($raw_body_trimmed[$i] === '{') {
                    $brace_count++;
                } elseif ($raw_body_trimmed[$i] === '}') {
                    $brace_count--;
                    if ($brace_count === 0) {
                        $end_pos = $i;
                        break;
                    }
                }
            }

            // Check if we found matching closing brace
            if ($brace_count !== 0) {
                return false;
            }

            // Extract the data JSON
            $data_json = substr($raw_body_trimmed, $start_pos, $end_pos - $start_pos + 1);

            // Validate that it's valid JSON
            $test_decode = json_decode($data_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }

            return $data_json;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate webhook timestamp
     *
     * @param string $timestamp ISO 8601 timestamp
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    private static function validate_timestamp($timestamp) {
        if (empty($timestamp)) {
            return new WP_Error('missing_timestamp', 'Timestamp header missing');
        }

        // Parse ISO 8601 timestamp
        $webhook_time = strtotime($timestamp);
        if ($webhook_time === false) {
            return new WP_Error('invalid_timestamp', 'Invalid timestamp format - expected ISO 8601');
        }

        // Check if timestamp is not too old (protect against replay attacks)
        $current_time = time();
        $age = $current_time - $webhook_time;

        if ($age > self::MAX_TIMESTAMP_AGE) {
            return new WP_Error('timestamp_too_old', 'Webhook timestamp is too old (max 5 minutes allowed)');
        }

        // Allow small clock skew (future timestamps up to 1 minute)
        if ($age < -60) {
            return new WP_Error('timestamp_too_future', 'Webhook timestamp is too far in the future');
        }

        return true;
    }

    /**
     * Process webhook payload - supports both new event format and legacy format
     *
     * @param array $payload Webhook payload
     * @return array Processing result
     */
    private static function process_webhook($payload) {
        // Check if it's new event-based format
        if (isset($payload['event']) && isset($payload['data'])) {
            return self::process_event_webhook($payload);
        }

        // Legacy format - direct transaction data
        return self::process_legacy_webhook($payload);
    }

    /**
     * Process new event-based webhook format
     *
     * @param array $payload Event webhook payload
     * @return array Processing result
     */
    private static function process_event_webhook($payload) {
        $event = $payload['event'];
        $data = $payload['data'];
        $delivery_id = $payload['deliveryId'] ?? 'unknown';

        bna_log('Processing event webhook', array(
            'event' => $event,
            'delivery_id' => $delivery_id,
            'data_keys' => array_keys($data)
        ));

        switch ($event) {
            case 'transaction.created':
            case 'transaction.processed':
            case 'transaction.approved':
            case 'transaction.declined':
            case 'transaction.canceled':
            case 'transaction.updated':
            case 'transaction.expired':
                return self::handle_transaction_event($event, $data);

            case 'subscription.created':
            case 'subscription.processed':
            case 'subscription.will_expire':
            case 'subscription.updated':
            case 'subscription.deleted':
                return self::handle_subscription_event($event, $data);

            case 'customer.created':
            case 'customer.updated':
            case 'customer.deleted':
                return self::handle_customer_event($event, $data);

            case 'payment_method.created':
            case 'payment_method.deleted':
                return self::handle_payment_method_event($event, $data);

            default:
                bna_log('Unknown webhook event received', array('event' => $event));
                return array('status' => 'ignored', 'reason' => 'Unknown event type');
        }
    }

    /**
     * Process legacy webhook format (direct transaction data)
     *
     * @param array $payload Legacy webhook payload
     * @return array Processing result
     */
    private static function process_legacy_webhook($payload) {
        bna_log('Processing legacy webhook', array(
            'payload_keys' => array_keys($payload)
        ));

        // Check for transaction data
        if (isset($payload['id']) && isset($payload['status'])) {
            return self::handle_transaction_event('transaction.updated', $payload);
        }

        // Check for customer data
        if (isset($payload['customerId']) || isset($payload['email'])) {
            return self::handle_customer_event('customer.updated', $payload);
        }

        return array('status' => 'ignored', 'reason' => 'Unrecognized legacy format');
    }

    /**
     * Handle transaction events
     *
     * @param string $event Event name
     * @param array $data Transaction data
     * @return array Processing result
     */
    private static function handle_transaction_event($event, $data) {
        if (!isset($data['id'])) {
            return array('status' => 'error', 'reason' => 'Missing transaction ID');
        }

        $transaction_id = $data['id'];
        $status = $data['status'] ?? 'unknown';

        bna_log('Handling transaction event', array(
            'event' => $event,
            'transaction_id' => $transaction_id,
            'status' => $status
        ));

        // Find WooCommerce order by transaction ID
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_transaction_id',
            'meta_value' => $transaction_id,
            'limit' => 1
        ));

        if (empty($orders)) {
            // Try to find by reference UUID
            if (isset($data['referenceUUID'])) {
                $orders = wc_get_orders(array(
                    'meta_key' => '_bna_reference_uuid',
                    'meta_value' => $data['referenceUUID'],
                    'limit' => 1
                ));
            }
        }

        if (empty($orders)) {
            bna_log('Order not found for transaction', array(
                'transaction_id' => $transaction_id,
                'reference_uuid' => $data['referenceUUID'] ?? 'none'
            ));
            return array('status' => 'ignored', 'reason' => 'Order not found');
        }

        $order = $orders[0];

        // Update order status based on transaction status
        switch (strtolower($status)) {
            case 'approved':
                if ($order->get_status() !== 'processing') {
                    $order->payment_complete($transaction_id);
                    $order->add_order_note(__('Payment approved via BNA webhook.', 'bna-smart-payment'));
                }
                break;

            case 'declined':
                if (!$order->has_status(array('cancelled', 'failed'))) {
                    $order->update_status('failed', __('Payment declined via BNA webhook.', 'bna-smart-payment'));
                }
                break;

            case 'canceled':
            case 'cancelled':
                if (!$order->has_status('cancelled')) {
                    $order->update_status('cancelled', __('Payment cancelled via BNA webhook.', 'bna-smart-payment'));
                }
                break;

            case 'expired':
                if (!$order->has_status(array('cancelled', 'failed'))) {
                    $order->update_status('failed', __('Payment expired via BNA webhook.', 'bna-smart-payment'));
                }
                break;
        }

        // Store additional transaction data
        if (isset($data['amount'])) {
            $order->update_meta_data('_bna_transaction_amount', $data['amount']);
        }
        if (isset($data['fee'])) {
            $order->update_meta_data('_bna_transaction_fee', $data['fee']);
        }
        if (isset($data['paymentMethod'])) {
            $order->update_meta_data('_bna_payment_method', $data['paymentMethod']);
        }
        if (isset($data['authCode'])) {
            $order->update_meta_data('_bna_auth_code', $data['authCode']);
        }

        $order->save();

        return array(
            'status' => 'processed',
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id
        );
    }

    /**
     * Handle subscription events
     *
     * @param string $event Event name
     * @param array $data Subscription data
     * @return array Processing result
     */
    private static function handle_subscription_event($event, $data) {
        bna_log('Handling subscription event', array(
            'event' => $event,
            'subscription_id' => $data['id'] ?? 'unknown'
        ));

        // Subscription handling implementation
        // This would integrate with WooCommerce Subscriptions if available

        return array('status' => 'processed', 'event' => $event);
    }

    /**
     * Handle customer events
     *
     * @param string $event Event name
     * @param array $data Customer data
     * @return array Processing result
     */
    private static function handle_customer_event($event, $data) {
        if (!isset($data['email'])) {
            return array('status' => 'error', 'reason' => 'Missing customer email');
        }

        $email = $data['email'];

        bna_log('Handling customer event', array(
            'event' => $event,
            'email' => $email
        ));

        // Find or create WordPress user
        $user = get_user_by('email', $email);

        if ($event === 'customer.created' && !$user) {
            // Create new user account
            $user_data = array(
                'user_login' => $email,
                'user_email' => $email,
                'first_name' => $data['firstName'] ?? '',
                'last_name' => $data['lastName'] ?? '',
                'role' => 'customer'
            );

            $user_id = wp_insert_user($user_data);
            if (!is_wp_error($user_id)) {
                // Store BNA customer ID
                if (isset($data['id'])) {
                    update_user_meta($user_id, '_bna_customer_id', $data['id']);
                }
            }
        }

        return array('status' => 'processed', 'event' => $event);
    }

    /**
     * Handle payment method events
     *
     * @param string $event Event name
     * @param array $data Payment method data
     * @return array Processing result
     */
    private static function handle_payment_method_event($event, $data) {
        bna_log('Handling payment method event', array(
            'event' => $event,
            'method_id' => $data['id'] ?? 'unknown',
            'customer_id' => $data['customerId'] ?? 'unknown'
        ));

        // Payment method handling implementation
        // This would sync with customer payment methods

        return array('status' => 'processed', 'event' => $event);
    }

    /**
     * Test endpoint to help debug webhook signature issues
     */
    public static function test_endpoint($request) {
        $webhook_secret = get_option('bna_smart_payment_webhook_secret', '');

        if (empty($webhook_secret)) {
            return new WP_REST_Response(array(
                'error' => 'Webhook secret not configured',
                'webhook_url' => rest_url('bna/v1/webhook'),
                'secret_status' => 'missing'
            ), 400);
        }

        // Example payload for testing
        $test_payload = array(
            'event' => 'transaction.approved',
            'deliveryId' => '12345678-1234-1234-1234-123456789012',
            'configId' => 'test-config-id',
            'data' => array(
                'id' => 'test-transaction-id',
                'status' => 'APPROVED',
                'amount' => 100.00,
                'currency' => 'CAD'
            )
        );

        $timestamp = gmdate('Y-m-d\TH:i:s.000\Z'); // Current UTC time in ISO 8601

        // Generate signature using BNA algorithm
        $string_data = json_encode($test_payload, 0); // Compact format
        $data_hash = hash('sha256', $string_data);
        $signing_string = $data_hash . ':' . $timestamp;
        $test_signature = hash_hmac('sha256', $signing_string, $webhook_secret);

        return new WP_REST_Response(array(
            'webhook_url' => rest_url('bna/v1/webhook'),
            'secret_configured' => true,
            'secret_length' => strlen($webhook_secret),
            'test_timestamp' => $timestamp,
            'test_payload' => $test_payload,
            'string_data' => $string_data,
            'data_hash' => $data_hash,
            'signing_string' => $signing_string,
            'test_signature' => $test_signature,
            'instructions' => array(
                'Use the test_signature as X-Bna-Signature header',
                'Use the test_timestamp as X-Bna-Timestamp header',
                'Send the test_payload as POST body to webhook URL',
                'Should result in successful verification'
            )
        ), 200);
    }
}