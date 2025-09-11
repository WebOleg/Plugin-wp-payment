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
     * Verify webhook HMAC signature using BNA algorithm
     *
     * Algorithm from BNA documentation:
     * 1. Parse JSON and re-serialize in compact format (same as sender)
     * 2. Create SHA-256 hash of the serialized data
     * 3. Combine hash and timestamp with colon: hash:timestamp
     * 4. Create HMAC-SHA256 signature using the secret key
     * 5. Convert result to hex format
     *
     * @param string $raw_body Raw request body (exactly as received)
     * @param string $signature Expected signature from X-Bna-Signature header
     * @param string $timestamp Timestamp from X-Bna-Timestamp header
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    /**
     * Verify webhook HMAC signature - Advanced Debug Version
     *
     * @param string $raw_body Raw request body
     * @param string $signature Expected signature from X-Bna-Signature header
     * @param string $timestamp Timestamp from X-Bna-Timestamp header
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    private static function verify_webhook_signature($raw_body, $signature, $timestamp) {
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

            // Test multiple serialization approaches
            $serialization_tests = array(
                'raw_body' => $raw_body,
                'json_encode_default' => json_encode($payload_data),
                'json_encode_no_flags' => json_encode($payload_data, 0),
                'json_encode_unescaped' => json_encode($payload_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'json_encode_numeric' => json_encode($payload_data, JSON_NUMERIC_CHECK),
                'json_encode_preserve' => json_encode($payload_data, JSON_PRESERVE_ZERO_FRACTION),
                'json_encode_combined' => json_encode($payload_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK)
            );

            $debug_results = array();
            $successful_approach = null;

            foreach ($serialization_tests as $approach_name => $serialized_data) {
                if ($serialized_data === false) {
                    $debug_results[$approach_name] = array('error' => 'JSON encoding failed');
                    continue;
                }

                // Create SHA-256 hash
                $data_hash = hash('sha256', $serialized_data);

                // Create signing string
                $signing_string = $data_hash . ':' . $timestamp;

                // Create HMAC signature
                $computed_signature = hash_hmac('sha256', $signing_string, $webhook_secret);

                // Store debug info
                $debug_results[$approach_name] = array(
                    'data_length' => strlen($serialized_data),
                    'data_first_100_chars' => substr($serialized_data, 0, 100),
                    'data_last_100_chars' => substr($serialized_data, -100),
                    'data_hash' => $data_hash,
                    'signing_string' => $signing_string,
                    'computed_signature' => $computed_signature,
                    'matches_provided' => hash_equals($computed_signature, $signature),
                    'identical_to_raw' => ($serialized_data === $raw_body)
                );

                // Check if this approach works
                if (hash_equals($computed_signature, $signature)) {
                    $successful_approach = $approach_name;
                    break;
                }
            }

            // Log comprehensive debug information
            bna_error('COMPREHENSIVE WEBHOOK DEBUG', array(
                'provided_signature' => $signature,
                'timestamp' => $timestamp,
                'webhook_secret' => substr($webhook_secret, 0, 5) . '***' . substr($webhook_secret, -5), // Partial key for security
                'webhook_secret_length' => strlen($webhook_secret),
                'raw_body_length' => strlen($raw_body),
                'raw_body_first_200' => substr($raw_body, 0, 200),
                'all_approaches' => $debug_results,
                'successful_approach' => $successful_approach
            ));

            if ($successful_approach) {
                bna_log('Webhook signature verified successfully', array(
                    'approach_used' => $successful_approach
                ));
                return true;
            }

            // Try one more approach - maybe BNA uses a different algorithm
            $alternative_approaches = array();

            // Test with different hash combinations
            $test_combinations = array(
                'hash_only' => hash('sha256', $raw_body),
                'timestamp_only' => $timestamp,
                'hash_timestamp_space' => hash('sha256', $raw_body) . ' ' . $timestamp,
                'timestamp_hash' => $timestamp . ':' . hash('sha256', $raw_body),
                'raw_timestamp_no_hash' => $raw_body . ':' . $timestamp,
                'just_raw_body' => $raw_body
            );

            foreach ($test_combinations as $combo_name => $signing_data) {
                $computed_sig = hash_hmac('sha256', $signing_data, $webhook_secret);
                $alternative_approaches[$combo_name] = array(
                    'signing_data_preview' => substr($signing_data, 0, 100) . '...',
                    'computed_signature' => $computed_sig,
                    'matches' => hash_equals($computed_sig, $signature)
                );

                if (hash_equals($computed_sig, $signature)) {
                    bna_log('FOUND WORKING ALTERNATIVE APPROACH', array(
                        'approach' => $combo_name,
                        'signing_data_preview' => substr($signing_data, 0, 100) . '...'
                    ));
                    return true;
                }
            }

            bna_error('ALTERNATIVE APPROACHES TESTED', array(
                'alternative_results' => $alternative_approaches
            ));

            return new WP_Error('invalid_signature', 'HMAC signature mismatch - exhaustive debug completed');

        } catch (Exception $e) {
            bna_error('HMAC verification exception', array(
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ));
            return new WP_Error('verification_error', 'Signature verification failed: ' . $e->getMessage());
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
     * Process webhook payload - supports both new event-based and legacy formats
     *
     * @param array $payload Webhook payload
     * @return array Processing result
     */
    private static function process_webhook($payload) {
        // New event-based webhook format (BNA v1.8.0+)
        if (isset($payload['event']) && isset($payload['data'])) {
            return self::handle_event_based_webhook($payload);
        }

        // Legacy webhook formats (backwards compatibility)
        if (isset($payload['paymentMethod']) && isset($payload['customer'])) {
            return self::handle_payment_method_with_customer($payload);
        }

        if (isset($payload['transaction']) || isset($payload['data']['transaction'])) {
            return self::handle_transaction_webhook($payload);
        }

        if (isset($payload['customer']) && isset($payload['action'])) {
            return self::handle_customer_webhook($payload);
        }

        if (isset($payload['subscription'])) {
            return self::handle_subscription_webhook($payload);
        }

        if (isset($payload['paymentMethod']) || isset($payload['payment_method'])) {
            return self::handle_payment_method_webhook($payload);
        }

        if (isset($payload['id']) && isset($payload['status'])) {
            return self::handle_transaction_webhook($payload);
        }

        bna_log('Unrecognized webhook structure', array(
            'payload_keys' => array_keys($payload),
            'has_event' => isset($payload['event']),
            'has_data' => isset($payload['data']),
            'has_paymentMethod' => isset($payload['paymentMethod']),
            'has_customer' => isset($payload['customer']),
            'has_transaction' => isset($payload['transaction']),
            'has_action' => isset($payload['action'])
        ));

        return array(
            'status' => 'success',
            'message' => 'Webhook received but not processed (unrecognized structure)',
            'payload_keys' => array_keys($payload)
        );
    }

    /**
     * Handle new event-based webhook format
     *
     * Format: { "event": "transaction.approved", "deliveryId": "uuid", "configId": "uuid", "data": {...} }
     *
     * @param array $payload Event-based webhook payload
     * @return array Processing result
     */
    private static function handle_event_based_webhook($payload) {
        $event = $payload['event'];
        $data = $payload['data'];
        $delivery_id = $payload['deliveryId'] ?? 'unknown';
        $config_id = $payload['configId'] ?? 'unknown';

        bna_log('Processing event-based webhook', array(
            'event' => $event,
            'delivery_id' => $delivery_id,
            'config_id' => $config_id,
            'data_keys' => array_keys($data)
        ));

        // Parse event domain and subdomain
        $event_parts = explode('.', $event, 2);
        $domain = $event_parts[0] ?? '';
        $subdomain = $event_parts[1] ?? '';

        try {
            switch ($domain) {
                case 'transaction':
                    return self::handle_transaction_event($subdomain, $data, $delivery_id);

                case 'customer':
                    return self::handle_customer_event($subdomain, $data, $delivery_id);

                case 'subscription':
                    return self::handle_subscription_event($subdomain, $data, $delivery_id);

                case 'payment_method':
                    return self::handle_payment_method_event($subdomain, $data, $delivery_id);

                default:
                    bna_log('Unknown event domain', array(
                        'event' => $event,
                        'domain' => $domain,
                        'subdomain' => $subdomain
                    ));
                    return array(
                        'status' => 'success',
                        'message' => 'Event received but not processed (unknown domain)',
                        'event' => $event
                    );
            }
        } catch (Exception $e) {
            bna_error('Event processing failed', array(
                'event' => $event,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return array(
                'status' => 'error',
                'message' => 'Event processing failed: ' . $e->getMessage(),
                'event' => $event
            );
        }
    }

    /**
     * Handle transaction events (transaction.*)
     *
     * @param string $event_type Event subdomain (created, processed, approved, etc.)
     * @param array $data Transaction data
     * @param string $delivery_id Webhook delivery ID
     * @return array Processing result
     */
    private static function handle_transaction_event($event_type, $data, $delivery_id) {
        bna_log('Processing transaction event', array(
            'event_type' => $event_type,
            'transaction_id' => $data['id'] ?? 'unknown',
            'status' => $data['status'] ?? 'unknown',
            'delivery_id' => $delivery_id
        ));

        switch ($event_type) {
            case 'created':
            case 'processed':
            case 'updated':
                return self::handle_transaction_webhook($data);

            case 'approved':
                $result = self::handle_transaction_webhook($data);
                // Additional processing for approved transactions
                do_action('bna_transaction_approved', $data);
                return $result;

            case 'declined':
                $result = self::handle_transaction_webhook($data);
                // Additional processing for declined transactions
                do_action('bna_transaction_declined', $data);
                return $result;

            case 'canceled':
                $result = self::handle_transaction_webhook($data);
                // Additional processing for canceled transactions
                do_action('bna_transaction_canceled', $data);
                return $result;

            case 'expired':
                $result = self::handle_transaction_webhook($data);
                // Additional processing for expired transactions
                do_action('bna_transaction_expired', $data);
                return $result;

            default:
                bna_log('Unknown transaction event type', array(
                    'event_type' => $event_type,
                    'transaction_id' => $data['id'] ?? 'unknown'
                ));
                return array(
                    'status' => 'success',
                    'message' => 'Transaction event received but not specifically handled',
                    'event_type' => $event_type
                );
        }
    }

    /**
     * Handle customer events (customer.*)
     *
     * @param string $event_type Event subdomain (created, updated, deleted)
     * @param array $data Customer data
     * @param string $delivery_id Webhook delivery ID
     * @return array Processing result
     */
    private static function handle_customer_event($event_type, $data, $delivery_id) {
        bna_log('Processing customer event', array(
            'event_type' => $event_type,
            'customer_id' => $data['id'] ?? 'unknown',
            'email' => $data['email'] ?? 'unknown',
            'delivery_id' => $delivery_id
        ));

        switch ($event_type) {
            case 'created':
            case 'updated':
            case 'deleted':
                return self::handle_customer_webhook($data);

            default:
                bna_log('Unknown customer event type', array(
                    'event_type' => $event_type,
                    'customer_id' => $data['id'] ?? 'unknown'
                ));
                return array(
                    'status' => 'success',
                    'message' => 'Customer event received but not specifically handled',
                    'event_type' => $event_type
                );
        }
    }

    /**
     * Handle subscription events (subscription.*)
     *
     * @param string $event_type Event subdomain (created, processed, will_expire, etc.)
     * @param array $data Subscription data
     * @param string $delivery_id Webhook delivery ID
     * @return array Processing result
     */
    private static function handle_subscription_event($event_type, $data, $delivery_id) {
        bna_log('Processing subscription event', array(
            'event_type' => $event_type,
            'subscription_id' => $data['id'] ?? 'unknown',
            'status' => $data['status'] ?? 'unknown',
            'delivery_id' => $delivery_id
        ));

        switch ($event_type) {
            case 'created':
            case 'processed':
            case 'will_expire':
            case 'updated':
            case 'deleted':
                return self::handle_subscription_webhook($data);

            default:
                bna_log('Unknown subscription event type', array(
                    'event_type' => $event_type,
                    'subscription_id' => $data['id'] ?? 'unknown'
                ));
                return array(
                    'status' => 'success',
                    'message' => 'Subscription event received but not specifically handled',
                    'event_type' => $event_type
                );
        }
    }

    /**
     * Handle payment method events (payment_method.*)
     *
     * @param string $event_type Event subdomain (created, deleted)
     * @param array $data Payment method data
     * @param string $delivery_id Webhook delivery ID
     * @return array Processing result
     */
    private static function handle_payment_method_event($event_type, $data, $delivery_id) {
        bna_log('Processing payment method event', array(
            'event_type' => $event_type,
            'payment_method_id' => $data['id'] ?? 'unknown',
            'customer_id' => $data['customerId'] ?? 'unknown',
            'method' => $data['method'] ?? 'unknown',
            'delivery_id' => $delivery_id
        ));

        switch ($event_type) {
            case 'created':
            case 'deleted':
                return self::handle_payment_method_webhook($data);

            default:
                bna_log('Unknown payment method event type', array(
                    'event_type' => $event_type,
                    'payment_method_id' => $data['id'] ?? 'unknown'
                ));
                return array(
                    'status' => 'success',
                    'message' => 'Payment method event received but not specifically handled',
                    'event_type' => $event_type
                );
        }
    }

    /**
     * Handle transaction webhook
     *
     * @param array $payload Transaction data (can be full payload or just data part)
     * @return array Processing result
     */
    private static function handle_transaction_webhook($payload) {
        // Extract transaction data
        $transaction = isset($payload['transaction']) ? $payload['transaction'] : $payload;

        if (empty($transaction['id'])) {
            bna_error('Invalid transaction webhook - missing ID');
            return array('status' => 'error', 'message' => 'Missing transaction ID');
        }

        $transaction_id = $transaction['id'];
        $status = $transaction['status'] ?? 'unknown';

        bna_log('Processing transaction webhook', array(
            'transaction_id' => $transaction_id,
            'status' => $status,
            'payment_method' => $transaction['paymentMethod'] ?? 'unknown',
            'amount' => $transaction['amount'] ?? 'unknown'
        ));

        try {
            // Find WooCommerce order by BNA transaction ID
            $orders = wc_get_orders(array(
                'meta_key' => '_bna_transaction_id',
                'meta_value' => $transaction_id,
                'limit' => 1,
                'status' => 'any'
            ));

            if (empty($orders)) {
                // Try to find by reference UUID if available
                if (!empty($transaction['referenceUUID'])) {
                    $orders = wc_get_orders(array(
                        'meta_key' => '_bna_reference_uuid',
                        'meta_value' => $transaction['referenceUUID'],
                        'limit' => 1,
                        'status' => 'any'
                    ));
                }
            }

            if (empty($orders)) {
                bna_log('No matching WooCommerce order found for transaction', array(
                    'transaction_id' => $transaction_id,
                    'reference_uuid' => $transaction['referenceUUID'] ?? 'not_provided'
                ));

                return array(
                    'status' => 'success',
                    'message' => 'Transaction processed but no matching order found',
                    'transaction_id' => $transaction_id
                );
            }

            $order = $orders[0];

            // Update order based on transaction status
            switch (strtolower($status)) {
                case 'approved':
                    if (!$order->is_paid()) {
                        $order->payment_complete($transaction_id);
                        $order->add_order_note(__('Payment approved via BNA webhook.', 'bna-smart-payment'));

                        // Store additional transaction data
                        if (isset($transaction['authCode'])) {
                            $order->update_meta_data('_bna_auth_code', $transaction['authCode']);
                        }
                        if (isset($transaction['fee'])) {
                            $order->update_meta_data('_bna_fee', $transaction['fee']);
                        }
                        if (isset($transaction['balance'])) {
                            $order->update_meta_data('_bna_balance', $transaction['balance']);
                        }

                        $order->save();

                        bna_log('Order marked as paid', array(
                            'order_id' => $order->get_id(),
                            'transaction_id' => $transaction_id
                        ));
                    }
                    break;

                case 'declined':
                case 'canceled':
                    $order->update_status('failed', __('Payment ' . $status . ' via BNA webhook.', 'bna-smart-payment'));

                    if (isset($transaction['declineReason'])) {
                        $order->add_order_note(sprintf(__('Decline reason: %s', 'bna-smart-payment'), $transaction['declineReason']));
                    }

                    bna_log('Order marked as failed', array(
                        'order_id' => $order->get_id(),
                        'transaction_id' => $transaction_id,
                        'status' => $status
                    ));
                    break;

                case 'processed':
                    $order->update_status('processing', __('Payment processing via BNA webhook.', 'bna-smart-payment'));

                    bna_log('Order status updated to processing', array(
                        'order_id' => $order->get_id(),
                        'transaction_id' => $transaction_id
                    ));
                    break;

                default:
                    $order->add_order_note(sprintf(__('Transaction status updated: %s via BNA webhook.', 'bna-smart-payment'), $status));

                    bna_log('Order note added for status update', array(
                        'order_id' => $order->get_id(),
                        'transaction_id' => $transaction_id,
                        'status' => $status
                    ));
                    break;
            }

            return array(
                'status' => 'success',
                'message' => 'Transaction webhook processed successfully',
                'order_id' => $order->get_id(),
                'transaction_id' => $transaction_id,
                'transaction_status' => $status
            );

        } catch (Exception $e) {
            bna_error('Transaction webhook processing failed', array(
                'transaction_id' => $transaction_id,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return array(
                'status' => 'error',
                'message' => 'Transaction processing failed: ' . $e->getMessage(),
                'transaction_id' => $transaction_id
            );
        }
    }

    /**
     * Handle customer webhook
     *
     * @param array $payload Customer data
     * @return array Processing result
     */
    private static function handle_customer_webhook($payload) {
        $customer = isset($payload['customer']) ? $payload['customer'] : $payload;
        $action = $payload['action'] ?? 'unknown';

        if (empty($customer['id'])) {
            bna_error('Invalid customer webhook - missing ID');
            return array('status' => 'error', 'message' => 'Missing customer ID');
        }

        $customer_id = $customer['id'];
        $email = $customer['email'] ?? '';

        bna_log('Processing customer webhook', array(
            'customer_id' => $customer_id,
            'email' => $email,
            'action' => $action
        ));

        try {
            // Find WordPress user by email
            if (!empty($email)) {
                $user = get_user_by('email', $email);

                if ($user) {
                    // Update user meta with BNA customer ID
                    update_user_meta($user->ID, '_bna_customer_id', $customer_id);

                    bna_log('Customer data synced with WordPress user', array(
                        'wp_user_id' => $user->ID,
                        'bna_customer_id' => $customer_id,
                        'email' => $email
                    ));
                } else {
                    bna_log('No matching WordPress user found for customer', array(
                        'bna_customer_id' => $customer_id,
                        'email' => $email
                    ));
                }
            }

            return array(
                'status' => 'success',
                'message' => 'Customer webhook processed successfully',
                'customer_id' => $customer_id,
                'action' => $action
            );

        } catch (Exception $e) {
            bna_error('Customer webhook processing failed', array(
                'customer_id' => $customer_id,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return array(
                'status' => 'error',
                'message' => 'Customer processing failed: ' . $e->getMessage(),
                'customer_id' => $customer_id
            );
        }
    }

    /**
     * Handle subscription webhook
     *
     * @param array $payload Subscription data
     * @return array Processing result
     */
    private static function handle_subscription_webhook($payload) {
        $subscription = isset($payload['subscription']) ? $payload['subscription'] : $payload;

        if (empty($subscription['id'])) {
            bna_error('Invalid subscription webhook - missing ID');
            return array('status' => 'error', 'message' => 'Missing subscription ID');
        }

        $subscription_id = $subscription['id'];
        $status = $subscription['status'] ?? 'unknown';
        $customer_id = $subscription['customerId'] ?? '';

        bna_log('Processing subscription webhook', array(
            'subscription_id' => $subscription_id,
            'status' => $status,
            'customer_id' => $customer_id
        ));

        try {
            // Find related orders or subscriptions
            $orders = wc_get_orders(array(
                'meta_key' => '_bna_subscription_id',
                'meta_value' => $subscription_id,
                'limit' => -1,
                'status' => 'any'
            ));

            foreach ($orders as $order) {
                $order->add_order_note(sprintf(__('Subscription status updated: %s via BNA webhook.', 'bna-smart-payment'), $status));

                // Store subscription data
                $order->update_meta_data('_bna_subscription_status', $status);
                if (isset($subscription['nextPaymentDate'])) {
                    $order->update_meta_data('_bna_next_payment_date', $subscription['nextPaymentDate']);
                }
                if (isset($subscription['remainingPayments'])) {
                    $order->update_meta_data('_bna_remaining_payments', $subscription['remainingPayments']);
                }

                $order->save();
            }

            return array(
                'status' => 'success',
                'message' => 'Subscription webhook processed successfully',
                'subscription_id' => $subscription_id,
                'orders_updated' => count($orders)
            );

        } catch (Exception $e) {
            bna_error('Subscription webhook processing failed', array(
                'subscription_id' => $subscription_id,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return array(
                'status' => 'error',
                'message' => 'Subscription processing failed: ' . $e->getMessage(),
                'subscription_id' => $subscription_id
            );
        }
    }

    /**
     * Handle payment method webhook
     *
     * @param array $payload Payment method data
     * @return array Processing result
     */
    private static function handle_payment_method_webhook($payload) {
        $payment_method = isset($payload['paymentMethod']) ? $payload['paymentMethod'] : $payload;

        if (empty($payment_method['id'])) {
            bna_error('Invalid payment method webhook - missing ID');
            return array('status' => 'error', 'message' => 'Missing payment method ID');
        }

        $method_id = $payment_method['id'];
        $customer_id = $payment_method['customerId'] ?? '';
        $method_type = $payment_method['method'] ?? 'unknown';

        bna_log('Processing payment method webhook', array(
            'method_id' => $method_id,
            'customer_id' => $customer_id,
            'method_type' => $method_type
        ));

        try {
            // Use BNA Payment Methods handler if available
            if (class_exists('BNA_Payment_Methods')) {
                return BNA_Payment_Methods::handle_webhook($payload);
            }

            return array(
                'status' => 'success',
                'message' => 'Payment method webhook received',
                'method_id' => $method_id,
                'customer_id' => $customer_id
            );

        } catch (Exception $e) {
            bna_error('Payment method webhook processing failed', array(
                'method_id' => $method_id,
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ));

            return array(
                'status' => 'error',
                'message' => 'Payment method processing failed: ' . $e->getMessage(),
                'method_id' => $method_id
            );
        }
    }

    /**
     * Handle combined payment method and customer webhook
     *
     * @param array $payload Combined payload with both paymentMethod and customer data
     * @return array Processing result
     */
    private static function handle_payment_method_with_customer($payload) {
        bna_log('Processing combined payment method and customer webhook', array(
            'has_payment_method' => isset($payload['paymentMethod']),
            'has_customer' => isset($payload['customer']),
            'customer_email' => $payload['customer']['email'] ?? 'unknown'
        ));

        $results = array();

        try {
            // Process customer first
            if (isset($payload['customer'])) {
                $customer_result = self::handle_customer_webhook($payload);
                $results['customer'] = $customer_result;
            }

            // Then process payment method
            if (isset($payload['paymentMethod'])) {
                $method_result = self::handle_payment_method_webhook($payload);
                $results['payment_method'] = $method_result;
            }

            return array(
                'status' => 'success',
                'message' => 'Combined webhook processed successfully',
                'results' => $results
            );

        } catch (Exception $e) {
            bna_error('Combined webhook processing failed', array(
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'results_so_far' => $results
            ));

            return array(
                'status' => 'error',
                'message' => 'Combined processing failed: ' . $e->getMessage(),
                'partial_results' => $results
            );
        }
    }

    /**
     * Test endpoint for webhook verification
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function test_endpoint($request) {
        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'BNA Webhook endpoint is active',
            'version' => defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'unknown',
            'timestamp' => current_time('c'),
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'hmac_enabled' => !empty(get_option('bna_smart_payment_webhook_secret', '')),
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => class_exists('WooCommerce') ? WC()->version : 'not_installed'
        ), 200);
    }
}