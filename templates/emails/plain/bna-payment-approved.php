<?php
/**
 * BNA Payment Approved Email (Plain Text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/bna-payment-approved.php.
 *
 * @package WC_BNA_Gateway/Templates/Emails/Plain
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

echo "= " . $email_heading . " =\n\n";

echo esc_html__( 'Thank you for your payment. Your order has been received and your payment has been successfully approved by BNA Smart Payment.', 'wc-bna-gateway' ) . "\n\n";

if ( ! empty( $transaction_data ) ) {
    echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
    echo esc_html__( 'PAYMENT DETAILS', 'wc-bna-gateway' ) . "\n\n";
    
    if ( ! empty( $transaction_data['id'] ) ) {
        echo esc_html__( 'Transaction ID:', 'wc-bna-gateway' ) . ' ' . esc_html( $transaction_data['id'] ) . "\n";
    }
    
    if ( ! empty( $transaction_data['paymentMethod'] ) ) {
        echo esc_html__( 'Payment Method:', 'wc-bna-gateway' ) . ' ' . esc_html( $transaction_data['paymentMethod'] ) . "\n";
    }
    
    if ( ! empty( $transaction_data['amount'] ) ) {
        $currency = ! empty( $transaction_data['currency'] ) ? $transaction_data['currency'] : 'CAD';
        echo esc_html__( 'Amount Paid:', 'wc-bna-gateway' ) . ' ' . esc_html( $currency . ' ' . number_format( $transaction_data['amount'], 2 ) ) . "\n";
    }
    
    if ( ! empty( $transaction_data['transactionTime'] ) ) {
        echo esc_html__( 'Payment Date:', 'wc-bna-gateway' ) . ' ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction_data['transactionTime'] ) ) ) . "\n";
    }
    
    if ( ! empty( $transaction_data['status'] ) ) {
        echo esc_html__( 'Status:', 'wc-bna-gateway' ) . ' ' . esc_html( $transaction_data['status'] ) . "\n";
    }
    
    echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo "\n\n";

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 1.0.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
    echo "\n\n";
}

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
