jQuery(document).ready(function($) {
    let currentTimeframe = '30d';
    let rogueData = null;
    let currentViolations = [];
    let currentFilteredAgent = null;

    // Load rogue agents data when tab is activated
    $(document).on('click', '[data-tab="rogue-agents"]', function() {
        loadRogueAgentsData();
    });

    // Timeframe filter change
    $('#rogue-timeframe').on('change', function() {
        currentTimeframe = $(this).val();
        loadRogueAgentsData();
    });

    // Export CSV report
    $('#export-rogue-report').on('click', function() {
        if (!rogueData || !rogueData.violations || rogueData.violations.length === 0) {
            alert('No rogue agent data to export');
            return;
        }
        exportRogueReport();
    });

    /**
     * Load rogue agents data from backend
     */
    function loadRogueAgentsData() {
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'agent_hub_get_rogue_agents',
                nonce: agentHubData.nonce,
                timeframe: currentTimeframe
            },
            success: function(response) {
                if (response.success) {
                    rogueData = response.data;
                    
                    // Check if data is empty (no violations)
                    if (!rogueData.summary || rogueData.summary.total_violations === 0) {
                        showEmptyState();
                    } else {
                        renderRogueData(rogueData);
                    }
                } else {
                    // API returned error
                    const errorMsg = response.data && response.data.error ? response.data.error : 'Unable to load rogue agents data';
                    showError(errorMsg, 'api_error');
                }
            },
            error: function(xhr, status, error) {
                console.error('[Rogue Agents] AJAX Error:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText,
                    error: error
                });
                
                let errorMsg = 'Unable to load rogue agents data';
                
                if (xhr.status === 0) {
                    errorMsg = 'Cannot connect to server - check your internet connection';
                } else if (xhr.status === 401 || xhr.status === 403) {
                    errorMsg = 'API key authentication failed - please check your plugin settings';
                } else if (xhr.status === 404) {
                    errorMsg = 'Site not registered - please register your site first';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error - please contact support';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.error) {
                            errorMsg += ': ' + response.error;
                        }
                    } catch(e) {
                        // Could not parse response
                    }
                }
                
                showError(errorMsg, 'error_' + xhr.status);
            }
        });
    }

    /**
     * Render all rogue agents data
     */
    function renderRogueData(data) {
        // Update summary cards
        $('#rogue-total-violations').text(data.summary.total_violations || 0);
        $('#rogue-lost-revenue').text('$' + (parseFloat(data.summary.lost_revenue) || 0).toFixed(3));
        $('#rogue-unique-agents').text(data.summary.unique_agents || 0);
        $('#rogue-pages-affected').text(data.summary.pages_affected || 0);

        // Check if we have any violations
        if (!data.agents || data.agents.length === 0) {
            $('#rogue-agents-content').hide();
            $('#rogue-agents-empty-state').show();
            return;
        }

        $('#rogue-agents-content').show();
        $('#rogue-agents-empty-state').hide();

        // Render agents summary
        renderAgentsSummary(data.agents);

        // Render violation log
        renderViolationLog(data.violations);
    }

    /**
     * Render agents summary table
     */
    function renderAgentsSummary(agents) {
        const tbody = $('#rogue-agents-summary');
        tbody.empty();

        if (!agents || agents.length === 0) {
            tbody.append('<tr><td colspan="7" style="text-align:center;">No rogue agents found</td></tr>');
            return;
        }

        agents.forEach(function(agent) {
            const firstViolation = new Date(agent.first_violation).toLocaleString();
            const lastViolation = new Date(agent.last_violation).toLocaleString();

            const viewDetailsBtn = $('<button>')
                .attr('type', 'button')
                .addClass('button button-small view-details-btn')
                .attr('data-agent-name', agent.agent_name)
                .text('View Details')
                .on('click', function() {
                    filterViolationsByAgent($(this).attr('data-agent-name'));
                });

            const row = $('<tr>')
                .append($('<td>').html('<strong>' + escapeHtml(agent.agent_name) + '</strong>'))
                .append($('<td>').text(agent.total_violations))
                .append($('<td>').text('$' + agent.lost_revenue.toFixed(3)))
                .append($('<td>').text(agent.pages_scraped))
                .append($('<td>').text(firstViolation))
                .append($('<td>').text(lastViolation))
                .append($('<td>').append(viewDetailsBtn));

            tbody.append(row);
        });
    }

    /**
     * Render detailed violation log
     */
    function renderViolationLog(violations, filterAgentName) {
        const tbody = $('#rogue-violations-log');
        const filterNotice = $('#rogue-filter-notice');
        const filterText = $('#filter-text');
        tbody.empty();

        // Store violations for filtering
        currentViolations = violations || [];
        currentFilteredAgent = filterAgentName || null;

        // Filter violations if agent name is provided
        let displayData = currentViolations;
        if (filterAgentName) {
            displayData = currentViolations.filter(v => v.agent_name === filterAgentName);
            filterNotice.show();
            filterText.html('<strong>Showing violations from:</strong> ' + escapeHtml(filterAgentName) + ' (' + displayData.length + ' violations)');
        } else {
            filterNotice.hide();
        }

        if (!displayData || displayData.length === 0) {
            const message = filterAgentName 
                ? 'No violations found for agent: ' + escapeHtml(filterAgentName)
                : 'No violations recorded';
            tbody.append('<tr><td colspan="6" style="text-align:center;">' + message + '</td></tr>');
            return;
        }

        // Show first 50 violations
        const displayViolations = displayData.slice(0, 50);

        displayViolations.forEach(function(violation) {
            const timestamp = new Date(violation.timestamp).toISOString();
            const paymentRequired = '$' + (violation.payment_required || 0).toFixed(2);

            const row = $('<tr>')
                .append($('<td>').text(timestamp))
                .append($('<td>').html('<strong>' + escapeHtml(violation.agent_name) + '</strong>'))
                .append($('<td>').html(
                    escapeHtml(violation.content_title) + 
                    (violation.wordpress_post_id ? '<br><small>Post ID: ' + violation.wordpress_post_id + '</small>' : '')
                ))
                .append($('<td>').text(paymentRequired))
                .append($('<td>').html('<code>' + escapeHtml(violation.agent_ip_address) + '</code>'))
                .append($('<td>').html('<small><code>' + escapeHtml(truncate(violation.agent_user_agent, 60)) + '</code></small>'));

            tbody.append(row);
        });

        if (displayData.length > 50) {
            tbody.append('<tr><td colspan="6" style="text-align:center; font-style:italic;">Showing 50 of ' + displayData.length + ' violations. Export CSV for full report.</td></tr>');
        }
    }

    /**
     * Filter violations by agent
     */
    function filterViolationsByAgent(agentName) {
        renderViolationLog(currentViolations, agentName);
        // Scroll to violation log
        $('html, body').animate({
            scrollTop: $('#rogue-violations-log').closest('.analytics-section').offset().top - 100
        }, 500);
    }

    /**
     * Clear agent filter
     */
    function clearAgentFilter() {
        renderViolationLog(currentViolations, null);
    }

    /**
     * Export rogue agents report as CSV
     */
    function exportRogueReport() {
        if (!rogueData) return;

        const siteName = agentHubData.siteName || 'Site';
        const timestamp = new Date().toISOString();
        const timeframeText = getTimeframeText(currentTimeframe);

        let csv = 'Rogue Agents Violation Report\n';
        csv += 'Site: ' + siteName + '\n';
        csv += 'Generated: ' + timestamp + '\n';
        csv += 'Report Period: ' + timeframeText + '\n';
        csv += '\n';
        csv += 'SUMMARY\n';
        csv += 'Total Violations,' + rogueData.summary.total_violations + '\n';
        csv += 'Total Lost Revenue,$' + rogueData.summary.lost_revenue + '\n';
        csv += 'Unique Rogue Agents,' + rogueData.summary.unique_agents + '\n';
        csv += 'Pages Affected,' + rogueData.summary.pages_affected + '\n';
        csv += '\n';
        csv += 'DETAILED VIOLATIONS\n';
        csv += 'Timestamp (UTC),Agent Name,Agent User Agent,IP Address,Content Title,Content URL,WordPress Post ID,Payment Required (USD),Payment Made,Violation Type\n';

        rogueData.violations.forEach(function(v) {
            csv += '"' + v.timestamp + '",';
            csv += '"' + escapeCSV(v.agent_name) + '",';
            csv += '"' + escapeCSV(v.agent_user_agent) + '",';
            csv += '"' + escapeCSV(v.agent_ip_address) + '",';
            csv += '"' + escapeCSV(v.content_title) + '",';
            csv += '"' + escapeCSV(v.content_url) + '",';
            csv += (v.wordpress_post_id || '') + ',';
            csv += '$' + (v.payment_required || 0).toFixed(2) + ',';
            csv += (v.payment_made ? 'Yes' : 'No') + ',';
            csv += 'Unauthorized Access\n';
        });

        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'rogue-agents-report-' + Date.now() + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }

    /**
     * Helper functions
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function escapeCSV(text) {
        return String(text).replace(/"/g, '""');
    }

    function truncate(text, length) {
        if (!text) return '';
        if (text.length <= length) return text;
        return text.substring(0, length) + '...';
    }

    function getTimeframeText(timeframe) {
        switch(timeframe) {
            case '7d': return 'Last 7 Days';
            case '30d': return 'Last 30 Days';
            case '90d': return 'Last 90 Days';
            case 'all': return 'All Time';
            default: return timeframe;
        }
    }

    function showError(message, errorType) {
        console.error(message);
        $('#rogue-agents-content').hide();
        $('#rogue-agents-empty-state').show();
        
        let icon = '⚠️';
        let helpText = '';
        
        if (errorType === 'auth_error') {
            icon = '🔒';
            helpText = '<p>Please verify your API key in the Settings tab.</p>';
        } else if (errorType === 'network_error') {
            icon = '🌐';
            helpText = '<p>Please check your internet connection and try again.</p>';
        }
        
        $('#rogue-agents-empty-state').html(
            '<div style="text-align:center; padding:40px;">' +
            '<div style="font-size:48px; margin-bottom:20px;">' + icon + '</div>' +
            '<h3 style="color:#d32f2f; margin-bottom:10px;">' + escapeHtml(message) + '</h3>' +
            helpText +
            '<button type="button" class="button button-primary" onclick="location.reload()">Retry</button>' +
            '</div>'
        );
    }
    
    function showEmptyState() {
        $('#rogue-agents-content').hide();
        $('#rogue-agents-empty-state').show().html(
            '<div style="text-align:center; padding:40px;">' +
            '<div style="font-size:48px; margin-bottom:20px;">🛡️</div>' +
            '<h3 style="color:#4caf50; margin-bottom:10px;">No Rogue Agent Activity Detected</h3>' +
            '<p>Your content protection is working! No unauthorized access attempts have been recorded.</p>' +
            '</div>'
        );
    }

    // Clear agent filter button
    $('#clear-agent-filter').on('click', clearAgentFilter);

    // Auto-load data if rogue-agents tab is already active
    if ($('[data-tab="rogue-agents"]').hasClass('active')) {
        loadRogueAgentsData();
    }
});
