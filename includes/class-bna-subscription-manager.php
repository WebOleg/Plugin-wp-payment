<?php

if (!defined('ABSPATH')) exit;

class BNA_Subscription_Manager {

    private static $instance = null;
    private $api;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api = new BNA_API();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('wp_ajax_bna_subscription_action', array($this, 'handle_subscription_action'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('woocommerce_account_menu_items', array($this, 'add_subscriptions_menu_item'), 40);
        add_action('woocommerce_account_subscriptions_endpoint', array($this, 'subscriptions_content'));
        add_rewrite_endpoint('subscriptions', EP_ROOT | EP_PAGES);
        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));
    }

    public function add_query_vars($vars) {
        $vars['subscriptions'] = 'subscriptions';
        return $vars;
    }

    public function add_subscriptions_menu_item($items) {
        $new_items = array();

        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            if ($key === 'orders') {
                $new_items['subscriptions'] = __('Subscriptions', 'bna-smart-payment');
            }
        }

        if (!isset($items['orders'])) {
            $new_items['subscriptions'] = __('Subscriptions', 'bna-smart-payment');
        }

        return $new_items;
    }

    public function subscriptions_content() {
        if (!is_user_logged_in()) {
            echo '<p>' . __('You must be logged in to view subscriptions.', 'bna-smart-payment') . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $subscriptions_instance = BNA_Subscriptions::get_instance();
        $subscriptions = $subscriptions_instance->get_user_subscriptions($user_id);

        include BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/my-account-subscriptions.php';
    }

    public function enqueue_scripts() {
        if (is_account_page() && isset($GLOBALS['wp']->query_vars['subscriptions'])) {
            wp_enqueue_script(
                'bna-subscription-manager',
                BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/subscription-manager.js',
                array('jquery'),
                BNA_SMART_PAYMENT_VERSION,
                true
            );

            wp_localize_script('bna-subscription-manager', 'bna_subscription_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bna_subscription_action'),
                'messages' => array(
                    'confirm_suspend' => __('Are you sure you want to pause this subscription?', 'bna-smart-payment'),
                    'confirm_resume' => __('Are you sure you want to resume this subscription?', 'bna-smart-payment'),
                    'confirm_cancel' => __('Are you sure you want to cancel this subscription? This action cannot be undone.', 'bna-smart-payment'),
                    'processing' => __('Processing...', 'bna-smart-payment'),
                    'success' => __('Action completed successfully.', 'bna-smart-payment'),
                    'error' => __('An error occurred. Please try again.', 'bna-smart-payment')
                )
            ));
        }
    }

    public function handle_subscription_action() {
        check_ajax_referer('bna_subscription_action', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(__('Not authorized', 'bna-smart-payment'));
        }

        $subscription_id = intval($_POST['subscription_id']);
        $action = sanitize_text_field($_POST['action_type']);
        $user_id = get_current_user_id();

        $subscription = $this->get_subscription($subscription_id);
        if (!$subscription || $subscription->user_id != $user_id) {
            wp_send_json_error(__('Invalid subscription', 'bna-smart-payment'));
        }

        $result = false;
        $message = '';

        switch ($action) {
            case 'suspend':
                $result = $this->suspend_subscription($subscription);
                $message = __('Subscription paused successfully', 'bna-smart-payment');
                break;
            case 'resume':
                $result = $this->resume_subscription($subscription);
                $message = __('Subscription resumed successfully', 'bna-smart-payment');
                break;
            case 'cancel':
                $result = $this->cancel_subscription($subscription);
                $message = __('Subscription cancelled successfully', 'bna-smart-payment');
                break;
            default:
                wp_send_json_error(__('Invalid action', 'bna-smart-payment'));
        }

        if ($result) {
            wp_send_json_success($message);
        } else {
            wp_send_json_error(__('Failed to perform action', 'bna-smart-payment'));
        }
    }

    private function get_subscription($subscription_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bna_subscriptions';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $subscription_id
        ));
    }

    private function suspend_subscription($subscription) {
        $result = $this->api->suspend_subscription($subscription->bna_subscription_id);
        
        if ($result) {
            $this->update_subscription_status($subscription->id, 'suspended');
            
            bna_log('Subscription suspended', array(
                'subscription_id' => $subscription->id,
                'bna_subscription_id' => $subscription->bna_subscription_id,
                'user_id' => $subscription->user_id
            ));
            
            return true;
        }
        
        return false;
    }

    private function resume_subscription($subscription) {
        $result = $this->api->resume_subscription($subscription->bna_subscription_id);
        
        if ($result) {
            $this->update_subscription_status($subscription->id, 'active');
            
            bna_log('Subscription resumed', array(
                'subscription_id' => $subscription->id,
                'bna_subscription_id' => $subscription->bna_subscription_id,
                'user_id' => $subscription->user_id
            ));
            
            return true;
        }
        
        return false;
    }

    private function cancel_subscription($subscription) {
        $result = $this->api->delete_subscription($subscription->bna_subscription_id);
        
        if ($result) {
            $this->update_subscription_status($subscription->id, 'cancelled');
            
            bna_log('Subscription cancelled', array(
                'subscription_id' => $subscription->id,
                'bna_subscription_id' => $subscription->bna_subscription_id,
                'user_id' => $subscription->user_id
            ));
            
            return true;
        }
        
        return false;
    }

    private function update_subscription_status($subscription_id, $status) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bna_subscriptions';
        
        return $wpdb->update(
            $table_name,
            array('status' => $status, 'updated_at' => current_time('mysql')),
            array('id' => $subscription_id)
        );
    }

    public function get_status_label($status) {
        $labels = array(
            'active' => __('Active', 'bna-smart-payment'),
            'suspended' => __('Paused', 'bna-smart-payment'),
            'cancelled' => __('Cancelled', 'bna-smart-payment'),
            'expired' => __('Expired', 'bna-smart-payment'),
            'failed' => __('Failed', 'bna-smart-payment'),
            'pending' => __('Pending', 'bna-smart-payment')
        );

        return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
    }

    public function get_status_class($status) {
        $classes = array(
            'active' => 'bna-status-active',
            'suspended' => 'bna-status-suspended',
            'cancelled' => 'bna-status-cancelled',
            'expired' => 'bna-status-expired',
            'failed' => 'bna-status-failed',
            'pending' => 'bna-status-pending'
        );

        return isset($classes[$status]) ? $classes[$status] : 'bna-status-unknown';
    }

    public function get_frequency_label($frequency) {
        $labels = array(
            'daily' => __('Daily', 'bna-smart-payment'),
            'weekly' => __('Weekly', 'bna-smart-payment'),
            'monthly' => __('Monthly', 'bna-smart-payment'),
            'quarterly' => __('Every 3 months', 'bna-smart-payment'),
            'biannual' => __('Every 6 months', 'bna-smart-payment'),
            'annual' => __('Annually', 'bna-smart-payment')
        );

        return isset($labels[$frequency]) ? $labels[$frequency] : ucfirst($frequency);
    }

    public function format_next_payment_date($date) {
        if (!$date) {
            return __('N/A', 'bna-smart-payment');
        }

        $timestamp = strtotime($date);
        if ($timestamp < time()) {
            return __('Overdue', 'bna-smart-payment');
        }

        return date_i18n(get_option('date_format'), $timestamp);
    }

    public function get_subscription_actions($subscription) {
        $actions = array();

        switch ($subscription->status) {
            case 'active':
                $actions['suspend'] = array(
                    'label' => __('Pause', 'bna-smart-payment'),
                    'class' => 'button bna-suspend-subscription',
                    'confirm' => true
                );
                $actions['cancel'] = array(
                    'label' => __('Cancel', 'bna-smart-payment'),
                    'class' => 'button bna-cancel-subscription',
                    'confirm' => true
                );
                break;
                
            case 'suspended':
                $actions['resume'] = array(
                    'label' => __('Resume', 'bna-smart-payment'),
                    'class' => 'button bna-resume-subscription',
                    'confirm' => true
                );
                $actions['cancel'] = array(
                    'label' => __('Cancel', 'bna-smart-payment'),
                    'class' => 'button bna-cancel-subscription',
                    'confirm' => true
                );
                break;
        }

        return $actions;
    }
}


