<?php
/**
 * BNA Admin Interface
 * Handles admin pages and functionality
 *
 * @since 1.9.0 Added subscription management
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Admin {

    private static $api;
    private static $subscriptions;

    /**
     * Initialize admin functionality
     */
    public static function init() {
        self::$api = new BNA_API();
        self::$subscriptions = BNA_Subscriptions::get_instance();

        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'handle_actions'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));

        // AJAX handlers for subscription management (v1.9.0)
        add_action('wp_ajax_bna_admin_manage_subscription', array(__CLASS__, 'handle_subscription_action'));
        add_action('wp_ajax_bna_admin_sync_subscriptions', array(__CLASS__, 'handle_sync_subscriptions'));
        add_action('wp_ajax_bna_admin_bulk_subscription_action', array(__CLASS__, 'handle_bulk_subscription_action'));

        // Add subscription columns to orders list
        add_filter('manage_edit-shop_order_columns', array(__CLASS__, 'add_order_subscription_column'));
        add_action('manage_shop_order_posts_custom_column', array(__CLASS__, 'display_order_subscription_column'), 10, 2);
    }

    /**
     * Add admin menu under WooCommerce
     */
    public static function add_menu() {
        // Logs page
        add_submenu_page(
            'woocommerce',
            'BNA Payment Logs',
            'BNA Logs',
            'manage_woocommerce',
            'bna-logs',
            array(__CLASS__, 'logs_page')
        );

        // Subscriptions page (v1.9.0)
        if (BNA_Subscriptions::is_enabled()) {
            add_submenu_page(
                'woocommerce',
                'BNA Subscriptions',
                'BNA Subscriptions',
                'manage_woocommerce',
                'bna-subscriptions',
                array(__CLASS__, 'subscriptions_page')
            );
        }

        // Settings shortcut
        add_submenu_page(
            'woocommerce',
            'BNA Payment Settings',
            'BNA Settings',
            'manage_woocommerce',
            'wc-settings&tab=checkout&section=bna_smart_payment',
            null
        );
    }

    /**
     * Enqueue admin scripts
     */
    public static function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'bna-') === false) {
            return;
        }

        wp_enqueue_script(
            'bna-admin',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            BNA_SMART_PAYMENT_VERSION,
            true
        );

        wp_enqueue_style(
            'bna-admin',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BNA_SMART_PAYMENT_VERSION
        );

        // Localize script for subscriptions page
        if (strpos($hook, 'bna-subscriptions') !== false) {
            wp_localize_script('bna-admin', 'bna_admin_subscriptions', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bna_admin_subscription_action'),
                'messages' => array(
                    'confirm_suspend' => __('Are you sure you want to suspend this subscription?', 'bna-smart-payment'),
                    'confirm_resume' => __('Are you sure you want to resume this subscription?', 'bna-smart-payment'),
                    'confirm_cancel' => __('Are you sure you want to cancel this subscription?', 'bna-smart-payment'),
                    'confirm_delete' => __('Are you sure you want to delete this subscription?', 'bna-smart-payment'),
                    'confirm_sync' => __('This will sync all subscriptions with BNA API. Continue?', 'bna-smart-payment'),
                    'processing' => __('Processing...', 'bna-smart-payment'),
                    'error' => __('Error processing request. Please try again.', 'bna-smart-payment'),
                    'success_suspend' => __('Subscription suspended successfully.', 'bna-smart-payment'),
                    'success_resume' => __('Subscription resumed successfully.', 'bna-smart-payment'),
                    'success_cancel' => __('Subscription cancelled successfully.', 'bna-smart-payment'),
                    'success_delete' => __('Subscription deleted successfully.', 'bna-smart-payment'),
                    'success_sync' => __('Subscriptions synced successfully.', 'bna-smart-payment')
                )
            ));
        }
    }

    /**
     * Handle admin actions
     */
    public static function handle_actions() {
        if (!isset($_GET['bna_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        $action = sanitize_text_field($_GET['bna_action']);
        $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'bna_admin_action')) {
            wp_die(__('Security check failed.', 'bna-smart-payment'));
        }

        switch ($action) {
            case 'clear_logs':
                BNA_Logger::clear();
                wp_redirect(admin_url('admin.php?page=bna-logs&message=logs_cleared'));
                exit;

            case 'download_logs':
                self::download_logs();
                exit;

            case 'sync_all_subscriptions':
                self::sync_all_subscriptions();
                wp_redirect(admin_url('admin.php?page=bna-subscriptions&message=sync_completed'));
                exit;

            case 'export_subscriptions':
                self::export_subscriptions();
                exit;
        }
    }

    // ==========================================
    // SUBSCRIPTION MANAGEMENT (v1.9.0)
    // ==========================================

    /**
     * Display subscriptions management page
     */
    public static function subscriptions_page() {
        if (!BNA_Subscriptions::is_enabled()) {
            echo '<div class="wrap"><h1>BNA Subscriptions</h1>';
            echo '<div class="notice notice-warning"><p>' . __('Subscriptions are not enabled.', 'bna-smart-payment') . '</p></div>';
            echo '</div>';
            return;
        }

        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $status_filter = sanitize_text_field($_GET['status'] ?? 'all');
        $search = sanitize_text_field($_GET['s'] ?? '');
        $per_page = 20;

        // Get subscriptions data
        $subscriptions_data = self::get_admin_subscriptions($page, $per_page, $status_filter, $search);
        $subscriptions = $subscriptions_data['subscriptions'];
        $total_subscriptions = $subscriptions_data['total'];
        $total_pages = ceil($total_subscriptions / $per_page);

        // Get statistics
        $stats = self::get_subscription_stats();

        $message = sanitize_text_field($_GET['message'] ?? '');

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">BNA Subscriptions</h1>

            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo self::get_admin_message($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="bna-admin-stats" style="display: flex; gap: 20px; margin: 20px 0;">
                <div class="bna-stat-card" style="background: white; padding: 20px; border-radius: 5px; border-left: 4px solid #28a745; min-width: 150px;">
                    <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo $stats['active']; ?></div>
                    <div><?php _e('Active', 'bna-smart-payment'); ?></div>
                </div>
                <div class="bna-stat-card" style="background: white; padding: 20px; border-radius: 5px; border-left: 4px solid #ffc107; min-width: 150px;">
                    <div style="font-size: 24px; font-weight: bold; color: #ffc107;"><?php echo $stats['suspended']; ?></div>
                    <div><?php _e('Suspended', 'bna-smart-payment'); ?></div>
                </div>
                <div class="bna-stat-card" style="background: white; padding: 20px; border-radius: 5px; border-left: 4px solid #dc3545; min-width: 150px;">
                    <div style="font-size: 24px; font-weight: bold; color: #dc3545;"><?php echo $stats['cancelled']; ?></div>
                    <div><?php _e('Cancelled', 'bna-smart-payment'); ?></div>
                </div>
                <div class="bna-stat-card" style="background: white; padding: 20px; border-radius: 5px; border-left: 4px solid #6c757d; min-width: 150px;">
                    <div style="font-size: 24px; font-weight: bold; color: #6c757d;"><?php echo $stats['total']; ?></div>
                    <div><?php _e('Total', 'bna-smart-payment'); ?></div>
                </div>
            </div>

            <!-- Filters and Search -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="bna-subscriptions">

                        <select name="status" id="subscription-status-filter">
                            <option value="all"><?php _e('All Statuses', 'bna-smart-payment'); ?></option>
                            <option value="active" <?php selected($status_filter, 'active'); ?>><?php _e('Active', 'bna-smart-payment'); ?></option>
                            <option value="suspended" <?php selected($status_filter, 'suspended'); ?>><?php _e('Suspended', 'bna-smart-payment'); ?></option>
                            <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>><?php _e('Cancelled', 'bna-smart-payment'); ?></option>
                            <option value="expired" <?php selected($status_filter, 'expired'); ?>><?php _e('Expired', 'bna-smart-payment'); ?></option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('Failed', 'bna-smart-payment'); ?></option>
                        </select>

                        <?php submit_button(__('Filter', 'bna-smart-payment'), 'action', 'filter_action', false); ?>
                    </form>
                </div>

                <div class="alignright actions">
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=bna-subscriptions&bna_action=sync_all_subscriptions'), 'bna_admin_action'); ?>"
                       class="button" id="sync-subscriptions">
                        <?php _e('Sync with BNA API', 'bna-smart-payment'); ?>
                    </a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=bna-subscriptions&bna_action=export_subscriptions'), 'bna_admin_action'); ?>"
                       class="button">
                        <?php _e('Export CSV', 'bna-smart-payment'); ?>
                    </a>
                </div>

                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => $total_pages,
                        'current' => $page
                    ));
                    ?>
                </div>
            </div>

            <!-- Subscriptions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1">
                    </th>
                    <th scope="col"><?php _e('Order', 'bna-smart-payment'); ?></th>
                    <th scope="col"><?php _e('Customer', 'bna-smart-payment'); ?></th>
                    <th scope="col"><?php _e('Status', 'bna-smart-payment'); ?></th>
                    <th scope="col"><?php _e('Frequency', 'bna-smart-payment'); ?></th>
                    <th scope="col"><?php _e('Total', 'bna-smart-payment'); ?></th>
                    <th scope="col"><?php _e('Created', 'bna-smart-payment'); ?></th>
                    <th scope="col"><?php _e('Next Payment', 'bna-smart-payment'); ?></th>
                    <th scope="col"><?php _e('Actions', 'bna-smart-payment'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($subscriptions)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px;">
                            <?php _e('No subscriptions found.', 'bna-smart-payment'); ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subscriptions as $subscription): ?>
                        <?php self::render_subscription_row($subscription); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Bulk Actions -->
            <div class="tablenav bottom">
                <div class="alignleft actions">
                    <select name="bulk_action" id="bulk-action-selector">
                        <option value=""><?php _e('Bulk Actions', 'bna-smart-payment'); ?></option>
                        <option value="suspend"><?php _e('Suspend', 'bna-smart-payment'); ?></option>
                        <option value="resume"><?php _e('Resume', 'bna-smart-payment'); ?></option>
                        <option value="cancel"><?php _e('Cancel', 'bna-smart-payment'); ?></option>
                    </select>
                    <button type="button" class="button" id="bulk-apply-btn"><?php _e('Apply', 'bna-smart-payment'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render individual subscription row
     */
    private static function render_subscription_row($subscription) {
        $order = wc_get_order($subscription['order_id']);
        $customer = $order ? $order->get_user() : null;
        $status = $subscription['status'] ?? 'new';
        $status_color = BNA_My_Account::get_subscription_status_color($status);

        ?>
        <tr data-order-id="<?php echo esc_attr($subscription['order_id']); ?>">
            <td class="check-column">
                <input type="checkbox" name="subscription[]" value="<?php echo esc_attr($subscription['order_id']); ?>">
            </td>
            <td>
                <strong>
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $subscription['order_id'] . '&action=edit')); ?>">
                        #<?php echo $subscription['order_id']; ?>
                    </a>
                </strong>
                <?php if ($subscription['bna_subscription_id'] ?? false): ?>
                    <br><small style="color: #666;">BNA ID: <?php echo esc_html($subscription['bna_subscription_id']); ?></small>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($customer): ?>
                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $customer->ID)); ?>">
                        <?php echo esc_html($customer->display_name); ?>
                    </a>
                    <br><small><?php echo esc_html($customer->user_email); ?></small>
                <?php else: ?>
                    <?php echo esc_html($order ? $order->get_billing_email() : 'N/A'); ?>
                <?php endif; ?>
            </td>
            <td>
                <span class="subscription-status-badge" style="background: <?php echo esc_attr($status_color); ?>; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                    <?php echo esc_html(BNA_My_Account::get_subscription_status_label($status)); ?>
                </span>
            </td>
            <td>
                <?php
                $frequency = 'monthly';
                if (!empty($subscription['items'])) {
                    $first_item = reset($subscription['items']);
                    $frequency = $first_item['subscription_data']['frequency'] ?? 'monthly';
                }
                echo esc_html(BNA_My_Account::format_subscription_frequency($frequency));
                ?>
            </td>
            <td>
                <strong><?php echo $order ? $order->get_formatted_order_total() : 'N/A'; ?></strong>
            </td>
            <td>
                <?php echo $subscription['created_date'] ? $subscription['created_date']->format('M j, Y') : 'N/A'; ?>
            </td>
            <td>
                <?php echo $subscription['next_payment'] ?? 'N/A'; ?>
            </td>
            <td>
                <?php self::render_subscription_actions($subscription['order_id'], $status); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render subscription action buttons
     */
    private static function render_subscription_actions($order_id, $status) {
        $actions = array();

        if (BNA_My_Account::is_subscription_action_allowed($status, 'suspend')) {
            $actions[] = '<button type="button" class="button-small bna-subscription-action" data-action="suspend" data-order-id="' . $order_id . '">Pause</button>';
        }

        if (BNA_My_Account::is_subscription_action_allowed($status, 'resume')) {
            $actions[] = '<button type="button" class="button-small bna-subscription-action" data-action="resume" data-order-id="' . $order_id . '">Resume</button>';
        }

        if (BNA_My_Account::is_subscription_action_allowed($status, 'cancel')) {
            $actions[] = '<button type="button" class="button-small bna-subscription-action" data-action="cancel" data-order-id="' . $order_id . '" style="color: #d63638;">Cancel</button>';
        }

        $actions[] = '<a href="' . admin_url('post.php?post=' . $order_id . '&action=edit') . '" class="button-small">View</a>';

        echo implode(' ', $actions);
    }

    /**
     * Get admin subscriptions data
     */
    private static function get_admin_subscriptions($page = 1, $per_page = 20, $status_filter = 'all', $search = '') {
        $offset = ($page - 1) * $per_page;

        $args = array(
            'meta_key' => '_bna_subscription_created',
            'meta_compare' => 'EXISTS',
            'limit' => $per_page,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        // Status filter
        if ($status_filter !== 'all') {
            $args['meta_query'] = array(
                array(
                    'key' => '_bna_subscription_status',
                    'value' => $status_filter,
                    'compare' => '='
                )
            );
        }

        // Search filter
        if (!empty($search)) {
            $args['search'] = $search;
        }

        $orders = wc_get_orders($args);
        $subscriptions = array();

        foreach ($orders as $order) {
            $subscription_items = $order->get_meta('_bna_subscription_items', true);
            if (is_array($subscription_items)) {
                $subscriptions[] = array(
                    'id' => $order->get_id(),
                    'order_id' => $order->get_id(),
                    'status' => $order->get_meta('_bna_subscription_status', true) ?: 'new',
                    'total' => $order->get_total(),
                    'currency' => $order->get_currency(),
                    'created_date' => $order->get_date_created(),
                    'next_payment' => self::$subscriptions->calculate_next_payment_date($order, $subscription_items),
                    'items' => $subscription_items,
                    'bna_subscription_id' => $order->get_meta('_bna_subscription_id', true)
                );
            }
        }

        // Get total count
        $total_args = $args;
        unset($total_args['limit'], $total_args['offset']);
        $total_subscriptions = count(wc_get_orders($total_args));

        return array(
            'subscriptions' => $subscriptions,
            'total' => $total_subscriptions
        );
    }

    /**
     * Get subscription statistics
     */
    private static function get_subscription_stats() {
        $stats = array(
            'total' => 0,
            'active' => 0,
            'suspended' => 0,
            'cancelled' => 0,
            'expired' => 0,
            'failed' => 0
        );

        $orders = wc_get_orders(array(
            'meta_key' => '_bna_subscription_created',
            'meta_compare' => 'EXISTS',
            'limit' => -1
        ));

        foreach ($orders as $order) {
            $status = $order->get_meta('_bna_subscription_status', true) ?: 'new';
            $stats['total']++;

            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $stats;
    }

    /**
     * Handle subscription action AJAX
     */
    public static function handle_subscription_action() {
        check_ajax_referer('bna_admin_subscription_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Insufficient permissions.', 'bna-smart-payment'));
        }

        $action = sanitize_text_field($_POST['action_type'] ?? '');
        $order_id = intval($_POST['order_id'] ?? 0);

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Order not found.', 'bna-smart-payment'));
        }

        $subscription_id = $order->get_meta('_bna_subscription_id');

        switch ($action) {
            case 'suspend':
                $result = self::$api->suspend_subscription($subscription_id);
                if (!is_wp_error($result)) {
                    $order->update_meta_data('_bna_subscription_status', 'suspended');
                    $order->update_status('on-hold', __('Subscription suspended by admin.', 'bna-smart-payment'));
                    $order->save();
                    wp_send_json_success(__('Subscription suspended successfully.', 'bna-smart-payment'));
                } else {
                    wp_send_json_error($result->get_error_message());
                }
                break;

            case 'resume':
                $result = self::$api->resume_subscription($subscription_id);
                if (!is_wp_error($result)) {
                    $order->update_meta_data('_bna_subscription_status', 'active');
                    $order->update_status('processing', __('Subscription resumed by admin.', 'bna-smart-payment'));
                    $order->save();
                    wp_send_json_success(__('Subscription resumed successfully.', 'bna-smart-payment'));
                } else {
                    wp_send_json_error($result->get_error_message());
                }
                break;

            case 'cancel':
                $result = self::$api->delete_subscription($subscription_id);
                if (!is_wp_error($result)) {
                    $order->update_meta_data('_bna_subscription_status', 'cancelled');
                    $order->update_status('cancelled', __('Subscription cancelled by admin.', 'bna-smart-payment'));
                    $order->save();
                    wp_send_json_success(__('Subscription cancelled successfully.', 'bna-smart-payment'));
                } else {
                    wp_send_json_error($result->get_error_message());
                }
                break;

            default:
                wp_send_json_error(__('Invalid action.', 'bna-smart-payment'));
        }
    }

    /**
     * Handle sync subscriptions
     */
    public static function handle_sync_subscriptions() {
        check_ajax_referer('bna_admin_subscription_action', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Insufficient permissions.', 'bna-smart-payment'));
        }

        $synced_count = self::sync_all_subscriptions();

        wp_send_json_success(sprintf(
            __('Synced %d subscriptions successfully.', 'bna-smart-payment'),
            $synced_count
        ));
    }

    /**
     * Sync all subscriptions with BNA API
     */
    private static function sync_all_subscriptions() {
        $orders = wc_get_orders(array(
            'meta_key' => '_bna_subscription_created',
            'meta_compare' => 'EXISTS',
            'limit' => -1
        ));

        $synced_count = 0;

        foreach ($orders as $order) {
            $bna_customer_id = $order->get_meta('_bna_customer_id');
            if (empty($bna_customer_id)) {
                continue;
            }

            try {
                $bna_subscriptions = self::$api->get_customer_subscriptions($bna_customer_id);
                if (!is_wp_error($bna_subscriptions) && is_array($bna_subscriptions)) {
                    // Update order with fresh subscription data
                    foreach ($bna_subscriptions as $bna_sub) {
                        $subscription_id = $order->get_meta('_bna_subscription_id');
                        if ($bna_sub['id'] === $subscription_id) {
                            $order->update_meta_data('_bna_subscription_status', strtolower($bna_sub['status'] ?? 'new'));
                            $order->save();
                            $synced_count++;
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                bna_error('Failed to sync subscription', array(
                    'order_id' => $order->get_id(),
                    'error' => $e->getMessage()
                ));
            }
        }

        return $synced_count;
    }

    /**
     * Export subscriptions to CSV
     */
    private static function export_subscriptions() {
        $data = self::get_admin_subscriptions(1, -1);
        $subscriptions = $data['subscriptions'];

        $filename = 'bna-subscriptions-export-' . date('Y-m-d-H-i-s') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // CSV headers
        fputcsv($output, array(
            'Order ID',
            'Status',
            'Customer Email',
            'Total',
            'Frequency',
            'Created Date',
            'Next Payment',
            'BNA Subscription ID'
        ));

        // CSV data
        foreach ($subscriptions as $subscription) {
            $order = wc_get_order($subscription['order_id']);
            $frequency = 'monthly';
            if (!empty($subscription['items'])) {
                $first_item = reset($subscription['items']);
                $frequency = $first_item['subscription_data']['frequency'] ?? 'monthly';
            }

            fputcsv($output, array(
                $subscription['order_id'],
                $subscription['status'],
                $order ? $order->get_billing_email() : '',
                $subscription['total'],
                $frequency,
                $subscription['created_date'] ? $subscription['created_date']->format('Y-m-d H:i:s') : '',
                $subscription['next_payment'] ?? '',
                $subscription['bna_subscription_id'] ?? ''
            ));
        }

        fclose($output);
    }

    /**
     * Add subscription column to orders list
     */
    public static function add_order_subscription_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_status') {
                $new_columns['subscription_status'] = __('Subscription', 'bna-smart-payment');
            }
        }
        return $new_columns;
    }

    /**
     * Display subscription column content
     */
    public static function display_order_subscription_column($column, $order_id) {
        if ($column !== 'subscription_status') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $has_subscription = $order->get_meta('_bna_has_subscription') === 'yes';
        if (!$has_subscription) {
            echo '—';
            return;
        }

        $status = $order->get_meta('_bna_subscription_status', true) ?: 'new';
        $status_color = BNA_My_Account::get_subscription_status_color($status);

        echo '<span class="subscription-status-badge" style="background: ' . esc_attr($status_color) . '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">';
        echo esc_html(BNA_My_Account::get_subscription_status_label($status));
        echo '</span>';
    }

    // ==========================================
    // LOGS PAGE (EXISTING)
    // ==========================================

    /**
     * Download logs as text file
     */
    private static function download_logs() {
        $logs = BNA_Logger::get_logs(0);
        $filename = 'bna-payment-logs-' . date('Y-m-d-H-i-s') . '.txt';

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($logs));

        echo $logs;
    }


    /**
     * Display logs page
     */
    public static function logs_page() {
        // Prepare all data for the template
        $data = array(
            'logs' => BNA_Logger::get_logs(1000),
            'log_size' => BNA_Logger::get_log_size(),
            'webhook_url' => home_url('/wp-json/bna/v1/webhook'),
            'plugin_version' => defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'Unknown',
            'wp_version' => get_bloginfo('version'),
            'wc_version' => class_exists('WooCommerce') ? WC()->version : 'Not installed',
            'php_version' => PHP_VERSION,
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'message' => isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '',

            // Generate secure nonce URLs for actions
            'clear_logs_url' => wp_nonce_url(admin_url('admin.php?page=bna-logs&bna_action=clear_logs'), 'bna_admin_action'),
            'download_logs_url' => wp_nonce_url(admin_url('admin.php?page=bna-logs&bna_action=download_logs'), 'bna_admin_action')
        );

        // Render template with data
        BNA_Template::render_admin_logs($data);
    }

    /**
     * Get admin message text
     */
    private static function get_admin_message($message) {
        $messages = array(
            'logs_cleared' => __('Logs cleared successfully.', 'bna-smart-payment'),
            'sync_completed' => __('Subscription sync completed.', 'bna-smart-payment'),
        );

        return isset($messages[$message]) ? $messages[$message] : '';
    }
}