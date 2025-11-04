<?php
/**
 * Single Product - BNA Subscription Details
 *
 * Shows subscription information on product page
 * 
 * This template can be overridden by copying it to:
 * yourtheme/woocommerce/single-product/bna-subscription-details.php
 *
 * @package BNA_Smart_Payment
 * @version 1.9.0
 * 
 * @var WC_Product $product
 * @var string $frequency
 * @var string $frequency_label
 * @var float $signup_fee
 * @var int $trial_length
 * @var float $price
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!$product) {
    return;
}
?>

<div class="bna-subscription-info">
    <span class="bna-subscription-badge">
        <?php esc_html_e('Subscription', 'bna-smart-payment'); ?>
    </span>
    
    <div class="bna-subscription-details">
        <div class="bna-subscription-frequency">
            <strong><?php esc_html_e('Billing:', 'bna-smart-payment'); ?></strong>
            <?php echo esc_html($frequency_label); ?>
        </div>
        
        <?php if ($signup_fee > 0) : ?>
            <div class="bna-subscription-signup-fee">
                <strong><?php esc_html_e('Sign-up fee:', 'bna-smart-payment'); ?></strong>
                <?php echo wc_price($signup_fee); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($trial_length > 0) : ?>
            <div class="bna-subscription-trial">
                <strong><?php esc_html_e('Free trial:', 'bna-smart-payment'); ?></strong>
                <?php 
                printf(
                    _n('%d day', '%d days', $trial_length, 'bna-smart-payment'),
                    $trial_length
                );
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>
