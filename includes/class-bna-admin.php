<?php
/**
 * BNA Admin Page
 * Simple admin page with logs view only
 */

if (!defined('ABSPATH')) {
    exit;
}

class BNA_Admin {
    
    /**
     * Initialize admin
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'handle_actions'));
    }
    
    /**
     * Add menu to WooCommerce
     */
    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'BNA Payment Logs',
            'BNA Logs',
            'manage_woocommerce',
            'bna-logs',
            array(__CLASS__, 'logs_page')
        );
    }
    
    /**
     * Handle admin actions
     */
    public static function handle_actions() {
        if (!isset($_GET['bna_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        
        $action = sanitize_text_field($_GET['bna_action']);
        
        switch ($action) {
            case 'clear_logs':
                BNA_Logger::clear();
                wp_redirect(admin_url('admin.php?page=bna-logs&message=logs_cleared'));
                exit;
                
            case 'download_logs':
                self::download_logs();
                exit;
        }
    }
    
    /**
     * Download logs as file
     */
    private static function download_logs() {
        $logs = BNA_Logger::get_logs(0); // Get all logs
        $filename = 'bna-payment-logs-' . date('Y-m-d-H-i-s') . '.txt';
        
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($logs));
        
        echo $logs;
    }
    
    /**
     * Main logs page
     */
    public static function logs_page() {
        $logs = BNA_Logger::get_logs(1000); // Last 1000 lines
        $log_size = BNA_Logger::get_log_size();
        
        // Show messages
        if (isset($_GET['message'])) {
            $messages = array(
                'logs_cleared' => 'Logs cleared successfully'
            );
            $message = $messages[$_GET['message']] ?? '';
            if ($message) {
                echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
            }
        }
        
        ?>
        <div class="wrap">
            <h1>🔧 BNA Payment Logs</h1>
            
            <!-- Log Info -->
            <div style="background: white; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2>Log Information</h2>
                <p>
                    <strong>Log Size:</strong> <?php echo size_format($log_size); ?> |
                    <strong>Webhook URL:</strong> <code><?php echo esc_html(home_url('/wp-json/bna/v1/webhook')); ?></code>
                </p>
                
                <p>
                    <a href="?page=bna-logs&bna_action=download_logs" class="button">📥 Download Logs</a>
                    <a href="?page=bna-logs&bna_action=clear_logs" class="button" onclick="return confirm('Clear all logs? This cannot be undone.')">🗑️ Clear Logs</a>
                </p>
            </div>
            
            <!-- Logs Display -->
            <div style="background: white; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px;">
                <h2>Recent Logs (Last 1000 lines)</h2>
                
                <?php if (!empty(trim($logs))): ?>
                    <div style="margin-bottom: 15px;">
                        <button id="bna-auto-scroll" class="button" onclick="toggleAutoScroll()">📜 Enable Auto-scroll</button>
                        <button class="button" onclick="searchLogs()">🔍 Search in Logs</button>
                        <input type="text" id="search-input" placeholder="Search logs..." style="margin-left: 10px; width: 200px;">
                    </div>
                    
                    <textarea id="bna-logs-content" readonly style="
                        width: 100%; 
                        height: 500px; 
                        font-family: 'Courier New', monospace; 
                        font-size: 12px; 
                        background: #1e1e1e; 
                        color: #d4d4d4; 
                        border: 1px solid #444; 
                        padding: 10px;
                        resize: vertical;
                        white-space: pre;
                        overflow: auto;
                    "><?php echo esc_textarea($logs); ?></textarea>
                    
                    <p style="margin-top: 10px; color: #666; font-size: 12px;">
                        💡 <strong>Tips:</strong> Logs auto-refresh every 30 seconds when auto-scroll is enabled. 
                        Use Ctrl+F to search within the logs. Different log levels: DEBUG, INFO, ERROR.
                    </p>
                    
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <h3>📝 No Logs Found</h3>
                        <p>No logs have been generated yet. Logs will appear here when:</p>
                        <ul style="text-align: left; display: inline-block;">
                            <li>Payments are processed</li>
                            <li>Webhooks are received</li>
                            <li>API requests are made</li>
                            <li>Errors occur</li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Debug Info -->
            <div style="background: #f0f8ff; padding: 15px; margin: 15px 0; border: 1px solid #0073aa; border-radius: 4px;">
                <h3>🔗 Quick Debug Info</h3>
                <ul style="margin: 0;">
                    <li><strong>Plugin Version:</strong> <?php echo defined('BNA_SMART_PAYMENT_VERSION') ? BNA_SMART_PAYMENT_VERSION : 'Unknown'; ?></li>
                    <li><strong>WordPress Version:</strong> <?php echo get_bloginfo('version'); ?></li>
                    <li><strong>WooCommerce Version:</strong> <?php echo class_exists('WooCommerce') ? WC()->version : 'Not installed'; ?></li>
                    <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
                    <li><strong>WP Debug:</strong> <?php echo defined('WP_DEBUG') && WP_DEBUG ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    <li><strong>Log File:</strong> <code>wp-content/uploads/bna-logs/bna-payment.log</code></li>
                </ul>
            </div>
        </div>
        
        <script>
        let autoScrollEnabled = false;
        let refreshInterval = null;
        
        function toggleAutoScroll() {
            const button = document.getElementById('bna-auto-scroll');
            const textarea = document.getElementById('bna-logs-content');
            
            autoScrollEnabled = !autoScrollEnabled;
            
            if (autoScrollEnabled) {
                button.textContent = '⏸️ Disable Auto-scroll';
                button.style.background = '#dc3232';
                button.style.color = 'white';
                
                // Scroll to bottom
                textarea.scrollTop = textarea.scrollHeight;
                
                // Start auto-refresh
                refreshInterval = setInterval(function() {
                    location.reload();
                }, 30000); // 30 seconds
                
            } else {
                button.textContent = '📜 Enable Auto-scroll';
                button.style.background = '';
                button.style.color = '';
                
                // Stop auto-refresh
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
            }
        }
        
        function searchLogs() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            const textarea = document.getElementById('bna-logs-content');
            const content = textarea.value.toLowerCase();
            
            if (!searchTerm) {
                alert('Please enter a search term');
                return;
            }
            
            const index = content.indexOf(searchTerm);
            if (index !== -1) {
                // Calculate line number for positioning
                const beforeSearch = content.substring(0, index);
                const lineNumber = beforeSearch.split('\n').length;
                
                // Rough scroll positioning
                const totalLines = content.split('\n').length;
                const scrollPosition = (lineNumber / totalLines) * textarea.scrollHeight;
                textarea.scrollTop = scrollPosition;
                
                alert(`Found "${searchTerm}" at approximately line ${lineNumber}`);
            } else {
                alert(`"${searchTerm}" not found in logs`);
            }
        }
        
        // Search on Enter key
        document.getElementById('search-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                searchLogs();
            }
        });
        
        // Auto-scroll to bottom on page load if there are logs
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.getElementById('bna-logs-content');
            if (textarea && textarea.value.trim()) {
                textarea.scrollTop = textarea.scrollHeight;
            }
        });
        </script>
        
        <style>
        #bna-logs-content::-webkit-scrollbar {
            width: 12px;
        }
        
        #bna-logs-content::-webkit-scrollbar-track {
            background: #2c2c2c;
        }
        
        #bna-logs-content::-webkit-scrollbar-thumb {
            background: #555;
            border-radius: 6px;
        }
        
        #bna-logs-content::-webkit-scrollbar-thumb:hover {
            background: #777;
        }
        </style>
        <?php
    }
}
