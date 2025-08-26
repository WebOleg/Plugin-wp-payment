<?php
/**
 * BNA Smart Payment Admin Settings
 * 
 * Handles admin panel settings and configuration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * BNA Admin Settings Class
 * 
 * Manages admin interface and settings
 */
class BNA_Admin_Settings {
    
    /**
     * Constructor
     * 
     * Initialize admin hooks and settings
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_filter('plugin_action_links_' . BNA_PLUGIN_BASENAME, array($this, 'add_action_links'));
    }
    
    /**
     * Add admin menu
     * 
     * Create admin menu item for plugin settings
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('BNA Smart Payment Settings', 'bna-smart-payment'),
            __('BNA Payment', 'bna-smart-payment'),
            'manage_woocommerce',
            'bna-smart-payment',
            array($this, 'admin_page_content')
        );
    }
    
    /**
     * Initialize admin settings
     * 
     * Register settings sections and fields
     */
    public function init_settings() {
        register_setting('bna_settings_group', 'bna_smart_payment_options');
        
        // Main settings section
        add_settings_section(
            'bna_main_section',
            __('Main Settings', 'bna-smart-payment'),
            array($this, 'main_section_callback'),
            'bna-smart-payment'
        );
        
        // API Settings section
        add_settings_section(
            'bna_api_section',
            __('API Configuration', 'bna-smart-payment'),
            array($this, 'api_section_callback'),
            'bna-smart-payment'
        );
        
        // iFrame Settings section
        add_settings_section(
            'bna_iframe_section',
            __('iFrame Settings', 'bna-smart-payment'),
            array($this, 'iframe_section_callback'),
            'bna-smart-payment'
        );
    }
    
    /**
     * Main settings section callback
     * 
     * Display main section description
     */
    public function main_section_callback() {
        echo '<p>' . __('Configure main BNA Smart Payment settings.', 'bna-smart-payment') . '</p>';
    }
    
    /**
     * API settings section callback
     * 
     * Display API section description
     */
    public function api_section_callback() {
        echo '<p>' . __('Enter your BNA Smart Payment API credentials. You can find these in your BNA merchant portal.', 'bna-smart-payment') . '</p>';
    }
    
    /**
     * iFrame settings section callback
     * 
     * Display iframe section description
     */
    public function iframe_section_callback() {
        echo '<p>' . __('Configure iFrame integration settings.', 'bna-smart-payment') . '</p>';
    }
    
    /**
     * Admin page content
     * 
     * Display admin settings page
     */
    public function admin_page_content() {
        ?>
        <div class="wrap">
            <h1><?php _e('BNA Smart Payment Settings', 'bna-smart-payment'); ?></h1>
            
            <?php settings_errors(); ?>
            
            <nav class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active"><?php _e('General', 'bna-smart-payment'); ?></a>
                <a href="#api" class="nav-tab"><?php _e('API Settings', 'bna-smart-payment'); ?></a>
                <a href="#iframe" class="nav-tab"><?php _e('iFrame', 'bna-smart-payment'); ?></a>
                <a href="#webhooks" class="nav-tab"><?php _e('Webhooks', 'bna-smart-payment'); ?></a>
            </nav>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('bna_settings_group');
                
                // Get current gateway settings
                $gateway_settings = get_option('woocommerce_bna_gateway_settings', array());
                ?>
                
                <div id="general" class="tab-content">
                    <h2><?php _e('General Settings', 'bna-smart-payment'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Environment', 'bna-smart-payment'); ?></th>
                            <td>
                                <select name="woocommerce_bna_gateway_environment" id="bna_environment">
                                    <option value="development" <?php selected(isset($gateway_settings['environment']) ? $gateway_settings['environment'] : 'staging', 'development'); ?>>
                                        <?php _e('Development', 'bna-smart-payment'); ?>
                                    </option>
                                    <option value="staging" <?php selected(isset($gateway_settings['environment']) ? $gateway_settings['environment'] : 'staging', 'staging'); ?>>
                                        <?php _e('Staging', 'bna-smart-payment'); ?>
                                    </option>
                                    <option value="production" <?php selected(isset($gateway_settings['environment']) ? $gateway_settings['environment'] : 'staging', 'production'); ?>>
                                        <?php _e('Production', 'bna-smart-payment'); ?>
                                    </option>
                                </select>
                                <p class="description"><?php _e('Select the BNA Smart Payment environment to use.', 'bna-smart-payment'); ?></p>
                                
                                <div id="environment-urls" style="margin-top: 10px;">
                                    <p><strong><?php _e('Current API URL:', 'bna-smart-payment'); ?></strong> <span id="current-api-url"></span></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Payment Gateway Status', 'bna-smart-payment'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="woocommerce_bna_gateway_enabled" value="yes" 
                                           <?php checked(isset($gateway_settings['enabled']) ? $gateway_settings['enabled'] : 'no', 'yes'); ?>>
                                    <?php _e('Enable BNA Smart Payment Gateway', 'bna-smart-payment'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="api" class="tab-content" style="display: none;">
                    <h2><?php _e('API Configuration', 'bna-smart-payment'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="bna_access_key"><?php _e('Access Key', 'bna-smart-payment'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="bna_access_key" name="woocommerce_bna_gateway_access_key" 
                                       value="<?php echo esc_attr(isset($gateway_settings['access_key']) ? $gateway_settings['access_key'] : ''); ?>" 
                                       class="regular-text">
                                <p class="description"><?php _e('Enter your BNA Smart Payment Access Key.', 'bna-smart-payment'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="bna_secret_key"><?php _e('Secret Key', 'bna-smart-payment'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="bna_secret_key" name="woocommerce_bna_gateway_secret_key" 
                                       value="<?php echo esc_attr(isset($gateway_settings['secret_key']) ? $gateway_settings['secret_key'] : ''); ?>" 
                                       class="regular-text">
                                <p class="description"><?php _e('Enter your BNA Smart Payment Secret Key.', 'bna-smart-payment'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('API Connection Test', 'bna-smart-payment'); ?></th>
                            <td>
                                <button type="button" id="test-api-connection" class="button button-secondary">
                                    <?php _e('Test Connection', 'bna-smart-payment'); ?>
                                </button>
                                <div id="api-test-result" style="margin-top: 10px;"></div>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="iframe" class="tab-content" style="display: none;">
                    <h2><?php _e('iFrame Configuration', 'bna-smart-payment'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="bna_iframe_id"><?php _e('iFrame ID', 'bna-smart-payment'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="bna_iframe_id" name="woocommerce_bna_gateway_iframe_id" 
                                       value="<?php echo esc_attr(isset($gateway_settings['iframe_id']) ? $gateway_settings['iframe_id'] : ''); ?>" 
                                       class="regular-text">
                                <p class="description"><?php _e('Enter your BNA Smart Payment iFrame ID from the merchant portal.', 'bna-smart-payment'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Processing Fees', 'bna-smart-payment'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="woocommerce_bna_gateway_apply_fees" value="yes" 
                                           <?php checked(isset($gateway_settings['apply_fees']) ? $gateway_settings['apply_fees'] : 'no', 'yes'); ?>>
                                    <?php _e('Apply BNA Smart Payment processing fees to customer', 'bna-smart-payment'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div id="webhooks" class="tab-content" style="display: none;">
                    <h2><?php _e('Webhook Configuration', 'bna-smart-payment'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Webhook URL', 'bna-smart-payment'); ?></th>
                            <td>
                                <input type="text" readonly value="<?php echo esc_url(WC()->api->get_endpoint('bna_webhook')); ?>" class="regular-text">
                                <p class="description"><?php _e('Use this URL in your BNA merchant portal webhook settings.', 'bna-smart-payment'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Debug Logging', 'bna-smart-payment'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="woocommerce_bna_gateway_debug" value="yes" 
                                           <?php checked(isset($gateway_settings['debug']) ? $gateway_settings['debug'] : 'no', 'yes'); ?>>
                                    <?php _e('Enable debug logging', 'bna-smart-payment'); ?>
                                </label>
                                <p class="description"><?php _e('Log BNA Smart Payment events for debugging purposes.', 'bna-smart-payment'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <style>
            .nav-tab-wrapper { margin-bottom: 20px; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            #api-test-result.success { color: #46b450; }
            #api-test-result.error { color: #dc3232; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.tab-content').hide();
                $(target).show();
            });
            
            // Environment URL display
            function updateApiUrl() {
                var env = $('#bna_environment').val();
                var urls = {
                    'development': 'https://dev-api-service.bnasmartpayment.com',
                    'staging': 'https://stage-api-service.bnasmartpayment.com',
                    'production': 'https://api.bnasmartpayment.com'
                };
                $('#current-api-url').text(urls[env]);
            }
            
            $('#bna_environment').change(updateApiUrl);
            updateApiUrl();
            
            // API connection test
            $('#test-api-connection').click(function() {
                var button = $(this);
                var result = $('#api-test-result');
                
                button.prop('disabled', true).text('<?php _e('Testing...', 'bna-smart-payment'); ?>');
                result.removeClass('success error').text('');
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'bna_test_api_connection',
                        access_key: $('#bna_access_key').val(),
                        secret_key: $('#bna_secret_key').val(),
                        environment: $('#bna_environment').val(),
                        nonce: '<?php echo wp_create_nonce('bna_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            result.addClass('success').text('<?php _e('Connection successful!', 'bna-smart-payment'); ?>');
                        } else {
                            result.addClass('error').text(response.data || '<?php _e('Connection failed.', 'bna-smart-payment'); ?>');
                        }
                    },
                    error: function() {
                        result.addClass('error').text('<?php _e('Connection test failed.', 'bna-smart-payment'); ?>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php _e('Test Connection', 'bna-smart-payment'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Enqueue admin scripts
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'bna-smart-payment') !== false) {
            wp_enqueue_script('jquery');
        }
    }
    
    /**
     * Add action links
     * 
     * Add settings link to plugin actions
     * 
     * @param array $links Existing action links
     * @return array Modified action links
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=bna-smart-payment') . '">' . __('Settings', 'bna-smart-payment') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

// Initialize admin settings
if (is_admin()) {
    new BNA_Admin_Settings();
}
