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
     * 1. Use the raw request body exactly as received
     * 2. Create SHA-256 hash of the raw body
     * 3. Combine hash and timestamp with colon: hash:timestamp
     * 4. Create HMAC-SHA256 signature using the secret key
     * 5. Convert result to hex format
     *
     * @param string $raw_body Raw request body (exactly as received)
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
            // Validate that raw body is valid JSON (but don't re-serialize it)
            $payload_data = json_decode($raw_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_json', 'Invalid JSON in webhook payload');
            }

            // Step 1: Use raw body exactly as received (this is crucial!)
            $serialized_data = $raw_body;

            // Step 2: Create SHA-256 hash of the raw body
            $data_hash = hash('sha256', $serialized_data);

            // Step 3: Combine hash and timestamp with colon
            $signing_string = $data_hash . ':' . $timestamp;

            // Step 4: Create HMAC-SHA256 signature using the secret key
            $computed_signature = hash_hmac('sha256', $signing_string, $webhook_secret);

            bna_debug('HMAC signature verification details', array(
                'raw_body_length' => strlen($raw_body),
                'data_hash' => $data_hash,
                'signing_string' => $signing_string,
                'computed_signature' => $computed_signature,
                'provided_signature' => $signature,
                'signatures_match' => hash_equals($computed_signature, $signature),
                'webhook_secret_length' => strlen($webhook_secret)
            ));

            // Step 5: Secure comparison (constant-time comparison)
            if (!hash_equals($computed_signature, $signature)) {
                return new WP_Error('invalid_signature', 'HMAC signature mismatch');
            }

            return true;

        } catch (Exception $e) {
            bna_error('HMAC verification exception', array(
                'error' => $e->getMessage(),
                'line' => $e->getLine()
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

        switch ($domain) {
            case 'transaction':
                return self::handle_transaction_event($subdomain, $data, $delivery_id);

            case 'customer':
                return self::handle_customer_event($subdomain, $data, $delivery_id);

            case 'payment_method':
                return self::handle_payment_method_event($subdomain, $data, $delivery_id);

            case 'subscription':
                return self::handle_subscription_event($subdomain, $data, $delivery_id);

            default:
                bna_log('Unrecognized event domain', array(
                    'event' => $event,
                    'domain' => $domain,
                    'subdomain' => $subdomain
                ));

                return array(
                    'status' => 'success',
                    'message' => 'Event received but not processed (unrecognized domain)',
                    'event' => $event
                );
        }
    }

    /**
     * Handle transaction events (transaction.*)
     *
     * Events: created, processed, approved, declined, canceled, updated, expired
     *
     * @param string $event_type Transaction event type (approved, declined, etc.)
     * @param array $data Transaction data
     * @param string $delivery_id Webhook delivery ID
     * @return array Processing result
     */
    private static function handle_transaction_event($event_type, $data, $delivery_id) {
        bna_log('Processing transaction event', array(
            'event_type' => $event_type,
            'transaction_id' => $data['id'] ?? 'unknown',
            'status' => $data['status'] ?? 'unknown',
            'payment_method' => $data['paymentMethod'] ?? 'unknown',
            'amount' => $data['total'] ?? 'unknown',
            'delivery_id' => $delivery_id
        ));

        switch ($event_type) {
            case 'approved':
            case 'processed':
                return self::handle_successful_transaction($data);

            case 'declined':
            case 'canceled':
            case 'expired':
                return self::handle_failed_transaction($data);

            case 'created':
            case 'updated':
                return self::handle_transaction_status_update($data);

            default:
                bna_log('Unrecognized transaction event type', array(
                    'event_type' => $event_type,
                    'transaction_id' => $data['id'] ?? 'unknown'
                ));

                return array(
                    'status' => 'success',
                    'message' => 'Transaction event received but not processed',
                    'event_type' => $event_type
                );
        }
    }

    /**
     * Handle customer events (customer.*)
     *
     * Events: created, updated, deleted
     *
     * @param string $event_type Customer event type
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

        // For now, just log customer events - extend as needed
        return array(
            'status' => 'success',
            'message' => 'Customer event processed',
            'event_type' => $event_type,
            'customer_id' => $data['id'] ?? null
        );
    }

    /**
     * Handle payment method events (payment_method.*)
     *
     * Events: created, deleted
     *
     * @param string $event_type Payment method event type
     * @param array $data Payment method data
     * @param string $delivery_id Webhook delivery ID
     * @return array Processing result
     */
    private static function handle_payment_method_event($event_type, $data, $delivery_id) {
        bna_log('Processing payment method event', array(
            'event_type' => $event_type,
            'payment_method_id' => $data['id'] ?? 'unknown',
            'customer_id' => $data['customerId'] ?? 'unknown',
            'method_type' => $data['method'] ?? 'unknown',
            'delivery_id' => $delivery_id
        ));

        $customer_id = $data['customerId'] ?? null;

        switch ($event_type) {
            case 'created':
                return self::handle_payment_method_created($data, $customer_id);

            case 'deleted':
                return self::handle_payment_method_deleted($data, $customer_id);

            default:
                bna_log('Unrecognized payment method event type', array(
                    'event_type' => $event_type,
                    'payment_method_id' => $data['id'] ?? 'unknown'
                ));

                return array(
                    'status' => 'success',
                    'message' => 'Payment method event received but not processed',
                    'event_type' => $event_type
                );
        }
    }

    /**
     * Handle subscription events (subscription.*)
     *
     * Events: created, processed, will_expire, updated, deleted
     *
     * @param string $event_type Subscription event type
     * @param array $data Subscription data
     * @param string $delivery_id Webhook delivery ID
     * @return array Processing result
     */
    private static function handle_subscription_event($event_type, $data, $delivery_id) {
        bna_log('Processing subscription event', array(
            'event_type' => $event_type,
            'subscription_id' => $data['id'] ?? 'unknown',
            'customer_id' => $data['customerId'] ?? 'unknown',
            'status' => $data['status'] ?? 'unknown',
            'delivery_id' => $delivery_id
        ));

        // For now, just log subscription events - extend as needed
        return array(
            'status' => 'success',
            'message' => 'Subscription event processed',
            'event_type' => $event_type,
            'subscription_id' => $data['id'] ?? null
        );
    }

    // ==========================================
    // LEGACY WEBHOOK HANDLERS (Backwards Compatibility)
    // ==========================================

    /**
     * Handle legacy payment method webhook with customer data
     *
     * @param array $payload Legacy webhook payload
     * @return array Processing result
     */
    private static function handle_payment_method_with_customer($payload) {
        bna_log('Processing legacy payment method webhook with customer data', array(
            'payment_method_id' => $payload['paymentMethod']['id'] ?? 'unknown',
            'customer_id' => $payload['customer']['id'] ?? 'unknown',
            'method_type' => $payload['paymentMethod']['method'] ?? $payload['paymentMethod']['cardType'] ?? 'unknown',
            'card_brand' => $payload['paymentMethod']['cardBrand'] ?? 'unknown',
            'created_at' => $payload['paymentMethod']['createdAt'] ?? 'unknown'
        ));

        $payment_method = $payload['paymentMethod'];
        $customer = $payload['customer'];
        $customer_id = $customer['id'] ?? null;

        if (empty($customer_id)) {
            bna_error('Customer ID missing in payment method webhook', array(
                'payload_keys' => array_keys($payload)
            ));
            return array(
                'status' => 'error',
                'message' => 'Customer ID missing in payment method webhook'
            );
        }

        $action = $payload['action'] ?? $payload['event'] ?? 'created';

        if (isset($payload['paymentMethod']['createdAt'])) {
            $action = 'created';
            bna_log('Payment method creation detected via createdAt field', array(
                'payment_method_id' => $payment_method['id'],
                'created_at' => $payment_method['createdAt']
            ));
        }

        switch (strtolower($action)) {
            case 'created':
            case 'payment_method.created':
                return self::handle_payment_method_created($payment_method, $customer_id);

            case 'deleted':
            case 'delete':
            case 'payment_method.delete':
                return self::handle_payment_method_deleted($payment_method, $customer_id);

            default:
                bna_log('Assuming payment method creation due to presence of paymentMethod and customer', array(
                    'assumed_action' => 'created',
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method['id'] ?? 'unknown',
                    'original_action' => $action
                ));

                return self::handle_payment_method_created($payment_method, $customer_id);
        }
    }

    /**
     * Handle legacy transaction webhook
     *
     * @param array $payload Legacy transaction webhook payload
     * @return array Processing result
     */
    private static function handle_transaction_webhook($payload) {
        $transaction_data = $payload['transaction'] ?? $payload['data']['transaction'] ?? $payload;

        bna_log('Processing legacy transaction webhook', array(
            'transaction_id' => $transaction_data['id'] ?? 'unknown',
            'status' => $transaction_data['status'] ?? 'unknown',
            'amount' => $transaction_data['total'] ?? $transaction_data['amount'] ?? 'unknown'
        ));

        return self::handle_transaction_status_update($transaction_data);
    }

    /**
     * Handle legacy customer webhook
     *
     * @param array $payload Legacy customer webhook payload
     * @return array Processing result
     */
    private static function handle_customer_webhook($payload) {
        $customer_data = $payload['customer'] ?? $payload;
        $action = $payload['action'] ?? 'updated';

        bna_log('Processing legacy customer webhook', array(
            'customer_id' => $customer_data['id'] ?? 'unknown',
            'action' => $action,
            'email' => $customer_data['email'] ?? 'unknown'
        ));

        return array(
            'status' => 'success',
            'message' => 'Legacy customer webhook processed',
            'action' => $action
        );
    }

    /**
     * Handle legacy subscription webhook
     *
     * @param array $payload Legacy subscription webhook payload
     * @return array Processing result
     */
    private static function handle_subscription_webhook($payload) {
        $subscription_data = $payload['subscription'] ?? $payload;

        bna_log('Processing legacy subscription webhook', array(
            'subscription_id' => $subscription_data['id'] ?? 'unknown',
            'status' => $subscription_data['status'] ?? 'unknown'
        ));

        return array(
            'status' => 'success',
            'message' => 'Legacy subscription webhook processed'
        );
    }

    /**
     * Handle legacy payment method webhook
     *
     * @param array $payload Legacy payment method webhook payload
     * @return array Processing result
     */
    private static function handle_payment_method_webhook($payload) {
        bna_log('Processing legacy payment method webhook', array(
            'payload_keys' => array_keys($payload)
        ));

        $payment_method = $payload['payment_method'] ?? $payload['paymentMethod'] ?? $payload['data'] ?? array();
        $action = $payload['action'] ?? $payload['event'] ?? 'created';

        return array(
            'status' => 'success',
            'message' => 'Legacy payment method webhook processed',
            'action' => $action
        );
    }

    // ==========================================
    // TRANSACTION PROCESSING METHODS
    // ==========================================

    /**
     * Handle successful transaction (approved/processed)
     *
     * @param array $transaction_data Transaction data
     * @return array Processing result
     */
    private static function handle_successful_transaction($transaction_data) {
        $transaction_id = $transaction_data['id'] ?? null;
        $reference_uuid = $transaction_data['referenceUUID'] ?? $transaction_id;

        if (empty($reference_uuid)) {
            bna_error('Transaction reference missing in successful transaction webhook');
            return array(
                'status' => 'error',
                'message' => 'Transaction reference missing'
            );
        }

        // Find order by transaction reference
        $order = self::find_order_by_transaction_reference($reference_uuid);
        if (!$order) {
            bna_log('Order not found for successful transaction', array(
                'transaction_id' => $transaction_id,
                'reference_uuid' => $reference_uuid
            ));
            return array(
                'status' => 'warning',
                'message' => 'Order not found for transaction reference'
            );
        }

        // Mark order as paid
        if (!$order->is_paid()) {
            $order->payment_complete($transaction_id);
            $order->add_order_note(sprintf(
                'Payment completed via BNA Smart Payment. Transaction ID: %s',
                $transaction_id
            ));

            bna_log('Order marked as paid', array(
                'order_id' => $order->get_id(),
                'transaction_id' => $transaction_id,
                'amount' => $transaction_data['total'] ?? 'unknown'
            ));

            // Save payment method if provided
            self::save_payment_method_from_transaction($transaction_data, $order);
        }

        return array(
            'status' => 'success',
            'message' => 'Transaction processed successfully',
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id
        );
    }

    /**
     * Handle failed transaction (declined/canceled/expired)
     *
     * @param array $transaction_data Transaction data
     * @return array Processing result
     */
    private static function handle_failed_transaction($transaction_data) {
        $transaction_id = $transaction_data['id'] ?? null;
        $reference_uuid = $transaction_data['referenceUUID'] ?? $transaction_id;
        $decline_reason = $transaction_data['declineReason'] ?? 'Payment failed';

        if (empty($reference_uuid)) {
            bna_error('Transaction reference missing in failed transaction webhook');
            return array(
                'status' => 'error',
                'message' => 'Transaction reference missing'
            );
        }

        // Find order by transaction reference
        $order = self::find_order_by_transaction_reference($reference_uuid);
        if (!$order) {
            bna_log('Order not found for failed transaction', array(
                'transaction_id' => $transaction_id,
                'reference_uuid' => $reference_uuid
            ));
            return array(
                'status' => 'warning',
                'message' => 'Order not found for transaction reference'
            );
        }

        // Mark order as failed
        $order->update_status('failed', sprintf(
            'Payment failed via BNA Smart Payment: %s (Transaction ID: %s)',
            $decline_reason,
            $transaction_id
        ));

        bna_log('Order marked as failed', array(
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id,
            'reason' => $decline_reason
        ));

        return array(
            'status' => 'success',
            'message' => 'Failed transaction processed',
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id
        );
    }

    /**
     * Handle transaction status update
     *
     * @param array $transaction_data Transaction data
     * @return array Processing result
     */
    private static function handle_transaction_status_update($transaction_data) {
        $status = $transaction_data['status'] ?? 'unknown';

        switch (strtoupper($status)) {
            case 'APPROVED':
            case 'PROCESSED':
                return self::handle_successful_transaction($transaction_data);

            case 'DECLINED':
            case 'CANCELED':
            case 'EXPIRED':
                return self::handle_failed_transaction($transaction_data);

            default:
                bna_log('Transaction status update received', array(
                    'transaction_id' => $transaction_data['id'] ?? 'unknown',
                    'status' => $status
                ));

                return array(
                    'status' => 'success',
                    'message' => 'Transaction status update received',
                    'transaction_status' => $status
                );
        }
    }

    // ==========================================
    // PAYMENT METHOD PROCESSING
    // ==========================================

    /**
     * Handle payment method creation
     *
     * @param array $payment_method Payment method data
     * @param string $customer_id BNA customer ID
     * @return array Processing result
     */
    private static function handle_payment_method_created($payment_method, $customer_id) {
        bna_log('Payment method created', array(
            'payment_method_id' => $payment_method['id'] ?? 'unknown',
            'customer_id' => $customer_id,
            'method_type' => $payment_method['method'] ?? $payment_method['cardType'] ?? 'unknown'
        ));

        // Find WordPress user by BNA customer ID
        $wp_user_id = self::find_wp_user_by_bna_customer_id($customer_id);
        if (!$wp_user_id) {
            bna_log('WordPress user not found for BNA customer', array(
                'bna_customer_id' => $customer_id
            ));
            return array(
                'status' => 'warning',
                'message' => 'WordPress user not found for customer'
            );
        }

        // Save payment method
        $payment_methods_manager = BNA_Payment_Methods::get_instance();
        $result = $payment_methods_manager->save_payment_method($wp_user_id, $payment_method);

        if ($result) {
            bna_log('Payment method saved to WordPress user', array(
                'wp_user_id' => $wp_user_id,
                'payment_method_id' => $payment_method['id'] ?? 'unknown'
            ));
        }

        return array(
            'status' => 'success',
            'message' => 'Payment method created and saved',
            'wp_user_id' => $wp_user_id,
            'saved' => $result
        );
    }

    /**
     * Handle payment method deletion
     *
     * @param array $payment_method Payment method data
     * @param string $customer_id BNA customer ID
     * @return array Processing result
     */
    private static function handle_payment_method_deleted($payment_method, $customer_id) {
        bna_log('Payment method deleted', array(
            'payment_method_id' => $payment_method['id'] ?? 'unknown',
            'customer_id' => $customer_id
        ));

        // Find WordPress user by BNA customer ID
        $wp_user_id = self::find_wp_user_by_bna_customer_id($customer_id);
        if (!$wp_user_id) {
            bna_log('WordPress user not found for BNA customer during deletion', array(
                'bna_customer_id' => $customer_id
            ));
            return array(
                'status' => 'warning',
                'message' => 'WordPress user not found for customer'
            );
        }

        // Remove payment method
        $payment_methods_manager = BNA_Payment_Methods::get_instance();
        $result = $payment_methods_manager->delete_payment_method($wp_user_id, $payment_method['id']);

        if ($result) {
            bna_log('Payment method removed from WordPress user', array(
                'wp_user_id' => $wp_user_id,
                'payment_method_id' => $payment_method['id'] ?? 'unknown'
            ));
        }

        return array(
            'status' => 'success',
            'message' => 'Payment method deleted',
            'wp_user_id' => $wp_user_id,
            'deleted' => $result
        );
    }

    // ==========================================
    // UTILITY METHODS
    // ==========================================

    /**
     * Find WooCommerce order by transaction reference
     *
     * @param string $reference_uuid Transaction reference UUID
     * @return WC_Order|null Order object or null if not found
     */
    private static function find_order_by_transaction_reference($reference_uuid) {
        if (empty($reference_uuid)) {
            return null;
        }

        // Search by order ID if numeric
        if (is_numeric($reference_uuid)) {
            $order = wc_get_order($reference_uuid);
            if ($order && $order->get_payment_method() === 'bna_smart_payment') {
                return $order;
            }
        }

        // Search by order meta
        $orders = wc_get_orders(array(
            'limit' => 1,
            'meta_query' => array(
                array(
                    'key' => '_bna_transaction_reference',
                    'value' => $reference_uuid,
                    'compare' => '='
                )
            )
        ));

        return !empty($orders) ? $orders[0] : null;
    }

    /**
     * Find WordPress user by BNA customer ID
     *
     * @param string $bna_customer_id BNA customer ID
     * @return int|null WordPress user ID or null if not found
     */
    private static function find_wp_user_by_bna_customer_id($bna_customer_id) {
        if (empty($bna_customer_id)) {
            return null;
        }

        $users = get_users(array(
            'meta_key' => '_bna_customer_id',
            'meta_value' => $bna_customer_id,
            'number' => 1
        ));

        return !empty($users) ? $users[0]->ID : null;
    }

    /**
     * Save payment method from transaction data
     *
     * @param array $transaction_data Transaction data containing payment details
     * @param WC_Order $order WooCommerce order
     */
    private static function save_payment_method_from_transaction($transaction_data, $order) {
        if (!isset($transaction_data['paymentDetails']) || !$order->get_customer_id()) {
            return;
        }

        $payment_details = $transaction_data['paymentDetails'];
        $payment_method = $transaction_data['paymentMethod'] ?? 'CARD';

        // Prepare payment method data
        $method_data = array(
            'id' => $transaction_data['id'] ?? uniqid('bna_'),
            'type' => $payment_method,
            'created_at' => current_time('Y-m-d H:i:s')
        );

        // Add specific details based on payment method
        switch ($payment_method) {
            case 'CARD':
                $method_data['last4'] = $payment_details['cardNumber'] ?? '';
                $method_data['brand'] = $payment_details['cardBrand'] ?? '';
                $method_data['card_type'] = $payment_details['cardType'] ?? '';
                break;

            case 'EFT':
                $method_data['bank_name'] = $payment_details['bankName'] ?? '';
                $method_data['account_number'] = $payment_details['accountNumber'] ?? '';
                break;

            case 'E_TRANSFER':
                $method_data['name'] = $payment_details['name'] ?? '';
                $method_data['delivery_type'] = $payment_details['deliveryType'] ?? '';
                break;
        }

        // Save payment method
        $payment_methods_manager = BNA_Payment_Methods::get_instance();
        $payment_methods_manager->save_payment_method($order->get_customer_id(), $method_data);

        bna_log('Payment method saved from transaction', array(
            'order_id' => $order->get_id(),
            'customer_id' => $order->get_customer_id(),
            'payment_method' => $payment_method,
            'method_id' => $method_data['id']
        ));
    }

    /**
     * Test endpoint for webhook connectivity
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function test_endpoint($request) {
        return new WP_REST_Response(array(
            'status' => 'ok',
            'message' => 'BNA Webhook endpoint is working',
            'timestamp' => current_time('c'),
            'version' => defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'unknown'
        ), 200);
    }
}