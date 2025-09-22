<?php
/**
 * BNA Template Loader
 * Handles loading of template files
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Template {

    /**
     * Load template file with variables
     * @param string $template Template filename without .php
     * @param array $vars Variables to pass to template
     * @param bool $return Whether to return output or echo it
     * @return string|void
     */
    public static function load($template, $vars = array(), $return = false) {
        $template_path = self::get_template_path($template);
        
        if (!file_exists($template_path)) {
            bna_error('Template not found', array('template' => $template, 'path' => $template_path));
            return $return ? '' : null;
        }

        if ($return) {
            ob_start();
        }

        extract($vars, EXTR_SKIP);
        include $template_path;

        if ($return) {
            return ob_get_clean();
        }
    }

    /**
     * Get full path to template file
     * @param string $template Template filename without .php
     * @return string
     */
    public static function get_template_path($template) {
        $template = str_replace('.php', '', $template);
        return BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/' . $template . '.php';
    }

    /**
     * Check if template exists
     * @param string $template Template filename without .php
     * @return bool
     */
    public static function template_exists($template) {
        return file_exists(self::get_template_path($template));
    }

    /**
     * Render payment page with header and footer
     * @param WC_Order $order
     * @param string $iframe_url
     */
    public static function render_payment_page($order, $iframe_url) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        get_header();
        
        self::load('payment-form', array(
            'order' => $order,
            'iframe_url' => $iframe_url
        ));
        
        get_footer();
        exit;
    }

    /**
     * Render admin logs page
     * @param array $data Log data and settings
     */
    public static function render_admin_logs($data) {
        self::load('admin-logs', $data);
    }

    /**
     * Render subscriptions page in My Account (v1.9.0)
     * @param array $subscriptions User subscriptions data
     * @param int $user_id Current user ID
     */
    public static function render_subscriptions_page($subscriptions, $user_id = null) {
        if (null === $user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            echo '<div class="woocommerce-error">';
            echo '<p>' . __('You must be logged in to view subscriptions.', 'bna-smart-payment') . '</p>';
            echo '</div>';
            return;
        }

        if (!BNA_Subscriptions::is_enabled()) {
            echo '<div class="woocommerce-info">';
            echo '<p>' . __('Subscriptions are currently disabled.', 'bna-smart-payment') . '</p>';
            echo '</div>';
            return;
        }

        $template_vars = array(
            'subscriptions' => $subscriptions,
            'user_id' => $user_id,
            'subscription_count' => count($subscriptions)
        );

        bna_debug('Rendering subscriptions template', array(
            'user_id' => $user_id,
            'subscriptions_count' => count($subscriptions),
            'template_vars' => array_keys($template_vars)
        ));

        if (self::template_exists('my-account-subscriptions')) {
            self::load('my-account-subscriptions', $template_vars);
        } else {
            echo '<div class="woocommerce-error">';
            echo '<p>' . __('Subscriptions template not found.', 'bna-smart-payment') . '</p>';
            echo '</div>';

            bna_error('Subscriptions template not found', array(
                'template_path' => self::get_template_path('my-account-subscriptions'),
                'user_id' => $user_id
            ));
        }
    }

    /**
     * Render subscription product fields (v1.9.0)
     * Used in product edit page for subscription settings
     * @param WC_Product|null $product
     */
    public static function render_subscription_product_fields($product = null) {
        if (!BNA_Subscriptions::is_enabled()) {
            return;
        }

        $template_vars = array(
            'product' => $product,
            'frequencies' => BNA_Subscriptions::get_frequencies(),
            'statuses' => BNA_Subscriptions::get_statuses()
        );

        if (self::template_exists('admin-subscription-fields')) {
            self::load('admin-subscription-fields', $template_vars);
        } else {
            bna_debug('Subscription fields template not found, using inline HTML');
            // Fallback handled in BNA_Subscription_Product class
        }
    }

    /**
     * Render subscription info on product page (v1.9.0)
     * Shows subscription details to customers on product pages
     * @param WC_Product $product
     */
    public static function render_subscription_info($product) {
        if (!$product || !BNA_Subscriptions::is_subscription_product($product)) {
            return;
        }

        if (!BNA_Subscriptions::is_enabled()) {
            return;
        }

        $frequency = $product->get_meta('_bna_subscription_frequency', true) ?: 'monthly';
        $signup_fee = $product->get_meta('_bna_signup_fee', true) ?: 0;
        $trial_length = $product->get_meta('_bna_trial_length', true) ?: 0;

        $template_vars = array(
            'product' => $product,
            'frequency' => $frequency,
            'frequency_label' => BNA_Subscriptions::FREQUENCIES[$frequency] ?? ucfirst($frequency),
            'signup_fee' => $signup_fee,
            'trial_length' => $trial_length,
            'price' => $product->get_price()
        );

        if (self::template_exists('product-subscription-info')) {
            self::load('product-subscription-info', $template_vars);
        } else {
            // Fallback inline display handled in BNA_Subscription_Product class
            bna_debug('Product subscription info template not found, using inline HTML');
        }
    }

    /**
     * Get template with error handling (v1.9.0)
     * Safely loads template and returns content or error message
     * @param string $template Template name
     * @param array $vars Template variables
     * @param string $fallback_message Message to show if template not found
     * @return string
     */
    public static function get_template($template, $vars = array(), $fallback_message = '') {
        if (self::template_exists($template)) {
            return self::load($template, $vars, true);
        }

        bna_error('Template not found in get_template', array(
            'template' => $template,
            'path' => self::get_template_path($template)
        ));

        if (!empty($fallback_message)) {
            return '<div class="woocommerce-error"><p>' . esc_html($fallback_message) . '</p></div>';
        }

        return '<div class="woocommerce-error"><p>' . 
               sprintf(__('Template "%s" not found.', 'bna-smart-payment'), esc_html($template)) . 
               '</p></div>';
    }

    /**
     * Include template part (v1.9.0)
     * For including smaller template parts within larger templates
     * @param string $template Template part name
     * @param array $vars Variables to pass
     */
    public static function include_part($template, $vars = array()) {
        $part_path = BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/parts/' . $template . '.php';
        
        if (file_exists($part_path)) {
            extract($vars, EXTR_SKIP);
            include $part_path;
        } else {
            bna_debug('Template part not found', array(
                'part' => $template,
                'path' => $part_path
            ));
        }
    }
}
