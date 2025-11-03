<?php
if (!defined('ABSPATH')) exit;

$my_account = BNA_My_Account::get_instance();
?>

<div class="bna-payment-methods">
    <h3><?php _e('Saved Payment Methods', 'bna-smart-payment'); ?></h3>

    <?php if (empty($payment_methods)): ?>
        <div class="bna-no-methods">
            <div class="bna-no-methods-icon">💳</div>
            <h4><?php _e('No Payment Methods Saved', 'bna-smart-payment'); ?></h4>
            <p><?php _e('You have no saved payment methods yet.', 'bna-smart-payment'); ?></p>
            <p><small><?php _e('Payment methods will be automatically saved when you complete a purchase using BNA Smart Payment.', 'bna-smart-payment'); ?></small></p>
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

        <script>
        jQuery(document).ready(function($) {
            // Hide WooCommerce notice when BNA payment methods exist
            $('.woocommerce-MyAccount-content .woocommerce-info').hide();
            $('.woocommerce-MyAccount-content .woocommerce-message').hide();
        });
        </script>
    <?php endif; ?>
</div>
