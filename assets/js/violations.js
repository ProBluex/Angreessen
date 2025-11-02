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
                action: 'agent_hub_get_violations_summary',
                nonce: agentHubData.nonce
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
                action: 'agent_hub_get_site_bot_policies',
                nonce: agentHubData.nonce
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

        // Sort agents based on current sort column and direction
        const sortedAgents = sortAgents(data.agents, currentSortColumn, currentSortDirection);
        
        // Update table header sort indicators
        updateSortIndicators();

        // Build table rows - show ALL agents from bot_registry
        $tbody.empty();
        sortedAgents.forEach(function(agent) {
            const $row = $('<tr>');
            
            // Agent name
            $row.append($('<td>').html(
                '<strong>' + escapeHtml(agent.agent_name) + '</strong>'
            ));

            // Total violations
            $row.append($('<td>').html(
                '<span class="violation-badge violation-total">' + 
                formatNumber(agent.total_violations) + 
                '</span>'
            ));

            // Robots.txt violations
            $row.append($('<td>').html(
                agent.robots_txt_violations > 0 
                    ? '<span class="violation-badge violation-robots">' + 
                      formatNumber(agent.robots_txt_violations) + 
                      '</span>'
                    : '<span class="violation-badge violation-none">0</span>'
            ));

            // Unpaid access
            $row.append($('<td>').html(
                agent.unpaid_access_violations > 0 
                    ? '<span class="violation-badge violation-unpaid">' + 
                      formatNumber(agent.unpaid_access_violations) + 
                      '</span>'
                    : '<span class="violation-badge violation-none">0</span>'
            ));

            // Last seen
            $row.append($('<td>').text(formatDateTime(agent.last_seen)));

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
                .attr('data-bot-id', agent.bot_registry_id);
            
            // Create dropdown button showing current policy
            const $dropdownButton = $('<button>')
                .addClass('policy-dropdown-button')
                .attr('type', 'button')
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
                return direction === 'asc' 
                    ? aVal.localeCompare(bVal)
                    : bVal.localeCompare(aVal);
            }
            
            // Numeric comparison
            if (direction === 'asc') {
                return aVal - bVal;
            } else {
                return bVal - aVal;
            }
        });
        
        return sorted;
    }
    
    /**
     * Update sort indicator arrows in table headers
     */
    function updateSortIndicators() {
        // Remove all sort indicators
        $('#violations-table th.sortable').removeClass('sorted-asc sorted-desc');
        
        // Add indicator to current sort column
        $('#violations-table th.sortable[data-sort="' + currentSortColumn + '"]')
            .addClass('sorted-' + currentSortDirection);
    }

    // Close dropdowns when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.policy-dropdown-container').length) {
            $('.policy-dropdown-container.open').removeClass('open')
                .find('.policy-dropdown-menu').hide();
        }
    });

    /**
     * Save bot policies to backend
     */
    function savePolicies() {
        const $saveBtn = $('#violations-save-policies');
        const $loading = $('#violations-save-loading');
        const $error = $('#violations-save-error');

        // Show loading state
        $saveBtn.prop('disabled', true);
        if ($loading.length) {
            $loading.show();
        }
        if ($error.length) {
            $error.hide();
        }

        // Collect changed policies from dropdowns
        $('.policy-dropdown-container.policy-changed').each(function() {
            const botId = $(this).attr('data-bot-id');
            const newValue = $(this).attr('data-new-value');
            botPolicies[botId] = newValue;
        });

        // Convert botPolicies object to array format
        const policies = [];
        Object.keys(botPolicies).forEach(function(bot_registry_id) {
            policies.push({
                bot_registry_id: bot_registry_id,
                action: botPolicies[bot_registry_id]
            });
        });

        debugLog('[Violations] Saving policies:', policies);

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'agent_hub_update_site_bot_policies',
                nonce: agentHubData.nonce,
                policies: policies
            },
            success: function(response) {
                $saveBtn.prop('disabled', false);
                if ($loading.length) {
                    $loading.hide();
                }

                if (response.success) {
                    debugLog('[Violations] Policies saved successfully');
                    
                    // Clear changed policies
                    changedPolicies.clear();
                    
                    // Hide save button
                    $saveBtn.hide();
                    
                    // Show success message
                    const $success = $('#violations-save-success');
                    if ($success.length) {
                        $success.show();
                        setTimeout(function() {
                            $success.fadeOut();
                        }, 3000);
                    }
                } else {
                    console.error('[Violations] Failed to save policies:', response.data);
                    if ($error.length) {
                        $('#violations-save-error-message').text(
                            response.data || 'Failed to save policies'
                        );
                        $error.show();
                    }
                }
            },
            error: function(xhr, status, error) {
                $saveBtn.prop('disabled', false);
                if ($loading.length) {
                    $loading.hide();
                }
                
                console.error('[Violations] Network error saving policies:', error);
                if ($error.length) {
                    $('#violations-save-error-message').text('Network error: ' + error);
                    $error.show();
                }
            }
        });
    }

    /**
     * Show error message
     */
    function showError(message) {
        console.error('[Violations] Error:', message);
        console.log('[Violations] Debug - AJAX URL:', ajaxurl);
        console.log('[Violations] Debug - Nonce:', agentHubData?.nonce);
        console.log('[Violations] Debug - Site URL:', agentHubData?.siteUrl);
        
        $('#violations-error-message').text(message);
        $('#violations-error').show();
    }

    /**
     * Format number with commas
     */
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /**
     * Format datetime string
     */
    function formatDateTime(dateStr) {
        // Return "Never" for null, undefined, or empty strings
        if (!dateStr || dateStr === null) return 'Never';
        
        const date = new Date(dateStr);
        
        // Validate date is actually valid
        if (isNaN(date.getTime())) return 'Never';
        
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return diffMins + ' min ago';
        if (diffHours < 24) return diffHours + ' hour' + (diffHours > 1 ? 's' : '') + ' ago';
        if (diffDays < 30) return diffDays + ' day' + (diffDays > 1 ? 's' : '') + ' ago';
        
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

})(jQuery);
