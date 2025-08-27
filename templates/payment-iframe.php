<?php
/**
 * BNA Smart Payment iFrame Template with proper referer handling
 */

if (!defined('ABSPATH')) {
    exit;
}

BNA_Logger::info('Loading iframe template', [
    'order_id' => $order->get_id(),
    'iframe_url' => $iframe_url,
    'current_domain' => $_SERVER['HTTP_HOST'] ?? 'unknown',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
]);
?>

<div class="bna-payment-container">
    <div class="bna-payment-header">
        <h2>Complete Your Payment</h2>
        <div class="bna-order-summary">
            <p><strong>Order:</strong> #<?php echo esc_html($order->get_order_number()); ?></p>
            <p><strong>Total:</strong> <?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></p>
        </div>
    </div>

    <!-- Debug Info -->
    <?php if (get_option('bna_debug_enabled', false)): ?>
    <div style="background: #f0f8ff; padding: 10px; margin: 10px 0; border: 1px solid #0073aa; font-size: 12px;">
        <strong>Debug Info:</strong><br>
        iFrame URL: <?php echo esc_html($iframe_url); ?><br>
        Current Domain: <?php echo esc_html($_SERVER['HTTP_HOST'] ?? 'unknown'); ?><br>
        Full URL: <?php echo esc_html(home_url($_SERVER['REQUEST_URI'] ?? '')); ?><br>
        Order ID: <?php echo $order->get_id(); ?>
    </div>
    <?php endif; ?>

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

    <!-- Payment iFrame with explicit referer settings -->
    <div id="bna-iframe-container" style="display: none;">
        <iframe
                id="bna-payment-iframe"
                src="<?php echo esc_url($iframe_url); ?>"
                width="100%"
                height="600"
                frameborder="0"
                style="border: 1px solid #ddd; border-radius: 8px;"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="payment"
                sandbox="allow-same-origin allow-scripts allow-forms allow-top-navigation"
                title="Secure Payment Form">
            <p>Your browser does not support iframes.</p>
        </iframe>
    </div>
</div>

<style>
.bna-payment-container { max-width: 800px; margin: 0 auto; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.bna-payment-header { text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #f0f0f0; }
.bna-loading { text-align: center; padding: 60px 20px; background: #f9f9f9; border: 2px solid #e1e1e1; border-radius: 8px; margin: 20px 0; }
.bna-spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 20px; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.bna-error { text-align: center; padding: 40px 20px; background: #fef7f7; border: 2px solid #e74c3c; border-radius: 8px; margin: 20px 0; }
.bna-error-icon { font-size: 48px; margin-bottom: 15px; }
.bna-btn { display: inline-block; padding: 12px 24px; margin: 0 10px; text-decoration: none; border-radius: 6px; font-weight: 500; cursor: pointer; border: none; font-size: 16px; transition: all 0.3s ease; }
.bna-btn-primary { background: #0073aa; color: white; }
.bna-btn-secondary { background: #666; color: white; }
#bna-iframe-container { margin: 20px 0; }
@media (max-width: 768px) {
    .bna-payment-container { padding: 15px; }
    #bna-payment-iframe { height: 500px; }
    .bna-btn { display: block; margin: 10px 0; width: 100%; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('BNA Payment Debug Info:');
    console.log('Current URL:', window.location.href);
    console.log('Current Domain:', window.location.hostname);
    console.log('Protocol:', window.location.protocol);
    console.log('Referer Policy:', document.referrerPolicy);
    console.log('Document Referrer:', document.referrer);
    
    const iframe = document.getElementById('bna-payment-iframe');
    const loading = document.getElementById('bna-payment-loading');
    const error = document.getElementById('bna-payment-error');
    const container = document.getElementById('bna-iframe-container');

    if (iframe) {
        // Set explicit referrer policy on iframe
        iframe.referrerPolicy = 'strict-origin-when-cross-origin';
        
        let loadTimeout = setTimeout(function() {
            console.error('BNA Payment: iFrame loading timeout');
            loading.style.display = 'none';
            error.style.display = 'block';
            document.getElementById('bna-error-message').textContent = 
                'Payment form loading failed. This may be due to domain configuration issues.';
        }, 15000);

        iframe.addEventListener('load', function() {
            console.log('BNA Payment: iFrame loaded successfully');
            clearTimeout(loadTimeout);
            loading.style.display = 'none';
            container.style.display = 'block';
        });

        iframe.addEventListener('error', function() {
            console.error('BNA Payment: iFrame failed to load');
            clearTimeout(loadTimeout);
            loading.style.display = 'none';
            error.style.display = 'block';
            document.getElementById('bna-error-message').textContent = 
                'Payment form failed to load. Please check domain whitelist configuration.';
        });

        console.log('BNA Payment: iframe setup completed');
    }
});
</script>
