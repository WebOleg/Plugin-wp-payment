<?php
/**
 * BNA Template Loader
 * Handles loading of template files
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Template {

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

    public static function get_template_path($template) {
        $template = str_replace('.php', '', $template);
        return BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/' . $template . '.php';
    }

    public static function template_exists($template) {
        return file_exists(self::get_template_path($template));
    }

    public static function render_payment_page($order, $iframe_url) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        get_header();
        
        wc_get_template(
            'checkout/payment-form.php',
            array(
                'order' => $order,
                'iframe_url' => $iframe_url
            ),
            '',
            BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/'
        );
        
        get_footer();
        exit;
    }

    public static function render_admin_logs($data) {
        self::load('admin-logs', $data);
    }

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
        }
    }

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

        wc_get_template(
            'single-product/bna-subscription-details.php',
            $template_vars,
            '',
            BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/'
        );
    }

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
