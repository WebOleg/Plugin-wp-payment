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
    
    <!-- Feature Status -->
    <div style="background: #e7f3ff; padding: 15px; margin: 15px 0; border: 1px solid #0073aa; border-radius: 4px;">
        <h3>🚀 Active Features</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <div>
                <strong>✅ iFrame Integration</strong><br>
                <small>Secure payment form integration</small>
            </div>
            <div>
                <strong>✅ Webhook Processing</strong><br>
                <small>Real-time payment notifications</small>
            </div>
            <div>
                <strong><?php echo bna_is_shipping_enabled() ? '✅' : '❌'; ?> Shipping Address</strong><br>
                <small><?php echo bna_is_shipping_enabled() ? 'Enabled' : 'Disabled'; ?> - Different from billing address</small>
            </div>
            <div>
                <strong>✅ Customer Data Sync</strong><br>
                <small>v1.6.0 - Auto-update customer data changes</small>
            </div>
        </div>
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
                Use Ctrl+F to search within the logs. Look for "Customer updated", "Customer created", or "Customer data changed" to track sync activity.
            </p>
            
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #666;">
                <h3>📝 No Logs Found</h3>
                <p>No logs have been generated yet. Logs will appear here when:</p>
                <ul style="text-align: left; display: inline-block;">
                    <li>Payments are processed</li>
                    <li>Webhooks are received</li>
                    <li>API requests are made</li>
                    <li>Customer data is synced</li>
                    <li>Errors occur</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Debug Info -->
    <div style="background: #f0f8ff; padding: 15px; margin: 15px 0; border: 1px solid #0073aa; border-radius: 4px;">
        <h3>🔗 System Information</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
            <div>
                <h4>Plugin Details</h4>
                <ul style="margin: 0;">
                    <li><strong>Plugin Version:</strong> <?php echo esc_html($plugin_version); ?></li>
                    <li><strong>Customer Sync:</strong> ✅ Enabled (v1.6.0)</li>
                    <li><strong>Shipping Address:</strong> <?php echo bna_is_shipping_enabled() ? '✅ Enabled' : '❌ Disabled'; ?></li>
                    <li><strong>Log File:</strong> <code>wp-content/uploads/bna-logs/bna-payment.log</code></li>
                </ul>
            </div>
            <div>
                <h4>Environment</h4>
                <ul style="margin: 0;">
                    <li><strong>WordPress:</strong> <?php echo esc_html($wp_version); ?></li>
                    <li><strong>WooCommerce:</strong> <?php echo esc_html($wc_version); ?></li>
                    <li><strong>PHP:</strong> <?php echo esc_html($php_version); ?></li>
                    <li><strong>WP Debug:</strong> <?php echo $wp_debug ? '✅ Enabled' : '❌ Disabled'; ?></li>
                </ul>
            </div>
        </div>
        
        <div style="margin-top: 15px; padding: 10px; background: #fff; border-radius: 4px;">
            <h4>🔄 Customer Sync Features</h4>
            <p><strong>What syncs automatically:</strong> Customer name, email, phone, address, shipping address, birthdate</p>
            <p><strong>When it syncs:</strong> When customer data changes between orders</p>
            <p><strong>How to track:</strong> Look for log entries containing "Customer updated" or "Customer data changed"</p>
        </div>
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
    
    // Predefined searches for customer sync
    const syncKeywords = ['customer updated', 'customer created', 'customer data changed', 'sync'];
    if (syncKeywords.includes(searchTerm)) {
        alert('💡 Tip: Search for "Customer updated" or "Customer data changed" to find sync activity');
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

// Add quick search buttons
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('bna-logs-content');
    if (textarea && textarea.value.trim()) {
        textarea.scrollTop = textarea.scrollHeight;
    }
    
    // Add quick search buttons after search input
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        const quickButtons = document.createElement('div');
        quickButtons.style.marginTop = '10px';
        quickButtons.innerHTML = `
            <small>Quick searches: </small>
            <button type="button" class="button button-small" onclick="document.getElementById('search-input').value='Customer updated'; searchLogs();">Customer Updates</button>
            <button type="button" class="button button-small" onclick="document.getElementById('search-input').value='error'; searchLogs();">Errors</button>
            <button type="button" class="button button-small" onclick="document.getElementById('search-input').value='webhook'; searchLogs();">Webhooks</button>
        `;
        searchInput.parentNode.appendChild(quickButtons);
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

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}
</style>
