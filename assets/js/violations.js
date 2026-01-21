/**
 * Violations Tab Handler
 */
(function($) {
    'use strict';
    
    // Debug mode detection
    const DEBUG_MODE = window.location.hostname === 'localhost' || 
                       window.location.hostname.includes('127.0.0.1') ||
                       window.location.search.includes('debug=true');
    const debugLog = DEBUG_MODE ? console.log.bind(console) : () => {};
    const debugWarn = DEBUG_MODE ? console.warn.bind(console) : () => {};

    // Store bot policies: key = bot_registry_id, value = action
    let botPolicies = {};
    let changedPolicies = new Set();
    let violationsData = null;
    
    // Sorting state
    let currentSortColumn = 'total_violations';
    let currentSortDirection = 'desc';
    
    // Search filter
    let searchFilter = '';

    // Initialize when document is ready
    $(document).ready(function() {
        // Load violations when tab becomes active
        $(document).on('click', '[data-tab="violations"]', function() {
            loadViolations();
        });

        // Load on page load if violations tab is active
        if ($('#tab-violations').hasClass('active')) {
            loadViolations();
        }

        // Event handler for policy dropdown changes
        $(document).on('change', '.bot-policy-select', function() {
            const botId = $(this).data('bot-id');
            const newAction = $(this).val();
            
            debugLog('[Violations] Policy changed for bot:', botId, 'to:', newAction);
            
            botPolicies[botId] = newAction;
            changedPolicies.add(botId);
            
            // Show save button
            $('#violations-save-policies').show();
        });

        // Event handler for save button
        $(document).on('click', '#violations-save-policies', function() {
            savePolicies();
        });
        
        // Event handler for sortable table headers
        $(document).on('click', '#violations-table th.sortable', function() {
            const column = $(this).data('sort');
            
            // Toggle direction if clicking same column, otherwise default to desc
            if (column === currentSortColumn) {
                currentSortDirection = currentSortDirection === 'desc' ? 'asc' : 'desc';
            } else {
                currentSortColumn = column;
                currentSortDirection = 'desc';
            }
            
            // Re-render with sorted data
            if (violationsData) {
                displayViolations(violationsData);
            }
        });
        
        // Event handler for search input
        $(document).on('input', '#violations-search', function() {
            searchFilter = $(this).val().toLowerCase().trim();
            
            // Re-render with filtered data
            if (violationsData) {
                displayViolations(violationsData);
            }
        });
        
        // Re-initialize feather icons when violations tab is shown
        $(document).on('click', '[data-tab="violations"]', function() {
            setTimeout(function() {
                if (typeof feather !== 'undefined') {
                    feather.replace();
                }
            }, 100);
        });
        
        // Event handler for info buttons
        $(document).on('click', '.stat-info-btn', function(e) {
            e.stopPropagation();
            const tooltipId = $(this).data('tooltip');
            const $tooltip = $('#tooltip-' + tooltipId);
            
            // Close all other tooltips
            $('.stat-info-tooltip').not($tooltip).removeClass('active');
            
            // Toggle this tooltip
            $tooltip.toggleClass('active');
        });

        // Close tooltips when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.stat-info-btn, .stat-info-tooltip').length) {
                $('.stat-info-tooltip').removeClass('active');
            }
        });
    });

    /**
     * Load violations data from API
     */
    function loadViolations() {
        const $loading = $('#violations-loading');
        const $error = $('#violations-error');
        const $table = $('#violations-table');
        const $empty = $('#violations-empty');
        const $saveBtn = $('#violations-save-policies');

        // Show loading state
        $loading.show();
        $error.hide();
        $table.hide();
        $empty.hide();
        $saveBtn.hide();

        // Reset state
        changedPolicies.clear();

        // Make AJAX request for violations
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'angreessen49_get_violations_summary',
                nonce: angreessen49Data.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    violationsData = response.data;
                    
                    // Now fetch policies
                    loadPolicies();
                } else {
                    $loading.hide();
                    showError(response.data?.message || 'Failed to load violations data');
                }
            },
            error: function(xhr, status, error) {
                $loading.hide();
                showError('Network error: ' + error);
            }
        });
    }

    /**
     * Load bot policies from API
     */
    function loadPolicies() {
        const $loading = $('#violations-loading');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'angreessen49_get_site_bot_policies',
                nonce: angreessen49Data.nonce
            },
            success: function(response) {
                $loading.hide();

                if (response.success && response.data && response.data.policies) {
                    // Convert policies array to object for easy lookup
                    botPolicies = {};
                    response.data.policies.forEach(function(policy) {
                        botPolicies[policy.bot_registry_id] = policy.action;
                    });
                    
                    debugLog('[Violations] Loaded policies:', botPolicies);
                    
                    // Now display violations with policies
                    displayViolations(violationsData);
                } else {
                    debugLog('[Violations] No policies found, using defaults');
                    botPolicies = {};
                    
                    // Display violations with default policies
                    displayViolations(violationsData);
                }
            },
            error: function(xhr, status, error) {
                $loading.hide();
                console.error('[Violations] Failed to load policies:', error);
                
                // Continue with default policies
                botPolicies = {};
                displayViolations(violationsData);
            }
        });
    }

    /**
     * Display violations data
     */
    function displayViolations(data) {
        const $table = $('#violations-table');
        const $tbody = $('#violations-table-body');
        const $empty = $('#violations-empty');
        const $error = $('#violations-error');

        // Always hide error and empty states first
        $error.hide();
        $empty.hide();

        // Update stats
        $('#violations-total').text(formatNumber(data.totals.total_violations));
        $('#violations-robots').text(formatNumber(data.totals.robots_txt_violations));
        $('#violations-unpaid').text(formatNumber(data.totals.unpaid_access_violations));
        $('#violations-unique-agents').text(formatNumber(data.totals.unique_agents));

        // Check if we have agents - always show table with all agents
        if (!data.agents || data.agents.length === 0) {
            $('#violations-policy-actions').hide();
            $empty.show();
            return;
        }
        
        // Filter agents based on search
        let filteredAgents = data.agents;
        if (searchFilter) {
            filteredAgents = data.agents.filter(function(agent) {
                return agent.agent_name.toLowerCase().includes(searchFilter);
            });
        }

        // Sort agents based on current sort column and direction
        const sortedAgents = sortAgents(filteredAgents, currentSortColumn, currentSortDirection);
        
        // Update table header sort indicators
        updateSortIndicators();

        // Build table rows - show ALL agents from bot_registry
        $tbody.empty();
        sortedAgents.forEach(function(agent) {
            const $row = $('<tr>');
            
            // Agent name with icon
            const initials = getInitials(agent.agent_name);
            $row.append($('<td>').html(
                '<div class="agent-cell">' +
                    '<div class="agent-icon">' + escapeHtml(initials) + '</div>' +
                    '<span class="agent-name-text">' + escapeHtml(agent.agent_name) + '</span>' +
                '</div>'
            ));

            // Total violations - gray badge
            $row.append($('<td>').html(
                '<span class="count-badge count-badge-gray">' + 
                formatNumber(agent.total_violations) + 
                '</span>'
            ));

            // Robots.txt violations - rose if > 0, green if 0
            $row.append($('<td>').html(
                agent.robots_txt_violations > 0 
                    ? '<span class="count-badge count-badge-rose">' + formatNumber(agent.robots_txt_violations) + '</span>'
                    : '<span class="count-badge count-badge-green">0</span>'
            ));

            // Unpaid access - amber if > 0, green if 0
            $row.append($('<td>').html(
                agent.unpaid_access_violations > 0 
                    ? '<span class="count-badge count-badge-amber">' + formatNumber(agent.unpaid_access_violations) + '</span>'
                    : '<span class="count-badge count-badge-green">0</span>'
            ));

            // Last seen with relative time
            $row.append($('<td>').html(
                '<span class="last-seen">' +
                    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>' +
                    formatRelativeTime(agent.last_seen) +
                '</span>'
            ));

            // Policy dropdown with custom button
            const currentPolicy = botPolicies[agent.bot_registry_id] || 'monetize';
            const $policyCell = $('<td>').addClass('policy-cell');
            
            // Policy labels
            const policyLabels = {
                'monetize': 'Monetized',
                'allow': 'Allowed',
                'block': 'Blocked'
            };
            
            const policyOptions = [
                { value: 'monetize', activeLabel: 'Monetized', inactiveLabel: 'Monetize' },
                { value: 'allow', activeLabel: 'Allowed', inactiveLabel: 'Allow' },
                { value: 'block', activeLabel: 'Blocked', inactiveLabel: 'Block' }
            ];
            
            // Create custom dropdown container
            const $dropdownContainer = $('<div>')
                .addClass('policy-dropdown-container')
                .attr('data-bot-id', agent.bot_registry_id)
                .attr('data-policy', currentPolicy);
            
            // Create dropdown button showing current policy
            const $dropdownButton = $('<button>')
                .addClass('policy-dropdown-button')
                .attr('type', 'button')
                .attr('data-current', currentPolicy)
                .html(
                    '<span class="policy-status-dot"></span>' +
                    '<span class="policy-label">' + policyLabels[currentPolicy] + '</span>' +
                    '<svg class="policy-arrow" width="12" height="12" viewBox="0 0 12 12" fill="none">' +
                    '<path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
                    '</svg>'
                );
            
            // Create dropdown menu
            const $dropdownMenu = $('<div>')
                .addClass('policy-dropdown-menu')
                .css('display', 'none');
            
            // Add options to menu
            policyOptions.forEach(function(opt) {
                const isActive = opt.value === currentPolicy;
                const $option = $('<div>')
                    .addClass('policy-dropdown-option')
                    .attr('data-value', opt.value)
                    .text(opt.inactiveLabel);
                
                if (isActive) {
                    $option.addClass('active');
                }
                
                // Click handler for option
                $option.on('click', function() {
                    const newValue = $(this).attr('data-value');
                    
                    // Update container and button data attributes
                    $dropdownContainer.attr('data-policy', newValue);
                    $dropdownButton.attr('data-current', newValue);
                    
                    // Update button display
                    $dropdownButton.html(
                        '<span class="policy-status-dot"></span>' +
                        '<span class="policy-label">' + policyLabels[newValue] + '</span>' +
                        '<svg class="policy-arrow" width="12" height="12" viewBox="0 0 12 12" fill="none">' +
                        '<path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
                        '</svg>'
                    );
                    
                    // Update active state in menu
                    $dropdownMenu.find('.policy-dropdown-option').removeClass('active');
                    $(this).addClass('active');
                    
                    // Close menu
                    $dropdownMenu.hide();
                    $dropdownContainer.removeClass('open');
                    
                    // Mark as changed if different from original
                    if (botPolicies[agent.bot_registry_id] !== newValue) {
                        $dropdownContainer.addClass('policy-changed').attr('data-new-value', newValue);
                        $('#violations-save-policies').show();
                    }
                });
                
                $dropdownMenu.append($option);
            });
            
            // Toggle dropdown on button click
            $dropdownButton.on('click', function(e) {
                e.stopPropagation();
                
                // Close other dropdowns
                $('.policy-dropdown-container.open').not($dropdownContainer).removeClass('open')
                    .find('.policy-dropdown-menu').hide();
                
                // Toggle this dropdown
                $dropdownContainer.toggleClass('open');
                $dropdownMenu.toggle();
            });
            
            $dropdownContainer.append($dropdownButton).append($dropdownMenu);
            $policyCell.append($dropdownContainer);
            $row.append($policyCell);

            $tbody.append($row);
        });

        debugLog('[Violations] Rendering', sortedAgents.length, 'agents');
        $table.show();
        
        // Show policy actions container when table has data
        $('#violations-policy-actions').show();
    }
    
    /**
     * Get initials from agent name (first 2 chars or first letter of each word)
     */
    function getInitials(name) {
        if (!name) return '??';
        const words = name.trim().split(/[\s_-]+/);
        if (words.length >= 2) {
            return (words[0][0] + words[1][0]).toUpperCase();
        }
        return name.substring(0, 2).toUpperCase();
    }
    
    /**
     * Format relative time (e.g., "2 mins ago", "3 hours ago")
     */
    function formatRelativeTime(dateStr) {
        if (!dateStr) return 'Never';
        
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now - date;
        const diffSecs = Math.floor(diffMs / 1000);
        const diffMins = Math.floor(diffSecs / 60);
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);
        
        if (diffSecs < 60) return 'Just now';
        if (diffMins < 60) return diffMins + ' min' + (diffMins === 1 ? '' : 's') + ' ago';
        if (diffHours < 24) return diffHours + ' hour' + (diffHours === 1 ? '' : 's') + ' ago';
        if (diffDays < 7) return diffDays + ' day' + (diffDays === 1 ? '' : 's') + ' ago';
        
        // Fall back to formatted date for older entries
        return date.toLocaleDateString();
    }
    
    /**
     * Sort agents array by column and direction
     */
    function sortAgents(agents, column, direction) {
        const sorted = [...agents].sort(function(a, b) {
            let aVal = a[column];
            let bVal = b[column];
            
            // Handle special cases
            if (column === 'last_seen') {
                // Convert to timestamps for sorting
                aVal = aVal ? new Date(aVal).getTime() : 0;
                bVal = bVal ? new Date(bVal).getTime() : 0;
            } else if (column === 'agent_name') {
                // String comparison
                aVal = (aVal || '').toLowerCase();
                bVal = (bVal || '').toLowerCase();
            }
            
            if (aVal < bVal) return direction === 'asc' ? -1 : 1;
            if (aVal > bVal) return direction === 'asc' ? 1 : -1;
            return 0;
        });
        
        return sorted;
    }
    
    /**
     * Update sort indicators in table headers
     */
    function updateSortIndicators() {
        $('#violations-table th.sortable').removeClass('sorted-asc sorted-desc');
        $('#violations-table th.sortable[data-sort="' + currentSortColumn + '"]')
            .addClass('sorted-' + currentSortDirection);
    }

    /**
     * Show error message
     */
    function showError(message) {
        $('#violations-error').show().find('#error-message').text(message);
    }

    /**
     * Format number with commas
     */
    function formatNumber(num) {
        return new Intl.NumberFormat().format(num || 0);
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Save changed policies
     */
    function savePolicies() {
        const $saveBtn = $('#violations-save-policies');
        const originalText = $saveBtn.text();
        
        // Collect all changed policies
        const policiesToSave = [];
        $('.policy-dropdown-container.policy-changed').each(function() {
            policiesToSave.push({
                bot_registry_id: $(this).attr('data-bot-id'),
                action: $(this).attr('data-new-value')
            });
        });
        
        if (policiesToSave.length === 0) {
            $saveBtn.hide();
            return;
        }
        
        debugLog('[Violations] Saving policies:', policiesToSave);
        
        $saveBtn.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'angreessen49_save_site_bot_policies',
                nonce: angreessen49Data.nonce,
                policies: JSON.stringify(policiesToSave)
            },
            success: function(response) {
                if (response.success) {
                    // Update local state
                    policiesToSave.forEach(function(policy) {
                        botPolicies[policy.bot_registry_id] = policy.action;
                    });
                    
                    // Remove changed indicators
                    $('.policy-dropdown-container.policy-changed').removeClass('policy-changed');
                    
                    $saveBtn.text('Saved!');
                    setTimeout(function() {
                        $saveBtn.prop('disabled', false).text(originalText).hide();
                    }, 2000);
                    
                    if (typeof window.showToast === 'function') {
                        window.showToast('Success', 'Bot policies saved successfully', 'success');
                    }
                } else {
                    $saveBtn.prop('disabled', false).text(originalText);
                    if (typeof window.showToast === 'function') {
                        window.showToast('Error', response.data?.message || 'Failed to save policies', 'error');
                    }
                }
            },
            error: function(xhr, status, error) {
                $saveBtn.prop('disabled', false).text(originalText);
                console.error('[Violations] Failed to save policies:', error);
                if (typeof window.showToast === 'function') {
                    window.showToast('Error', 'Network error: ' + error, 'error');
                }
            }
        });
    }

    // Close dropdowns when clicking outside
    $(document).on('click', function() {
        $('.policy-dropdown-container.open').removeClass('open')
            .find('.policy-dropdown-menu').hide();
    });

})(jQuery);
