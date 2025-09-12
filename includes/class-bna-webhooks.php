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
     * Verify webhook HMAC signature using BNA algorithm - ORIGINAL COMPLEX VERSION
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
            bna_log('Webhook signature verification debug', array(
                'successful_approach' => $successful_approach,
                'provided_signature' => $signature,
                'timestamp' => $timestamp,
                'webhook_secret_length' => strlen($webhook_secret),
                'data_part_keys' => is_array($data_part) ? array_keys($data_part) : 'not_array',
                'raw_extraction_attempted' => $raw_extraction_count > 0,
                'raw_extraction_count' => $raw_extraction_count,
                'raw_body_preview' => substr($raw_body, 0, 100) . '...',
                'total_tests' => count($all_tests)
            ));

            if ($successful_approach) {
                bna_log('Webhook signature verified successfully', array(
                    'method' => $successful_approach
                ));
                return true;
            }

            bna_error('Webhook signature verification failed - all approaches failed', array(
                'provided_signature' => $signature,
                'all_computed_signatures' => array_column($debug_info, 'computed_signature'),
                'payload_structure' => array_keys($payload_data),
                'data_part_size' => is_array($data_part) ? count($data_part) : 'not_array',
                'tested_approaches' => array_keys($all_tests)
            ));

            return new WP_Error('invalid_signature', 'HMAC signature verification failed. Check webhook secret key.');

        } catch (Exception $e) {
            bna_error('Exception during webhook signature verification', array(
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ));
            return new WP_Error('verification_exception', 'Signature verification error: ' . $e->getMessage());
        }
    }

    /**
     * Validate timestamp format and age
     */
    private static function validate_timestamp($timestamp) {
        if (empty($timestamp)) {
            return new WP_Error('missing_timestamp', 'Missing timestamp header');
        }

        // Parse timestamp
        $timestamp_unix = strtotime($timestamp);
        if ($timestamp_unix === false) {
            return new WP_Error('invalid_timestamp_format', 'Invalid timestamp format');
        }

        // Check if timestamp is too old (prevents replay attacks)
        $current_time = time();
        $age = $current_time - $timestamp_unix;

        if ($age > self::MAX_TIMESTAMP_AGE) {
            return new WP_Error('timestamp_too_old', 'Webhook timestamp is too old (older than ' . self::MAX_TIMESTAMP_AGE . ' seconds)');
        }

        // Check if timestamp is too far in the future (clock skew tolerance)
        if ($age < -60) { // Allow 1 minute future
            return new WP_Error('timestamp_future', 'Webhook timestamp is too far in the future');
        }

        return true;
    }

    /**
     * Extract data part from raw JSON string
     */
    private static function extract_data_from_raw_json($raw_body) {
        // Try to extract just the "data" part from raw JSON
        $pattern = '/"data"\s*:\s*(\{.*\})\s*\}$/';
        if (preg_match($pattern, $raw_body, $matches)) {
            return trim($matches[1]);
        }

        // Try more complex extraction
        $pattern = '/"data"\s*:\s*(\{(?:[^{}]|(?1))*\})/';
        if (preg_match($pattern, $raw_body, $matches)) {
            return trim($matches[1]);
        }

        return false;
    }

    /**
     * Process webhook payload
     *
     * @param array $payload Webhook payload
     * @return array Processing result
     */
    private static function process_webhook($payload) {
        // Handle new webhook format with events
        if (isset($payload['event']) && isset($payload['data'])) {
            return self::process_event_webhook($payload);
        }

        // Handle legacy webhook format (direct data)
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

        // Find WooCommerce order by BNA transaction ID
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_transaction_id',
            'meta_value' => $transaction_id,
            'limit' => 1
        ));

        if (empty($orders)) {
            bna_log('No order found for transaction', array('transaction_id' => $transaction_id));
            return array('status' => 'ignored', 'reason' => 'Order not found');
        }

        $order = $orders[0];

        // Update order status based on transaction status
        switch (strtolower($status)) {
            case 'approved':
            case 'completed':
                if (!$order->has_status(array('processing', 'completed'))) {
                    $order->payment_complete($transaction_id);
                    $order->add_order_note(__('Payment approved via BNA webhook.', 'bna-smart-payment'));
                }
                break;

            case 'declined':
            case 'failed':
                if (!$order->has_status(array('failed', 'cancelled'))) {
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
     * Handle payment method events - ПОВНІСТЮ РЕАЛІЗОВАНО!
     *
     * @param string $event Event name
     * @param array $data Payment method data
     * @return array Processing result
     */
    private static function handle_payment_method_event($event, $data) {
        if (!isset($data['customerId'])) {
            bna_error('Payment method event missing customerId', array(
                'event' => $event,
                'data_keys' => array_keys($data)
            ));
            return array('status' => 'error', 'reason' => 'Missing customerId');
        }

        $customer_id = $data['customerId'];
        $payment_method_id = $data['id'] ?? '';

        bna_log('Handling payment method event', array(
            'event' => $event,
            'method_id' => $payment_method_id,
            'customer_id' => $customer_id,
            'method_type' => $data['method'] ?? 'unknown'
        ));

        // Find WordPress user by BNA customer ID
        $users = get_users(array(
            'meta_key' => '_bna_customer_id',
            'meta_value' => $customer_id,
            'number' => 1
        ));

        if (empty($users)) {
            bna_log('No WordPress user found for BNA customer', array(
                'customer_id' => $customer_id
            ));
            return array('status' => 'ignored', 'reason' => 'User not found');
        }

        $user = $users[0];
        $user_id = $user->ID;
        $payment_methods_handler = BNA_Payment_Methods::get_instance();

        switch ($event) {
            case 'payment_method.created':
                return self::handle_payment_method_created($user_id, $data, $payment_methods_handler);

            case 'payment_method.deleted':
                return self::handle_payment_method_deleted($user_id, $payment_method_id, $payment_methods_handler);

            default:
                return array('status' => 'ignored', 'reason' => 'Unknown payment method event');
        }
    }

    /**
     * Handle payment method created event
     *
     * @param int $user_id WordPress user ID
     * @param array $data Payment method data from webhook
     * @param BNA_Payment_Methods $payment_methods_handler Payment methods handler instance
     * @return array Processing result
     */
    private static function handle_payment_method_created($user_id, $data, $payment_methods_handler) {
        bna_log('Processing payment method created', array(
            'user_id' => $user_id,
            'method_id' => $data['id'] ?? 'unknown',
            'webhook_method_type' => $data['method'] ?? 'unknown'
        ));

        // Transform webhook data to internal format
        $payment_method_data = self::transform_webhook_payment_method_data($data);

        if (!$payment_method_data) {
            bna_error('Failed to transform webhook payment method data', array(
                'raw_data' => $data
            ));
            return array('status' => 'error', 'reason' => 'Invalid payment method data');
        }

        // Save payment method using existing handler
        $result = $payment_methods_handler->save_payment_method($user_id, $payment_method_data);

        if ($result) {
            bna_log('Payment method saved from webhook', array(
                'user_id' => $user_id,
                'method_id' => $payment_method_data['id'],
                'internal_type' => $payment_method_data['type']
            ));

            return array(
                'status' => 'processed',
                'event' => 'payment_method.created',
                'method_id' => $payment_method_data['id']
            );
        } else {
            bna_error('Failed to save payment method from webhook', array(
                'user_id' => $user_id,
                'method_data' => $payment_method_data
            ));

            return array('status' => 'error', 'reason' => 'Failed to save payment method');
        }
    }

    /**
     * Handle payment method deleted event
     *
     * @param int $user_id WordPress user ID
     * @param string $payment_method_id Payment method ID
     * @param BNA_Payment_Methods $payment_methods_handler Payment methods handler instance
     * @return array Processing result
     */
    private static function handle_payment_method_deleted($user_id, $payment_method_id, $payment_methods_handler) {
        if (empty($payment_method_id)) {
            return array('status' => 'error', 'reason' => 'Missing payment method ID');
        }

        bna_log('Processing payment method deleted', array(
            'user_id' => $user_id,
            'method_id' => $payment_method_id
        ));

        // Delete payment method using existing handler
        $result = $payment_methods_handler->delete_payment_method_by_id($user_id, $payment_method_id);

        if ($result) {
            bna_log('Payment method deleted from webhook', array(
                'user_id' => $user_id,
                'method_id' => $payment_method_id
            ));

            return array(
                'status' => 'processed',
                'event' => 'payment_method.deleted',
                'method_id' => $payment_method_id
            );
        } else {
            bna_log('Payment method not found for deletion (may already be deleted)', array(
                'user_id' => $user_id,
                'method_id' => $payment_method_id
            ));

            return array(
                'status' => 'processed', // Consider it processed since it's not there anyway
                'event' => 'payment_method.deleted',
                'method_id' => $payment_method_id
            );
        }
    }

    /**
     * Transform webhook payment method data to internal format
     * ВИРІШУЄ ПРОБЛЕМУ МАПІНГУ ТИПІВ!
     *
     * @param array $webhook_data Raw webhook data
     * @return array|false Transformed data or false on failure
     */
    private static function transform_webhook_payment_method_data($webhook_data) {
        if (!isset($webhook_data['id']) || !isset($webhook_data['method'])) {
            return false;
        }

        $base_data = array(
            'id' => $webhook_data['id'],
            'created_at' => $webhook_data['createdAt'] ?? current_time('Y-m-d H:i:s')
        );

        // Transform based on webhook method type
        switch ($webhook_data['method']) {
            case 'CARD':
                return self::transform_card_method_data($webhook_data, $base_data);

            case 'E_TRANSFER':
                return self::transform_e_transfer_method_data($webhook_data, $base_data);

            case 'EFT':
                return self::transform_eft_method_data($webhook_data, $base_data);

            default:
                bna_log('Unknown webhook payment method type', array(
                    'method' => $webhook_data['method'],
                    'method_id' => $webhook_data['id']
                ));
                return false;
        }
    }

    /**
     * Transform CARD method data
     */
    private static function transform_card_method_data($webhook_data, $base_data) {
        // Map CARD + cardType to internal type
        $card_type = strtoupper($webhook_data['cardType'] ?? 'CREDIT');
        $internal_type = strtolower($card_type); // 'CREDIT' -> 'credit', 'DEBIT' -> 'debit'

        return array_merge($base_data, array(
            'type' => $internal_type,
            'cardNumber' => $webhook_data['cardNumber'] ?? '',
            'cardHolder' => $webhook_data['cardHolder'] ?? '',
            'cardBrand' => $webhook_data['cardBrand'] ?? '',
            'cardType' => $card_type,
            'expiryMonth' => $webhook_data['expiryMonth'] ?? '',
            'expiryYear' => $webhook_data['expiryYear'] ?? '',
            'currency' => $webhook_data['currency'] ?? 'CAD'
        ));
    }

    /**
     * Transform E_TRANSFER method data
     */
    private static function transform_e_transfer_method_data($webhook_data, $base_data) {
        return array_merge($base_data, array(
            'type' => 'e_transfer',
            'name' => $webhook_data['name'] ?? '',
            'email' => $webhook_data['email'] ?? '',
            'deliveryType' => $webhook_data['deliveryType'] ?? 'EMAIL',
            'message' => $webhook_data['message'] ?? '',
            'securityQuestion' => $webhook_data['securityQuestion'] ?? '',
            'securityAnswer' => $webhook_data['securityAnswer'] ?? ''
        ));
    }

    /**
     * Transform EFT method data
     */
    private static function transform_eft_method_data($webhook_data, $base_data) {
        return array_merge($base_data, array(
            'type' => 'eft',
            'accountNumber' => $webhook_data['accountNumber'] ?? '',
            'bankNumber' => $webhook_data['bankNumber'] ?? '',
            'transitNumber' => $webhook_data['transitNumber'] ?? '',
            'bankName' => $webhook_data['bankName'] ?? ''
        ));
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
        $signature = hash_hmac('sha256', $signing_string, $webhook_secret);

        return new WP_REST_Response(array(
            'webhook_url' => rest_url('bna/v1/webhook'),
            'secret_configured' => true,
            'test_payload' => $test_payload,
            'test_headers' => array(
                'X-Bna-Signature' => $signature,
                'X-Bna-Timestamp' => $timestamp,
                'Content-Type' => 'application/json'
            ),
            'curl_example' => sprintf(
                'curl -X POST %s -H "Content-Type: application/json" -H "X-Bna-Signature: %s" -H "X-Bna-Timestamp: %s" -d \'%s\'',
                rest_url('bna/v1/webhook'),
                $signature,
                $timestamp,
                json_encode($test_payload)
            )
        ), 200);
    }
}