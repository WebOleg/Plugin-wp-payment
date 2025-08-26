<?php
/**
 * BNA Smart Payment - Payment iFrame Template
 * 
 * This template displays the payment iframe
 *
 * @package BnaSmartPayment
 * @var WC_Order $order
 * @var string $token
 * @var string $iframe_url
 * @var string $return_url
 * @var string $checkout_url
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="woocommerce">
    <div class="container">
        
        <header class="bna-payment-header">
            <h1><?php _e('Complete Your Payment', 'bna-smart-payment'); ?></h1>
            <p><?php printf(__('Order #%s - Total: %s', 'bna-smart-payment'), $order->get_order_number(), wc_price($order->get_total())); ?></p>
        </header>

        <div class="bna-payment-container">
            
            <!-- Loading state -->
            <div id="bna-payment-loading" class="bna-loading-container">
                <div class="bna-spinner"></div>
                <p><?php _e('Loading secure payment form...', 'bna-smart-payment'); ?></p>
                <small><?php _e('This may take a few seconds', 'bna-smart-payment'); ?></small>
            </div>

            <!-- Error state -->
            <div id="bna-payment-error" class="bna-error-container" style="display: none;">
                <h3><?php _e('Payment Form Unavailable', 'bna-smart-payment'); ?></h3>
                <p id="bna-error-message"><?php _e('Unable to load payment form. Please try again or contact support.', 'bna-smart-payment'); ?></p>
                <div class="bna-error-actions">
                    <a href="<?php echo esc_url($checkout_url); ?>" class="button"><?php _e('Back to Checkout', 'bna-smart-payment'); ?></a>
                </div>
            </div>

            <!-- iFrame container -->
            <div id="bna-iframe-container" class="bna-iframe-container">
                <iframe 
                    id="bna-payment-iframe" 
                    src="<?php echo esc_url($iframe_url); ?>" 
                    frameborder="0" 
                    allowtransparency="true"
                    style="display: none;">
                    <?php _e('Your browser does not support iframes. Please use a different browser or contact support.', 'bna-smart-payment'); ?>
                </iframe>
            </div>

        </div>

        <footer class="bna-payment-footer">
            <div class="bna-security-info">
                <small>
                    <span class="dashicons dashicons-lock"></span>
                    <?php _e('Your payment information is secure and encrypted', 'bna-smart-payment'); ?>
                </small>
            </div>
            
            <div class="bna-support-info">
                <p><small><?php _e('Having trouble? ', 'bna-smart-payment'); ?><a href="<?php echo esc_url($checkout_url); ?>"><?php _e('Return to checkout', 'bna-smart-payment'); ?></a></small></p>
            </div>
        </footer>

    </div>
</div>

<script>
// Simple iframe load handler
document.addEventListener('DOMContentLoaded', function() {
    var iframe = document.getElementById('bna-payment-iframe');
    var loading = document.getElementById('bna-payment-loading');
    var errorContainer = document.getElementById('bna-payment-error');
    
    // Show iframe when loaded
    iframe.onload = function() {
        loading.style.display = 'none';
        iframe.style.display = 'block';
    };
    
    // Handle iframe load timeout
    setTimeout(function() {
        if (loading.style.display !== 'none') {
            loading.style.display = 'none';
            errorContainer.style.display = 'block';
            document.getElementById('bna-error-message').textContent = '<?php _e('Payment form loading timed out. Please refresh the page or try again.', 'bna-smart-payment'); ?>';
        }
    }, 15000);
    
    // Handle iframe errors
    iframe.onerror = function() {
        loading.style.display = 'none';
        errorContainer.style.display = 'block';
    };
});
</script>
