<?php
if (!defined('ABSPATH')) exit;

$manager = BNA_Subscription_Manager::get_instance();
?>

<div class="bna-subscriptions-wrapper">
    <?php if (!empty($subscriptions)): ?>
        <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
            <thead>
                <tr>
                    <th class="woocommerce-orders-table__header"><?php _e('Subscription', 'bna-smart-payment'); ?></th>
                    <th class="woocommerce-orders-table__header"><?php _e('Status', 'bna-smart-payment'); ?></th>
                    <th class="woocommerce-orders-table__header"><?php _e('Next Payment', 'bna-smart-payment'); ?></th>
                    <th class="woocommerce-orders-table__header"><?php _e('Total', 'bna-smart-payment'); ?></th>
                    <th class="woocommerce-orders-table__header"><?php _e('Actions', 'bna-smart-payment'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscriptions as $subscription): 
                    $product = wc_get_product($subscription->product_id);
                    $original_order = wc_get_order($subscription->order_id);
                ?>
                <tr class="woocommerce-orders-table__row order">
                    <td class="woocommerce-orders-table__cell" data-title="<?php _e('Subscription', 'bna-smart-payment'); ?>">
                        <div class="bna-subscription-details">
                            <?php if ($product): ?>
                                <strong><?php echo esc_html($product->get_name()); ?></strong><br>
                                <small><?php echo esc_html($manager->get_frequency_label($subscription->frequency)); ?></small>
                            <?php else: ?>
                                <strong><?php _e('Product not found', 'bna-smart-payment'); ?></strong>
                            <?php endif; ?>
                            <br><small><?php printf(__('Started: %s', 'bna-smart-payment'), date_i18n(get_option('date_format'), strtotime($subscription->created_at))); ?></small>
                        </div>
                    </td>
                    <td class="woocommerce-orders-table__cell" data-title="<?php _e('Status', 'bna-smart-payment'); ?>">
                        <span class="bna-subscription-status <?php echo esc_attr($manager->get_status_class($subscription->status)); ?>">
                            <?php echo esc_html($manager->get_status_label($subscription->status)); ?>
                        </span>
                    </td>
                    <td class="woocommerce-orders-table__cell" data-title="<?php _e('Next Payment', 'bna-smart-payment'); ?>">
                        <?php echo esc_html($manager->format_next_payment_date($subscription->next_payment_date)); ?>
                        <?php if ($subscription->remaining_payments): ?>
                            <br><small><?php printf(__('%d payments remaining', 'bna-smart-payment'), $subscription->remaining_payments); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="woocommerce-orders-table__cell" data-title="<?php _e('Total', 'bna-smart-payment'); ?>">
                        <?php echo wc_price($subscription->amount, array('currency' => $subscription->currency)); ?>
                    </td>
                    <td class="woocommerce-orders-table__cell" data-title="<?php _e('Actions', 'bna-smart-payment'); ?>">
                        <?php 
                        $actions = $manager->get_subscription_actions($subscription);
                        if (!empty($actions)): 
                        ?>
                            <div class="bna-subscription-actions">
                                <?php foreach ($actions as $action_key => $action): ?>
                                    <button 
                                        type="button" 
                                        class="<?php echo esc_attr($action['class']); ?>" 
                                        data-subscription-id="<?php echo esc_attr($subscription->id); ?>" 
                                        data-action="<?php echo esc_attr($action_key); ?>"
                                        <?php echo isset($action['confirm']) && $action['confirm'] ? 'data-confirm="true"' : ''; ?>
                                    >
                                        <?php echo esc_html($action['label']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($original_order): ?>
                            <div class="bna-subscription-links">
                                <a href="<?php echo esc_url($original_order->get_view_order_url()); ?>" class="woocommerce-button button view">
                                    <?php _e('View Original Order', 'bna-smart-payment'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="bna-subscription-info">
            <h3><?php _e('Subscription Management', 'bna-smart-payment'); ?></h3>
            <ul>
                <li><strong><?php _e('Active:', 'bna-smart-payment'); ?></strong> <?php _e('Your subscription is active and payments will be processed automatically.', 'bna-smart-payment'); ?></li>
                <li><strong><?php _e('Paused:', 'bna-smart-payment'); ?></strong> <?php _e('Your subscription is temporarily paused. No payments will be processed until resumed.', 'bna-smart-payment'); ?></li>
                <li><strong><?php _e('Cancelled:', 'bna-smart-payment'); ?></strong> <?php _e('Your subscription has been cancelled and no further payments will be processed.', 'bna-smart-payment'); ?></li>
                <li><strong><?php _e('Expired:', 'bna-smart-payment'); ?></strong> <?php _e('Your subscription has reached its end date or payment limit.', 'bna-smart-payment'); ?></li>
            </ul>
            
            <p class="bna-subscription-note">
                <?php _e('Changes to your subscription may take a few minutes to process. You will receive email confirmations for any changes made.', 'bna-smart-payment'); ?>
            </p>
        </div>

    <?php else: ?>
        <div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
            <div class="bna-no-subscriptions">
                <h3><?php _e('No Subscriptions Found', 'bna-smart-payment'); ?></h3>
                <p><?php _e('You don\'t have any active subscriptions yet.', 'bna-smart-payment'); ?></p>
                <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="woocommerce-button button">
                    <?php _e('Browse Subscription Products', 'bna-smart-payment'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.bna-subscriptions-wrapper {
    margin: 20px 0;
}

.bna-subscription-details strong {
    color: #333;
    font-size: 14px;
}

.bna-subscription-details small {
    color: #666;
    font-size: 12px;
}

.bna-subscription-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.bna-status-active {
    background: #d4edda;
    color: #155724;
}

.bna-status-suspended {
    background: #fff3cd;
    color: #856404;
}

.bna-status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

.bna-status-expired {
    background: #e2e3e5;
    color: #495057;
}

.bna-status-failed {
    background: #f5c6cb;
    color: #721c24;
}

.bna-subscription-actions {
    margin-bottom: 10px;
}

.bna-subscription-actions button {
    margin-right: 5px;
    margin-bottom: 5px;
    font-size: 12px;
    padding: 6px 12px;
}

.bna-subscription-actions button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.bna-subscription-links a {
    font-size: 12px;
    text-decoration: none;
}

.bna-subscription-info {
    margin-top: 30px;
    padding: 20px;
    background: #f9f9f9;
    border: 1px solid #e1e1e1;
    border-radius: 4px;
}

.bna-subscription-info h3 {
    margin-top: 0;
    color: #333;
}

.bna-subscription-info ul {
    margin: 15px 0;
}

.bna-subscription-info li {
    margin-bottom: 8px;
    line-height: 1.5;
}

.bna-subscription-note {
    margin-top: 15px;
    padding: 10px;
    background: #e7f3ff;
    border-left: 4px solid #0073aa;
    font-size: 13px;
    color: #666;
}

.bna-no-subscriptions {
    text-align: center;
    padding: 40px 20px;
}

.bna-no-subscriptions h3 {
    margin-bottom: 15px;
    color: #333;
}

.bna-no-subscriptions p {
    margin-bottom: 20px;
    color: #666;
}

@media (max-width: 768px) {
    .bna-subscription-actions button {
        display: block;
        width: 100%;
        margin-bottom: 8px;
    }
    
    .bna-subscription-actions {
        margin-bottom: 15px;
    }
}
</style>
