<?php

if (!defined('BNA_TESTING')) {
    define('BNA_TESTING', true);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('bna_log')) {
    function bna_log($message, $data = array()) {}
}

if (!function_exists('bna_error')) {
    function bna_error($message, $data = array()) {}
}

if (!function_exists('bna_debug')) {
    function bna_debug($message, $data = array()) {}
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) {
        return json_encode($data, $flags);
    }
}

require_once __DIR__ . '/../includes/class-bna-webhooks.php';
require_once __DIR__ . '/../includes/class-bna-api.php';
