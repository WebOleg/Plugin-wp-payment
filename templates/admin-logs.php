<div class="wrap">
    <h1>🔧 BNA Payment Logs</h1>
    
    <?php if (!empty($message)): ?>
        <?php $messages = array('logs_cleared' => 'Logs cleared successfully'); ?>
        <?php if (isset($messages[$message])): ?>
            <div class="notice notice-success">
                <p><?php echo esc_html($messages[$message]); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Log Info -->
    <div style="background: white; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
        <h2>Log Information</h2>
        <p>
            <strong>Log Size:</strong> <?php echo size_format($log_size); ?> |
            <strong>Webhook URL:</strong> <code><?php echo esc_html($webhook_url); ?></code>
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
                Use Ctrl+F to search within the logs.
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
    
    <!-- Debug Info -->
    <div style="background: #f0f8ff; padding: 15px; margin: 15px 0; border: 1px solid #0073aa; border-radius: 4px;">
        <h3>🔗 Debug Information</h3>
        <ul style="margin: 0;">
            <li><strong>Plugin Version:</strong> <?php echo esc_html($plugin_version); ?></li>
            <li><strong>WordPress Version:</strong> <?php echo esc_html($wp_version); ?></li>
            <li><strong>WooCommerce Version:</strong> <?php echo esc_html($wc_version); ?></li>
            <li><strong>PHP Version:</strong> <?php echo esc_html($php_version); ?></li>
            <li><strong>WP Debug:</strong> <?php echo $wp_debug ? '✅ Enabled' : '❌ Disabled'; ?></li>
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
        
        textarea.scrollTop = textarea.scrollHeight;
        
        refreshInterval = setInterval(function() {
            location.reload();
        }, 30000);
        
    } else {
        button.textContent = '📜 Enable Auto-scroll';
        button.style.background = '';
        button.style.color = '';
        
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
        const beforeSearch = content.substring(0, index);
        const lineNumber = beforeSearch.split('\n').length;
        
        const totalLines = content.split('\n').length;
        const scrollPosition = (lineNumber / totalLines) * textarea.scrollHeight;
        textarea.scrollTop = scrollPosition;
        
        alert(`Found "${searchTerm}" at approximately line ${lineNumber}`);
    } else {
        alert(`"${searchTerm}" not found in logs`);
    }
}

document.getElementById('search-input').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        searchLogs();
    }
});

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
