<?php
/**
 * BNA Debug Admin V2
 * Enhanced debug panel with tabs for each logger
 */

if (!defined('ABSPATH')) exit;

class BNA_Debug_Admin_V2 {

    private $logger_manager;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'BNA Debug',
            'BNA Debug',
            'manage_woocommerce',
            'bna-debug-v2',
            [__CLASS__, 'admin_page']
        );
    }

    public static function enqueue_styles($hook) {
        if ($hook !== 'woocommerce_page_bna-debug-v2') return;
        
        wp_add_inline_style('wp-admin', '
            .bna-tabs { border-bottom: 1px solid #ccd0d4; margin: 0 0 20px; }
            .bna-tabs .nav-tab { margin-bottom: -1px; }
            .bna-tab-content { display: none; }
            .bna-tab-content.active { display: block; }
            .bna-log-files { display: flex; gap: 20px; }
            .bna-files-list { flex: 0 0 300px; background: white; padding: 15px; border: 1px solid #ccd0d4; }
            .bna-file-content { flex: 1; background: white; padding: 15px; border: 1px solid #ccd0d4; }
            .bna-file-item { padding: 10px; margin: 5px 0; border-left: 3px solid transparent; cursor: pointer; }
            .bna-file-item:hover { background: #f0f0f0; }
            .bna-file-item.active { background: #e7f3ff; border-left-color: #0073aa; }
            .bna-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
            .bna-stat-card { background: white; padding: 20px; border: 1px solid #ccd0d4; text-align: center; }
            .bna-stat-number { font-size: 24px; font-weight: bold; color: #0073aa; }
        ');
    }

    public static function handle_actions() {
        if (!isset($_GET['bna_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }

        $manager = BNA_Logger_Manager::instance();
        $action = sanitize_text_field($_GET['bna_action']);
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';

        switch ($action) {
            case 'enable_all':
                $manager->enable_all();
                wp_redirect(admin_url('admin.php?page=bna-debug-v2&message=enabled_all'));
                exit;

            case 'disable_all':
                $manager->disable_all();
                wp_redirect(admin_url('admin.php?page=bna-debug-v2&message=disabled_all'));
                exit;

            case 'enable_type':
                if ($type && $logger = $manager->get_logger($type)) {
                    $logger->enable();
                    wp_redirect(admin_url("admin.php?page=bna-debug-v2&tab={$type}&message=enabled_{$type}"));
                    exit;
                }
                break;

            case 'disable_type':
                if ($type && $logger = $manager->get_logger($type)) {
                    $logger->disable();
                    wp_redirect(admin_url("admin.php?page=bna-debug-v2&tab={$type}&message=disabled_{$type}"));
                    exit;
                }
                break;

            case 'clear_type':
                if ($type && $logger = $manager->get_logger($type)) {
                    $logger->clear();
                    wp_redirect(admin_url("admin.php?page=bna-debug-v2&tab={$type}&message=cleared_{$type}"));
                    exit;
                }
                break;

            case 'clear_all':
                $manager->clear_all();
                wp_redirect(admin_url('admin.php?page=bna-debug-v2&message=cleared_all'));
                exit;

            case 'test_all':
                $manager->test_all();
                wp_redirect(admin_url('admin.php?page=bna-debug-v2&message=tested_all'));
                exit;
        }
    }

    public static function admin_page() {
        $manager = BNA_Logger_Manager::instance();
        $stats = $manager->get_stats();
        $current_tab = $_GET['tab'] ?? 'overview';
        $current_file = $_GET['file'] ?? '';

        self::show_messages();
        ?>
        <div class="wrap">
            <h1>🔧 BNA Debug Logger V2</h1>

            <?php self::render_tabs($current_tab, $stats); ?>

            <div class="bna-tab-content <?php echo $current_tab === 'overview' ? 'active' : ''; ?>" id="tab-overview">
                <?php self::render_overview($manager, $stats); ?>
            </div>

            <?php foreach (['woocommerce', 'api', 'webhooks'] as $type): ?>
                <div class="bna-tab-content <?php echo $current_tab === $type ? 'active' : ''; ?>" id="tab-<?php echo $type; ?>">
                    <?php self::render_logger_tab($manager->get_logger($type), $type, $stats['by_type'][$type], $current_file); ?>
                </div>
            <?php endforeach; ?>

            <?php self::render_tab_script(); ?>
        </div>
        <?php
    }

    private static function show_messages() {
        if (!isset($_GET['message'])) return;

        $messages = [
            'enabled_all' => '✅ All loggers enabled',
            'disabled_all' => '❌ All loggers disabled',
            'cleared_all' => '🗑️ All logs cleared',
            'tested_all' => '🧪 Test logs created for all enabled loggers',
            'enabled_woocommerce' => '✅ WooCommerce logging enabled',
            'disabled_woocommerce' => '❌ WooCommerce logging disabled',
            'cleared_woocommerce' => '🗑️ WooCommerce logs cleared',
            'enabled_api' => '✅ API logging enabled',
            'disabled_api' => '❌ API logging disabled',
            'cleared_api' => '🗑️ API logs cleared',
            'enabled_webhooks' => '✅ Webhooks logging enabled',
            'disabled_webhooks' => '❌ Webhooks logging disabled',
            'cleared_webhooks' => '🗑️ Webhooks logs cleared'
        ];

        $message = $messages[$_GET['message']] ?? '';
        if ($message) {
            echo "<div class='notice notice-success'><p>{$message}</p></div>";
        }
    }

    private static function render_tabs($current_tab, $stats) {
        $tabs = [
            'overview' => '📊 Overview',
            'woocommerce' => '🛒 WooCommerce (' . $stats['by_type']['woocommerce']['files_count'] . ')',
            'api' => '🔗 API (' . $stats['by_type']['api']['files_count'] . ')',
            'webhooks' => '📡 Webhooks (' . $stats['by_type']['webhooks']['files_count'] . ')'
        ];

        echo '<div class="bna-tabs">';
        foreach ($tabs as $tab_key => $tab_label) {
            $active = $current_tab === $tab_key ? 'nav-tab-active' : '';
            echo "<a href='?page=bna-debug-v2&tab={$tab_key}' class='nav-tab {$active}'>{$tab_label}</a>";
        }
        echo '</div>';
    }

    private static function render_overview($manager, $stats) {
        ?>
        <div class="bna-stats-grid">
            <div class="bna-stat-card">
                <div class="bna-stat-number"><?php echo $stats['enabled_loggers']; ?>/<?php echo $stats['total_loggers']; ?></div>
                <div>Enabled Loggers</div>
            </div>
            <div class="bna-stat-card">
                <div class="bna-stat-number"><?php echo $stats['total_files']; ?></div>
                <div>Total Files</div>
            </div>
            <div class="bna-stat-card">
                <div class="bna-stat-number"><?php echo size_format($stats['total_size']); ?></div>
                <div>Total Size</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; border: 1px solid #ccd0d4;">
            <h2>Global Controls</h2>
            <p>
                <a href="?page=bna-debug-v2&bna_action=enable_all" class="button button-primary">✅ Enable All Loggers</a>
                <a href="?page=bna-debug-v2&bna_action=disable_all" class="button">❌ Disable All Loggers</a>
                <a href="?page=bna-debug-v2&bna_action=test_all" class="button">🧪 Test All Loggers</a>
                <a href="?page=bna-debug-v2&bna_action=clear_all" class="button" onclick="return confirm('Clear all logs?')">🗑️ Clear All Logs</a>
            </p>
        </div>

        <div style="background: #f0f8ff; padding: 20px; margin: 20px 0; border: 1px solid #0073aa;">
            <h3>🔗 Webhook Information</h3>
            <p><strong>URL:</strong> <code><?php echo BNA_Webhook_Init::get_webhook_url(); ?></code></p>
            <p><a href="<?php echo BNA_Webhook_Init::get_webhook_url() . '/test'; ?>" target="_blank" class="button">Test Endpoint</a></p>
        </div>
        <?php
    }

    private static function render_logger_tab($logger, $type, $type_stats, $current_file) {
        $files = $logger->get_files();
        $enabled = $logger->is_enabled();
        
        ?>
        <div style="background: white; padding: 20px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
            <h2><?php echo ucfirst($type); ?> Logger</h2>
            <p><strong>Status:</strong> 
                <?php if ($enabled): ?>
                    <span style="color: green;">✅ ENABLED</span>
                    <a href="?page=bna-debug-v2&tab=<?php echo $type; ?>&bna_action=disable_type&type=<?php echo $type; ?>" class="button">Disable</a>
                <?php else: ?>
                    <span style="color: red;">❌ DISABLED</span>
                    <a href="?page=bna-debug-v2&tab=<?php echo $type; ?>&bna_action=enable_type&type=<?php echo $type; ?>" class="button button-primary">Enable</a>
                <?php endif; ?>
            </p>
            <p>
                <strong>Files:</strong> <?php echo $type_stats['files_count']; ?> |
                <strong>Size:</strong> <?php echo size_format($type_stats['total_size']); ?>
            </p>
            <p>
                <a href="?page=bna-debug-v2&tab=<?php echo $type; ?>&bna_action=clear_type&type=<?php echo $type; ?>" class="button" onclick="return confirm('Clear <?php echo $type; ?> logs?')">🗑️ Clear <?php echo ucfirst($type); ?> Logs</a>
            </p>
        </div>

        <?php if (!empty($files)): ?>
            <div class="bna-log-files">
                <div class="bna-files-list">
                    <h3>📁 Log Files</h3>
                    <?php foreach ($files as $file): ?>
                        <?php
                        $is_current = ($current_file === $file['name']);
                        $url = admin_url("admin.php?page=bna-debug-v2&tab={$type}&file={$file['name']}");
                        ?>
                        <div class="bna-file-item <?php echo $is_current ? 'active' : ''; ?>" onclick="location.href='<?php echo esc_url($url); ?>'">
                            <strong><?php echo esc_html($file['name']); ?></strong><br>
                            <small>📊 <?php echo size_format($file['size']); ?> | 🕒 <?php echo date('M j, H:i', $file['modified']); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="bna-file-content">
                    <?php if ($current_file): ?>
                        <h3>📄 <?php echo esc_html($current_file); ?></h3>
                        <?php 
                        $content = $logger->read_file($current_file, 100);
                        if ($content): ?>
                            <pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; font-size: 12px; max-height: 500px; overflow: auto; font-family: 'Courier New', monospace;"><?php echo esc_html($content); ?></pre>
                        <?php else: ?>
                            <p>❌ Unable to read log file.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>👈 Select a log file to view its contents.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div style="background: white; padding: 20px; border: 1px solid #ccd0d4;">
                <h3>📝 No <?php echo ucfirst($type); ?> Log Files</h3>
                <p>No log files found. <?php echo $enabled ? 'Use your site to generate logs.' : 'Enable logging to start collecting data.'; ?></p>
            </div>
        <?php endif; ?>
        <?php
    }

    private static function render_tab_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.nav-tab');
            const contents = document.querySelectorAll('.bna-tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Remove active class from all
                    tabs.forEach(t => t.classList.remove('nav-tab-active'));
                    contents.forEach(c => c.classList.remove('active'));
                    
                    // Add active class to clicked tab
                    this.classList.add('nav-tab-active');
                    
                    // Show corresponding content
                    const tabName = new URL(this.href).searchParams.get('tab');
                    const content = document.getElementById('tab-' + tabName);
                    if (content) {
                        content.classList.add('active');
                    }
                });
            });
        });
        </script>
        <?php
    }
}
