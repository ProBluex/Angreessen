(function($) {
    'use strict';
    
    let currentFilter = 'all';
    let autoRefreshInterval = null;
    
    /**
     * Load and display logs
     */
    function loadLogs() {
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_dev_logs',
                nonce: agentHubData.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderLogs(response.data);
                } else {
                    $('#dev-logs-display').html('<p class="no-logs error">Error loading logs: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                $('#dev-logs-display').html('<p class="no-logs error">Failed to load logs</p>');
            }
        });
    }
    
    /**
     * Render logs to the display area
     */
    function renderLogs(logs) {
        const container = $('#dev-logs-display');
        container.empty();
        
        if (!logs || logs.length === 0) {
            container.html('<p class="no-logs">No logs recorded yet. Perform actions in the plugin to see debug logs.</p>');
            return;
        }
        
        // Filter logs by category
        const filtered = currentFilter === 'all' 
            ? logs 
            : logs.filter(log => log.category === currentFilter);
        
        if (filtered.length === 0) {
            container.html('<p class="no-logs">No logs found for this category</p>');
            return;
        }
        
        // Display logs in reverse order (newest first)
        const reversedLogs = [...filtered].reverse();
        
        reversedLogs.forEach(log => {
            const time = new Date(log.timestamp * 1000).toLocaleTimeString();
            const datetime = log.datetime || time;
            
            // Format data for display
            let dataHtml = '';
            if (log.data !== null && log.data !== undefined) {
                if (typeof log.data === 'object') {
                    dataHtml = '<pre class="log-data">' + JSON.stringify(log.data, null, 2) + '</pre>';
                } else {
                    dataHtml = '<div class="log-data-simple">' + escapeHtml(String(log.data)) + '</div>';
                }
            }
            
            // Determine category class for color coding
            const categoryClass = getCategoryClass(log.category);
            
            const html = `
                <div class="dev-log-entry ${categoryClass}">
                    <div class="log-header">
                        <span class="log-time" title="${escapeHtml(datetime)}">${escapeHtml(time)}</span>
                        <span class="log-category">${escapeHtml(log.category)}</span>
                        <span class="log-action">${escapeHtml(log.action)}</span>
                    </div>
                    ${dataHtml}
                </div>
            `;
            container.append(html);
        });
        
        // Update counts
        updateCategoryCounts(logs);
    }
    
    /**
     * Get CSS class for category color coding
     */
    function getCategoryClass(category) {
        const classMap = {
            'ERROR': 'log-error',
            'INSTALL': 'log-install',
            'PROVISION': 'log-provision',
            'API': 'log-api',
            'LINK_CREATE': 'log-link',
            'LINK_UPDATE': 'log-link',
            'BATCH': 'log-batch',
            'POST_SYNC': 'log-sync',
            'EDGE_FUNCTION': 'log-edge'
        };
        return classMap[category] || '';
    }
    
    /**
     * Update category counts in tab labels
     */
    function updateCategoryCounts(logs) {
        const counts = {};
        logs.forEach(log => {
            counts[log.category] = (counts[log.category] || 0) + 1;
        });
        
        $('.dev-log-tab').each(function() {
            const category = $(this).data('category');
            if (category === 'all') {
                $(this).find('.tab-count').text('(' + logs.length + ')');
            } else if (counts[category]) {
                $(this).find('.tab-count').text('(' + counts[category] + ')');
            } else {
                $(this).find('.tab-count').text('(0)');
            }
        });
    }
    
    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Tab switching
     */
    $(document).on('click', '.dev-log-tab', function() {
        $('.dev-log-tab').removeClass('active');
        $(this).addClass('active');
        currentFilter = $(this).data('category');
        loadLogs();
    });
    
    /**
     * Clear logs
     */
    $(document).on('click', '#clear-dev-logs', function() {
        if (!confirm('Clear all debug logs? This cannot be undone.')) {
            return;
        }
        
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'clear_dev_logs',
                nonce: agentHubData.nonce
            },
            success: function(response) {
                if (response.success) {
                    loadLogs();
                    alert('All logs cleared successfully');
                } else {
                    alert('Failed to clear logs: ' + response.data.message);
                }
            },
            error: function() {
                alert('Failed to clear logs');
            }
        });
    });
    
    /**
     * Refresh logs
     */
    $(document).on('click', '#refresh-dev-logs', function() {
        loadLogs();
    });
    
    /**
     * Export logs
     */
    $(document).on('click', '#export-dev-logs', function() {
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'get_dev_logs',
                nonce: agentHubData.nonce
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    downloadLogsAsJSON(response.data);
                } else {
                    alert('No logs to export');
                }
            }
        });
    });
    
    /**
     * Download logs as JSON file
     */
    function downloadLogsAsJSON(logs) {
        const dataStr = JSON.stringify(logs, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        const url = URL.createObjectURL(dataBlob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'tolliver-debug-logs-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }
    
    /**
     * Auto-refresh when Dev Logs tab is active
     */
    function startAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
        
        autoRefreshInterval = setInterval(function() {
            if ($('#dev-logs').is(':visible')) {
                loadLogs();
            }
        }, 3000); // Refresh every 3 seconds
    }
    
    /**
     * Stop auto-refresh
     */
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
    
    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        // Initial load if Dev Logs tab exists
        if ($('#dev-logs').length > 0) {
            loadLogs();
            startAutoRefresh();
        }
        
        // Handle tab changes
        $('.tab-button').on('click', function() {
            const tabId = $(this).data('tab');
            if (tabId === 'dev-logs') {
                loadLogs();
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
    });
    
    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        stopAutoRefresh();
    });
    
})(jQuery);
