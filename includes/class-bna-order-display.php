<?php

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Order_Display {

    public static function init() {
        add_filter('woocommerce_my_account_my_orders_columns', array(__CLASS__, 'add_payment_method_column'));
        add_action('woocommerce_my_account_my_orders_column_bna-payment-method', array(__CLASS__, 'render_payment_method_column'));
        
        add_filter('woocommerce_get_order_item_totals', array(__CLASS__, 'add_all_details_to_order_totals'), 10, 2);
        
        add_action('wp_head', array(__CLASS__, 'add_custom_styles'), 999);
        
        // Load our custom templates
        add_filter('woocommerce_locate_template', array(__CLASS__, 'locate_bna_template'), 10, 3);
    }

    public static function locate_bna_template($template, $template_name, $template_path) {
        $plugin_template = BNA_SMART_PAYMENT_PLUGIN_PATH . 'templates/' . $template_name;
        
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
        
        return $template;
    }

    public static function add_custom_styles() {
        if (is_account_page() || is_checkout() || is_wc_endpoint_url('order-received')) {
            ?>
            <style>
                /* Style for separator row */
                .woocommerce-table--order-details tfoot tr.customer-separator th {
                    background-color: #f8f8f8 !important;
                    text-transform: uppercase;
                    font-size: 0.85em;
                    color: #555;
                    letter-spacing: 0.5px;
                    padding: 15px 12px 8px 12px !important;
                    font-weight: 700;
                    border-top: 2px solid #ddd !important;
                }
            </style>
            <?php
        }
    }

    public static function add_payment_method_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            
            if ($key === 'order-status') {
                $new_columns['bna-payment-method'] = __('Payment Method', 'bna-smart-payment');
            }
        }
        
        return $new_columns;
    }

    public static function render_payment_method_column($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }

        if (!$order || $order->get_payment_method() !== 'bna_smart_payment') {
            echo '-';
            return;
        }

        $formatted = self::format_payment_method($order);
        echo esc_html($formatted);
    }

    public static function add_all_details_to_order_totals($total_rows, $order) {
        if ($order->get_payment_method() !== 'bna_smart_payment') {
            return $total_rows;
        }

        $payment_method_formatted = self::format_payment_method($order);
        $transaction_id = $order->get_meta('_bna_transaction_id');

        if ($payment_method_formatted !== 'BNA Smart Payment') {
            $total_rows['payment_method']['value'] = esc_html($payment_method_formatted);
        }

        $new_rows = array();
        
        foreach ($total_rows as $key => $row) {
            $new_rows[$key] = $row;
            
            if ($key === 'payment_method' && !empty($transaction_id)) {
                $new_rows['transaction_id'] = array(
                    'label' => __('Transaction ID:', 'bna-smart-payment'),
                    'value' => '<code style="background-color: #f4f4f4; padding: 4px 8px; border-radius: 3px; font-family: monospace; font-size: 0.9em; word-break: break-all;">' . esc_html($transaction_id) . '</code>'
                );
            }
        }
        
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();
        $billing_address = $order->get_formatted_billing_address();

        $new_rows['customer_separator'] = array(
            'label' => __('Customer Details', 'bna-smart-payment'),
            'value' => '',
            'class' => 'customer-separator'
        );

        if (!empty($billing_email)) {
            $new_rows['customer_email'] = array(
                'label' => __('Email:', 'bna-smart-payment'),
                'value' => esc_html($billing_email)
            );
        }

        if (!empty($billing_phone)) {
            $new_rows['customer_phone'] = array(
                'label' => __('Telephone:', 'bna-smart-payment'),
                'value' => esc_html($billing_phone)
            );
        }

        if (!empty($billing_address)) {
            $new_rows['billing_address'] = array(
                'label' => __('Billing address:', 'bna-smart-payment'),
                'value' => wp_kses_post($billing_address)
            );
        }

        return $new_rows;
    }

    public static function format_payment_method($order) {
        $payment_method_type = $order->get_meta('_bna_payment_method');
        $payment_details_json = $order->get_meta('_bna_payment_details');

        if (empty($payment_method_type)) {
            return 'BNA Smart Payment';
        }

        $payment_details = array();
        if (!empty($payment_details_json)) {
            $payment_details = json_decode($payment_details_json, true);
            if (!is_array($payment_details)) {
                $payment_details = array();
            }
        }

        switch (strtoupper($payment_method_type)) {
            case 'CARD':
                return self::format_card_method($payment_details);

            case 'E_TRANSFER':
                return self::format_e_transfer_method($payment_details);

            case 'EFT':
                return self::format_eft_method($payment_details);

            case 'CHEQUE':
                return self::format_cheque_method($payment_details);

            case 'CASH':
                return __('Cash Payment', 'bna-smart-payment');

            default:
                return 'BNA Smart Payment';
        }
    }

    private static function format_card_method($details) {
        if (empty($details)) {
            return __('Card Payment', 'bna-smart-payment');
        }

        $brand = $details['cardBrand'] ?? '';
        $type = $details['cardType'] ?? '';
        $number = $details['cardNumber'] ?? '';

        $parts = array();

        if (!empty($brand)) {
            $parts[] = $brand;
        }

        if (!empty($type)) {
            $type_lower = strtolower($type);
            if ($type_lower === 'credit') {
                $parts[] = __('Credit', 'bna-smart-payment');
            } elseif ($type_lower === 'debit') {
                $parts[] = __('Debit', 'bna-smart-payment');
            }
        }

        if (!empty($number)) {
            $parts[] = $number;
        }

        if (empty($parts)) {
            return __('Card Payment', 'bna-smart-payment');
        }

        return implode(' ', $parts);
    }

    private static function format_e_transfer_method($details) {
        if (empty($details)) {
            return __('Interac e-Transfer', 'bna-smart-payment');
        }

        $email = $details['email'] ?? '';

        if (!empty($email)) {
            return sprintf(__('Interac e-Transfer - %s', 'bna-smart-payment'), $email);
        }

        return __('Interac e-Transfer', 'bna-smart-payment');
    }

    private static function format_eft_method($details) {
        if (empty($details)) {
            return __('EFT Payment', 'bna-smart-payment');
        }

        $bank_name = $details['bankName'] ?? '';
        $account_number = $details['accountNumber'] ?? '';

        $parts = array();

        if (!empty($bank_name)) {
            $parts[] = $bank_name;
        }

        if (!empty($account_number)) {
            $parts[] = $account_number;
        }

        if (empty($parts)) {
            return __('EFT Payment', 'bna-smart-payment');
        }

        return 'EFT - ' . implode(' ', $parts);
    }

    private static function format_cheque_method($details) {
        if (empty($details)) {
            return __('Cheque Payment', 'bna-smart-payment');
        }

        $cheque_number = $details['chequeNumber'] ?? '';

        if (!empty($cheque_number)) {
            return sprintf(__('Cheque #%s', 'bna-smart-payment'), $cheque_number);
        }

        return __('Cheque Payment', 'bna-smart-payment');
    }
}
