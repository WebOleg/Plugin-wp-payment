<?php
/**
 * BNA Simple Debug Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Debug_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'BNA Debug',
            'BNA Debug', 
            'manage_woocommerce',
            'bna-debug',
            [__CLASS__, 'admin_page']
        );
    }

    public static function handle_actions() {
        if (!isset($_GET['bna_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        $action = sanitize_text_field($_GET['bna_action']);
        
        switch ($action) {
            case 'enable':
                BNA_Logger::enable();
                wp_redirect(admin_url('admin.php?page=bna-debug&message=enabled'));
                exit;
                
            case 'disable':
                BNA_Logger::disable();
                wp_redirect(admin_url('admin.php?page=bna-debug&message=disabled'));
                exit;
                
            case 'clear':
                BNA_Logger::clear_logs();
                wp_redirect(admin_url('admin.php?page=bna-debug&message=cleared'));
                exit;
                
            case 'test':
                BNA_Logger::debug('Test debug message', ['test' => true]);
                BNA_Logger::info('Test info message', ['test' => true]);
                BNA_Logger::error('Test error message', ['test' => true]);
                wp_redirect(admin_url('admin.php?page=bna-debug&message=tested'));
                exit;

            case 'test_webhook':
                self::test_webhook_functionality();
                wp_redirect(admin_url('admin.php?page=bna-debug&message=webhook_tested'));
                exit;
        }
    }

    private static function test_webhook_functionality() {
        BNA_Logger::info('Webhook functionality test', [
            'webhook_url' => BNA_Webhook_Init::get_webhook_url(),
            'webhook_config' => BNA_Webhook_Init::get_webhook_config(),
            'test_timestamp' => current_time('c')
        ]);
    }

    public static function admin_page() {
        $enabled = get_option('bna_debug_enabled', false);
        $log_files = BNA_Logger::get_log_files();
        $current_file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
        $webhook_url = BNA_Webhook_Init::get_webhook_url();
        
        // Show message
        if (isset($_GET['message'])) {
            $messages = [
                'enabled' => 'Debug logging enabled',
                'disabled' => 'Debug logging disabled', 
                'cleared' => 'All logs cleared',
                'tested' => 'Test logs created',
                'webhook_tested' => 'Webhook test completed'
            ];
            $message = $messages[$_GET['message']] ?? '';
            if ($message) {
                echo "<div class='notice notice-success'><p>{$message}</p></div>";
            }
        }

        ?>
        <div class="wrap">
            <h1>🔧 BNA Debug Logger</h1>
            
            <!-- Debug Settings -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 4px;">
                <h2>Debug Settings</h2>
                <p><strong>Status:</strong> 
                    <?php if ($enabled): ?>
                        <span style="color: green; font-weight: bold;">✅ ENABLED</span>
                        <a href="?page=bna-debug&bna_action=disable" class="button">Disable</a>
                    <?php else: ?>
                        <span style="color: red; font-weight: bold;">❌ DISABLED</span>
                        <a href="?page=bna-debug&bna_action=enable" class="button button-primary">Enable</a>
                    <?php endif; ?>
                </p>
                
                <p>
                    <a href="?page=bna-debug&bna_action=test" class="button">🧪 Create Test Logs</a>
                    <a href="?page=bna-debug&bna_action=clear" class="button" onclick="return confirm('Clear all logs?')">🗑️ Clear All Logs</a>
                </p>
            </div>

            <!-- Webhook Info -->
            <div style="background: #f0f8ff; padding: 20px; margin: 20px 0; border: 1px solid #0073aa; border-radius: 4px;">
                <h2>🔗 Webhook Information</h2>
                <p><strong>Webhook URL:</strong> <code><?php echo esc_html($webhook_url); ?></code></p>
                <p><strong>Test Webhook:</strong> <a href="<?php echo esc_url($webhook_url . '/test'); ?>" target="_blank" class="button">Test Endpoint</a></p>
                <p>
                    <a href="?page=bna-debug&bna_action=test_webhook" class="button">🔗 Test Webhook System</a>
                </p>
            </div>

            <?php if (!empty($log_files)): ?>
            <div style="display: flex; gap: 20px;">
                <!-- Log Files List -->
                <div style="flex: 0 0 250px; background: white; padding: 15px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3>📁 Log Files</h3>
                    <?php foreach ($log_files as $file): ?>
                        <?php
                        $is_current = ($current_file === $file['name']);
                        $url = admin_url("admin.php?page=bna-debug&file={$file['name']}");
                        $style = $is_current ? 'background: #e7f3ff; border-left: 3px solid #0073aa;' : '';
                        ?>
                        <div style="margin-bottom: 10px; padding: 8px; <?php echo $style; ?> border-radius: 3px;">
                            <a href="<?php echo esc_url($url); ?>" style="text-decoration: none;">
                                <strong><?php echo esc_html($file['name']); ?></strong><br>
                                <small style="color: #666;">
                                    📊 <?php echo size_format($file['size']); ?> | 
                                    🕒 <?php echo date('M j, H:i', $file['modified']); ?>
                                </small>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Log Content -->
                <div style="flex: 1; background: white; padding: 15px; border: 1px solid #ccc; border-radius: 4px;">
                    <?php if ($current_file): ?>
                        <h3>📄 Log Content: <?php echo esc_html($current_file); ?></h3>
                        <?php 
                        $content = BNA_Logger::read_log($current_file, 100);
                        if ($content): ?>
                            <pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-size: 13px; max-height: 500px; overflow: auto; font-family: 'Courier New', monospace;"><?php echo esc_html($content); ?></pre>
                        <?php else: ?>
                            <p>❌ Unable to read log file.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>👈 Select a log file to view its contents.</p>
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-top: 20px;">
                            <h4>💡 Quick Start:</h4>
                            <ol>
                                <li>Enable debug logging above</li>
                                <li>Create test logs to see how it works</li>
                                <li>Use your site and check the logs</li>
                                <li>Test webhook functionality</li>
                            </ol>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
                <div style="background: white; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3>📝 No Log Files Found</h3>
                    <p>No log files found. Enable logging and create some test logs to get started.</p>
                    
                    <?php if (!$enabled): ?>
                        <p>
                            <a href="?page=bna-debug&bna_action=enable" class="button button-primary">
                                🚀 Enable Logging to Get Started
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
