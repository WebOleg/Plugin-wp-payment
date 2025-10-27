
                <?php
                if (!defined('ABSPATH')) {
                    exit;
                }

                if ($order->is_paid()) {
                    wp_safe_redirect($order->get_checkout_order_received_url());
                    exit;
                }
                ?>




                    <div id="main">
                        <div class="bna-breadcrumbs-wrapper">
                            <nav class="bna-breadcrumbs" aria-label="Checkout progress">
                                <ol class="bna-breadcrumbs-list">
                                    <li class="bna-breadcrumb-item bna-breadcrumb-completed">
                                        <a href="<?php echo esc_url(home_url('/')); ?>">
                                            <span class="bna-breadcrumb-icon">🏠</span>
                                            <span class="bna-breadcrumb-text">Home</span>
                                        </a>
                                    </li>
                                    <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
                                    <li class="bna-breadcrumb-item bna-breadcrumb-completed">
                                        <a href="<?php echo esc_url(wc_get_cart_url()); ?>">
                                            <span class="bna-breadcrumb-icon">🛒</span>
                                            <span class="bna-breadcrumb-text">Cart</span>
                                        </a>
                                    </li>
                                    <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
                                    <li class="bna-breadcrumb-item bna-breadcrumb-completed">
                                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>">
                                            <span class="bna-breadcrumb-icon">📋</span>
                                            <span class="bna-breadcrumb-text">Checkout</span>
                                        </a>
                                    </li>
                                    <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
                                    <li class="bna-breadcrumb-item bna-breadcrumb-active" aria-current="page">
                                        <span class="bna-breadcrumb-icon">💳</span>
                                        <span class="bna-breadcrumb-text">Payment</span>
                                    </li>
                                    <li class="bna-breadcrumb-separator" aria-hidden="true">›</li>
                                    <li class="bna-breadcrumb-item bna-breadcrumb-upcoming">
                                        <span class="bna-breadcrumb-icon">✅</span>
                                        <span class="bna-breadcrumb-text">Confirmation</span>
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
                                <div class="bna-payment-container__aside">
                                <div class="bna-payment-header">
                                    <div class="bna-payment-header-top">
                                        <h2 class="bna-payment-title">Complete Your Payment</h2>
                                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn-edit-order">
                                            <span class="bna-btn-icon" aria-hidden="true">←</span>
                                            <span class="bna-btn-text">Edit Order</span>
                                        </a>
                                    </div>
                                    <div class="bna-order-summary">
                                        <div class="bna-summary-item">
                                            <span class="bna-summary-label">Order Number:</span>
                                            <span class="bna-summary-value">#<?php echo esc_html($order->get_order_number()); ?></span>
                                        </div>
                                        <div class="bna-summary-item">
                                            <span class="bna-summary-label">Order Date:</span>
                                            <span class="bna-summary-value"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></span>
                                        </div>
                                        <div class="bna-summary-item bna-summary-total">
                                            <span class="bna-summary-label">Total:</span>
                                            <span class="bna-summary-value"><?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="bna-order-details-section">
                                    <h3 class="bna-section-title">
                                        <span class="bna-section-icon" aria-hidden="true">📦</span>
                                        <span class="bna-section-text">Order Details</span>
                                    </h3>
                                    <div class="bna-order-items">
                                        <table class="bna-items-table">
                                            <thead>
                                            <tr>
                                                <th class="bna-col-product">Product</th>
                                                <th class="bna-col-quantity">Quantity</th>
                                                <th class="bna-col-price">Price</th>
                                                <th class="bna-col-total">Total</th>
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
                                            <span class="bna-total-label">Subtotal:</span>
                                            <span class="bna-total-value"><?php echo wp_kses_post(wc_price($order->get_subtotal(), array('currency' => $order->get_currency()))); ?></span>
                                        </div>
                                        <div class="bna-total-row bna-total-grand">
                                            <span class="bna-total-label">Total to Pay:</span>
                                            <span class="bna-total-value"><?php echo wp_kses_post(wc_price($order->get_total(), array('currency' => $order->get_currency()))); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="bna-customer-info-section">
                                    <div class="bna-info-columns">
                                        <div class="bna-info-column bna-billing-column">
                                            <h3 class="bna-section-title">
                                                <span class="bna-section-icon" aria-hidden="true">👤</span>
                                                <span class="bna-section-text">Billing Information</span>
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
                                    </div>
                                </div>
                                </div>
                                <div id="bna-payment-loading" class="bna-loading" style="display: none;">
                                    <div class="bna-spinner"></div>
                                    <p>Loading secure payment form...</p>
                                </div>

                                <div id="bna-payment-error" class="bna-error" style="display: none;">
                                    <div class="bna-error-icon">⚠️</div>
                                    <h3>Payment Form Error</h3>
                                    <p id="bna-error-message">Unable to load payment form.</p>
                                    <div class="bna-error-actions">
                                        <button onclick="window.location.reload()" class="bna-btn bna-btn-primary">Refresh Page</button>
                                        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="bna-btn bna-btn-secondary">Back to Checkout</a>
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
                                            title="BNA Payment Form">
                                    </iframe>
                                </div>
                            </div>
                        </div>
                    </div> <!--main-->
