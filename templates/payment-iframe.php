<?php
/**
 * BNA Smart Payment iFrame Template
 *
 * @var WC_Order $order
 * @var string $iframe_url
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="bna-payment-container">
    <div class="bna-payment-header">
        <h2><?php esc_html_e('Complete Your Payment', 'bna-smart-payment'); ?></h2>

        <div class="bna-order-summary">
            <p>
                <strong><?php esc_html_e('Order:', 'bna-smart-payment'); ?></strong>
                #<?php echo esc_html($order->get_order_number()); ?>
            </p>
            <p>
                <strong><?php esc_html_e('Total:', 'bna-smart-payment'); ?></strong>
                <?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?>
            </p>
        </div>
    </div>

    <!-- Loading State -->
    <div id="bna-payment-loading" class="bna-loading">
        <div class="bna-spinner"></div>
        <p><?php esc_html_e('Loading secure payment form...', 'bna-smart-payment'); ?></p>
    </div>

    <!-- Error State -->
    <div id="bna-payment-error" class="bna-error" style="display: none;">
        <div class="bna-error-icon">⚠️</div>
        <h3><?php esc_html_e('Payment Form Error', 'bna-smart-payment'); ?></h3>
        <p id="bna-error-message">
            <?php esc_html_e('Unable to load payment form. Please refresh the page or contact support.', 'bna-smart-payment'); ?>
        </p>
        <div class="bna-error-actions">
            <button onclick="window.location.reload()" class="bna-btn bna-btn-primary">
                <?php esc_html_e('Refresh Page', 'bna-smart-payment'); ?>
            </button>
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn bna-btn-secondary">
                <?php esc_html_e('Back to Checkout', 'bna-smart-payment'); ?>
            </a>
        </div>
    </div>

    <!-- Payment iFrame -->
    <div id="bna-iframe-container" style="display: none;">
        <iframe
                id="bna-payment-iframe"
                src="<?php echo esc_url($iframe_url); ?>"
                width="100%"
                height="600"
                frameborder="0"
                style="border: 1px solid #ddd; border-radius: 8px;"
                title="<?php esc_attr_e('Secure Payment Form', 'bna-smart-payment'); ?>">
            <p><?php esc_html_e('Your browser does not support iframes. Please update your browser.', 'bna-smart-payment'); ?></p>
        </iframe>
    </div>

    <!-- Footer Info -->
    <div class="bna-payment-footer">
        <p>
            <small>
                🔒 <?php esc_html_e('Your payment information is secure and encrypted.', 'bna-smart-payment'); ?>
            </small>
        </p>
    </div>
</div>

<style>
    .bna-payment-container {
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }

    .bna-payment-header {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #f0f0f0;
    }

    .bna-payment-header h2 {
        margin: 0 0 15px 0;
        color: #333;
        font-size: 28px;
    }

    .bna-order-summary {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        display: inline-block;
    }

    .bna-order-summary p {
        margin: 5px 0;
        font-size: 16px;
    }

    .bna-loading {
        text-align: center;
        padding: 60px 20px;
        background: #f9f9f9;
        border: 2px solid #e1e1e1;
        border-radius: 8px;
        margin: 20px 0;
    }

    .bna-spinner {
        width: 40px;
        height: 40px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #0073aa;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .bna-error {
        text-align: center;
        padding: 40px 20px;
        background: #fef7f7;
        border: 2px solid #e74c3c;
        border-radius: 8px;
        margin: 20px 0;
    }

    .bna-error-icon {
        font-size: 48px;
        margin-bottom: 15px;
    }

    .bna-error h3 {
        color: #e74c3c;
        margin: 0 0 15px 0;
    }

    .bna-error-actions {
        margin-top: 25px;
    }

    .bna-btn {
        display: inline-block;
        padding: 12px 24px;
        margin: 0 10px;
        text-decoration: none;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        font-size: 16px;
        transition: all 0.3s ease;
    }

    .bna-btn-primary {
        background: #0073aa;
        color: white;
    }

    .bna-btn-primary:hover {
        background: #005177;
    }

    .bna-btn-secondary {
        background: #666;
        color: white;
    }

    .bna-btn-secondary:hover {
        background: #444;
    }

    .bna-payment-footer {
        text-align: center;
        margin-top: 30px;
        color: #666;
    }

    #bna-iframe-container {
        margin: 20px 0;
    }

    @media (max-width: 768px) {
        .bna-payment-container {
            padding: 15px;
        }

        .bna-payment-header h2 {
            font-size: 24px;
        }

        #bna-payment-iframe {
            height: 500px;
        }

        .bna-btn {
            display: block;
            margin: 10px 0;
            width: 100%;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const iframe = document.getElementById('bna-payment-iframe');
        const loading = document.getElementById('bna-payment-loading');
        const error = document.getElementById('bna-payment-error');
        const container = document.getElementById('bna-iframe-container');

        let loadTimeout;

        if (iframe) {
            // Set loading timeout (15 seconds)
            loadTimeout = setTimeout(function() {
                loading.style.display = 'none';
                error.style.display = 'block';
                document.getElementById('bna-error-message').textContent =
                    '<?php esc_html_e("Payment form loading timed out. Please refresh the page.", "bna-smart-payment"); ?>';
            }, 15000);

            // Handle successful load
            iframe.addEventListener('load', function() {
                clearTimeout(loadTimeout);
                loading.style.display = 'none';
                container.style.display = 'block';
            });

            // Handle load error
            iframe.addEventListener('error', function() {
                clearTimeout(loadTimeout);
                loading.style.display = 'none';
                error.style.display = 'block';
            });
        }
    });
</script>