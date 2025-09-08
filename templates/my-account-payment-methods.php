<?php
if (!defined('ABSPATH')) exit;

$my_account = BNA_My_Account::get_instance();
?>

<div class="bna-payment-methods">
    <h3><?php _e('Saved Payment Methods', 'bna-smart-payment'); ?></h3>
    
    <?php if (empty($payment_methods)): ?>
        <div class="bna-no-methods">
            <p><?php _e('You have no saved payment methods.', 'bna-smart-payment'); ?></p>
            <p><?php _e('Payment methods will be automatically saved when you make a purchase.', 'bna-smart-payment'); ?></p>
        </div>
    <?php else: ?>
        <div class="bna-methods-list">
            <?php foreach ($payment_methods as $method): ?>
                <div class="bna-payment-method" data-method-id="<?php echo esc_attr($method['id']); ?>">
                    <div class="bna-method-info">
                        <span class="bna-method-icon">
                            <?php echo $my_account->get_payment_method_icon($method); ?>
                        </span>
                        <div class="bna-method-details">
                            <strong class="bna-method-name">
                                <?php echo esc_html($my_account->get_payment_method_display_name($method)); ?>
                            </strong>
                            <small class="bna-method-date">
                                <?php 
                                printf(
                                    __('Added on %s', 'bna-smart-payment'), 
                                    date_i18n(get_option('date_format'), strtotime($method['created_at']))
                                ); 
                                ?>
                            </small>
                        </div>
                    </div>
                    <div class="bna-method-actions">
                        <button 
                            type="button" 
                            class="button bna-delete-method" 
                            data-method-id="<?php echo esc_attr($method['id']); ?>"
                        >
                            <?php _e('Delete', 'bna-smart-payment'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="bna-methods-info">
            <p><small><?php _e('Payment methods are securely stored and can be used for future purchases.', 'bna-smart-payment'); ?></small></p>
        </div>
    <?php endif; ?>
</div>

<style>
.bna-payment-methods {
    margin: 20px 0;
}

.bna-no-methods {
    text-align: center;
    padding: 40px 20px;
    background: #f8f9fa;
    border-radius: 8px;
    color: #666;
}

.bna-methods-list {
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.bna-payment-method {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    background: white;
}

.bna-payment-method:last-child {
    border-bottom: none;
}

.bna-method-info {
    display: flex;
    align-items: center;
    flex: 1;
}

.bna-method-icon {
    font-size: 24px;
    margin-right: 15px;
}

.bna-method-details {
    display: flex;
    flex-direction: column;
}

.bna-method-name {
    font-size: 16px;
    color: #333;
    margin-bottom: 4px;
}

.bna-method-date {
    color: #666;
    font-size: 13px;
}

.bna-method-actions {
    margin-left: 15px;
}

.bna-delete-method {
    background: #dc3232;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}

.bna-delete-method:hover {
    background: #c02d2d;
}

.bna-delete-method:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.bna-methods-info {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

@media (max-width: 600px) {
    .bna-payment-method {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .bna-method-actions {
        margin-left: 0;
        width: 100%;
    }
    
    .bna-delete-method {
        width: 100%;
    }
}
</style>
