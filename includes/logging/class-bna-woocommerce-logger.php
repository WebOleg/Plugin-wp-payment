<?php
/**
 * BNA WooCommerce Logger
 * Handles WooCommerce-specific logging
 */

if (!defined('ABSPATH')) exit;

class BNA_WooCommerce_Logger extends BNA_Abstract_Logger {
    
    private static $instance = null;
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        parent::__construct('woocommerce');
    }
    
    /**
     * Log order events
     */
    public function log_order($event, $order, $data = []) {
        if (!$order) {
            $this->error("Order logging failed - no order provided", ['event' => $event]);
            return;
        }
        
        $order_data = [
            'event' => $event,
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'customer_email' => $order->get_billing_email()
        ];
        
        $this->info("Order {$event}", array_merge($order_data, $data));
    }
    
    /**
     * Log payment events
     */
    public function log_payment($event, $order, $data = []) {
        $payment_data = [
            'event' => $event,
            'order_id' => $order ? $order->get_id() : 'unknown',
            'payment_method' => $order ? $order->get_payment_method() : 'unknown'
        ];
        
        if ($event === 'success') {
            $this->info("Payment completed", array_merge($payment_data, $data));
        } else {
            $this->error("Payment {$event}", array_merge($payment_data, $data));
        }
    }
    
    /**
     * Log gateway events
     */
    public function log_gateway($event, $data = []) {
        $this->info("Gateway {$event}", $data);
    }
    
    /**
     * Log checkout events
     */
    public function log_checkout($event, $data = []) {
        $this->debug("Checkout {$event}", $data);
    }
}

// Global helper functions
function bna_wc_log($message, $data = []) {
    BNA_WooCommerce_Logger::instance()->info($message, $data);
}

function bna_wc_debug($message, $data = []) {
    BNA_WooCommerce_Logger::instance()->debug($message, $data);
}

function bna_wc_error($message, $data = []) {
    BNA_WooCommerce_Logger::instance()->error($message, $data);
}
