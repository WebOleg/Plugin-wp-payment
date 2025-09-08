<?php
if (!defined('ABSPATH')) exit;

$my_account = BNA_My_Account::get_instance();

// Debug information for troubleshooting
if (current_user_can('manage_options') && isset($_GET['debug_payment_methods'])) {
    echo '<div style="background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffc107; border-radius: 4px;">';
    echo '<h4>Debug Information</h4>';
    echo '<p><strong>User ID:</strong> ' . $user_id . '</p>';
    echo '<p><strong>Payment Methods Count:</strong> ' . count($payment_methods) . '</p>';
    echo '<p><strong>BNA Customer ID:</strong> ' . get_user_meta($user_id, '_bna_customer_id', true) . '</p>';
    if (!empty($payment_methods)) {
        echo '<p><strong>Payment Methods Data:</strong></p>';
        echo '<pre>' . print_r($payment_methods, true) . '</pre>';
    }
    echo '</div>';
}
?>

<div class="bna-payment-methods">
    <h3><?php _e('Saved Payment Methods', 'bna-smart-payment'); ?></h3>

    <?php if (empty($payment_methods)): ?>
        <div class="bna-no-methods">
            <div class="bna-no-methods-icon">💳</div>
            <h4><?php _e('No Payment Methods Saved', 'bna-smart-payment'); ?></h4>
            <p><?php _e('You have no saved payment methods yet.', 'bna-smart-payment'); ?></p>
            <p><small><?php _e('Payment methods will be automatically saved when you complete a purchase using BNA Smart Payment.', 'bna-smart-payment'); ?></small></p>

            <?php if (current_user_can('manage_options')): ?>
                <p style="margin-top: 15px;">
                    <a href="?debug_payment_methods=1" class="button button-secondary" style="font-size: 12px;">
                        🔍 Debug Payment Methods (Admin Only)
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="bna-methods-list">
            <?php foreach ($payment_methods as $index => $method): ?>
                <div class="bna-payment-method" data-method-id="<?php echo esc_attr($method['id']); ?>">
                    <div class="bna-method-info">
                        <span class="bna-method-icon">
                            <?php echo $my_account->get_payment_method_icon($method); ?>
                        </span>
                        <div class="bna-method-details">
                            <strong class="bna-method-name">
                                <?php echo esc_html($my_account->get_payment_method_display_name($method)); ?>
                            </strong>
                            <div class="bna-method-meta">
                                <small class="bna-method-date">
                                    <?php
                                    $created_date = isset($method['created_at']) ? $method['created_at'] : current_time('Y-m-d H:i:s');
                                    printf(
                                        __('Added on %s', 'bna-smart-payment'),
                                        date_i18n(get_option('date_format'), strtotime($created_date))
                                    );
                                    ?>
                                </small>
                                <?php if (!empty($method['type'])): ?>
                                    <small class="bna-method-type">
                                        • <?php echo esc_html(ucfirst(strtolower($method['type']))); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="bna-method-actions">
                        <button
                                type="button"
                                class="button bna-delete-method"
                                data-method-id="<?php echo esc_attr($method['id']); ?>"
                                title="<?php esc_attr_e('Delete this payment method', 'bna-smart-payment'); ?>"
                        >
                            <?php _e('Delete', 'bna-smart-payment'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="bna-methods-info">
            <div class="bna-info-box">
                <h4>🔒 <?php _e('Security & Privacy', 'bna-smart-payment'); ?></h4>
                <ul>
                    <li><?php _e('Payment methods are securely stored and encrypted', 'bna-smart-payment'); ?></li>
                    <li><?php _e('Only the last 4 digits and method type are shown', 'bna-smart-payment'); ?></li>
                    <li><?php _e('Full card details are never stored on this website', 'bna-smart-payment'); ?></li>
                    <li><?php _e('You can delete any saved method at any time', 'bna-smart-payment'); ?></li>
                </ul>
            </div>

            <?php if (current_user_can('manage_options')): ?>
                <p style="margin-top: 15px;">
                    <a href="?debug_payment_methods=1" class="button button-secondary" style="font-size: 12px;">
                        🔍 Debug Payment Methods (Admin Only)
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    .bna-payment-methods {
        margin: 20px 0;
        background: white;
        border-radius: 8px;
        overflow: hidden;
    }

    .bna-payment-methods h3 {
        margin: 0 0 20px 0;
        padding: 0;
        font-size: 20px;
        color: #333;
    }

    .bna-no-methods {
        text-align: center;
        padding: 60px 20px;
        background: #f8f9fa;
        border-radius: 8px;
        color: #666;
        border: 2px dashed #ddd;
    }

    .bna-no-methods-icon {
        font-size: 48px;
        margin-bottom: 20px;
        opacity: 0.5;
    }

    .bna-no-methods h4 {
        color: #333;
        margin-bottom: 10px;
        font-size: 18px;
    }

    .bna-no-methods p {
        margin: 10px 0;
        line-height: 1.5;
    }

    .bna-methods-list {
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
        background: white;
    }

    .bna-payment-method {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 20px;
        border-bottom: 1px solid #eee;
        background: white;
        transition: background-color 0.2s ease;
    }

    .bna-payment-method:last-child {
        border-bottom: none;
    }

    .bna-payment-method:hover {
        background: #f8f9fa;
    }

    .bna-method-info {
        display: flex;
        align-items: center;
        flex: 1;
    }

    .bna-method-icon {
        font-size: 28px;
        margin-right: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        background: #f0f0f0;
        border-radius: 6px;
    }

    .bna-method-details {
        display: flex;
        flex-direction: column;
    }

    .bna-method-name {
        font-size: 16px;
        color: #333;
        margin-bottom: 4px;
        font-weight: 600;
    }

    .bna-method-meta {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .bna-method-date,
    .bna-method-type {
        color: #666;
        font-size: 13px;
    }

    .bna-method-actions {
        margin-left: 15px;
    }

    .bna-delete-method {
        background: #dc3232 !important;
        color: white !important;
        border: none !important;
        padding: 8px 16px !important;
        border-radius: 4px !important;
        cursor: pointer;
        font-size: 14px !important;
        transition: background-color 0.2s ease;
    }

    .bna-delete-method:hover {
        background: #c02d2d !important;
    }

    .bna-delete-method:disabled {
        background: #ccc !important;
        cursor: not-allowed !important;
    }

    .bna-methods-info {
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px solid #eee;
    }

    .bna-info-box {
        background: #e7f3ff;
        padding: 15px;
        border-radius: 6px;
        border-left: 4px solid #0073aa;
    }

    .bna-info-box h4 {
        margin: 0 0 10px 0;
        color: #0073aa;
        font-size: 14px;
    }

    .bna-info-box ul {
        margin: 0;
        padding-left: 20px;
    }

    .bna-info-box li {
        margin: 5px 0;
        font-size: 13px;
        color: #555;
    }

    /* Mobile responsiveness */
    @media (max-width: 600px) {
        .bna-payment-method {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }

        .bna-method-info {
            width: 100%;
        }

        .bna-method-actions {
            margin-left: 0;
            width: 100%;
        }

        .bna-delete-method {
            width: 100%;
        }

        .bna-no-methods {
            padding: 40px 15px;
        }
    }

    /* Loading state */
    .bna-payment-method.deleting {
        opacity: 0.6;
        pointer-events: none;
    }

    /* Success/Error messages */
    .bna-message {
        margin: 15px 0;
        padding: 12px;
        border-radius: 4px;
        font-size: 14px;
    }

    .bna-message.notice-success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .bna-message.notice-error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }
</style>