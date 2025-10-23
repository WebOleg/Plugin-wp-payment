<div class="bna-breadcrumbs-wrapper">
    <nav class="bna-breadcrumbs" aria-label="<?php esc_attr_e('Checkout progress', 'bna-smart-payment'); ?>">
        <ol class="bna-breadcrumbs-list">
            <li class="bna-breadcrumb-item bna-breadcrumb-completed">
                <a href="<?php echo esc_url(home_url('/')); ?>">
                    <span class="bna-breadcrumb-icon">🏠</span>
                    <span class="bna-breadcrumb-text"><?php _e('Home', 'bna-smart-payment'); ?></span>
                </a>
            </li>
            <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
            <li class="bna-breadcrumb-item bna-breadcrumb-completed">
                <a href="<?php echo esc_url(wc_get_cart_url()); ?>">
                    <span class="bna-breadcrumb-icon">🛒</span>
                    <span class="bna-breadcrumb-text"><?php _e('Cart', 'bna-smart-payment'); ?></span>
                </a>
            </li>
            <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
            <li class="bna-breadcrumb-item bna-breadcrumb-completed">
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>">
                    <span class="bna-breadcrumb-icon">📋</span>
                    <span class="bna-breadcrumb-text"><?php _e('Checkout', 'bna-smart-payment'); ?></span>
                </a>
            </li>
            <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
            <li class="bna-breadcrumb-item bna-breadcrumb-active" aria-current="page">
                <span class="bna-breadcrumb-icon">💳</span>
                <span class="bna-breadcrumb-text"><?php _e('Payment', 'bna-smart-payment'); ?></span>
            </li>
            <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
            <li class="bna-breadcrumb-item bna-breadcrumb-upcoming">
                <span class="bna-breadcrumb-icon">✅</span>
                <span class="bna-breadcrumb-text"><?php _e('Confirmation', 'bna-smart-payment'); ?></span>
            </li>
        </ol>
    </nav>
</div>

<div class="container" style="margin: 20px auto; max-width: 1200px; padding: 0 20px;">
    <script>
        window.bnaPaymentData = {
            thankYouUrl: '<?php echo esc_js($order->get_checkout_order_received_url()); ?>',
            orderId: <?php echo $order->get_id(); ?>
        };
    </script>

    <div class="bna-payment-container">
        <div class="bna-payment-header">
            <div class="bna-payment-header-top">
                <h2 class="bna-payment-title"><?php _e('Complete Your Payment', 'bna-smart-payment'); ?></h2>
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn-edit-order">
                    <span class="bna-btn-icon" aria-hidden="true">←</span>
                    <span class="bna-btn-text"><?php _e('Edit Order', 'bna-smart-payment'); ?></span>
                </a>
            </div>
            <div class="bna-order-summary">
                <div class="bna-summary-item">
                    <span class="bna-summary-label"><?php _e('Order Number:', 'bna-smart-payment'); ?></span>
                    <span class="bna-summary-value">#<?php echo esc_html($order->get_order_number()); ?></span>
                </div>
                <div class="bna-summary-item">
                    <span class="bna-summary-label"><?php _e('Order Date:', 'bna-smart-payment'); ?></span>
                    <span class="bna-summary-value"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></span>
                </div>
                <div class="bna-summary-item bna-summary-total">
                    <span class="bna-summary-label"><?php _e('Total:', 'bna-smart-payment'); ?></span>
                    <span class="bna-summary-value"><?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></span>
                </div>
            </div>
        </div>

        <div class="bna-order-details-section">
            <h3 class="bna-section-title">
                <span class="bna-section-icon" aria-hidden="true">📦</span>
                <span class="bna-section-text"><?php _e('Order Details', 'bna-smart-payment'); ?></span>
            </h3>
            <div class="bna-order-items">
                <table class="bna-items-table">
                    <thead>
                        <tr>
                            <th class="bna-col-product"><?php _e('Product', 'bna-smart-payment'); ?></th>
                            <th class="bna-col-quantity"><?php _e('Quantity', 'bna-smart-payment'); ?></th>
                            <th class="bna-col-price"><?php _e('Price', 'bna-smart-payment'); ?></th>
                            <th class="bna-col-total"><?php _e('Total', 'bna-smart-payment'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order->get_items() as $item_id => $item): ?>
                            <?php $product = $item->get_product(); ?>
                            <tr class="bna-item-row">
                                <td class="bna-item-product">
                                    <div class="bna-product-info">
                                        <?php if ($product && $product->get_image_id()): ?>
                                            <img src="<?php echo esc_url(wp_get_attachment_image_url($product->get_image_id(), 'thumbnail')); ?>" 
                                                 alt="<?php echo esc_attr($item->get_name()); ?>"
                                                 class="bna-product-image"
                                                 width="50"
                                                 height="50">
                                        <?php endif; ?>
                                        <span class="bna-product-name"><?php echo esc_html($item->get_name()); ?></span>
                                    </div>
                                </td>
                                <td class="bna-item-quantity">
                                    <span class="bna-quantity-value"><?php echo esc_html($item->get_quantity()); ?></span>
                                </td>
                                <td class="bna-item-price">
                                    <?php echo wp_kses_post(wc_price($order->get_item_total($item, false, false), array('currency' => $order->get_currency()))); ?>
                                </td>
                                <td class="bna-item-total">
                                    <?php echo wp_kses_post(wc_price($order->get_line_total($item, false, false), array('currency' => $order->get_currency()))); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="bna-order-totals">
                <div class="bna-total-row bna-total-subtotal">
                    <span class="bna-total-label"><?php _e('Subtotal:', 'bna-smart-payment'); ?></span>
                    <span class="bna-total-value"><?php echo wp_kses_post(wc_price($order->get_subtotal(), array('currency' => $order->get_currency()))); ?></span>
                </div>
                <?php if ($order->get_shipping_total() > 0): ?>
                <div class="bna-total-row bna-total-shipping">
                    <span class="bna-total-label"><?php _e('Shipping:', 'bna-smart-payment'); ?></span>
                    <span class="bna-total-value"><?php echo wp_kses_post(wc_price($order->get_shipping_total(), array('currency' => $order->get_currency()))); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($order->get_total_tax() > 0): ?>
                <div class="bna-total-row bna-total-tax">
                    <span class="bna-total-label"><?php _e('Tax:', 'bna-smart-payment'); ?></span>
                    <span class="bna-total-value"><?php echo wp_kses_post(wc_price($order->get_total_tax(), array('currency' => $order->get_currency()))); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($order->get_total_discount() > 0): ?>
                <div class="bna-total-row bna-total-discount">
                    <span class="bna-total-label"><?php _e('Discount:', 'bna-smart-payment'); ?></span>
                    <span class="bna-total-value">-<?php echo wp_kses_post(wc_price($order->get_total_discount(), array('currency' => $order->get_currency()))); ?></span>
                </div>
                <?php endif; ?>
                <div class="bna-total-row bna-total-grand">
                    <span class="bna-total-label"><?php _e('Total to Pay:', 'bna-smart-payment'); ?></span>
                    <span class="bna-total-value"><?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></span>
                </div>
            </div>
        </div>

        <div class="bna-customer-info-section">
            <div class="bna-info-columns">
                <div class="bna-info-column bna-billing-column">
                    <h3 class="bna-section-title">
                        <span class="bna-section-icon" aria-hidden="true">👤</span>
                        <span class="bna-section-text"><?php _e('Billing Information', 'bna-smart-payment'); ?></span>
                    </h3>
                    <div class="bna-info-content">
                        <div class="bna-info-item bna-info-name">
                            <span class="bna-customer-name"><?php echo esc_html($order->get_formatted_billing_full_name()); ?></span>
                        </div>
                        <?php if ($order->get_billing_company()): ?>
                        <div class="bna-info-item bna-info-company">
                            <span class="bna-customer-company"><?php echo esc_html($order->get_billing_company()); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="bna-info-item bna-info-email">
                            <span class="bna-info-icon" aria-hidden="true">📧</span>
                            <span class="bna-info-text"><?php echo esc_html($order->get_billing_email()); ?></span>
                        </div>
                        <?php if ($order->get_billing_phone()): ?>
                        <div class="bna-info-item bna-info-phone">
                            <span class="bna-info-icon" aria-hidden="true">📱</span>
                            <span class="bna-info-text"><?php echo esc_html($order->get_billing_phone()); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="bna-info-item bna-info-address">
                            <span class="bna-info-icon" aria-hidden="true">📍</span>
                            <span class="bna-info-text"><?php echo wp_kses_post($order->get_formatted_billing_address()); ?></span>
                        </div>
                    </div>
                </div>
                <?php if ($order->has_shipping_address()): ?>
                <div class="bna-info-column bna-shipping-column">
                    <h3 class="bna-section-title">
                        <span class="bna-section-icon" aria-hidden="true">🚚</span>
                        <span class="bna-section-text"><?php _e('Shipping Address', 'bna-smart-payment'); ?></span>
                    </h3>
                    <div class="bna-info-content">
                        <div class="bna-info-item bna-info-name">
                            <span class="bna-shipping-name"><?php echo esc_html($order->get_formatted_shipping_full_name()); ?></span>
                        </div>
                        <?php if ($order->get_shipping_company()): ?>
                        <div class="bna-info-item bna-info-company">
                            <span class="bna-shipping-company"><?php echo esc_html($order->get_shipping_company()); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="bna-info-item bna-info-address">
                            <span class="bna-info-icon" aria-hidden="true">📍</span>
                            <span class="bna-info-text"><?php echo wp_kses_post($order->get_formatted_shipping_address()); ?></span>
                        </div>
                        <?php if ($order->get_shipping_method()): ?>
                        <div class="bna-info-item bna-info-method">
                            <span class="bna-info-icon" aria-hidden="true">📦</span>
                            <span class="bna-info-label"><?php _e('Method:', 'bna-smart-payment'); ?></span>
                            <span class="bna-info-text"><?php echo esc_html($order->get_shipping_method()); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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
