<?php
/**
 * Violations Dashboard Template
 * Displays agent violations with filtering and sorting
 */

if (!defined('ABSPATH')) {
    exit;
}

$site_id = get_option('402links_site_id');
?>

<div class="wrap agent-hub-violations">
    <h1>🚨 Agent Violations Dashboard</h1>
    
    <?php if (!$site_id): ?>
        <div class="notice notice-error">
            <p><strong>Site Not Registered:</strong> Please register your site in the main <a href="<?php echo admin_url('admin.php?page=agent-hub'); ?>">Tolliver dashboard</a> first.</p>
        </div>
        <?php return; ?>
    <?php endif; ?>
    
    <div class="violations-header">
        <p class="description">
            Track and monitor AI agents that violate robots.txt rules, ignore 402 payment requirements, or attempt unauthorized access to your content.
        </p>
    </div>
    
    <!-- Filters -->
    <div class="violations-filters" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 4px;">
        <h3>Filters</h3>
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
            <div>
                <label for="filter-violation-type"><strong>Violation Type:</strong></label>
                <select id="filter-violation-type" style="margin-left: 10px;">
                    <option value="">All Types</option>
                    <option value="robots_txt">Robots.txt Violation</option>
                    <option value="unpaid_access">Unpaid Access</option>
                    <option value="ignored_402">Ignored 402</option>
                    <option value="scraped_content">Scraped Content</option>
                </select>
            </div>
            <div>
                <label for="filter-agent-name"><strong>Agent Name:</strong></label>
                <input type="text" id="filter-agent-name" placeholder="Search agent..." style="margin-left: 10px; padding: 4px 8px;">
            </div>
            <div>
                <button id="btn-apply-filters" class="button button-primary">Apply Filters</button>
                <button id="btn-reset-filters" class="button">Reset</button>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="violations-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
        <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #d63638; border-radius: 4px;">
            <h3 style="margin: 0; font-size: 32px; color: #d63638;" id="stat-total">-</h3>
            <p style="margin: 5px 0 0; color: #666;">Total Violations</p>
        </div>
        <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #f56e28; border-radius: 4px;">
            <h3 style="margin: 0; font-size: 32px; color: #f56e28;" id="stat-robots">-</h3>
            <p style="margin: 5px 0 0; color: #666;">Robots.txt Violations</p>
        </div>
        <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #fcb900; border-radius: 4px;">
            <h3 style="margin: 0; font-size: 32px; color: #fcb900;" id="stat-unpaid">-</h3>
            <p style="margin: 5px 0 0; color: #666;">Unpaid Access</p>
        </div>
        <div class="stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a0d2; border-radius: 4px;">
            <h3 style="margin: 0; font-size: 32px; color: #00a0d2;" id="stat-unique-agents">-</h3>
            <p style="margin: 5px 0 0; color: #666;">Unique Agents</p>
        </div>
    </div>
    
    <!-- Violations Table -->
    <div class="violations-table-container" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccc; border-radius: 4px;">
        <div id="violations-loading" style="text-align: center; padding: 40px;">
            <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
            <p>Loading violations...</p>
        </div>
        
        <div id="violations-error" style="display: none; padding: 20px; background: #fee; border-left: 4px solid #d63638;">
            <strong>Error:</strong> <span id="error-message"></span>
        </div>
        
        <div id="violations-empty" style="display: none; text-align: center; padding: 40px; color: #666;">
            <p style="font-size: 18px;">✅ No violations detected!</p>
            <p>All AI agents are respecting your robots.txt rules and payment requirements.</p>
        </div>
        
        <table id="violations-table" class="wp-list-table widefat fixed striped" style="display: none;">
            <thead>
                <tr>
                    <th>Agent Name</th>
                    <th>Violation Type</th>
                    <th>IP Address</th>
                    <th>URL Requested</th>
                    <th>Detected At</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="violations-tbody">
                <!-- Populated by JavaScript -->
            </tbody>
        </table>
    </div>
</div>

<style>
.violations-filters select,
.violations-filters input {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

#violations-table {
    margin-top: 20px;
}

#violations-table th {
    padding: 12px;
    text-align: left;
    background: #f9f9f9;
    font-weight: 600;
}

#violations-table td {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
}

.violation-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    color: #fff;
}

.violation-robots_txt { background: #f56e28; }
.violation-unpaid_access { background: #fcb900; color: #000; }
.violation-ignored_402 { background: #d63638; }
.violation-scraped_content { background: #826eb4; }

.violation-details {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}
</style>

<script>
jQuery(document).ready(function($) {
    let allViolations = [];
    
    // Load violations
    function loadViolations(filters = {}) {
        $('#violations-loading').show();
        $('#violations-error').hide();
        $('#violations-table').hide();
        $('#violations-empty').hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'agent_hub_get_violations',
                nonce: agentHubData.nonce,
                ...filters
            },
            success: function(response) {
                $('#violations-loading').hide();
                
                if (response.success) {
                    allViolations = response.data.violations || [];
                    renderViolations(allViolations);
                    updateStats(allViolations);
                } else {
                    $('#violations-error').show();
                    $('#error-message').text(response.data?.message || 'Failed to load violations');
                }
            },
            error: function() {
                $('#violations-loading').hide();
                $('#violations-error').show();
                $('#error-message').text('Network error occurred');
            }
        });
    }
    
    // Render violations table
    function renderViolations(violations) {
        if (violations.length === 0) {
            $('#violations-empty').show();
            return;
        }
        
        const tbody = $('#violations-tbody');
        tbody.empty();
        
        violations.forEach(function(v) {
            const row = $('<tr>');
            
            row.append($('<td>').html('<strong>' + escapeHtml(v.agent_name || 'Unknown') + '</strong>'));
            
            row.append($('<td>').html(
                '<span class="violation-badge violation-' + v.violation_type + '">' +
                formatViolationType(v.violation_type) +
                '</span>'
            ));
            
            row.append($('<td>').text(v.ip_address || '-'));
            
            row.append($('<td>').html('<code style="font-size: 11px;">' + escapeHtml(v.requested_url || '-') + '</code>'));
            
            row.append($('<td>').text(formatDate(v.detected_at)));
            
            let details = '-';
            if (v.violation_type === 'robots_txt' && v.robots_txt_directive) {
                details = '<div class="violation-details"><strong>Violated Rule:</strong> ' + escapeHtml(v.robots_txt_directive) + '</div>';
            }
            row.append($('<td>').html(details));
            
            tbody.append(row);
        });
        
        $('#violations-table').show();
    }
    
    // Update statistics
    function updateStats(violations) {
        $('#stat-total').text(violations.length);
        
        const robotsCount = violations.filter(v => v.violation_type === 'robots_txt').length;
        $('#stat-robots').text(robotsCount);
        
        const unpaidCount = violations.filter(v => v.violation_type === 'unpaid_access').length;
        $('#stat-unpaid').text(unpaidCount);
        
        const uniqueAgents = new Set(violations.map(v => v.agent_name)).size;
        $('#stat-unique-agents').text(uniqueAgents);
    }
    
    // Helper functions
    function formatViolationType(type) {
        const map = {
            'robots_txt': 'Robots.txt',
            'unpaid_access': 'Unpaid Access',
            'ignored_402': 'Ignored 402',
            'scraped_content': 'Scraped Content'
        };
        return map[type] || type;
    }
    
    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleString();
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Filter handling
    $('#btn-apply-filters').click(function() {
        const filters = {};
        const violationType = $('#filter-violation-type').val();
        const agentName = $('#filter-agent-name').val().trim();
        
        if (violationType) filters.violation_type = violationType;
        if (agentName) filters.agent_name = agentName;
        
        loadViolations(filters);
    });
    
    $('#btn-reset-filters').click(function() {
        $('#filter-violation-type').val('');
        $('#filter-agent-name').val('');
        loadViolations();
    });
    
    // Initial load
    loadViolations();
});
</script>
