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

<!-- Pause Subscription Modal -->
<div id="bna-pause-subscription-modal" class="bna-modal" style="display: none;">
    <div class="bna-modal-overlay"></div>
    <div class="bna-modal-content">
        <div class="bna-modal-header">
            <h3><?php _e('Pause reason', 'bna-smart-payment'); ?></h3>
            <button type="button" class="bna-pause-modal-close" aria-label="<?php esc_attr_e('Close', 'bna-smart-payment'); ?>">×</button>
        </div>
        <div class="bna-modal-body">
            <form id="bna-pause-subscription-form">
                <div class="bna-pause-reasons">
                    <label class="bna-pause-reason-option">
                        <input type="radio" name="pause_reason" value="pause_temporarily" checked>
                        <span><?php _e('I want to pause temporarily.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-pause-reason-option">
                        <input type="radio" name="pause_reason" value="review_budget">
                        <span><?php _e('I need to review my budget.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-pause-reason-option">
                        <input type="radio" name="pause_reason" value="service_quality">
                        <span><?php _e('Service quality issues.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-pause-reason-option">
                        <input type="radio" name="pause_reason" value="not_using">
                        <span><?php _e('I\'m not using it right now.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-pause-reason-option">
                        <input type="radio" name="pause_reason" value="technical_problems">
                        <span><?php _e('Technical problems.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-pause-reason-option">
                        <input type="radio" name="pause_reason" value="other">
                        <span><?php _e('Other (Fill in the reason in the message box below).', 'bna-smart-payment'); ?></span>
                    </label>
                </div>

                <div class="bna-pause-reason-other" style="display: none; margin-top: 15px;">
                    <label for="bna-pause-reason-text" style="display: block; margin-bottom: 5px; font-weight: 600; color: #6c757d;">
                        <?php _e('Reason', 'bna-smart-payment'); ?>
                    </label>
                    <textarea 
                        id="bna-pause-reason-text" 
                        rows="4" 
                        placeholder="<?php esc_attr_e('Please enter your reason for pausing this recurring payment', 'bna-smart-payment'); ?>"
                        style="width: 100%; padding: 10px; border: 1px solid #dee2e6; border-radius: 4px; font-family: inherit; resize: vertical;"
                    ></textarea>
                </div>
            </form>
        </div>
        <div class="bna-modal-footer">
            <button type="button" id="bna-pause-modal-cancel" class="button">
                <?php _e('Cancel', 'bna-smart-payment'); ?>
            </button>
            <button type="button" id="bna-pause-modal-submit" class="button button-primary">
                <?php _e('Submit', 'bna-smart-payment'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Delete Subscription Modal -->
<div id="bna-delete-subscription-modal" class="bna-modal" style="display: none;">
    <div class="bna-modal-overlay"></div>
    <div class="bna-modal-content">
        <div class="bna-modal-header">
            <h3><?php _e('Delete reason', 'bna-smart-payment'); ?></h3>
            <button type="button" class="bna-delete-modal-close" aria-label="<?php esc_attr_e('Close', 'bna-smart-payment'); ?>">×</button>
        </div>
        <div class="bna-modal-body">
            <form id="bna-delete-subscription-form">
                <div class="bna-delete-reasons">
                    <label class="bna-delete-reason-option">
                        <input type="radio" name="delete_reason" value="already_paid" checked>
                        <span><?php _e('I already paid.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-delete-reason-option">
                        <input type="radio" name="delete_reason" value="disagree_request">
                        <span><?php _e('I disagree with the request.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-delete-reason-option">
                        <input type="radio" name="delete_reason" value="disagree_amount">
                        <span><?php _e('I disagree with the amount.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-delete-reason-option">
                        <input type="radio" name="delete_reason" value="unknown_requestor">
                        <span><?php _e('I don\'t know the requestor.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-delete-reason-option">
                        <input type="radio" name="delete_reason" value="not_for_me">
                        <span><?php _e('The request for money is not for me.', 'bna-smart-payment'); ?></span>
                    </label>

                    <label class="bna-delete-reason-option">
                        <input type="radio" name="delete_reason" value="other">
                        <span><?php _e('Other (Fill in the reason in the message box below).', 'bna-smart-payment'); ?></span>
                    </label>
                </div>

                <div class="bna-delete-reason-other" style="display: none; margin-top: 15px;">
                    <label for="bna-delete-reason-text" style="display: block; margin-bottom: 5px; font-weight: 600; color: #6c757d;">
                        <?php _e('Reason', 'bna-smart-payment'); ?>
                    </label>
                    <textarea 
                        id="bna-delete-reason-text" 
                        rows="4" 
                        placeholder="<?php esc_attr_e('Please enter your reason for deleting this recurring payment', 'bna-smart-payment'); ?>"
                        style="width: 100%; padding: 10px; border: 1px solid #dee2e6; border-radius: 4px; font-family: inherit; resize: vertical;"
                    ></textarea>
                </div>
            </form>
        </div>
        <div class="bna-modal-footer">
            <button type="button" id="bna-delete-modal-cancel" class="button">
                <?php _e('Cancel', 'bna-smart-payment'); ?>
            </button>
            <button type="button" id="bna-delete-modal-submit" class="button button-primary">
                <?php _e('Submit', 'bna-smart-payment'); ?>
            </button>
        </div>
    </div>
</div>

<style>
    /* Subscription Items Responsive */
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

    /* Modal Styles (shared for both Pause and Delete modals) */
    .bna-modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .bna-modal-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
    }

    .bna-modal-content {
        position: relative;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        max-width: 500px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        z-index: 1;
    }

    .bna-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 24px;
        border-bottom: 1px solid #dee2e6;
    }

    .bna-modal-header h3 {
        margin: 0;
        font-size: 20px;
        font-weight: 600;
        color: #212529;
    }

    .bna-delete-modal-close,
    .bna-pause-modal-close {
        background: none;
        border: none;
        font-size: 32px;
        line-height: 1;
        color: #6c757d;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .bna-delete-modal-close:hover,
    .bna-pause-modal-close:hover {
        background: #f8f9fa;
        color: #212529;
    }

    .bna-modal-body {
        padding: 24px;
    }

    .bna-delete-reasons,
    .bna-pause-reasons {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .bna-delete-reason-option,
    .bna-pause-reason-option {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
        margin: 0;
    }

    .bna-delete-reason-option:hover,
    .bna-pause-reason-option:hover {
        background: #f8f9fa;
        border-color: #0073aa;
    }

    .bna-delete-reason-option input[type="radio"],
    .bna-pause-reason-option input[type="radio"] {
        margin: 2px 0 0 0;
        cursor: pointer;
        width: 18px;
        height: 18px;
        flex-shrink: 0;
    }

    .bna-delete-reason-option input[type="radio"]:checked,
    .bna-pause-reason-option input[type="radio"]:checked {
        accent-color: #0073aa;
    }

    .bna-delete-reason-option span,
    .bna-pause-reason-option span {
        flex: 1;
        line-height: 1.5;
        color: #212529;
        font-size: 14px;
    }

    .bna-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 20px 24px;
        border-top: 1px solid #dee2e6;
        background: #f8f9fa;
        border-radius: 0 0 8px 8px;
    }

    .bna-modal-footer .button {
        margin: 0;
        padding: 10px 24px;
        font-size: 14px;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s;
    }

    #bna-delete-modal-cancel,
    #bna-pause-modal-cancel {
        background: white;
        color: #6c757d;
        border: 1px solid #dee2e6;
    }

    #bna-delete-modal-cancel:hover,
    #bna-pause-modal-cancel:hover {
        background: #f8f9fa;
        border-color: #6c757d;
    }

    #bna-delete-modal-submit,
    #bna-pause-modal-submit {
        background: #0073aa;
        color: white;
        border: 1px solid #0073aa;
    }

    #bna-delete-modal-submit:hover,
    #bna-pause-modal-submit:hover {
        background: #005a87;
        border-color: #005a87;
    }

    /* Mobile Responsive */
    @media (max-width: 480px) {
        .bna-modal {
            padding: 10px;
        }

        .bna-modal-content {
            max-height: 95vh;
        }

        .bna-modal-header,
        .bna-modal-body,
        .bna-modal-footer {
            padding: 16px;
        }

        .bna-modal-footer {
            flex-direction: column;
        }

        .bna-modal-footer .button {
            width: 100%;
            text-align: center;
        }
    }
</style>
