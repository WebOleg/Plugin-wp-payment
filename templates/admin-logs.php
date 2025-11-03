<?php
/**
 * Admin Logs Template
 * Displays BNA payment logs
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
        </div>
    <?php else: ?>
        <div style="background: white; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
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
        </div>
    <?php endif; ?>
</div>