<?php
/**
 * My Account Subscriptions Template
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
    <div class="bna-subscriptions-header" style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #0073aa;">
        <h2 style="margin: 0; color: #0073aa;">
            <?php _e('My Subscriptions', 'bna-smart-payment'); ?>
            <?php if ($subscription_count > 0) : ?>
                <span style="background: #0073aa; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; margin-left: 10px;">
                    <?php echo $subscription_count; ?>
                </span>
            <?php endif; ?>
        </h2>
    </div>

    <?php if (empty($subscriptions)) : ?>
        <div class="bna-no-subscriptions" style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">
            <div style="font-size: 48px; margin-bottom: 20px;">📦</div>
            <h3 style="color: #6c757d; margin-bottom: 15px;">
                <?php _e('No Subscriptions Yet', 'bna-smart-payment'); ?>
            </h3>
            <p style="color: #6c757d;">
                <?php _e('You don\'t have any subscription services yet.', 'bna-smart-payment'); ?>
            </p>
        </div>

    <?php else : ?>
        <div class="bna-subscriptions-list">
            <?php foreach ($subscriptions as $subscription) :
                $status = $subscription['status'] ?? 'new';
                $status_class = 'status-' . esc_attr($status);
                $status_color = BNA_My_Account::get_subscription_status_color($status);
                $status_label = BNA_My_Account::get_subscription_status_label($status);
                $order_id = $subscription['order_id'] ?? $subscription['id'];
                $subscription_id = $subscription['subscription_id'] ?? $subscription['id'];

                $next_payment = 'N/A';
                if (!empty($subscription['next_payment'])) {
                    $next_payment = date('M j, Y', strtotime($subscription['next_payment']));
                }

                $created_date = 'N/A';
                if (isset($subscription['created_date'])) {
                    if (is_object($subscription['created_date'])) {
                        $created_date = $subscription['created_date']->format('M j, Y');
                    } else {
                        $created_date = date('M j, Y', strtotime($subscription['created_date']));
                    }
                }

                $items = $subscription['items'] ?? array();
                $first_item = !empty($items) ? reset($items) : null;
                $frequency = 'monthly';
                $frequency_label = 'Monthly';

                if ($first_item && isset($first_item['subscription_data']['frequency'])) {
                    $frequency = $first_item['subscription_data']['frequency'];
                    $frequency_label = BNA_Subscriptions::get_frequency_label($frequency);
                }
                ?>

                <div class="subscription-item" style="background: white; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" data-order-id="<?php echo esc_attr($order_id); ?>" data-subscription-id="<?php echo esc_attr($subscription_id); ?>">
                    <div class="subscription-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div>
                            <h4 style="margin: 0 0 5px 0;">
                                <?php echo sprintf(__('Subscription #%s', 'bna-smart-payment'), $subscription['id']); ?>
                            </h4>
                            <span class="status-badge" style="background: <?php echo esc_attr($status_color); ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </div>
                        <div class="subscription-total" style="text-align: right;">
                            <strong style="font-size: 18px; color: #0073aa;">
                                <?php
                                if (isset($subscription['currency'])) {
                                    echo wc_price($subscription['total'], array('currency' => $subscription['currency']));
                                } else {
                                    echo wc_price($subscription['total']);
                                }
                                ?>
                            </strong>
                            <br>
                            <small style="color: #6c757d;"><?php echo esc_html($frequency_label); ?></small>
                        </div>
                    </div>

                    <div class="subscription-details" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <strong><?php _e('Next Payment:', 'bna-smart-payment'); ?></strong><br>
                            <span style="color: #6c757d;"><?php echo esc_html($next_payment); ?></span>
                        </div>
                        <div>
                            <strong><?php _e('Created:', 'bna-smart-payment'); ?></strong><br>
                            <span style="color: #6c757d;"><?php echo esc_html($created_date); ?></span>
                        </div>
                    </div>

                    <?php if (!empty($items)) : ?>
                        <div class="subscription-items" style="margin-bottom: 20px;">
                            <h5 style="margin: 0 0 10px 0; color: #333;"><?php _e('Items:', 'bna-smart-payment'); ?></h5>
                            <?php foreach ($items as $item) : ?>
                                <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; margin-bottom: 5px;">
                                    <strong><?php echo esc_html($item['product_name'] ?? 'Unknown Product'); ?></strong>
                                    <?php if (isset($item['quantity']) && $item['quantity'] > 1) : ?>
                                        × <?php echo esc_html($item['quantity']); ?>
                                    <?php endif; ?>
                                    <?php if (isset($item['total'])) : ?>
                                        <span style="float: right; color: #0073aa;">
                                            <?php echo wc_price($item['total']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="subscription-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php if (in_array($status, array('active', 'new')) && BNA_My_Account::is_subscription_action_allowed($status, 'suspend')) : ?>
                            <button type="button" class="button bna-subscription-action" data-action="suspend" data-order-id="<?php echo esc_attr($order_id); ?>" data-subscription-id="<?php echo esc_attr($subscription_id); ?>" style="background: #ffc107; color: #212529; border-color: #ffc107;">
                                <?php _e('Pause', 'bna-smart-payment'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if ($status === 'suspended' && BNA_My_Account::is_subscription_action_allowed($status, 'resume')) : ?>
                            <button type="button" class="button bna-subscription-action" data-action="resume" data-order-id="<?php echo esc_attr($order_id); ?>" data-subscription-id="<?php echo esc_attr($subscription_id); ?>" style="background: #28a745; color: white; border-color: #28a745;">
                                <?php _e('Resume', 'bna-smart-payment'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array($status, array('active', 'suspended', 'new')) && BNA_My_Account::is_subscription_action_allowed($status, 'cancel')) : ?>
                            <button type="button" class="button bna-subscription-action" data-action="cancel" data-order-id="<?php echo esc_attr($order_id); ?>" data-subscription-id="<?php echo esc_attr($subscription_id); ?>" style="background: #dc3545; color: white; border-color: #dc3545;">
                                <?php _e('Cancel', 'bna-smart-payment'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if ($status === 'cancelled') : ?>
                            <button type="button" class="button bna-subscription-action" data-action="delete" data-order-id="<?php echo esc_attr($order_id); ?>" data-subscription-id="<?php echo esc_attr($subscription_id); ?>" style="background: #6c757d; color: white; border-color: #6c757d;">
                                <?php _e('Delete Permanently', 'bna-smart-payment'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if (in_array($status, array('failed', 'expired')) && BNA_My_Account::is_subscription_action_allowed($status, 'reactivate')) : ?>
                            <button type="button" class="button bna-subscription-action" data-action="reactivate" data-order-id="<?php echo esc_attr($order_id); ?>" data-subscription-id="<?php echo esc_attr($subscription_id); ?>" style="background: #17a2b8; color: white; border-color: #17a2b8;">
                                <?php _e('Reactivate', 'bna-smart-payment'); ?>
                            </button>
                        <?php endif; ?>

                        <?php if ($status !== 'deleted') : ?>
                            <button type="button" class="button bna-subscription-action" data-action="resend_notification" data-order-id="<?php echo esc_attr($order_id); ?>" data-subscription-id="<?php echo esc_attr($subscription_id); ?>" style="background: #28a745; color: white; border-color: #28a745;">
                                <?php _e('Resend Notification', 'bna-smart-payment'); ?>
                            </button>
                        <?php endif; ?>

                        <button type="button" class="button bna-view-subscription-details" data-order-id="<?php echo esc_attr($order_id); ?>">
                            <?php _e('View Details', 'bna-smart-payment'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="subscription-legend" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;">
            <h4><?php _e('Status Legend:', 'bna-smart-payment'); ?></h4>
            <div style="display: flex; flex-wrap: wrap; gap: 15px;">
                <?php foreach (BNA_Subscriptions::get_statuses() as $status_key => $status_name) : ?>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <span class="status-badge" style="background: <?php echo esc_attr(BNA_My_Account::get_subscription_status_color($status_key)); ?>; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">
                            <?php echo esc_html(BNA_My_Account::get_subscription_status_label($status_key)); ?>
                        </span>
                        <small><?php echo esc_html($status_name); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    @media (max-width: 768px) {
        .subscription-details {
            grid-template-columns: 1fr !important;
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