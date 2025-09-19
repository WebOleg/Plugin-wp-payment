<?php

if (!defined('ABSPATH')) exit;

class BNA_Subscriptions {

    private static $instance = null;
    private $api;
    private $table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bna_subscriptions';
        $this->api = new BNA_API();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('init', array($this, 'init'));
        register_activation_hook(BNA_SMART_PAYMENT_PLUGIN_BASENAME, array($this, 'create_table'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        add_action('woocommerce_order_status_completed', array($this, 'maybe_create_subscription'), 10, 1);
        add_action('wp_ajax_bna_manage_subscription', array($this, 'manage_subscription'));
        add_action('bna_process_subscription_webhook', array($this, 'process_subscription_webhook'), 10, 2);
    }

    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            bna_subscription_id varchar(100) NOT NULL,
            product_id bigint(20) NOT NULL,
            order_id bigint(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            frequency varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL DEFAULT 'CAD',
            remaining_payments int DEFAULT NULL,
            next_payment_date datetime DEFAULT NULL,
            last_payment_date datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY bna_subscription_id (bna_subscription_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        bna_log('BNA Subscriptions table created', array('table' => $this->table_name));
    }

    public function maybe_create_subscription($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'bna_smart_payment') {
            return;
        }

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_type() === 'bna_subscription') {
                $this->create_bna_subscription($order, $product, $item);
                break;
            }
        }
    }

    private function create_bna_subscription($order, $product, $item) {
        $user_id = $order->get_user_id();
        $frequency = $product->get_meta('_bna_subscription_frequency');
        $amount = $item->get_total();
        $remaining_payments = $product->get_meta('_bna_subscription_payments');

        $subscription_data = array(
            'customerId' => get_user_meta($user_id, '_bna_customer_id', true),
            'recurrence' => strtoupper($frequency),
            'action' => 'SALE',
            'subtotal' => floatval($amount),
            'currency' => $order->get_currency(),
            'applyFee' => false,
            'paymentMethod' => 'CARD',
            'startPaymentDate' => date('Y-m-d H:i:s', strtotime('+1 ' . $frequency)),
            'items' => array(array(
                'sku' => $product->get_sku() ?: $product->get_id(),
                'description' => $product->get_name(),
                'price' => floatval($amount),
                'quantity' => 1,
                'amount' => floatval($amount)
            ))
        );

        if ($remaining_payments && $remaining_payments > 0) {
            $subscription_data['remainingPayments'] = intval($remaining_payments);
        }

        $response = $this->api->create_subscription($subscription_data);

        if ($response && isset($response['id'])) {
            $this->save_subscription(array(
                'user_id' => $user_id,
                'bna_subscription_id' => $response['id'],
                'product_id' => $product->get_id(),
                'order_id' => $order->get_id(),
                'status' => 'active',
                'frequency' => $frequency,
                'amount' => $amount,
                'currency' => $order->get_currency(),
                'remaining_payments' => $remaining_payments ?: null,
                'next_payment_date' => date('Y-m-d H:i:s', strtotime('+1 ' . $frequency))
            ));

            bna_log('BNA Subscription created', array(
                'bna_subscription_id' => $response['id'],
                'user_id' => $user_id,
                'product_id' => $product->get_id()
            ));
        }
    }

    private function save_subscription($data) {
        global $wpdb;
        
        $wpdb->insert($this->table_name, $data);
        return $wpdb->insert_id;
    }

    public function get_user_subscriptions($user_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));
    }

    public function manage_subscription() {
        check_ajax_referer('bna_manage_subscription', 'nonce');

        $subscription_id = sanitize_text_field($_POST['subscription_id']);
        $action = sanitize_text_field($_POST['action_type']);

        $subscription = $this->get_subscription_by_id($subscription_id);
        if (!$subscription || $subscription->user_id != get_current_user_id()) {
            wp_send_json_error('Invalid subscription');
        }

        $result = false;
        switch ($action) {
            case 'suspend':
                $result = $this->api->suspend_subscription($subscription->bna_subscription_id);
                if ($result) {
                    $this->update_subscription_status($subscription_id, 'suspended');
                }
                break;
            case 'resume':
                $result = $this->api->resume_subscription($subscription->bna_subscription_id);
                if ($result) {
                    $this->update_subscription_status($subscription_id, 'active');
                }
                break;
            case 'cancel':
                $result = $this->api->delete_subscription($subscription->bna_subscription_id);
                if ($result) {
                    $this->update_subscription_status($subscription_id, 'cancelled');
                }
                break;
        }

        if ($result) {
            wp_send_json_success('Subscription ' . $action . 'd successfully');
        } else {
            wp_send_json_error('Failed to ' . $action . ' subscription');
        }
    }

    private function get_subscription_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE id = %d",
            $id
        ));
    }

    private function update_subscription_status($id, $status) {
        global $wpdb;
        return $wpdb->update(
            $this->table_name,
            array('status' => $status),
            array('id' => $id)
        );
    }

    public function process_subscription_webhook($event_type, $data) {
        switch ($event_type) {
            case 'subscription.processed':
                $this->handle_subscription_payment($data);
                break;
            case 'subscription.will_expire':
                $this->handle_subscription_expiry($data);
                break;
            case 'subscription.updated':
                $this->sync_subscription_status($data);
                break;
        }
    }

    private function handle_subscription_payment($data) {
        $subscription = $this->get_subscription_by_bna_id($data['subscriptionId']);
        if ($subscription) {
            $this->create_renewal_order($subscription, $data);
        }
    }

    private function get_subscription_by_bna_id($bna_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE bna_subscription_id = %s",
            $bna_id
        ));
    }

    private function create_renewal_order($subscription, $payment_data) {
        $original_order = wc_get_order($subscription->order_id);
        if (!$original_order) return;

        $order = wc_create_order(array('customer_id' => $subscription->user_id));
        $product = wc_get_product($subscription->product_id);
        
        if ($product) {
            $order->add_product($product, 1, array(
                'subtotal' => $subscription->amount,
                'total' => $subscription->amount
            ));
        }

        $order->set_payment_method('bna_smart_payment');
        $order->set_payment_method_title('BNA Smart Payment');
        $order->set_total($subscription->amount);
        $order->update_meta_data('_bna_subscription_renewal', $subscription->id);
        $order->update_meta_data('_bna_transaction_id', $payment_data['id']);
        $order->set_status('completed');
        $order->save();

        global $wpdb;
        $wpdb->update(
            $this->table_name,
            array(
                'last_payment_date' => current_time('mysql'),
                'next_payment_date' => date('Y-m-d H:i:s', strtotime('+1 ' . $subscription->frequency))
            ),
            array('id' => $subscription->id)
        );

        bna_log('Subscription renewal order created', array(
            'subscription_id' => $subscription->id,
            'order_id' => $order->get_id(),
            'amount' => $subscription->amount
        ));
    }

    private function handle_subscription_expiry($data) {
        $subscription = $this->get_subscription_by_bna_id($data['id']);
        if ($subscription) {
            $this->update_subscription_status($subscription->id, 'expired');
        }
    }

    private function sync_subscription_status($data) {
        $subscription = $this->get_subscription_by_bna_id($data['id']);
        if ($subscription) {
            $status_map = array(
                'ACTIVE' => 'active',
                'SUSPENDED' => 'suspended',
                'EXPIRED' => 'expired',
                'CANCELED' => 'cancelled',
                'FAILED' => 'failed'
            );
            
            $new_status = isset($status_map[$data['status']]) ? $status_map[$data['status']] : 'unknown';
            $this->update_subscription_status($subscription->id, $new_status);
        }
    }
}
