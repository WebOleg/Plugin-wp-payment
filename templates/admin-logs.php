<?php
/**
 * Admin Logs Template
 * Displays BNA payment logs with system information
 */

if (!defined('ABSPATH')) {
    exit;
}

// Safe extraction of template data with fallbacks
if (isset($data) && is_array($data)) {
    extract($data);
} else {
    // Fallback values if data is not provided
    $logs = BNA_Logger::get_logs(1000);
    $log_size = BNA_Logger::get_log_size();
    $webhook_url = home_url('/wp-json/bna/v1/webhook');
    $plugin_version = defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'Unknown';
    $wp_version = get_bloginfo('version');
    $wc_version = class_exists('WooCommerce') ? WC()->version : 'Not installed';
    $php_version = PHP_VERSION;
    $wp_debug = defined('WP_DEBUG') && WP_DEBUG;
    $message = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';

    // Generate nonce URLs
    $clear_logs_url = wp_nonce_url(admin_url('admin.php?page=bna-logs&bna_action=clear_logs'), 'bna_admin_action');
    $download_logs_url = wp_nonce_url(admin_url('admin.php?page=bna-logs&bna_action=download_logs'), 'bna_admin_action');
}
?>

<div class="wrap">
    <h1>🔧 BNA Payment Logs</h1>

    <?php if (!empty($message)): ?>
        <?php $messages = array('logs_cleared' => 'Logs cleared successfully'); ?>
        <?php if (isset($messages[$message])): ?>
            <div class="notice notice-success">
                <p><?php echo esc_html($messages[$message]); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div style="background: white; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
        <h2>Log Information</h2>
        <p>
            <strong>Log Size:</strong> <?php echo size_format($log_size); ?> |
            <strong>Webhook URL:</strong> <code><?php echo esc_html($webhook_url); ?></code>
        </p>

        <p>
            <a href="<?php echo esc_url($download_logs_url); ?>" class="button">📥 Download Logs</a>
            <a href="<?php echo esc_url($clear_logs_url); ?>" class="button" onclick="return confirm('Clear all logs? This cannot be undone.')">🗑️ Clear Logs</a>
        </p>
    </div>

    <div style="background: #e7f3ff; padding: 15px; margin: 15px 0; border: 1px solid #0073aa; border-radius: 4px;">
        <h3>🚀 Active Features</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <div>
                <strong>✅ iFrame Integration</strong><br>
                <small>Secure payment form integration</small>
            </div>
            <div>
                <strong>✅ Webhook Processing</strong><br>
                <small>Real-time payment notifications</small>
            </div>
            <div>
                <strong><?php echo bna_is_shipping_enabled() ? '✅' : '❌'; ?> Shipping Address</strong><br>
                <small><?php echo bna_is_shipping_enabled() ? 'Enabled' : 'Disabled'; ?> - Different from billing address</small>
            </div>
            <div>
                <strong>✅ Customer Data Sync</strong><br>
                <small>v1.6.0 - Auto-update customer data changes</small>
            </div>
            <div>
                <strong>✅ Payment Methods Management</strong><br>
                <small>v1.7.0 - Save & manage payment methods</small>
            </div>
            <div>
                <strong>✅ My Account Integration</strong><br>
                <small>v1.7.0 - Customer payment methods page</small>
            </div>
        </div>
    </div>

    <?php
    $health = bna_get_system_health();
    $healthy_count = count(array_filter($health));
    $total_count = count($health);
    $health_percentage = round(($healthy_count / $total_count) * 100);
    ?>
    <div style="background: <?php echo $health_percentage >= 80 ? '#d4edda' : ($health_percentage >= 60 ? '#fff3cd' : '#f8d7da'); ?>; padding: 15px; margin: 15px 0; border: 1px solid <?php echo $health_percentage >= 80 ? '#28a745' : ($health_percentage >= 60 ? '#ffc107' : '#dc3545'); ?>; border-radius: 4px;">
        <h3>⚡ System Health (<?php echo $health_percentage; ?>%)</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
            <?php foreach ($health as $check => $status): ?>
                <div>
                    <?php echo $status ? '✅' : '❌'; ?> <strong><?php echo esc_html(ucwords(str_replace('_', ' ', $check))); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($logs)): ?>
    <div style="background: white; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
        <h3>📋 Recent Logs (Last 1000 entries)</h3>
        <div style="background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; border-radius: 4px; max-height: 500px; overflow-y: auto;">
            <pre style="margin: 0; font-size: 12px; line-height: 1.4; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($logs); ?></pre>
        </div>
        <p style="margin-top: 10px; color: #666; font-size: 13px;">
            <strong>💡 Tip:</strong>
            Use Ctrl+F to search within the logs. Look for "Payment method saved", "Payment method deleted", or "Webhook received" to track payment methods activity.
        </p>

        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <h3>📝 No Logs Found</h3>
                <p>No logs have been generated yet. Logs will appear here when:</p>
                <ul style="text-align: left; display: inline-block;">
                    <li>Payments are processed</li>
                    <li>Webhooks are received</li>
                    <li>API requests are made</li>
                    <li>Customer data is synced</li>
                    <li>Payment methods are saved/deleted</li>
                    <li>Errors occur</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div style="background: #f0f8ff; padding: 15px; margin: 15px 0; border: 1px solid #0073aa; border-radius: 4px;">
        <h3>🔗 System Information</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
            <div>
                <h4>Plugin Details</h4>
                <ul style="margin: 0;">
                    <li><strong>Plugin Version:</strong> <?php echo esc_html($plugin_version); ?></li>
                    <li><strong>Customer Sync:</strong> ✅ Enabled (v1.6.0)</li>
                    <li><strong>Payment Methods:</strong> ✅ Enabled (v1.7.0)</li>
                    <li><strong>Error Handling:</strong> ✅ Improved (v1.6.1)</li>
                    <li><strong>Country Mapping:</strong> ✅ Enhanced (v1.6.1)</li>
                    <li><strong>Shipping Address:</strong> <?php echo bna_is_shipping_enabled() ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    <li><strong>Debug Mode:</strong> <?php echo bna_is_debug_mode() ? '🔍 Enabled' : '❌ Disabled'; ?></li>
                    <li><strong>Log File:</strong> <code>wp-content/uploads/bna-logs/bna-payment.log</code></li>
                </ul>
            </div>
            <div>
                <h4>Environment</h4>
                <ul style="margin: 0;">
                    <li><strong>WordPress:</strong> <?php echo esc_html($wp_version); ?></li>
                    <li><strong>WooCommerce:</strong> <?php echo esc_html($wc_version); ?></li>
                    <li><strong>PHP:</strong> <?php echo esc_html($php_version); ?> <?php echo version_compare(PHP_VERSION, '7.4', '>=') ? '✅' : (version_compare(PHP_VERSION, '7.0', '>=') ? '⚠️' : '❌'); ?></li>
                    <li><strong>JSON Support:</strong> <?php echo function_exists('json_encode') ? '✅ Available' : '❌ Missing'; ?></li>
                    <li><strong>JSON Constants:</strong> <?php echo (defined('JSON_UNESCAPED_UNICODE') && defined('JSON_SORT_KEYS')) ? '✅ Full' : '⚠️ Limited'; ?></li>
                    <li><strong>WP Debug:</strong> <?php echo $wp_debug ? '🔍 Enabled' : '❌ Disabled'; ?></li>
                    <li><strong>SSL:</strong> <?php echo is_ssl() ? '🔒 Enabled' : '⚠️ Disabled'; ?></li>
                    <li><strong>Permalinks:</strong> <?php echo get_option('permalink_structure') ? '✅ Pretty' : '⚠️ Plain'; ?></li>
                </ul>
            </div>
        </div>
    </div>

    <div style="background: #fff8e1; padding: 15px; margin: 15px 0; border: 1px solid #ffb300; border-radius: 4px;">
        <h3>📖 Quick Help</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <div>
                <h4>🔍 Log Analysis</h4>
                <ul style="margin: 0; font-size: 13px;">
                    <li><code>[INFO]</code> - Normal operations</li>
                    <li><code>[DEBUG]</code> - Detailed debugging info</li>
                    <li><code>[ERROR]</code> - Problems that need attention</li>
                    <li>Look for patterns in timestamps</li>
                </ul>
            </div>
            <div>
                <h4>🚨 Common Issues</h4>
                <ul style="margin: 0; font-size: 13px;">
                    <li><strong>Webhook failures:</strong> Check URL accessibility</li>
                    <li><strong>API errors:</strong> Verify credentials</li>
                    <li><strong>SSL warnings:</strong> Enable HTTPS</li>
                    <li><strong>Large logs:</strong> Enable log rotation</li>
                </ul>
            </div>
            <div>
                <h4>⚙️ Settings</h4>
                <ul style="margin: 0; font-size: 13px;">
                    <li><a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=bna_smart_payment'); ?>">Gateway Settings</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=bna-subscriptions'); ?>">Subscriptions</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=wc-status'); ?>">WooCommerce Status</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>