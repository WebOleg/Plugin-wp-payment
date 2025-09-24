<?php
/**
 * BNA Webhooks Handler
 * Handles incoming webhook requests with HMAC security verification
 * Supports both new event-based format and legacy webhook formats
 *
 * @since 1.9.0 Added comprehensive subscription webhook support
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
     * BNA subscription status mapping to WooCommerce order status
     * @var array
     */
    private static $SUBSCRIPTION_STATUS_MAP = array(
        'new' => 'processing',
        'active' => 'processing',
        'suspended' => 'on-hold',
        'expired' => 'completed',
        'invalid' => 'failed',
        'canceled' => 'cancelled',
        'cancelled' => 'cancelled',
        'failed' => 'failed',
        'deleted' => 'cancelled'
    );

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

        // Verify webhook signature if configured
        $webhook_secret = get_option('bna_smart_payment_webhook_secret', '');
        if (!empty($webhook_secret)) {
            $signature_valid = self::verify_webhook_signature($raw_body, $signature, $timestamp, $webhook_secret);

            if (!$signature_valid) {
                bna_error('Webhook signature verification failed');
                return new WP_REST_Response(array('error' => 'Invalid signature'), 401);
            }

            bna_log('Webhook signature verified successfully');
        } else {
            bna_log('Webhook signature verification skipped (no secret configured)');
        }

        // Validate payload
        if (!is_array($payload)) {
            bna_error('Invalid webhook payload format');
            return new WP_REST_Response(array('error' => 'Invalid payload'), 400);
        }

        // Process webhook based on format
        $result = self::process_webhook($payload);

        // Log processing result
        $processing_time = round((microtime(true) - $start_time) * 1000, 2);
        bna_log('Webhook processed successfully', array(
            'processing_time_ms' => $processing_time,
            'result' => $result
        ));

        return new WP_REST_Response($result, 200);
    }

    /**
     * Verify webhook signature using HMAC-SHA256
     *
     * @param string $raw_body Raw request body
     * @param string $signature Provided signature
     * @param string $timestamp Request timestamp
     * @param string $webhook_secret Webhook secret key
     * @return bool True if signature is valid
     */
    private static function verify_webhook_signature($raw_body, $signature, $timestamp, $webhook_secret) {
        if (empty($signature) || empty($timestamp) || empty($webhook_secret)) {
            return false;
        }

        // Check timestamp age (5 minutes tolerance)
        $timestamp_unix = strtotime($timestamp);
        if ($timestamp_unix === false || abs(time() - $timestamp_unix) > self::MAX_TIMESTAMP_AGE) {
            bna_log('Webhook timestamp validation failed', array(
                'provided_timestamp' => $timestamp,
                'current_time' => date('c'),
                'age_seconds' => time() - $timestamp_unix
            ));
            return false;
        }

        // Parse payload to extract data part
        $payload_data = json_decode($raw_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            bna_error('Failed to parse webhook payload for signature verification', array(
                'json_error' => json_last_error_msg()
            ));
            return false;
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
                'data_hash' => $data_hash,
                'signing_string' => $signing_string,
                'computed_signature' => $computed_signature,
                'provided_signature' => $signature,
                'match' => hash_equals($signature, $computed_signature),
                'serialized_preview' => $serialized_preview,
                'serialized_length' => strlen($serialized_data)
            );

            if (hash_equals($signature, $computed_signature)) {
                $successful_approach = $approach_name;
                break;
            }
        }

        bna_log('Webhook signature verification debug', array(
            'successful_approach' => $successful_approach,
            'provided_signature' => $signature,
            'timestamp' => $timestamp,
            'webhook_secret_length' => strlen($webhook_secret),
            'data_part_keys' => is_array($data_part) ? array_keys($data_part) : 'invalid',
            'raw_extraction_attempted' => true,
            'raw_extraction_count' => 2,
            'raw_body_preview' => substr($raw_body, 0, 100) . '...',
            'total_tests' => count($all_tests)
        ));

        if ($successful_approach) {
            bna_log('Webhook signature verified successfully', array('method' => $successful_approach));
            return true;
        }

        return false;
    }

    /**
     * Extract raw data JSON from raw body for signature verification
     *
     * @param string $raw_body Raw request body
     * @return string|false Extracted data JSON or false
     */
    private static function extract_data_from_raw_json($raw_body) {
        // Try simple pattern first
        $pattern = '/"data"\s*:\s*(\{[^}]*(?:\{[^}]*\}[^}]*)*\})\s*\}$/';
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
            case 'subscription.canceled':
            case 'subscription.cancelled':
            case 'subscription.suspended':
            case 'subscription.resumed':
            case 'subscription.expired':
            case 'subscription.failed':
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
     * ВИПРАВЛЕНО: додано пошук по invoiceId та обробку transaction.processed
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

        // ВИПРАВЛЕННЯ: пошук замовлення кількома способами
        // Спочатку по transaction ID
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_transaction_id',
            'meta_value' => $transaction_id,
            'limit' => 1
        ));

        // ВИПРАВЛЕННЯ: якщо не знайдено, шукаємо по invoiceId
        if (empty($orders) && isset($data['invoiceInfo']['invoiceId'])) {
            $invoice_id = $data['invoiceInfo']['invoiceId'];
            bna_log('Transaction not found by ID, trying invoiceId search', array(
                'transaction_id' => $transaction_id,
                'invoice_id' => $invoice_id
            ));

            if (is_numeric($invoice_id)) {
                $order = wc_get_order($invoice_id);
                if ($order && $order->get_payment_method() === 'bna_smart_payment') {
                    $orders = array($order);
                    bna_log('Order found by invoiceId', array('order_id' => $invoice_id));
                }
            }
        }

        // ВИПРАВЛЕННЯ: також спробуємо пошук по referenceUUID
        if (empty($orders) && isset($data['referenceUUID'])) {
            $reference_uuid = $data['referenceUUID'];
            $orders = wc_get_orders(array(
                'meta_key' => '_bna_reference_uuid',
                'meta_value' => $reference_uuid,
                'limit' => 1
            ));

            if (!empty($orders)) {
                bna_log('Order found by referenceUUID', array('reference_uuid' => $reference_uuid));
            }
        }

        if (empty($orders)) {
            bna_log('No order found for transaction', array('transaction_id' => $transaction_id));
            return array('status' => 'ignored', 'reason' => 'Order not found');
        }

        $order = $orders[0];

        // Save transaction ID to order meta if not already saved
        if (!$order->get_meta('_bna_transaction_id')) {
            $order->update_meta_data('_bna_transaction_id', $transaction_id);
            $order->save_meta_data();
        }

        // Check if this is a subscription renewal transaction
        if (self::is_subscription_renewal_transaction($data)) {
            return self::handle_subscription_renewal_transaction($order, $data);
        }

        // Update order status based on transaction status
        switch (strtolower($status)) {
            case 'approved':
            case 'completed':
                if (!$order->has_status(array('processing', 'completed'))) {
                    $order->payment_complete($transaction_id);
                    $order->add_order_note(__('Payment approved via BNA webhook.', 'bna-smart-payment'));
                }
                break;

            case 'processed':
                // ВИПРАВЛЕННЯ: для EFT та eTransfer - processed означає успішну обробку
                $payment_method = $data['paymentMethod']['method'] ?? '';
                if ($payment_method === 'EFT' || $payment_method === 'E_TRANSFER') {
                    if (!$order->has_status(array('processing', 'completed'))) {
                        $order->payment_complete($transaction_id);
                        $order->add_order_note(__('Payment processed via BNA webhook (EFT/eTransfer).', 'bna-smart-payment'));
                        bna_log('Order payment processed for EFT/eTransfer', array(
                            'order_id' => $order->get_id(),
                            'payment_method' => $payment_method
                        ));
                    }
                } else {
                    // Для інших методів processed означає очікування
                    $order->add_order_note(__('Payment processing via BNA webhook.', 'bna-smart-payment'));
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

            default:
                $order->add_order_note(sprintf(__('Transaction status updated: %s', 'bna-smart-payment'), $status));
                break;
        }

        return array(
            'status' => 'processed',
            'event' => $event,
            'order_id' => $order->get_id(),
            'transaction_id' => $transaction_id
        );
    }

    /**
     * Handle subscription events - ПОВНА РЕАЛІЗАЦІЯ (v1.9.0)
     *
     * @param string $event Event name
     * @param array $data Subscription data
     * @return array Processing result
     */
    private static function handle_subscription_event($event, $data) {
        if (!bna_subscriptions_enabled()) {
            return array('status' => 'ignored', 'reason' => 'Subscriptions not enabled');
        }

        if (!isset($data['id'])) {
            return array('status' => 'error', 'reason' => 'Missing subscription ID');
        }

        $subscription_id = $data['id'];
        $customer_id = $data['customerId'] ?? '';
        $status = strtolower($data['status'] ?? '');

        bna_log('Handling subscription event', array(
            'event' => $event,
            'subscription_id' => $subscription_id,
            'customer_id' => $customer_id,
            'status' => $status,
            'data_keys' => array_keys($data)
        ));

        // Find original order with this subscription
        $original_order = self::find_subscription_order($subscription_id, $customer_id);

        if (!$original_order) {
            bna_log('No original order found for subscription', array(
                'subscription_id' => $subscription_id,
                'customer_id' => $customer_id
            ));
            return array('status' => 'ignored', 'reason' => 'Original order not found');
        }

        // Store BNA subscription ID in order meta if not already stored
        if (!$original_order->get_meta('_bna_subscription_id')) {
            $original_order->update_meta_data('_bna_subscription_id', $subscription_id);
            $original_order->save();
        }

        // Process event based on type
        switch ($event) {
            case 'subscription.created':
                return self::handle_subscription_created($original_order, $data);

            case 'subscription.processed':
                return self::handle_subscription_processed($original_order, $data);

            case 'subscription.updated':
                return self::handle_subscription_updated($original_order, $data);

            case 'subscription.suspended':
                return self::handle_subscription_suspended($original_order, $data);

            case 'subscription.resumed':
                return self::handle_subscription_resumed($original_order, $data);

            case 'subscription.canceled':
            case 'subscription.cancelled':
                return self::handle_subscription_cancelled($original_order, $data);

            case 'subscription.expired':
                return self::handle_subscription_expired($original_order, $data);

            case 'subscription.failed':
                return self::handle_subscription_failed($original_order, $data);

            case 'subscription.deleted':
                return self::handle_subscription_deleted($original_order, $data);

            case 'subscription.will_expire':
                return self::handle_subscription_will_expire($original_order, $data);

            default:
                bna_log('Unknown subscription event', array('event' => $event));
                return array('status' => 'ignored', 'reason' => 'Unknown subscription event');
        }
    }

    /**
     * Find order associated with subscription
     */
    private static function find_subscription_order($subscription_id, $customer_id = '') {
        // First, try to find by subscription ID
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_subscription_id',
            'meta_value' => $subscription_id,
            'limit' => 1
        ));

        if (!empty($orders)) {
            return $orders[0];
        }

        // If not found, try to find by customer ID and subscription flag
        if (!empty($customer_id)) {
            $orders = wc_get_orders(array(
                'meta_key' => '_bna_customer_id',
                'meta_value' => $customer_id,
                'meta_query' => array(
                    array(
                        'key' => '_bna_has_subscription',
                        'value' => 'yes',
                        'compare' => '='
                    )
                ),
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC'
            ));

            if (!empty($orders)) {
                return $orders[0];
            }
        }

        return null;
    }

    /**
     * Handle subscription created event
     */
    private static function handle_subscription_created($order, $data) {
        $order->update_meta_data('_bna_subscription_status', 'active');
        $order->update_meta_data('_bna_subscription_last_event', 'created');
        $order->add_order_note(__('BNA subscription created successfully.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription created', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.created',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription processed event (recurring payment successful)
     */
    private static function handle_subscription_processed($order, $data) {
        // Create renewal order for successful recurring payment
        $renewal_order = self::create_subscription_renewal_order($order, $data);

        if ($renewal_order) {
            // Mark renewal order as paid
            $transaction_id = $data['transactionId'] ?? $data['id'];
            $renewal_order->payment_complete($transaction_id);
            $renewal_order->add_order_note(__('Subscription renewal payment processed via BNA webhook.', 'bna-smart-payment'));

            bna_log('Subscription renewal order created and completed', array(
                'original_order_id' => $order->get_id(),
                'renewal_order_id' => $renewal_order->get_id(),
                'subscription_id' => $data['id']
            ));
        }

        // Update original order
        $order->update_meta_data('_bna_subscription_status', 'active');
        $order->update_meta_data('_bna_subscription_last_event', 'processed');
        $order->update_meta_data('_bna_subscription_last_payment', current_time('Y-m-d H:i:s'));
        $order->add_order_note(__('Subscription payment processed successfully.', 'bna-smart-payment'));
        $order->save();

        return array(
            'status' => 'processed',
            'event' => 'subscription.processed',
            'order_id' => $order->get_id(),
            'renewal_order_id' => $renewal_order ? $renewal_order->get_id() : null,
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription suspended event
     */
    private static function handle_subscription_suspended($order, $data) {
        $order->update_meta_data('_bna_subscription_status', 'suspended');
        $order->update_meta_data('_bna_subscription_last_event', 'suspended');
        $order->update_status('on-hold', __('Subscription suspended via BNA webhook.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription suspended', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.suspended',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription resumed event
     */
    private static function handle_subscription_resumed($order, $data) {
        $order->update_meta_data('_bna_subscription_status', 'active');
        $order->update_meta_data('_bna_subscription_last_event', 'resumed');
        $order->update_status('processing', __('Subscription resumed via BNA webhook.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription resumed', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.resumed',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription cancelled event
     */
    private static function handle_subscription_cancelled($order, $data) {
        $order->update_meta_data('_bna_subscription_status', 'cancelled');
        $order->update_meta_data('_bna_subscription_last_event', 'cancelled');
        $order->update_status('cancelled', __('Subscription cancelled via BNA webhook.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription cancelled', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.cancelled',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription expired event
     */
    private static function handle_subscription_expired($order, $data) {
        $order->update_meta_data('_bna_subscription_status', 'expired');
        $order->update_meta_data('_bna_subscription_last_event', 'expired');
        $order->update_status('completed', __('Subscription expired via BNA webhook.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription expired', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.expired',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription failed event
     */
    private static function handle_subscription_failed($order, $data) {
        $order->update_meta_data('_bna_subscription_status', 'failed');
        $order->update_meta_data('_bna_subscription_last_event', 'failed');
        $order->add_order_note(__('Subscription payment failed via BNA webhook.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription payment failed', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.failed',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription deleted event
     */
    private static function handle_subscription_deleted($order, $data) {
        $order->update_meta_data('_bna_subscription_status', 'deleted');
        $order->update_meta_data('_bna_subscription_last_event', 'deleted');
        $order->update_status('cancelled', __('Subscription deleted via BNA webhook.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription deleted', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.deleted',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription will expire event
     */
    private static function handle_subscription_will_expire($order, $data) {
        $expiry_date = $data['expiryDate'] ?? 'unknown';
        $order->update_meta_data('_bna_subscription_last_event', 'will_expire');
        $order->update_meta_data('_bna_subscription_expiry_date', $expiry_date);
        $order->add_order_note(sprintf(__('Subscription will expire on: %s', 'bna-smart-payment'), $expiry_date));
        $order->save();

        bna_log('Subscription will expire', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id'],
            'expiry_date' => $expiry_date
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.will_expire',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Handle subscription updated event
     */
    private static function handle_subscription_updated($order, $data) {
        $order->update_meta_data('_bna_subscription_last_event', 'updated');
        $order->add_order_note(__('Subscription updated via BNA webhook.', 'bna-smart-payment'));
        $order->save();

        bna_log('Subscription updated', array(
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        ));

        return array(
            'status' => 'processed',
            'event' => 'subscription.updated',
            'order_id' => $order->get_id(),
            'subscription_id' => $data['id']
        );
    }

    /**
     * Check if transaction is a subscription renewal
     */
    private static function is_subscription_renewal_transaction($data) {
        return isset($data['subscriptionId']) && !empty($data['subscriptionId']);
    }

    /**
     * Handle subscription renewal transaction
     */
    private static function handle_subscription_renewal_transaction($original_order, $data) {
        $subscription_id = $data['subscriptionId'];
        $transaction_id = $data['id'];
        $status = strtolower($data['status'] ?? '');

        bna_log('Handling subscription renewal transaction', array(
            'original_order_id' => $original_order->get_id(),
            'subscription_id' => $subscription_id,
            'transaction_id' => $transaction_id,
            'status' => $status
        ));

        // Create renewal order if payment is successful
        if (in_array($status, array('approved', 'completed', 'processed'))) {
            $renewal_order = self::create_subscription_renewal_order($original_order, $data);

            if ($renewal_order) {
                $renewal_order->payment_complete($transaction_id);
                $renewal_order->add_order_note(__('Subscription renewal payment completed.', 'bna-smart-payment'));

                // Update original order
                $original_order->update_meta_data('_bna_subscription_last_payment', current_time('Y-m-d H:i:s'));
                $original_order->save();

                return array(
                    'status' => 'processed',
                    'event' => 'subscription.renewal',
                    'original_order_id' => $original_order->get_id(),
                    'renewal_order_id' => $renewal_order->get_id(),
                    'transaction_id' => $transaction_id
                );
            }
        }

        return array(
            'status' => 'processed',
            'event' => 'subscription.renewal_failed',
            'original_order_id' => $original_order->get_id(),
            'transaction_id' => $transaction_id
        );
    }

    /**
     * Create subscription renewal order
     */
    private static function create_subscription_renewal_order($original_order, $data) {
        try {
            // Create new order based on original
            $renewal_order = wc_create_order(array(
                'customer_id' => $original_order->get_customer_id(),
                'status' => 'pending'
            ));

            if (!$renewal_order) {
                bna_error('Failed to create renewal order');
                return null;
            }

            // Copy items from original order
            foreach ($original_order->get_items() as $item) {
                $product = $item->get_product();
                if ($product && BNA_Subscriptions::is_subscription_product($product)) {
                    $renewal_order->add_product($product, $item->get_quantity());
                }
            }

            // Copy billing/shipping addresses
            $renewal_order->set_address($original_order->get_address('billing'), 'billing');
            $renewal_order->set_address($original_order->get_address('shipping'), 'shipping');

            // Set payment method
            $renewal_order->set_payment_method('bna_smart_payment');

            // Add renewal metadata
            $renewal_order->update_meta_data('_bna_subscription_renewal', 'yes');
            $renewal_order->update_meta_data('_bna_original_order_id', $original_order->get_id());
            $renewal_order->update_meta_data('_bna_subscription_id', $data['subscriptionId'] ?? $data['id']);
            $renewal_order->update_meta_data('_bna_customer_id', $data['customerId'] ?? '');

            // Calculate totals
            $renewal_order->calculate_totals();
            $renewal_order->save();

            bna_log('Subscription renewal order created', array(
                'original_order_id' => $original_order->get_id(),
                'renewal_order_id' => $renewal_order->get_id(),
                'subscription_id' => $data['subscriptionId'] ?? $data['id']
            ));

            return $renewal_order;

        } catch (Exception $e) {
            bna_error('Exception creating renewal order', array(
                'original_order_id' => $original_order->get_id(),
                'exception' => $e->getMessage(),
                'line' => $e->getLine()
            ));
            return null;
        }
    }

    /**
     * Handle customer events
     *
     * @param string $event Event name
     * @param array $data Customer data
     * @return array Processing result
     */
    private static function handle_customer_event($event, $data) {
        bna_log('Handling customer event', array(
            'event' => $event,
            'customer_id' => $data['id'] ?? 'unknown'
        ));

        // Find or create WordPress user
        if (isset($data['email']) && !empty($data['email'])) {
            $user = get_user_by('email', $data['email']);

            if (!$user && $event === 'customer.created') {
                // Create new user
                $user_data = array(
                    'user_login' => $data['email'],
                    'user_email' => $data['email'],
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
     * ВИПРАВЛЕНО: додана нормалізація method type!
     *
     * @param array $webhook_data Raw webhook data
     * @return array|false Transformed data or false on failure
     */
    private static function transform_webhook_payment_method_data($webhook_data) {
        if (!isset($webhook_data['id']) || !isset($webhook_data['method'])) {
            bna_log('Missing required fields in webhook payment method data', array(
                'has_id' => isset($webhook_data['id']),
                'has_method' => isset($webhook_data['method']),
                'data_keys' => array_keys($webhook_data)
            ));
            return false;
        }

        $base_data = array(
            'id' => $webhook_data['id'],
            'created_at' => $webhook_data['createdAt'] ?? current_time('Y-m-d H:i:s')
        );

        // ВИПРАВЛЕННЯ: нормалізація method type - додана підтримка малих літер!
        $method_type = strtoupper($webhook_data['method']); // Приводимо до великих літер

        bna_log('Transforming webhook payment method', array(
            'original_method' => $webhook_data['method'],
            'normalized_method' => $method_type,
            'method_id' => $webhook_data['id']
        ));

        // Transform based on webhook method type
        switch ($method_type) {
            case 'CARD':
                return self::transform_card_method_data($webhook_data, $base_data);

            case 'E_TRANSFER':
                return self::transform_e_transfer_method_data($webhook_data, $base_data);

            case 'EFT':
                return self::transform_eft_method_data($webhook_data, $base_data);

            default:
                bna_log('Unknown webhook payment method type', array(
                    'method' => $webhook_data['method'],
                    'normalized' => $method_type,
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
            'subscriptions_enabled' => bna_subscriptions_enabled(),
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