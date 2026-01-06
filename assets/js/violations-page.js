/**
 * Agent Angreessen - Violations Page JavaScript
 * Handles violations dashboard filtering and display
 */
(function($) {
    'use strict';

    var allViolations = [];

    /**
     * Load violations from server
     * @param {Object} filters - Optional filters to apply
     */
    function loadViolations(filters) {
        filters = filters || {};
        
        $('#violations-loading').show();
        $('#violations-error').hide();
        $('#violations-table').hide();
        $('#violations-empty').hide();

        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: $.extend({
                action: 'agent_hub_get_violations',
                nonce: agentHubData.nonce
            }, filters),
            success: function(response) {
                $('#violations-loading').hide();

                if (response.success) {
                    allViolations = response.data.violations || [];
                    renderViolations(allViolations);
                    updateStats(allViolations);
                } else {
                    $('#violations-error').show();
                    $('#error-message').text(response.data && response.data.message ? response.data.message : 'Failed to load violations');
                }
            },
            error: function() {
                $('#violations-loading').hide();
                $('#violations-error').show();
                $('#error-message').text('Network error occurred');
            }
        });
    }

    /**
     * Render violations table
     * @param {Array} violations - Array of violation objects
     */
    function renderViolations(violations) {
        if (violations.length === 0) {
            $('#violations-empty').show();
            return;
        }

        var tbody = $('#violations-tbody');
        tbody.empty();

        violations.forEach(function(v) {
            var row = $('<tr>');

            row.append($('<td>').html('<strong>' + escapeHtml(v.agent_name || 'Unknown') + '</strong>'));

            row.append($('<td>').html(
                '<span class="violation-badge violation-' + v.violation_type + '">' +
                formatViolationType(v.violation_type) +
                '</span>'
            ));

            row.append($('<td>').text(v.ip_address || '-'));

            row.append($('<td>').html('<code style="font-size: 11px;">' + escapeHtml(v.requested_url || '-') + '</code>'));

            row.append($('<td>').text(formatDate(v.detected_at)));

            var details = '-';
            if (v.violation_type === 'robots_txt' && v.robots_txt_directive) {
                details = '<div class="violation-details"><strong>Violated Rule:</strong> ' + escapeHtml(v.robots_txt_directive) + '</div>';
            }
            row.append($('<td>').html(details));

            tbody.append(row);
        });

        $('#violations-table').show();
    }

    /**
     * Update statistics display
     * @param {Array} violations - Array of violation objects
     */
    function updateStats(violations) {
        $('#stat-total').text(violations.length);

        var robotsCount = violations.filter(function(v) {
            return v.violation_type === 'robots_txt';
        }).length;
        $('#stat-robots').text(robotsCount);

        var unpaidCount = violations.filter(function(v) {
            return v.violation_type === 'unpaid_access';
        }).length;
        $('#stat-unpaid').text(unpaidCount);

        var agentNames = {};
        violations.forEach(function(v) {
            agentNames[v.agent_name] = true;
        });
        var uniqueAgents = Object.keys(agentNames).length;
        $('#stat-unique-agents').text(uniqueAgents);
    }

    /**
     * Format violation type for display
     * @param {string} type - Violation type key
     * @returns {string} Formatted type name
     */
    function formatViolationType(type) {
        var map = {
            'robots_txt': 'Robots.txt',
            'unpaid_access': 'Unpaid Access',
            'ignored_402': 'Ignored 402',
            'scraped_content': 'Scraped Content'
        };
        return map[type] || type;
    }

    /**
     * Format date for display
     * @param {string} dateStr - ISO date string
     * @returns {string} Formatted date
     */
    function formatDate(dateStr) {
        if (!dateStr) {
            return '-';
        }
        var date = new Date(dateStr);
        return date.toLocaleString();
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped HTML
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Initialize violations page
     */
    $(document).ready(function() {
        // Apply filters button
        $('#btn-apply-filters').on('click', function() {
            var filters = {};
            var violationType = $('#filter-violation-type').val();
            var agentName = $('#filter-agent-name').val().trim();

            if (violationType) {
                filters.violation_type = violationType;
            }
            if (agentName) {
                filters.agent_name = agentName;
            }

            loadViolations(filters);
        });

        // Reset filters button
        $('#btn-reset-filters').on('click', function() {
            $('#filter-violation-type').val('');
            $('#filter-agent-name').val('');
            loadViolations();
        });

        // Initial load
        loadViolations();
    });
})(jQuery);
