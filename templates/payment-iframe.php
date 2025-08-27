<?php
if (!defined('ABSPATH')) exit;

if ($order->is_paid()) {
    wp_safe_redirect($order->get_checkout_order_received_url());
    exit;
}
?>

<script>
window.bnaPaymentData = {
    thankYouUrl: '<?php echo esc_js($order->get_checkout_order_received_url()); ?>',
    orderId: <?php echo $order->get_id(); ?>
};
</script>

<div class="bna-payment-container">
    <div class="bna-payment-header">
        <h2>Complete Your Payment</h2>
        <div class="bna-order-summary">
            <p><strong>Order:</strong> #<?php echo esc_html($order->get_order_number()); ?></p>
            <p><strong>Total:</strong> <?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></p>
        </div>
    </div>

    <!-- Loading State -->
    <div id="bna-payment-loading" class="bna-loading">
        <div class="bna-spinner"></div>
        <p>Loading secure payment form...</p>
    </div>

    <!-- Error State -->
    <div id="bna-payment-error" class="bna-error" style="display: none;">
        <div class="bna-error-icon">⚠️</div>
        <h3>Payment Form Error</h3>
        <p id="bna-error-message">Unable to load payment form.</p>
        <div class="bna-error-actions">
            <button onclick="window.location.reload()" class="bna-btn bna-btn-primary">Refresh Page</button>
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn bna-btn-secondary">Back to Checkout</a>
        </div>
    </div>

    <!-- Payment iFrame -->
    <div id="bna-iframe-container">
        <iframe
                id="bna-payment-iframe"
                src="<?php echo esc_url($iframe_url); ?>"
                width="100%"
                height="600"
                frameborder="0"
                style="border: 1px solid #ddd; border-radius: 8px;"
                allow="payment"
                title="BNA Payment Form">
        </iframe>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var iframe = document.getElementById('bna-payment-iframe');
    var loading = document.getElementById('bna-payment-loading');
    var container = document.getElementById('bna-iframe-container');
    
    if (iframe) {
        iframe.addEventListener('load', function() {
            loading.style.display = 'none';
            container.style.display = 'block';
        });
    }
});
</script>
