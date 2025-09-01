<div class="container" style="margin: 20px auto; max-width: 1200px; padding: 0 20px;">
    <script>
        window.bnaPaymentData = {
            thankYouUrl: '<?php echo esc_js($order->get_checkout_order_received_url()); ?>',
            orderId: <?php echo $order->get_id(); ?>
        };
    </script>

    <div class="bna-payment-container">
        <div class="bna-payment-header">
            <h2><?php _e('Complete Your Payment', 'bna-smart-payment'); ?></h2>
            <div class="bna-order-summary">
                <p><strong><?php _e('Order:', 'bna-smart-payment'); ?></strong> #<?php echo esc_html($order->get_order_number()); ?></p>
                <p><strong><?php _e('Total:', 'bna-smart-payment'); ?></strong> <?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></p>
            </div>
        </div>

        <div id="bna-payment-loading" class="bna-loading">
            <div class="bna-spinner"></div>
            <p><?php _e('Loading secure payment form...', 'bna-smart-payment'); ?></p>
        </div>

        <div id="bna-payment-error" class="bna-error" style="display: none;">
            <div class="bna-error-icon">⚠️</div>
            <h3><?php _e('Payment Form Error', 'bna-smart-payment'); ?></h3>
            <p id="bna-error-message"><?php _e('Unable to load payment form.', 'bna-smart-payment'); ?></p>
            <div class="bna-error-actions">
                <button onclick="window.location.reload()" class="bna-btn bna-btn-primary">
                    <?php _e('Refresh Page', 'bna-smart-payment'); ?>
                </button>
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn bna-btn-secondary">
                    <?php _e('Back to Checkout', 'bna-smart-payment'); ?>
                </a>
            </div>
        </div>

        <div id="bna-iframe-container">
            <iframe
                id="bna-payment-iframe"
                src="<?php echo esc_url($iframe_url); ?>"
                width="100%"
                height="600"
                frameborder="0"
                style="border: 1px solid #ddd; border-radius: 8px;"
                allow="payment"
                title="<?php esc_attr_e('BNA Payment Form', 'bna-smart-payment'); ?>">
            </iframe>
        </div>
    </div>
</div>
