<?php
/**
 * BNA Template Handler
 * Simple template loading
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Template_Handler {

    /**
     * Load template with data
     */
    public static function load($template_name, $data = array()) {
        $template_path = BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/' . $template_name . '.php';
        
        if (!file_exists($template_path)) {
            echo '<p>Template not found: ' . esc_html($template_name) . '</p>';
            return false;
        }

        if (!empty($data)) {
            extract($data);
        }

        include $template_path;
        return true;
    }

    /**
     * Get template content as string
     */
    public static function get_content($template_name, $data = array()) {
        ob_start();
        self::load($template_name, $data);
        return ob_get_clean();
    }
}
