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
}
