<?php
/**
 * BNA Payment Approved Email (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/bna-payment-approved.php.
 *
 * @package WC_BNA_Gateway/Templates/Emails
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php esc_html_e( 'Thank you for your payment. Your order has been received and your payment has been successfully approved by BNA Smart Payment.', 'wc-bna-gateway' ); ?></p>

<?php if ( ! empty( $transaction_data ) ) : ?>
<h2><?php esc_html_e( 'Payment Details', 'wc-bna-gateway' ); ?></h2>
<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 20px;" border="1">
    <tbody>
        <?php if ( ! empty( $transaction_data['id'] ) ) : ?>
        <tr>
            <th class="td" scope="row" style="text-align:left; padding: 12px;"><?php esc_html_e( 'Transaction ID', 'wc-bna-gateway' ); ?></th>
            <td class="td" style="text-align:left; padding: 12px;"><?php echo esc_html( $transaction_data['id'] ); ?></td>
        </tr>
        <?php endif; ?>

        <?php if ( ! empty( $transaction_data['paymentMethod'] ) ) : ?>
        <tr>
            <th class="td" scope="row" style="text-align:left; padding: 12px;"><?php esc_html_e( 'Payment Method', 'wc-bna-gateway' ); ?></th>
            <td class="td" style="text-align:left; padding: 12px;"><?php echo esc_html( $transaction_data['paymentMethod'] ); ?></td>
        </tr>
        <?php endif; ?>

        <?php if ( ! empty( $transaction_data['amount'] ) ) : ?>
        <tr>
            <th class="td" scope="row" style="text-align:left; padding: 12px;"><?php esc_html_e( 'Amount Paid', 'wc-bna-gateway' ); ?></th>
            <td class="td" style="text-align:left; padding: 12px;">
                <?php 
                $currency = ! empty( $transaction_data['currency'] ) ? $transaction_data['currency'] : 'CAD';
                echo esc_html( $currency . ' ' . number_format( $transaction_data['amount'], 2 ) ); 
                ?>
            </td>
        </tr>
        <?php endif; ?>

        <?php if ( ! empty( $transaction_data['transactionTime'] ) ) : ?>
        <tr>
            <th class="td" scope="row" style="text-align:left; padding: 12px;"><?php esc_html_e( 'Payment Date', 'wc-bna-gateway' ); ?></th>
            <td class="td" style="text-align:left; padding: 12px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction_data['transactionTime'] ) ) ); ?></td>
        </tr>
        <?php endif; ?>

        <?php if ( ! empty( $transaction_data['status'] ) ) : ?>
        <tr>
            <th class="td" scope="row" style="text-align:left; padding: 12px;"><?php esc_html_e( 'Status', 'wc-bna-gateway' ); ?></th>
            <td class="td" style="text-align:left; padding: 12px;"><strong style="color: #7ad03a;"><?php echo esc_html( $transaction_data['status'] ); ?></strong></td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
/**
 * Hook to add additional content before order details
 *
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 1.0.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook to add additional content after order details
 *
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook to add customer details
 *
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
?>

<?php
/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
