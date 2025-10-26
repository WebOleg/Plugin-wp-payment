<?php
/**
 * BNA Payment Approved Email
 *
 * Email sent to customers when their BNA payment is approved.
 *
 * @class       WC_BNA_Payment_Approved_Email
 * @version     1.0.0
 * @package     WC_BNA_Gateway/Emails
 * @extends     WC_Email
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'WC_BNA_Payment_Approved_Email' ) ) :

/**
 * BNA Payment Approved Email Class
 */
class WC_BNA_Payment_Approved_Email extends WC_Email {

    /**
     * Transaction data from BNA webhook
     *
     * @var array
     */
    public $transaction_data;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id             = 'bna_payment_approved';
        $this->customer_email = true;
        $this->title          = __( 'BNA Payment Approved', 'wc-bna-gateway' );
        $this->description    = __( 'Email sent to customers when their BNA payment is successfully approved.', 'wc-bna-gateway' );
        $this->template_html  = 'emails/bna-payment-approved.php';
        $this->template_plain = 'emails/plain/bna-payment-approved.php';
        $this->template_base  = plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'templates/';
        $this->placeholders   = array(
            '{order_date}'   => '',
            '{order_number}' => '',
        );

        // Triggers for this email
        add_action( 'wc_bna_payment_approved_notification', array( $this, 'trigger' ), 10, 2 );

        // Call parent constructor
        parent::__construct();
    }

    /**
     * Get email subject.
     *
     * @return string
     */
    public function get_default_subject() {
        return __( 'Your payment for order #{order_number} has been approved', 'wc-bna-gateway' );
    }

    /**
     * Get email heading.
     *
     * @return string
     */
    public function get_default_heading() {
        return __( 'Payment Approved', 'wc-bna-gateway' );
    }

    /**
     * Trigger the sending of this email.
     *
     * @param int   $order_id The order ID.
     * @param array $transaction_data Transaction data from BNA webhook.
     */
    public function trigger( $order_id, $transaction_data = array() ) {
        $this->setup_locale();

        if ( $order_id && ! is_a( $order_id, 'WC_Order' ) ) {
            $this->object = wc_get_order( $order_id );
        } else {
            $this->object = $order_id;
        }

        if ( ! is_a( $this->object, 'WC_Order' ) ) {
            $this->restore_locale();
            return;
        }

        // Set transaction data
        $this->transaction_data = $transaction_data;

        // Set recipient
        $this->recipient = $this->object->get_billing_email();

        // Set placeholders
        $this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
        $this->placeholders['{order_number}'] = $this->object->get_order_number();

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->send( 
                $this->get_recipient(), 
                $this->get_subject(), 
                $this->get_content(), 
                $this->get_headers(), 
                $this->get_attachments() 
            );
        }

        $this->restore_locale();
    }

    /**
     * Get content html.
     *
     * @return string
     */
    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'transaction_data'   => $this->transaction_data,
                'sent_to_admin'      => false,
                'plain_text'         => false,
                'email'              => $this,
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Get content plain.
     *
     * @return string
     */
    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order'              => $this->object,
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'transaction_data'   => $this->transaction_data,
                'sent_to_admin'      => false,
                'plain_text'         => true,
                'email'              => $this,
            ),
            '',
            $this->template_base
        );
    }

    /**
     * Initialize settings form fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'    => array(
                'title'   => __( 'Enable/Disable', 'wc-bna-gateway' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this email notification', 'wc-bna-gateway' ),
                'default' => 'yes',
            ),
            'subject'    => array(
                'title'       => __( 'Subject', 'wc-bna-gateway' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => sprintf( __( 'Available placeholders: %s', 'wc-bna-gateway' ), '<code>{order_date}, {order_number}</code>' ),
                'placeholder' => $this->get_default_subject(),
                'default'     => '',
            ),
            'heading'    => array(
                'title'       => __( 'Email heading', 'wc-bna-gateway' ),
                'type'        => 'text',
                'desc_tip'    => true,
                'description' => sprintf( __( 'Available placeholders: %s', 'wc-bna-gateway' ), '<code>{order_date}, {order_number}</code>' ),
                'placeholder' => $this->get_default_heading(),
                'default'     => '',
            ),
            'additional_content' => array(
                'title'       => __( 'Additional content', 'wc-bna-gateway' ),
                'description' => __( 'Text to appear below the main email content.', 'wc-bna-gateway' ),
                'css'         => 'width:400px; height: 75px;',
                'placeholder' => __( 'N/A', 'wc-bna-gateway' ),
                'type'        => 'textarea',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'email_type' => array(
                'title'       => __( 'Email type', 'wc-bna-gateway' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'wc-bna-gateway' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
            ),
        );
    }
}

endif;

return new WC_BNA_Payment_Approved_Email();
