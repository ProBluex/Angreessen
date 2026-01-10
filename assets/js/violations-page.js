/**
 * Agent Angreessen - Violations Page JavaScript
 * Polished violations dashboard with aggregated agent view
 */
(function($) {
    'use strict';

    var allViolations = [];
    var aggregatedAgents = [];
    var policyChanges = {};

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
                    aggregatedAgents = aggregateByAgent(allViolations);
                    renderViolations(aggregatedAgents);
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
     * Aggregate violations by agent name
     * @param {Array} violations - Array of violation objects
     * @returns {Array} Aggregated agent data
     */
    function aggregateByAgent(violations) {
        var agents = {};
        
        violations.forEach(function(v) {
            var name = v.agent_name || 'Unknown Agent';
            if (!agents[name]) {
                agents[name] = {
                    agent_name: name,
                    total: 0,
                    robots_txt: 0,
                    unpaid_access: 0,
                    ignored_402: 0,
                    last_seen: null,
                    ip_addresses: [],
                    policy: 'monitor'
                };
            }
            
            agents[name].total++;
            if (v.violation_type === 'robots_txt') agents[name].robots_txt++;
            if (v.violation_type === 'unpaid_access') agents[name].unpaid_access++;
            if (v.violation_type === 'ignored_402') agents[name].ignored_402++;
            
            var detectedAt = new Date(v.detected_at);
            if (!agents[name].last_seen || detectedAt > agents[name].last_seen) {
                agents[name].last_seen = detectedAt;
            }
            
            if (v.ip_address && agents[name].ip_addresses.indexOf(v.ip_address) === -1) {
                agents[name].ip_addresses.push(v.ip_address);
            }
        });
        
        return Object.values(agents).sort(function(a, b) {
            return b.total - a.total;
        });
    }

    /**
     * Render violations table with aggregated agent data
     * @param {Array} agents - Array of aggregated agent objects
     */
    function renderViolations(agents) {
        // Always hide empty state first
        $('#violations-empty').hide();
        
        if (agents.length === 0) {
            $('#violations-table').hide();
            $('#violations-empty').show();
            return;
        }

        var tbody = $('#violations-tbody');
        tbody.empty();

        agents.forEach(function(agent) {
            var row = $('<tr>');

            // Agent Name with icon
            var nameCell = $('<td>').addClass('agent-name-cell');
            var nameWrapper = $('<div>').addClass('agent-name-wrapper');
            var icon = $('<div>').addClass('agent-icon').html(getAgentInitial(agent.agent_name));
            var nameText = $('<span>').addClass('agent-name-text').text(agent.agent_name);
            nameWrapper.append(icon).append(nameText);
            nameCell.append(nameWrapper);
            row.append(nameCell);

            // Total violations
            row.append($('<td>').html('<span class="count-badge count-badge-slate">' + agent.total + '</span>'));

            // Robots.txt count
            var robotsBadgeClass = agent.robots_txt > 0 ? 'count-badge-rose' : 'count-badge-green';
            row.append($('<td>').html('<span class="count-badge ' + robotsBadgeClass + '">' + agent.robots_txt + '</span>'));

            // Unpaid Access count
            var unpaidBadgeClass = agent.unpaid_access > 0 ? 'count-badge-amber' : 'count-badge-green';
            row.append($('<td>').html('<span class="count-badge ' + unpaidBadgeClass + '">' + agent.unpaid_access + '</span>'));

            // Last seen with clock icon
            var lastSeenCell = $('<td>').addClass('last-seen-cell');
            var lastSeenWrapper = $('<div>').addClass('last-seen');
            lastSeenWrapper.html(
                '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
                '<span>' + formatRelativeTime(agent.last_seen) + '</span>'
            );
            lastSeenCell.append(lastSeenWrapper);
            row.append(lastSeenCell);

            // Policy dropdown
            var policyCell = $('<td>').addClass('policy-cell');
            policyCell.append(createPolicyDropdown(agent.agent_name, agent.policy));
            row.append(policyCell);

            // Actions menu
            var actionsCell = $('<td>').addClass('actions-cell');
            actionsCell.append(createActionsMenu(agent));
            row.append(actionsCell);

            tbody.append(row);
        });

        $('#violations-table').show();
    }

    /**
     * Get agent initial for icon
     * @param {string} name - Agent name
     * @returns {string} Initial letter(s)
     */
    function getAgentInitial(name) {
        if (!name) return '?';
        var words = name.split(/[\s-_]+/);
        if (words.length >= 2) {
            return (words[0][0] + words[1][0]).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    }

    /**
     * Create policy dropdown component
     * @param {string} agentName - Agent name
     * @param {string} currentPolicy - Current policy (block/allow/monitor)
     * @returns {jQuery} Dropdown element
     */
    function createPolicyDropdown(agentName, currentPolicy) {
        var wrapper = $('<div>').addClass('policy-dropdown-wrapper');
        
        var policies = {
            block: { label: 'Block', class: 'policy-block' },
            allow: { label: 'Allow', class: 'policy-allow' },
            monitor: { label: 'Monitor', class: 'policy-monitor' }
        };
        
        var current = policies[currentPolicy] || policies.monitor;
        
        var trigger = $('<button>')
            .addClass('policy-dropdown-trigger ' + current.class)
            .attr('data-agent', agentName)
            .attr('data-policy', currentPolicy)
            .html(
                '<span>' + current.label + '</span>' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>'
            );
        
        var menu = $('<div>').addClass('policy-dropdown-menu').hide();
        
        Object.keys(policies).forEach(function(key) {
            var item = $('<button>')
                .addClass('policy-dropdown-item')
                .attr('data-value', key)
                .text(policies[key].label);
            
            if (key === currentPolicy) {
                item.addClass('active');
            }
            
            item.on('click', function(e) {
                e.stopPropagation();
                selectPolicy(agentName, key, trigger, menu);
            });
            
            menu.append(item);
        });
        
        trigger.on('click', function(e) {
            e.stopPropagation();
            $('.policy-dropdown-menu').not(menu).hide();
            menu.toggle();
        });
        
        wrapper.append(trigger).append(menu);
        return wrapper;
    }

    /**
     * Handle policy selection
     */
    function selectPolicy(agentName, policy, trigger, menu) {
        var policies = {
            block: { label: 'Block', class: 'policy-block' },
            allow: { label: 'Allow', class: 'policy-allow' },
            monitor: { label: 'Monitor', class: 'policy-monitor' }
        };
        
        trigger
            .removeClass('policy-block policy-allow policy-monitor')
            .addClass(policies[policy].class)
            .attr('data-policy', policy)
            .find('span').text(policies[policy].label);
        
        menu.find('.policy-dropdown-item').removeClass('active');
        menu.find('[data-value="' + policy + '"]').addClass('active');
        menu.hide();
        
        policyChanges[agentName] = policy;
        
        if (Object.keys(policyChanges).length > 0) {
            $('#btn-save-policies').show();
        }
    }

    /**
     * Create actions menu
     * @param {Object} agent - Agent data
     * @returns {jQuery} Actions menu element
     */
    function createActionsMenu(agent) {
        var wrapper = $('<div>').addClass('actions-menu-wrapper');
        
        var trigger = $('<button>').addClass('actions-trigger').html(
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>'
        );
        
        var menu = $('<div>').addClass('actions-dropdown-menu').hide();
        
        menu.append(
            $('<button>').addClass('actions-dropdown-item').text('View Details').on('click', function(e) {
                e.stopPropagation();
                alert('Agent: ' + agent.agent_name + '\nTotal Violations: ' + agent.total + '\nIP Addresses: ' + agent.ip_addresses.join(', '));
                menu.hide();
            })
        );
        
        menu.append(
            $('<button>').addClass('actions-dropdown-item actions-item-danger').text('Block Agent').on('click', function(e) {
                e.stopPropagation();
                selectPolicy(agent.agent_name, 'block', wrapper.closest('tr').find('.policy-dropdown-trigger'), wrapper.closest('tr').find('.policy-dropdown-menu'));
                menu.hide();
            })
        );
        
        trigger.on('click', function(e) {
            e.stopPropagation();
            $('.actions-dropdown-menu').not(menu).hide();
            $('.policy-dropdown-menu').hide();
            menu.toggle();
        });
        
        wrapper.append(trigger).append(menu);
        return wrapper;
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
     * Format relative time
     * @param {Date} date - Date object
     * @returns {string} Relative time string
     */
    function formatRelativeTime(date) {
        if (!date) return 'Never';
        
        var now = new Date();
        var diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + ' mins ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
        if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
        
        return date.toLocaleDateString();
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
     * Filter agents by search query
     * @param {string} query - Search query
     */
    function filterAgents(query) {
        query = query.toLowerCase().trim();
        var violationType = $('#filter-violation-type').val();
        
        var filtered = aggregatedAgents.filter(function(agent) {
            var matchesQuery = !query || agent.agent_name.toLowerCase().indexOf(query) !== -1;
            var matchesType = !violationType || (
                (violationType === 'robots_txt' && agent.robots_txt > 0) ||
                (violationType === 'unpaid_access' && agent.unpaid_access > 0) ||
                (violationType === 'ignored_402' && agent.ignored_402 > 0)
            );
            return matchesQuery && matchesType;
        });
        
        renderViolations(filtered);
    }

    /**
     * Initialize violations page
     */
    $(document).ready(function() {
        // Live search
        $('#violations-search').on('input', function() {
            filterAgents($(this).val());
        });

        // Filter by type
        $('#filter-violation-type').on('change', function() {
            filterAgents($('#violations-search').val());
        });

        // Close dropdowns when clicking outside
        $(document).on('click', function() {
            $('.policy-dropdown-menu').hide();
            $('.actions-dropdown-menu').hide();
        });

        // Save policy changes
        $('#btn-save-policies').on('click', function() {
            console.log('Saving policy changes:', policyChanges);
            // TODO: Implement AJAX save
            alert('Policy changes saved (demo)');
            policyChanges = {};
            $(this).hide();
        });

        // Initial load
        loadViolations();
    });
})(jQuery);
