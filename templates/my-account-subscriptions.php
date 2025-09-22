<?php
/**
 * My Account Subscriptions Template
 * 
 * Shows customer subscriptions in My Account area
 * 
 * @since 1.9.0
 * @package BNA_Smart_Payment
 */

if (!defined('ABSPATH')) {
    exit;
}

$user_id = get_current_user_id();
$subscriptions = isset($subscriptions) ? $subscriptions : array();
$subscription_count = count($subscriptions);
?>

<div class="bna-my-account-subscriptions">
    <div class="bna-subscriptions-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #0073aa;">
        <h2 style="margin: 0; color: #0073aa;">
            <?php _e('📋 My Subscriptions', 'bna-smart-payment'); ?>
            <?php if ($subscription_count > 0) : ?>
                <span style="background: #0073aa; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; margin-left: 10px;">
                    <?php echo $subscription_count; ?>
                </span>
            <?php endif; ?>
        </h2>
        
        <div class="subscription-actions">
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="button button-primary">
                <?php _e('Browse Products', 'bna-smart-payment'); ?>
            </a>
        </div>
    </div>

    <?php if (empty($subscriptions)) : ?>
        <div class="bna-no-subscriptions" style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">
            <div style="font-size: 48px; margin-bottom: 20px;">📦</div>
            <h3 style="color: #6c757d; margin-bottom: 15px;">
                <?php _e('No Subscriptions Yet', 'bna-smart-payment'); ?>
            </h3>
            <p style="color: #6c757d; margin-bottom: 25px;">
                <?php _e('You don\'t have any active subscriptions. Browse our products to find subscription services that work for you.', 'bna-smart-payment'); ?>
            </p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="button button-primary">
                <?php _e('Start Shopping', 'bna-smart-payment'); ?>
            </a>
        </div>

    <?php else : ?>
        <div class="bna-subscriptions-list">
            <?php foreach ($subscriptions as $subscription) : ?>
                <?php 
                $status_class = 'status-' . esc_attr($subscription['status']);
                $status_color = self::get_status_color($subscription['status']);
                $next_payment = $subscription['next_payment'] ? date('M j, Y', strtotime($subscription['next_payment'])) : 'N/A';
                $frequency_label = isset(BNA_Subscriptions::FREQUENCIES[$subscription['frequency']]) ? 
                                  BNA_Subscriptions::FREQUENCIES[$subscription['frequency']] : 
                                  ucfirst($subscription['frequency']);
                ?>
                
                <div class="subscription-item <?php echo $status_class; ?>" style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div class="subscription-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                        <div class="subscription-info">
                            <h4 style="margin: 0 0 5px 0; color: #333;">
                                <?php _e('Subscription', 'bna-smart-payment'); ?> #<?php echo esc_html($subscription['id']); ?>
                            </h4>
                            <div class="subscription-meta" style="font-size: 14px; color: #666;">
                                <?php _e('Started:', 'bna-smart-payment'); ?> 
                                <?php echo $subscription['created_date'] ? $subscription['created_date']->date_i18n(wc_date_format()) : 'N/A'; ?>
                            </div>
                        </div>
                        
                        <div class="subscription-status">
                            <span class="status-badge" style="background: <?php echo $status_color; ?>; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold;">
                                <?php echo esc_html(ucfirst($subscription['status'])); ?>
                            </span>
                        </div>
                    </div>

                    <div class="subscription-details" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                        <div class="detail-item">
                            <strong style="color: #333;"><?php _e('Amount:', 'bna-smart-payment'); ?></strong><br>
                            <span style="font-size: 18px; color: #0073aa; font-weight: bold;">
                                <?php echo wc_price($subscription['total'], array('currency' => $subscription['currency'])); ?>
                            </span>
                        </div>
                        
                        <div class="detail-item">
                            <strong style="color: #333;"><?php _e('Frequency:', 'bna-smart-payment'); ?></strong><br>
                            <span><?php echo esc_html($frequency_label); ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <strong style="color: #333;"><?php _e('Next Payment:', 'bna-smart-payment'); ?></strong><br>
                            <span><?php echo esc_html($next_payment); ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <strong style="color: #333;"><?php _e('Payment Method:', 'bna-smart-payment'); ?></strong><br>
                            <span><?php _e('BNA Smart Payment', 'bna-smart-payment'); ?></span>
                        </div>
                    </div>

                    <?php if (!empty($subscription['products'])) : ?>
                        <div class="subscription-products" style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                            <strong style="color: #333; margin-bottom: 10px; display: block;"><?php _e('Products:', 'bna-smart-payment'); ?></strong>
                            <div class="products-list">
                                <?php foreach ($subscription['products'] as $product) : ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 5px 0;">
                                        <span><?php echo esc_html($product['name']); ?></span>
                                        <span style="color: #666;">
                                            <?php if ($product['quantity'] > 1) : ?>
                                                <?php echo $product['quantity']; ?> × 
                                            <?php endif; ?>
                                            <?php echo wc_price($product['total']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="subscription-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url(wc_get_endpoint_url('orders', $subscription['order_id'], wc_get_page_permalink('myaccount'))); ?>" 
                           class="button button-secondary" style="text-decoration: none;">
                            <?php _e('View Order', 'bna-smart-payment'); ?>
                        </a>
                        
                        <?php if ($subscription['status'] === 'active') : ?>
                            <button type="button" class="button suspend-subscription" 
                                    data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                    style="background: #f0ad4e; border-color: #eea236; color: white;">
                                <?php _e('Pause', 'bna-smart-payment'); ?>
                            </button>
                            <button type="button" class="button cancel-subscription" 
                                    data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                    style="background: #d9534f; border-color: #d43f3a; color: white;">
                                <?php _e('Cancel', 'bna-smart-payment'); ?>
                            </button>
                            
                        <?php elseif ($subscription['status'] === 'suspended') : ?>
                            <button type="button" class="button resume-subscription" 
                                    data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                    style="background: #5cb85c; border-color: #4cae4c; color: white;">
                                <?php _e('Resume', 'bna-smart-payment'); ?>
                            </button>
                            <button type="button" class="button cancel-subscription" 
                                    data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                    style="background: #d9534f; border-color: #d43f3a; color: white;">
                                <?php _e('Cancel', 'bna-smart-payment'); ?>
                            </button>
                            
                        <?php elseif (in_array($subscription['status'], ['cancelled', 'failed'])) : ?>
                            <button type="button" class="button reactivate-subscription" 
                                    data-subscription-id="<?php echo esc_attr($subscription['id']); ?>"
                                    style="background: #5bc0de; border-color: #46b8da; color: white;">
                                <?php _e('Reactivate', 'bna-smart-payment'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Subscription Management Notice -->
        <div class="subscription-notice" style="background: #d1ecf1; border: 1px solid #b8daff; border-radius: 4px; padding: 15px; margin-top: 20px;">
            <h4 style="margin-top: 0; color: #0c5460;">
                <?php _e('📝 Subscription Management', 'bna-smart-payment'); ?>
            </h4>
            <ul style="margin-bottom: 0; padding-left: 20px; color: #0c5460;">
                <li><?php _e('Changes may take up to 24 hours to process', 'bna-smart-payment'); ?></li>
                <li><?php _e('Paused subscriptions can be resumed anytime', 'bna-smart-payment'); ?></li>
                <li><?php _e('Cancelled subscriptions can be reactivated within 30 days', 'bna-smart-payment'); ?></li>
                <li><?php _e('Need help? Contact our support team', 'bna-smart-payment'); ?></li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Handle subscription actions (placeholder for future API integration)
    $('.suspend-subscription, .cancel-subscription, .resume-subscription, .reactivate-subscription').on('click', function(e) {
        e.preventDefault();
        
        var action = '';
        var subscriptionId = $(this).data('subscription-id');
        var button = $(this);
        
        if ($(this).hasClass('suspend-subscription')) {
            action = 'suspend';
        } else if ($(this).hasClass('cancel-subscription')) {
            action = 'cancel';
        } else if ($(this).hasClass('resume-subscription')) {
            action = 'resume';
        } else if ($(this).hasClass('reactivate-subscription')) {
            action = 'reactivate';
        }
        
        var confirmMessage = '';
        switch (action) {
            case 'suspend':
                confirmMessage = '<?php _e("Are you sure you want to pause this subscription?", "bna-smart-payment"); ?>';
                break;
            case 'cancel':
                confirmMessage = '<?php _e("Are you sure you want to cancel this subscription?", "bna-smart-payment"); ?>';
                break;
            case 'resume':
                confirmMessage = '<?php _e("Are you sure you want to resume this subscription?", "bna-smart-payment"); ?>';
                break;
            case 'reactivate':
                confirmMessage = '<?php _e("Are you sure you want to reactivate this subscription?", "bna-smart-payment"); ?>';
                break;
        }
        
        if (confirm(confirmMessage)) {
            // Placeholder: This will be connected to API in future
            alert('<?php _e("Feature coming soon! This will be connected to BNA API.", "bna-smart-payment"); ?>');
            
            // Future API call structure:
            /*
            $.post(wc_checkout_params.ajax_url, {
                action: 'bna_manage_subscription',
                subscription_id: subscriptionId,
                subscription_action: action,
                security: bna_ajax_nonce
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || 'Error occurred');
                }
            });
            */
        }
    });
});
</script>

<style type="text/css">
.bna-my-account-subscriptions {
    max-width: 100%;
}

.subscription-item {
    transition: box-shadow 0.3s ease;
}

.subscription-item:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
}

.subscription-actions .button {
    font-size: 13px;
    padding: 6px 12px;
    min-height: auto;
    line-height: 1.4;
}

.subscription-actions .button:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

@media (max-width: 768px) {
    .subscription-header {
        flex-direction: column;
        gap: 10px;
    }
    
    .subscription-details {
        grid-template-columns: 1fr !important;
        gap: 10px;
    }
    
    .subscription-actions {
        flex-direction: column;
    }
    
    .subscription-actions .button {
        width: 100%;
        text-align: center;
    }
}
</style>

<?php
// Helper function for status colors
if (!function_exists('bna_get_subscription_status_color')) {
    function bna_get_subscription_status_color($status) {
        $colors = array(
            'new' => '#6c757d',
            'active' => '#28a745', 
            'suspended' => '#ffc107',
            'cancelled' => '#dc3545',
            'expired' => '#6f42c1',
            'failed' => '#fd7e14'
        );
        return isset($colors[$status]) ? $colors[$status] : '#6c757d';
    }
}

// Make function available in template scope
if (!method_exists('BNA_My_Account_Subscriptions', 'get_status_color')) {
    function get_status_color($status) {
        return bna_get_subscription_status_color($status);
    }
}
?>
