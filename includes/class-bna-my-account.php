<?php

if (!defined('ABSPATH')) exit;

class BNA_My_Account {

    private static $instance = null;
    private $payment_methods;
    private $subscriptions;
    private $api;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->payment_methods = BNA_Payment_Methods::get_instance();
        $this->subscriptions = BNA_Subscriptions::get_instance();
        $this->api = new BNA_API();
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_rewrite_endpoint('payment-methods', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('subscriptions', EP_ROOT | EP_PAGES);

        add_filter('woocommerce_account_menu_items', array($this, 'add_payment_methods_tab'), 40);
        add_filter('woocommerce_account_menu_items', array($this, 'add_subscriptions_tab'), 41);

        add_action('woocommerce_account_payment-methods_endpoint', array($this, 'payment_methods_content'));
        add_action('woocommerce_account_subscriptions_endpoint', array($this, 'subscriptions_content'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_bna_delete_payment_method', array($this, 'handle_delete_payment_method'));

        add_action('wp_ajax_bna_suspend_subscription', array($this, 'handle_suspend_subscription'));
        add_action('wp_ajax_bna_resume_subscription', array($this, 'handle_resume_subscription'));
        add_action('wp_ajax_bna_cancel_subscription', array($this, 'handle_cancel_subscription'));
        add_action('wp_ajax_bna_delete_subscription', array($this, 'handle_delete_subscription'));
        add_action('wp_ajax_bna_resend_notification_subscription', array($this, 'handle_resend_notification'));
        add_action('wp_ajax_bna_reactivate_subscription', array($this, 'handle_reactivate_subscription'));
        add_action('wp_ajax_bna_get_subscription_details', array($this, 'handle_get_subscription_details'));

        add_filter('woocommerce_get_query_vars', array($this, 'add_query_vars'));

        bna_log('BNA My Account initialized', array(
            'endpoints_added' => true,
            'hooks_registered' => true,
            'subscriptions_enabled' => BNA_Subscriptions::is_enabled(),
            'ajax_handlers_added' => true
        ));
    }

    public function add_query_vars($vars) {
        $vars['payment-methods'] = 'payment-methods';
        $vars['subscriptions'] = 'subscriptions';
        return $vars;
    }

    public function add_payment_methods_tab($items) {
        $new_items = array();

        foreach ($items as $key => $item) {
            $new_items[$key] = $item;

            if ($key === 'edit-address') {
                $new_items['payment-methods'] = __('Payment Methods', 'bna-smart-payment');
            }
        }

        if (!isset($items['edit-address'])) {
            $new_items['payment-methods'] = __('Payment Methods', 'bna-smart-payment');
        }

        bna_debug('Payment methods tab added to My Account', array(
            'menu_items' => array_keys($new_items)
        ));

        return $new_items;
    }

    public function add_subscriptions_tab($items) {
        if (!BNA_Subscriptions::is_enabled()) {
            return $items;
        }

        $new_items = array();

        foreach ($items as $key => $item) {
            $new_items[$key] = $item;

            if ($key === 'payment-methods') {
                $new_items['subscriptions'] = __('My Subscriptions', 'bna-smart-payment');
            }
        }

        if (!isset($items['payment-methods'])) {
            foreach ($items as $key => $item) {
                $new_items[$key] = $item;
                if ($key === 'orders') {
                    $new_items['subscriptions'] = __('My Subscriptions', 'bna-smart-payment');
                }
            }
        }

        if (!isset($new_items['subscriptions'])) {
            $new_items['subscriptions'] = __('My Subscriptions', 'bna-smart-payment');
        }

        bna_debug('Subscriptions tab added to My Account', array(
            'menu_items' => array_keys($new_items),
            'subscriptions_enabled' => true
        ));

        return $new_items;
    }

    public function payment_methods_content() {
        if (!is_user_logged_in()) {
            bna_error('Unauthorized access to payment methods page');
            echo '<p>' . __('You must be logged in to view payment methods.', 'bna-smart-payment') . '</p>';
            return;
        }

        $user_id = get_current_user_id();
        $payment_methods = $this->payment_methods->get_user_payment_methods($user_id);

        bna_log('Loading payment methods page', array(
            'user_id' => $user_id,
            'methods_count' => count($payment_methods),
            'bna_customer_id' => get_user_meta($user_id, '_bna_customer_id', true)
        ));

        $template_file = BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/my-account-payment-methods.php';

        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="woocommerce-error">';
            echo '<p>' . __('Payment methods template not found.', 'bna-smart-payment') . '</p>';
            echo '</div>';

            bna_error('Payment methods template not found', array(
                'template_path' => $template_file
            ));
        }
    }

    /**
     * ВИПРАВЛЕНИЙ subscriptions_content метод з синхронізацією BNA API
     */
    public function subscriptions_content() {
        if (!is_user_logged_in()) {
            bna_error('Unauthorized access to subscriptions page');
            echo '<p>' . __('You must be logged in to view subscriptions.', 'bna-smart-payment') . '</p>';
            return;
        }

        if (!BNA_Subscriptions::is_enabled()) {
            echo '<div class="woocommerce-info">';
            echo '<p>' . __('Subscriptions are currently disabled.', 'bna-smart-payment') . '</p>';
            echo '</div>';
            return;
        }

        $user_id = get_current_user_id();
        $bna_customer_id = get_user_meta($user_id, '_bna_customer_id', true);

        // ВИПРАВЛЕННЯ: Синхронізація з BNA API перед показом
        if (!empty($bna_customer_id) && $this->api->has_credentials()) {
            $this->sync_user_subscriptions_with_api($user_id, $bna_customer_id);
        }

        $subscriptions = $this->get_user_subscriptions_with_api_sync($user_id);

        bna_log('Loading subscriptions page', array(
            'user_id' => $user_id,
            'subscriptions_count' => count($subscriptions),
            'bna_customer_id' => $bna_customer_id
        ));

        $template_file = BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/my-account-subscriptions.php';

        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="woocommerce-error">';
            echo '<p>' . __('Subscriptions template not found.', 'bna-smart-payment') . '</p>';
            echo '</div>';

            bna_error('Subscriptions template not found', array(
                'template_path' => $template_file,
                'user_id' => $user_id
            ));
        }
    }

    /**
     * НОВИЙ метод для синхронізації статусів підписок з BNA API
     */
    private function sync_user_subscriptions_with_api($user_id, $bna_customer_id) {
        try {
            $bna_subscriptions = $this->api->get_customer_subscriptions($bna_customer_id);
            if (is_wp_error($bna_subscriptions) || !is_array($bna_subscriptions)) {
                bna_debug('Could not sync subscriptions - API error', array(
                    'user_id' => $user_id,
                    'error' => is_wp_error($bna_subscriptions) ? $bna_subscriptions->get_error_message() : 'Invalid response'
                ));
                return;
            }

            $synced_count = 0;

            foreach ($bna_subscriptions as $bna_sub) {
                $bna_subscription_id = $bna_sub['id'] ?? '';
                $bna_status = strtolower($bna_sub['status'] ?? '');

                if (empty($bna_subscription_id) || empty($bna_status)) {
                    continue;
                }

                // Знаходимо локальне замовлення з цією підпискою
                $orders = wc_get_orders(array(
                    'customer_id' => $user_id,
                    'meta_key' => '_bna_subscription_id',
                    'meta_value' => $bna_subscription_id,
                    'limit' => 1,
                    'status' => 'any'
                ));

                if (!empty($orders)) {
                    $order = $orders[0];
                    $current_local_status = $order->get_meta('_bna_subscription_status') ?: 'new';

                    // Синхронізуємо тільки якщо статуси відрізняються
                    if ($current_local_status !== $bna_status) {
                        $order->update_meta_data('_bna_subscription_status', $bna_status);
                        $order->update_meta_data('_bna_last_api_sync', current_time('mysql'));
                        $order->add_order_note(sprintf(
                            __('Subscription status synced from BNA API: %s → %s', 'bna-smart-payment'),
                            $current_local_status,
                            $bna_status
                        ));
                        $order->save();
                        $synced_count++;

                        bna_log('Synced subscription status from API', array(
                            'order_id' => $order->get_id(),
                            'subscription_id' => $bna_subscription_id,
                            'old_status' => $current_local_status,
                            'new_status' => $bna_status,
                            'user_id' => $user_id
                        ));
                    }
                }
            }

            if ($synced_count > 0) {
                bna_log('Subscription sync completed', array(
                    'user_id' => $user_id,
                    'bna_customer_id' => $bna_customer_id,
                    'synced_count' => $synced_count,
                    'total_bna_subscriptions' => count($bna_subscriptions)
                ));
            }

        } catch (Exception $e) {
            bna_error('Failed to sync subscriptions with API', array(
                'user_id' => $user_id,
                'bna_customer_id' => $bna_customer_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
        }
    }

    /**
     * ВИПРАВЛЕНИЙ метод отримання підписок з API синхронізацією
     */
    private function get_user_subscriptions_with_api_sync($user_id) {
        $local_subscriptions = $this->subscriptions->get_user_subscriptions($user_id);

        $bna_customer_id = get_user_meta($user_id, '_bna_customer_id', true);

        if (!empty($bna_customer_id) && $this->api->has_credentials()) {
            try {
                $bna_subscriptions = $this->api->get_customer_subscriptions($bna_customer_id);

                if (!is_wp_error($bna_subscriptions) && is_array($bna_subscriptions)) {
                    $local_subscriptions = $this->merge_subscription_data($local_subscriptions, $bna_subscriptions, $user_id);

                    bna_debug('Subscriptions synced with BNA API', array(
                        'user_id' => $user_id,
                        'local_count' => count($local_subscriptions),
                        'bna_count' => count($bna_subscriptions)
                    ));
                }
            } catch (Exception $e) {
                bna_error('Failed to sync subscriptions with BNA API', array(
                    'user_id' => $user_id,
                    'error' => $e->getMessage()
                ));
            }
        }

        // Фільтруємо видалені підписки
        $local_subscriptions = array_filter($local_subscriptions, function($subscription) {
            $order = wc_get_order($subscription['order_id']);
            $status = $order ? ($order->get_meta('_bna_subscription_status') ?: 'new') : 'deleted';
            return $status !== 'deleted';
        });

        return $local_subscriptions;
    }

    /**
     * ВИПРАВЛЕНИЙ метод об'єднання даних підписок з оновленням локального статусу
     */
    private function merge_subscription_data($local_subscriptions, $bna_subscriptions, $user_id) {
        $merged = $local_subscriptions;

        foreach ($merged as $key => $local_sub) {
            $order = wc_get_order($local_sub['order_id']);
            if (!$order) {
                continue;
            }

            $bna_subscription_id = $order->get_meta('_bna_subscription_id');

            if (!empty($bna_subscription_id)) {
                foreach ($bna_subscriptions as $bna_sub) {
                    if ($bna_sub['id'] === $bna_subscription_id) {
                        $api_status = strtolower($bna_sub['status'] ?? '');
                        $local_status = $order->get_meta('_bna_subscription_status') ?: 'new';

                        // ВИПРАВЛЕННЯ: Оновлюємо локальний статус якщо він відрізняється від API
                        if ($local_status !== $api_status && !empty($api_status)) {
                            $order->update_meta_data('_bna_subscription_status', $api_status);
                            $order->update_meta_data('_bna_last_api_sync', current_time('mysql'));
                            $order->save();

                            bna_log('Updated local subscription status from API merge', array(
                                'order_id' => $order->get_id(),
                                'subscription_id' => $bna_subscription_id,
                                'old_status' => $local_status,
                                'new_status' => $api_status,
                                'user_id' => $user_id
                            ));

                            // Оновлюємо статус в merged array
                            $merged[$key]['status'] = $api_status;
                        }

                        $merged[$key]['bna_status'] = $api_status;
                        $merged[$key]['bna_next_payment'] = $bna_sub['nextPayment'] ?? null;
                        $merged[$key]['bna_last_payment'] = $bna_sub['lastPayment'] ?? null;
                        $merged[$key]['bna_subscription_id'] = $bna_sub['id'];
                        $merged[$key]['bna_data'] = $bna_sub;
                        break;
                    }
                }
            }
        }

        return $merged;
    }

    public function enqueue_scripts() {
        if (!is_account_page()) {
            return;
        }

        wp_enqueue_script(
            'bna-my-account-payment-methods',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/my-account-payment-methods.js',
            array('jquery'),
            BNA_SMART_PAYMENT_VERSION,
            true
        );

        wp_localize_script('bna-my-account-payment-methods', 'bna_my_account', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bna_delete_payment_method'),
            'messages' => array(
                'confirm_delete' => __('Are you sure you want to delete this payment method?', 'bna-smart-payment'),
                'deleting' => __('Deleting...', 'bna-smart-payment'),
                'error' => __('Error deleting payment method. Please try again.', 'bna-smart-payment'),
                'success' => __('Payment method deleted successfully.', 'bna-smart-payment')
            )
        ));

        if (BNA_Subscriptions::is_enabled()) {
            wp_enqueue_script(
                'bna-my-account-subscriptions',
                BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/my-account-subscriptions.js',
                array('jquery'),
                BNA_SMART_PAYMENT_VERSION,
                true
            );

            wp_localize_script('bna-my-account-subscriptions', 'bna_subscriptions', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bna_manage_subscription'),
                'messages' => array(
                    'confirm_suspend' => __('Are you sure you want to pause this subscription? You can resume it later.', 'bna-smart-payment'),
                    'confirm_cancel' => __('Are you sure you want to cancel this subscription permanently? This will stop all future payments and cannot be undone.', 'bna-smart-payment'),
                    'confirm_delete' => __('Are you sure you want to permanently delete this subscription record? This action cannot be undone.', 'bna-smart-payment'),
                    'confirm_resume' => __('Are you sure you want to resume this subscription?', 'bna-smart-payment'),
                    'confirm_reactivate' => __('Are you sure you want to reactivate this subscription?', 'bna-smart-payment'),
                    'confirm_resend_notification' => __('Resend notification for this subscription?', 'bna-smart-payment'),
                    'processing' => __('Processing...', 'bna-smart-payment'),
                    'error' => __('Error processing request. Please try again.', 'bna-smart-payment'),
                    'success_suspend' => __('Subscription paused successfully.', 'bna-smart-payment'),
                    'success_resume' => __('Subscription resumed successfully.', 'bna-smart-payment'),
                    'success_cancel' => __('Subscription cancelled successfully.', 'bna-smart-payment'),
                    'success_delete' => __('Subscription deleted permanently.', 'bna-smart-payment'),
                    'success_reactivate' => __('Subscription reactivated successfully.', 'bna-smart-payment'),
                    'success_resend_notification' => __('Notification sent successfully.', 'bna-smart-payment')
                )
            ));
        }

        bna_debug('My Account scripts enqueued', array(
            'page' => 'account',
            'payment_methods_script' => true,
            'subscriptions_script' => BNA_Subscriptions::is_enabled()
        ));
    }

    public function handle_suspend_subscription() {
        try {
            $this->verify_subscription_ajax_request();

            $order_id = intval($_POST['order_id'] ?? 0);
            if (!$order_id) {
                wp_send_json_error(__('Invalid order ID.', 'bna-smart-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                wp_send_json_error(__('Invalid subscription.', 'bna-smart-payment'));
            }

            $subscription_id = $order->get_meta('_bna_subscription_id');
            if (empty($subscription_id)) {
                wp_send_json_error(__('Subscription ID not found.', 'bna-smart-payment'));
            }

            $current_status = $order->get_meta('_bna_subscription_status') ?: 'new';

            if (!in_array($current_status, array('new', 'active'))) {
                wp_send_json_error(__('Only active subscriptions can be paused.', 'bna-smart-payment'));
            }

            bna_log('Suspending subscription via My Account (PAUSE)', array(
                'user_id' => get_current_user_id(),
                'order_id' => $order_id,
                'subscription_id' => $subscription_id,
                'current_status' => $current_status
            ));

            $result = $this->api->update_subscription_status($subscription_id, 'suspended');

            if (is_wp_error($result)) {
                bna_error('Failed to suspend subscription', array(
                    'subscription_id' => $subscription_id,
                    'error' => $result->get_error_message()
                ));
                wp_send_json_error($result->get_error_message());
            }

            $order->update_meta_data('_bna_subscription_status', 'suspended');
            $order->update_meta_data('_bna_subscription_last_action', 'suspended_by_customer');
            $order->update_meta_data('_bna_subscription_suspended_date', current_time('mysql'));
            $order->update_status('on-hold', __('Subscription paused by customer.', 'bna-smart-payment'));
            $order->save();

            bna_log('Subscription suspended successfully', array(
                'subscription_id' => $subscription_id,
                'new_status' => 'suspended'
            ));

            wp_send_json_success(array(
                'message' => __('Subscription paused successfully.', 'bna-smart-payment'),
                'new_status' => 'suspended'
            ));

        } catch (Exception $e) {
            bna_error('Exception in handle_suspend_subscription', array(
                'order_id' => $_POST['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error(__('An error occurred while pausing the subscription. Please try again.', 'bna-smart-payment'));
        }
    }

    public function handle_resume_subscription() {
        try {
            $this->verify_subscription_ajax_request();

            $order_id = intval($_POST['order_id'] ?? 0);
            if (!$order_id) {
                wp_send_json_error(__('Invalid order ID.', 'bna-smart-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                wp_send_json_error(__('Invalid subscription.', 'bna-smart-payment'));
            }

            $subscription_id = $order->get_meta('_bna_subscription_id');
            if (empty($subscription_id)) {
                wp_send_json_error(__('Subscription ID not found.', 'bna-smart-payment'));
            }

            $current_status = $order->get_meta('_bna_subscription_status') ?: 'new';

            if ($current_status !== 'suspended') {
                wp_send_json_error(__('Only paused subscriptions can be resumed.', 'bna-smart-payment'));
            }

            bna_log('Resuming subscription via My Account', array(
                'user_id' => get_current_user_id(),
                'order_id' => $order_id,
                'subscription_id' => $subscription_id,
                'current_status' => $current_status
            ));

            $result = $this->api->update_subscription_status($subscription_id, 'active');

            if (is_wp_error($result)) {
                bna_error('Failed to resume subscription', array(
                    'subscription_id' => $subscription_id,
                    'error' => $result->get_error_message()
                ));
                wp_send_json_error($result->get_error_message());
            }

            $order->update_meta_data('_bna_subscription_status', 'active');
            $order->update_meta_data('_bna_subscription_last_action', 'resumed_by_customer');
            $order->delete_meta_data('_bna_subscription_suspended_date');
            $order->update_status('processing', __('Subscription resumed by customer.', 'bna-smart-payment'));
            $order->save();

            bna_log('Subscription resumed successfully', array(
                'subscription_id' => $subscription_id,
                'new_status' => 'active'
            ));

            wp_send_json_success(array(
                'message' => __('Subscription resumed successfully.', 'bna-smart-payment'),
                'new_status' => 'active'
            ));

        } catch (Exception $e) {
            bna_error('Exception in handle_resume_subscription', array(
                'order_id' => $_POST['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error(__('An error occurred while resuming the subscription. Please try again.', 'bna-smart-payment'));
        }
    }

    public function handle_cancel_subscription() {
        try {
            $this->verify_subscription_ajax_request();

            $order_id = intval($_POST['order_id'] ?? 0);
            if (!$order_id) {
                wp_send_json_error(__('Invalid order ID.', 'bna-smart-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                wp_send_json_error(__('Invalid subscription.', 'bna-smart-payment'));
            }

            $subscription_id = $order->get_meta('_bna_subscription_id');
            if (empty($subscription_id)) {
                wp_send_json_error(__('Subscription ID not found.', 'bna-smart-payment'));
            }

            $current_status = $order->get_meta('_bna_subscription_status') ?: 'new';

            if (!in_array($current_status, array('new', 'active', 'suspended'))) {
                wp_send_json_error(__('This subscription cannot be cancelled.', 'bna-smart-payment'));
            }

            bna_log('Cancelling subscription via My Account (PERMANENT CANCEL)', array(
                'user_id' => get_current_user_id(),
                'order_id' => $order_id,
                'subscription_id' => $subscription_id,
                'current_status' => $current_status
            ));

            $result = $this->api->delete_subscription($subscription_id);

            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();

                if (strpos($error_message, '404') !== false || strpos(strtolower($error_message), 'not found') !== false) {
                    bna_log('Subscription already cancelled/deleted from BNA API', array(
                        'subscription_id' => $subscription_id,
                        'order_id' => $order_id
                    ));
                } else {
                    bna_error('Failed to cancel subscription via API', array(
                        'subscription_id' => $subscription_id,
                        'current_status' => $current_status,
                        'error' => $error_message
                    ));
                    wp_send_json_error(sprintf(
                        __('Failed to cancel subscription: %s', 'bna-smart-payment'),
                        $error_message
                    ));
                }
            } else {
                bna_log('Subscription cancelled successfully via API', array(
                    'subscription_id' => $subscription_id
                ));
            }

            $order->update_meta_data('_bna_subscription_status', 'cancelled');
            $order->update_meta_data('_bna_subscription_last_action', 'cancelled_by_customer');
            $order->update_meta_data('_bna_subscription_cancelled_date', current_time('mysql'));
            $order->update_status('cancelled', __('Subscription cancelled by customer.', 'bna-smart-payment'));
            $order->save();

            bna_log('Subscription status updated locally', array(
                'order_id' => $order_id,
                'old_status' => $current_status,
                'new_status' => 'cancelled'
            ));

            wp_send_json_success(array(
                'message' => __('Subscription cancelled successfully.', 'bna-smart-payment'),
                'new_status' => 'cancelled'
            ));

        } catch (Exception $e) {
            bna_error('Exception in handle_cancel_subscription', array(
                'order_id' => $_POST['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error(__('An error occurred while cancelling the subscription. Please try again.', 'bna-smart-payment'));
        }
    }

    public function handle_delete_subscription() {
        try {
            $this->verify_subscription_ajax_request();

            $order_id = intval($_POST['order_id'] ?? 0);
            $subscription_id = sanitize_text_field($_POST['subscription_id'] ?? '');

            if (!$order_id) {
                wp_send_json_error(__('Invalid order ID.', 'bna-smart-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                wp_send_json_error(__('Invalid subscription.', 'bna-smart-payment'));
            }

            if (empty($subscription_id)) {
                $subscription_id = $order->get_meta('_bna_subscription_id');
            }

            $current_status = $order->get_meta('_bna_subscription_status') ?: 'new';

            if ($current_status !== 'cancelled') {
                wp_send_json_error(__('Only cancelled subscriptions can be deleted permanently. Please cancel the subscription first.', 'bna-smart-payment'));
            }

            bna_log('Deleting subscription record permanently via My Account (DELETE RECORD)', array(
                'user_id' => get_current_user_id(),
                'order_id' => $order_id,
                'subscription_id' => $subscription_id,
                'current_status' => $current_status
            ));

            $order->update_meta_data('_bna_subscription_status', 'deleted');
            $order->update_meta_data('_bna_subscription_last_action', 'deleted_by_customer');
            $order->update_meta_data('_bna_subscription_deleted_date', current_time('mysql'));
            $order->add_order_note(__('Subscription record deleted permanently by customer.', 'bna-smart-payment'));
            $order->save();

            bna_log('Subscription marked as deleted locally', array(
                'order_id' => $order_id,
                'user_id' => get_current_user_id()
            ));

            wp_send_json_success(array(
                'message' => __('Subscription deleted permanently.', 'bna-smart-payment'),
                'new_status' => 'deleted'
            ));

        } catch (Exception $e) {
            bna_error('Exception in handle_delete_subscription', array(
                'order_id' => $_POST['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ));
            wp_send_json_error(__('An error occurred while deleting the subscription. Please try again.', 'bna-smart-payment'));
        }
    }

    public function handle_resend_notification() {
        try {
            $this->verify_subscription_ajax_request();

            $order_id = intval($_POST['order_id'] ?? 0);
            $subscription_id = sanitize_text_field($_POST['subscription_id'] ?? '');

            if (!$order_id) {
                wp_send_json_error(__('Invalid order ID.', 'bna-smart-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                wp_send_json_error(__('Invalid subscription.', 'bna-smart-payment'));
            }

            if (empty($subscription_id)) {
                $subscription_id = $order->get_meta('_bna_subscription_id');
                if (empty($subscription_id)) {
                    wp_send_json_error(__('Subscription ID not found.', 'bna-smart-payment'));
                }
            }

            $current_status = $order->get_meta('_bna_subscription_status') ?: 'new';

            if (in_array($current_status, ['deleted', 'cancelled'])) {
                wp_send_json_error(__('Cannot send notifications for cancelled or deleted subscriptions.', 'bna-smart-payment'));
            }

            bna_log('Resending subscription notification via My Account', array(
                'user_id' => get_current_user_id(),
                'order_id' => $order_id,
                'subscription_id' => $subscription_id,
                'current_status' => $current_status
            ));

            $result = $this->api->resend_subscription_notification($subscription_id);

            if (is_wp_error($result)) {
                bna_error('Failed to resend subscription notification', array(
                    'subscription_id' => $subscription_id,
                    'error' => $result->get_error_message(),
                    'current_status' => $current_status
                ));

                $error_message = $result->get_error_message();
                if (strpos($error_message, '500') !== false) {
                    $error_message = 'Unable to send notification. The subscription may no longer be active in the payment system.';
                } elseif (strpos($error_message, '404') !== false) {
                    $error_message = 'Subscription not found in the payment system.';
                } elseif (strpos($error_message, '403') !== false) {
                    $error_message = 'Permission denied. Please contact support.';
                }

                wp_send_json_error($error_message);
            }

            $order->update_meta_data('_bna_subscription_last_notification', current_time('mysql'));
            $order->add_order_note(__('Subscription notification resent by customer.', 'bna-smart-payment'));
            $order->save();

            wp_send_json_success(array(
                'message' => __('Notification sent successfully.', 'bna-smart-payment')
            ));

        } catch (Exception $e) {
            bna_error('Exception in handle_resend_notification', array(
                'order_id' => $_POST['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error(__('An error occurred while resending notification. Please try again.', 'bna-smart-payment'));
        }
    }

    public function handle_get_subscription_details() {
        try {
            $this->verify_subscription_ajax_request();

            $order_id = intval($_POST['order_id'] ?? 0);
            if (!$order_id) {
                wp_send_json_error(__('Invalid order ID.', 'bna-smart-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                wp_send_json_error(__('Invalid subscription.', 'bna-smart-payment'));
            }

            $subscription_id = $order->get_meta('_bna_subscription_id');
            $subscription_status = $order->get_meta('_bna_subscription_status') ?: 'new';

            bna_log('Loading subscription details', array(
                'order_id' => $order_id,
                'subscription_id' => $subscription_id,
                'local_status' => $subscription_status
            ));

            $details = array(
                'order_id' => $order_id,
                'subscription_id' => $subscription_id,
                'status' => $subscription_status,
                'total' => $order->get_formatted_order_total(),
                'created_date' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : '',
                'items' => array(),
                'bna_details' => null
            );

            try {
                foreach ($order->get_items() as $item) {
                    if (!$item) continue;

                    $item_data = array(
                        'name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total' => wc_price($item->get_total()),
                        'frequency' => 'monthly',
                        'trial_days' => 0,
                        'signup_fee' => 0
                    );

                    try {
                        $product = $item->get_product();
                        if ($product && BNA_Subscriptions::is_subscription_product($product)) {
                            $subscription_data = BNA_Subscriptions::get_subscription_data($product);
                            $item_data['frequency'] = $subscription_data['frequency'] ?? 'monthly';
                            $item_data['trial_days'] = $subscription_data['trial_days'] ?? 0;
                            $item_data['signup_fee'] = $subscription_data['signup_fee'] ?? 0;
                        }
                    } catch (Exception $product_e) {
                        bna_debug('Failed to get subscription data for item', array(
                            'item_id' => $item->get_id(),
                            'error' => $product_e->getMessage()
                        ));
                    }

                    $details['items'][] = $item_data;
                }
            } catch (Exception $items_e) {
                bna_error('Failed to process order items for details', array(
                    'order_id' => $order_id,
                    'error' => $items_e->getMessage()
                ));
            }

            if (!empty($subscription_id) && $this->api->has_credentials() && $subscription_id !== $order_id) {
                try {
                    $api_result = $this->api->get_subscription($subscription_id);
                    if (!is_wp_error($api_result)) {
                        $details['bna_details'] = $api_result;
                        bna_debug('BNA API details loaded for subscription', array(
                            'subscription_id' => $subscription_id,
                            'api_status' => $api_result['status'] ?? 'unknown'
                        ));
                    }
                } catch (Exception $api_e) {
                    bna_debug('Could not load BNA subscription details', array(
                        'subscription_id' => $subscription_id,
                        'error' => $api_e->getMessage()
                    ));
                }
            }

            bna_log('Subscription details loaded successfully', array(
                'order_id' => $order_id,
                'items_count' => count($details['items']),
                'has_bna_details' => !empty($details['bna_details'])
            ));

            wp_send_json_success($details);

        } catch (Exception $e) {
            bna_error('Exception in handle_get_subscription_details', array(
                'order_id' => $_POST['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine()
            ));
            wp_send_json_error(__('An error occurred while loading subscription details. Please try again.', 'bna-smart-payment'));
        }
    }

    public function handle_reactivate_subscription() {
        try {
            $this->verify_subscription_ajax_request();

            $order_id = intval($_POST['order_id'] ?? 0);
            if (!$order_id) {
                wp_send_json_error(__('Invalid order ID.', 'bna-smart-payment'));
            }

            $order = wc_get_order($order_id);
            if (!$order || $order->get_customer_id() !== get_current_user_id()) {
                wp_send_json_error(__('Invalid subscription.', 'bna-smart-payment'));
            }

            $current_status = $order->get_meta('_bna_subscription_status') ?: 'new';

            if (!in_array($current_status, array('failed', 'expired'))) {
                wp_send_json_error(__('Only failed or expired subscriptions can be reactivated.', 'bna-smart-payment'));
            }

            bna_log('Reactivating subscription via My Account', array(
                'user_id' => get_current_user_id(),
                'order_id' => $order_id,
                'current_status' => $current_status
            ));

            $order->update_meta_data('_bna_subscription_status', 'active');
            $order->update_meta_data('_bna_subscription_last_action', 'reactivated_by_customer');
            $order->update_status('processing', __('Subscription reactivated by customer - pending payment setup.', 'bna-smart-payment'));
            $order->save();

            wp_send_json_success(array(
                'message' => __('Subscription reactivation initiated. You may need to update your payment method.', 'bna-smart-payment'),
                'new_status' => 'active'
            ));

        } catch (Exception $e) {
            bna_error('Exception in handle_reactivate_subscription', array(
                'order_id' => $_POST['order_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error(__('An error occurred while reactivating the subscription. Please try again.', 'bna-smart-payment'));
        }
    }

    private function verify_subscription_ajax_request() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bna_manage_subscription')) {
                bna_error('AJAX nonce verification failed', array(
                    'nonce_provided' => isset($_POST['nonce']),
                    'action' => $_POST['action'] ?? 'unknown',
                    'user_id' => get_current_user_id()
                ));
                wp_send_json_error(__('Security check failed.', 'bna-smart-payment'));
            }

            if (!is_user_logged_in()) {
                bna_error('AJAX request from non-logged user', array(
                    'action' => $_POST['action'] ?? 'unknown',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ));
                wp_send_json_error(__('You must be logged in.', 'bna-smart-payment'));
            }

            if (!BNA_Subscriptions::is_enabled()) {
                bna_error('AJAX request but subscriptions disabled', array(
                    'action' => $_POST['action'] ?? 'unknown',
                    'user_id' => get_current_user_id()
                ));
                wp_send_json_error(__('Subscriptions are not enabled.', 'bna-smart-payment'));
            }

            bna_debug('AJAX subscription request verified', array(
                'action' => $_POST['action'] ?? 'unknown',
                'user_id' => get_current_user_id(),
                'order_id' => $_POST['order_id'] ?? 'not_provided'
            ));

        } catch (Exception $e) {
            bna_error('Exception in verify_subscription_ajax_request', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'action' => $_POST['action'] ?? 'unknown'
            ));
            wp_send_json_error(__('Security verification failed.', 'bna-smart-payment'));
        }
    }

    public function handle_delete_payment_method() {
        try {
            check_ajax_referer('bna_delete_payment_method', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error('Not logged in');
            }

            $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
            if (empty($payment_method_id)) {
                wp_send_json_error('Invalid payment method ID');
            }

            $user_id = get_current_user_id();

            bna_log('Processing payment method deletion request', array(
                'user_id' => $user_id,
                'payment_method_id' => $payment_method_id
            ));

            $result = $this->payment_methods->delete_payment_method($user_id, $payment_method_id);

            if (is_wp_error($result)) {
                bna_error('Payment method deletion failed', array(
                    'user_id' => $user_id,
                    'payment_method_id' => $payment_method_id,
                    'error' => $result->get_error_message()
                ));

                wp_send_json_error($result->get_error_message());
            }

            if (is_array($result)) {
                $status = $result['status'] ?? 'unknown';
                $message = $result['message'] ?? 'Unknown status';

                if ($status === 'success') {
                    bna_log('Payment method deleted successfully via My Account', array(
                        'user_id' => $user_id,
                        'payment_method_id' => $payment_method_id
                    ));

                    wp_send_json_success($message);
                } else {
                    bna_error('Payment method deletion not completed', array(
                        'user_id' => $user_id,
                        'payment_method_id' => $payment_method_id,
                        'status' => $status,
                        'message' => $message
                    ));

                    wp_send_json_error($message);
                }
            }

            wp_send_json_error('Unexpected response format');

        } catch (Exception $e) {
            bna_error('Exception in handle_delete_payment_method', array(
                'user_id' => get_current_user_id(),
                'payment_method_id' => $_POST['payment_method_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            wp_send_json_error(__('An error occurred while deleting the payment method. Please try again.', 'bna-smart-payment'));
        }
    }

    public function get_payment_method_display_name($method) {
        $type = strtolower($method['type'] ?? 'unknown');
        $last4 = $method['last4'] ?? '****';
        $brand = ucfirst(strtolower($method['brand'] ?? 'Unknown'));

        switch ($type) {
            case 'credit':
                return sprintf('%s Credit Card **** %s', $brand, $last4);

            case 'debit':
                return sprintf('%s Debit Card **** %s', $brand, $last4);

            case 'eft':
                return sprintf('Bank Transfer **** %s', $last4);

            case 'e_transfer':
                return __('E-Transfer', 'bna-smart-payment');

            default:
                if ($brand !== 'Unknown') {
                    return sprintf('%s **** %s', $brand, $last4);
                }
                return sprintf('Payment Method **** %s', $last4);
        }
    }

    public function get_payment_method_icon($method) {
        $type = strtolower($method['type'] ?? 'unknown');
        $brand = strtolower($method['brand'] ?? 'unknown');

        $icon_map = array(
            'visa' => '💳',
            'mastercard' => '💳',
            'amex' => '💳',
            'american express' => '💳',
            'discover' => '💳',
            'jcb' => '💳',
            'diners' => '💳',
            'credit' => '💳',
            'debit' => '💳',
            'eft' => '🏦',
            'e_transfer' => '✉️',
            'cheque' => '📝',
            'cash' => '💵'
        );

        if (isset($icon_map[$brand])) {
            return $icon_map[$brand];
        }

        if (isset($icon_map[$type])) {
            return $icon_map[$type];
        }

        return '💳';
    }

    public static function get_subscription_status_color($status) {
        $colors = array(
            'new' => '#6c757d',
            'active' => '#28a745',
            'suspended' => '#ffc107',
            'cancelled' => '#dc3545',
            'expired' => '#6f42c1',
            'failed' => '#fd7e14',
            'deleted' => '#343a40'
        );
        return isset($colors[$status]) ? $colors[$status] : '#6c757d';
    }

    public static function get_subscription_status_label($status) {
        $labels = array(
            'new' => __('New', 'bna-smart-payment'),
            'active' => __('Active', 'bna-smart-payment'),
            'suspended' => __('Paused', 'bna-smart-payment'),
            'cancelled' => __('Cancelled', 'bna-smart-payment'),
            'expired' => __('Expired', 'bna-smart-payment'),
            'failed' => __('Failed', 'bna-smart-payment'),
            'deleted' => __('Deleted', 'bna-smart-payment')
        );
        return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
    }

    public static function is_subscription_action_allowed($status, $action) {
        $allowed_actions = array(
            'active' => array('suspend', 'cancel', 'view'),
            'suspended' => array('resume', 'cancel', 'view'),
            'new' => array('suspend', 'cancel', 'view'),
            'cancelled' => array('view', 'delete'),
            'expired' => array('view', 'reactivate'),
            'failed' => array('view', 'reactivate'),
            'deleted' => array('view')
        );

        return isset($allowed_actions[$status]) && in_array($action, $allowed_actions[$status]);
    }

    public static function format_subscription_frequency($frequency) {
        $frequency_labels = array(
            'daily' => __('Daily', 'bna-smart-payment'),
            'weekly' => __('Weekly', 'bna-smart-payment'),
            'biweekly' => __('Every 2 weeks', 'bna-smart-payment'),
            'monthly' => __('Monthly', 'bna-smart-payment'),
            'quarterly' => __('Quarterly', 'bna-smart-payment'),
            'biannual' => __('Every 6 months', 'bna-smart-payment'),
            'annual' => __('Annually', 'bna-smart-payment')
        );

        return isset($frequency_labels[$frequency]) ? $frequency_labels[$frequency] : ucfirst($frequency);
    }

    public static function estimate_next_payment_date($frequency, $last_payment_date = null) {
        $base_date = $last_payment_date ? new DateTime($last_payment_date) : new DateTime();

        $intervals = array(
            'daily' => '+1 day',
            'weekly' => '+1 week',
            'biweekly' => '+2 weeks',
            'monthly' => '+1 month',
            'quarterly' => '+3 months',
            'biannual' => '+6 months',
            'annual' => '+1 year'
        );

        if (isset($intervals[$frequency])) {
            $next_date = clone $base_date;
            $next_date->modify($intervals[$frequency]);
            return $next_date->format('Y-m-d');
        }

        return null;
    }
}