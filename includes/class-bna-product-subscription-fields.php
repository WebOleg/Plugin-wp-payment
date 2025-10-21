<?php
if (!defined('ABSPATH')) {
    exit;
}

class BNA_Product_Subscription_Fields {

    private static $instance = null;

    const FREQUENCIES = array(
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'biweekly' => 'Bi-Weekly (Every 2 weeks)',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly (Every 3 months)',
        'semiannual' => 'Semi-Annual (Every 6 months)',
        'annual' => 'Annual (Yearly)'
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_subscription_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_subscription_fields'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('woocommerce_single_product_summary', array($this, 'display_subscription_info'), 25);
    }

    public function add_subscription_fields() {
        global $post;

        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';

        if (!$subscriptions_enabled) {
            return;
        }

        echo '<div class="options_group bna_subscription_options">';

        woocommerce_wp_checkbox(array(
            'id' => '_bna_is_subscription',
            'label' => __('Enable Subscription', 'bna-smart-payment'),
            'description' => __('Check this to make this product a subscription.', 'bna-smart-payment'),
            'desc_tip' => true,
        ));

        $current_is_subscription = get_post_meta($post->ID, '_bna_is_subscription', true) === 'yes';
        $fields_style = $current_is_subscription ? '' : 'style="display: none;"';

        echo '<div class="bna_subscription_fields" ' . $fields_style . '>';

        woocommerce_wp_select(array(
            'id' => '_bna_subscription_frequency',
            'label' => __('Billing Frequency', 'bna-smart-payment'),
            'description' => __('How often should the customer be billed?', 'bna-smart-payment'),
            'desc_tip' => true,
            'options' => self::FREQUENCIES,
            'value' => get_post_meta($post->ID, '_bna_subscription_frequency', true) ?: 'monthly'
        ));

        woocommerce_wp_select(array(
            'id' => '_bna_subscription_length_type',
            'label' => __('Subscription Length', 'bna-smart-payment'),
            'description' => __('Set the duration of the subscription.', 'bna-smart-payment'),
            'desc_tip' => true,
            'options' => array(
                'unlimited' => __('Unlimited (until cancelled)', 'bna-smart-payment'),
                'limited' => __('Limited number of payments', 'bna-smart-payment')
            ),
            'value' => get_post_meta($post->ID, '_bna_subscription_length_type', true) ?: 'unlimited'
        ));

        $current_length_type = get_post_meta($post->ID, '_bna_subscription_length_type', true) ?: 'unlimited';
        $num_payments_style = $current_length_type === 'limited' ? '' : 'style="display: none;"';

        echo '<p class="form-field _bna_subscription_num_payments_field" ' . $num_payments_style . '>';
        woocommerce_wp_text_input(array(
            'id' => '_bna_subscription_num_payments',
            'label' => __('Number of Payments', 'bna-smart-payment'),
            'description' => __('Total number of payments before subscription ends.', 'bna-smart-payment'),
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '1'
            ),
            'value' => get_post_meta($post->ID, '_bna_subscription_num_payments', true) ?: '12'
        ));
        echo '</p>';

        // === TRIAL PERIOD FIELDS - MINIMAL DESIGN ===
        echo '<div class="bna_trial_period_section">';
        echo '<h4>' . __('Trial Period Settings', 'bna-smart-payment') . '</h4>';

        woocommerce_wp_checkbox(array(
            'id' => '_bna_enable_trial',
            'label' => __('Enable Trial Period', 'bna-smart-payment'),
            'description' => __('Offer a free trial period before the first payment.', 'bna-smart-payment'),
            'desc_tip' => true,
        ));

        $current_enable_trial = get_post_meta($post->ID, '_bna_enable_trial', true) === 'yes';
        $trial_fields_style = $current_enable_trial ? '' : 'style="display: none;"';

        echo '<div class="bna_trial_fields" ' . $trial_fields_style . '>';

        woocommerce_wp_text_input(array(
            'id' => '_bna_trial_length',
            'label' => __('Trial Length (days)', 'bna-smart-payment'),
            'description' => __('Number of days for the free trial period. First payment will be charged after this period.', 'bna-smart-payment'),
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array(
                'step' => '1',
                'min' => '1',
                'max' => '365'
            ),
            'value' => get_post_meta($post->ID, '_bna_trial_length', true) ?: '7',
            'placeholder' => '7'
        ));

        echo '<p class="description" style="margin-left: 150px; margin-top: -10px; color: #666;">';
        echo __('Example: Set 7 days for a one-week free trial. Customer will be charged on day 8.', 'bna-smart-payment');
        echo '</p>';

        echo '</div>'; // .bna_trial_fields
        echo '</div>'; // .bna_trial_period_section
        // === END TRIAL PERIOD FIELDS ===

        echo '</div>'; // .bna_subscription_fields
        echo '</div>'; // .options_group
    }

    public function save_subscription_fields($post_id) {
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            delete_post_meta($post_id, '_bna_is_subscription');
            delete_post_meta($post_id, '_bna_subscription_frequency');
            delete_post_meta($post_id, '_bna_subscription_length_type');
            delete_post_meta($post_id, '_bna_subscription_num_payments');
            delete_post_meta($post_id, '_bna_enable_trial');
            delete_post_meta($post_id, '_bna_trial_length');
            return;
        }

        $is_subscription = isset($_POST['_bna_is_subscription']) ? 'yes' : 'no';
        update_post_meta($post_id, '_bna_is_subscription', $is_subscription);

        if ($is_subscription === 'yes') {
            // Save frequency
            if (isset($_POST['_bna_subscription_frequency'])) {
                $frequency = sanitize_text_field($_POST['_bna_subscription_frequency']);
                if (array_key_exists($frequency, self::FREQUENCIES)) {
                    update_post_meta($post_id, '_bna_subscription_frequency', $frequency);
                } else {
                    update_post_meta($post_id, '_bna_subscription_frequency', 'monthly');
                }
            }

            // Save length type and num payments
            if (isset($_POST['_bna_subscription_length_type'])) {
                $length_type = sanitize_text_field($_POST['_bna_subscription_length_type']);
                update_post_meta($post_id, '_bna_subscription_length_type', $length_type);

                if ($length_type === 'limited' && isset($_POST['_bna_subscription_num_payments'])) {
                    $num_payments = absint($_POST['_bna_subscription_num_payments']);
                    if ($num_payments > 0) {
                        update_post_meta($post_id, '_bna_subscription_num_payments', $num_payments);
                    } else {
                        update_post_meta($post_id, '_bna_subscription_num_payments', 12);
                    }
                } else {
                    delete_post_meta($post_id, '_bna_subscription_num_payments');
                }
            }

            // === SAVE TRIAL PERIOD ===
            $enable_trial = isset($_POST['_bna_enable_trial']) ? 'yes' : 'no';
            update_post_meta($post_id, '_bna_enable_trial', $enable_trial);

            if ($enable_trial === 'yes' && isset($_POST['_bna_trial_length'])) {
                $trial_length = absint($_POST['_bna_trial_length']);
                if ($trial_length > 0 && $trial_length <= 365) {
                    update_post_meta($post_id, '_bna_trial_length', $trial_length);
                } else {
                    update_post_meta($post_id, '_bna_trial_length', 7);
                }
            } else {
                delete_post_meta($post_id, '_bna_trial_length');
            }
            // === END SAVE TRIAL PERIOD ===

        } else {
            delete_post_meta($post_id, '_bna_subscription_frequency');
            delete_post_meta($post_id, '_bna_subscription_length_type');
            delete_post_meta($post_id, '_bna_subscription_num_payments');
            delete_post_meta($post_id, '_bna_enable_trial');
            delete_post_meta($post_id, '_bna_trial_length');
        }

        bna_debug('Subscription fields saved', array(
            'product_id' => $post_id,
            'is_subscription' => $is_subscription,
            'enable_trial' => isset($_POST['_bna_enable_trial']) ? 'yes' : 'no',
            'trial_length' => isset($_POST['_bna_trial_length']) ? absint($_POST['_bna_trial_length']) : 0,
            'subscriptions_enabled' => $subscriptions_enabled
        ));
    }

    public function admin_enqueue_scripts($hook) {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }

        $screen = get_current_screen();
        if ($screen->id !== 'product') {
            return;
        }

        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            return;
        }

        wp_enqueue_script(
            'bna-admin-subscription-fields',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/js/admin-subscription-fields.js',
            array('jquery'),
            BNA_SMART_PAYMENT_VERSION,
            true
        );

        wp_enqueue_style(
            'bna-admin-subscription',
            BNA_SMART_PAYMENT_PLUGIN_URL . 'assets/css/admin-subscription.css',
            array(),
            BNA_SMART_PAYMENT_VERSION
        );
    }

    public function display_subscription_info() {
        global $product;

        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            return;
        }

        if (!$this->is_subscription_product($product)) {
            return;
        }

        $frequency = get_post_meta($product->get_id(), '_bna_subscription_frequency', true);
        $length_type = get_post_meta($product->get_id(), '_bna_subscription_length_type', true);
        $num_payments = get_post_meta($product->get_id(), '_bna_subscription_num_payments', true);
        
        // === TRIAL PERIOD DISPLAY ===
        $enable_trial = get_post_meta($product->get_id(), '_bna_enable_trial', true) === 'yes';
        $trial_length = absint(get_post_meta($product->get_id(), '_bna_trial_length', true));
        // === END TRIAL PERIOD DISPLAY ===

        echo '<div class="bna-subscription-info">';

        echo '<p class="subscription-type">';
        echo '<strong>' . __('Subscription Product', 'bna-smart-payment') . '</strong>';
        echo '</p>';

        // === DISPLAY TRIAL INFO - MINIMAL ===
        if ($enable_trial && $trial_length > 0) {
            echo '<p class="subscription-trial" style="color: #666; font-weight: 500;">';
            printf(
                _n('%d day free trial', '%d days free trial', $trial_length, 'bna-smart-payment'), 
                $trial_length
            );
            echo '</p>';
        }
        // === END DISPLAY TRIAL INFO ===

        if ($frequency && isset(self::FREQUENCIES[$frequency])) {
            echo '<p class="subscription-frequency">';
            echo '<strong>' . __('Billing:', 'bna-smart-payment') . '</strong> ';
            echo esc_html(self::FREQUENCIES[$frequency]);
            echo '</p>';
        }

        if ($length_type === 'limited' && $num_payments > 0) {
            echo '<p class="subscription-length">';
            echo '<strong>' . __('Duration:', 'bna-smart-payment') . '</strong> ';
            printf(_n('%d payment', '%d payments', $num_payments, 'bna-smart-payment'), $num_payments);
            echo '</p>';
        } else {
            echo '<p class="subscription-length">';
            echo '<strong>' . __('Duration:', 'bna-smart-payment') . '</strong> ';
            echo __('Unlimited (until cancelled)', 'bna-smart-payment');
            echo '</p>';
        }

        echo '</div>';
    }

    public static function is_subscription_product($product) {
        if (is_string($product)) {
            if (is_numeric($product)) {
                $product = wc_get_product($product);
            } else {
                $post = get_page_by_path($product, OBJECT, 'product');
                if ($post) {
                    $product = wc_get_product($post->ID);
                } else {
                    return false;
                }
            }
        } elseif (is_numeric($product)) {
            $product = wc_get_product($product);
        }

        if (!$product || !is_a($product, 'WC_Product')) {
            return false;
        }

        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';
        if (!$subscriptions_enabled) {
            return false;
        }

        return get_post_meta($product->get_id(), '_bna_is_subscription', true) === 'yes';
    }

    public static function get_subscription_data($product_id) {
        $subscriptions_enabled = get_option('bna_smart_payment_enable_subscriptions', 'no') === 'yes';

        if (!$subscriptions_enabled) {
            return array(
                'is_subscription' => false,
                'frequency' => 'monthly',
                'length_type' => 'unlimited',
                'num_payments' => 0,
                'enable_trial' => false,
                'trial_length' => 0
            );
        }

        $frequency = get_post_meta($product_id, '_bna_subscription_frequency', true) ?: 'monthly';

        if (!array_key_exists($frequency, self::FREQUENCIES)) {
            $frequency = 'monthly';
        }

        $length_type = get_post_meta($product_id, '_bna_subscription_length_type', true) ?: 'unlimited';
        $num_payments = absint(get_post_meta($product_id, '_bna_subscription_num_payments', true));
        
        // === TRIAL PERIOD DATA ===
        $enable_trial = get_post_meta($product_id, '_bna_enable_trial', true) === 'yes';
        $trial_length = absint(get_post_meta($product_id, '_bna_trial_length', true));
        // === END TRIAL PERIOD DATA ===

        return array(
            'is_subscription' => get_post_meta($product_id, '_bna_is_subscription', true) === 'yes',
            'frequency' => $frequency,
            'length_type' => $length_type,
            'num_payments' => $num_payments,
            'enable_trial' => $enable_trial,
            'trial_length' => $trial_length
        );
    }

    public static function get_frequency_label($frequency) {
        return self::FREQUENCIES[$frequency] ?? $frequency;
    }

    public static function get_frequencies() {
        return self::FREQUENCIES;
    }
}

BNA_Product_Subscription_Fields::get_instance();
