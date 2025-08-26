<?php
/**
 * Simple Payment iFrame Template
 * 
 * @var WC_Order $order
 * @var string $iframe_url
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="bna-payment-receipt container">
    <h3><?php esc_html_e('Complete Your Payment', 'bna-smart-payment'); ?></h3>
    <p>
        <?php 
        printf(
            esc_html__('Order #%s - %s', 'bna-smart-payment'),
            esc_html($order->get_order_number()),
            wp_kses_post(wc_price($order->get_total()))
        );
        ?>
    </p>
    
    <div id="bna-loading" class="bna-loading">
        <p><?php esc_html_e('Loading payment form...', 'bna-smart-payment'); ?></p>
    </div>
    
    <iframe 
        id="bna-payment-iframe" 
        src="<?php echo esc_url($iframe_url); ?>" 
        width="100%" 
        height="600" 
        frameborder="0"
        style="display: none;">
    </iframe>
    
    <script>
    document.getElementById('bna-payment-iframe').onload = function() {
        document.getElementById('bna-loading').style.display = 'none';
        this.style.display = 'block';
    };
    </script>
</div>
