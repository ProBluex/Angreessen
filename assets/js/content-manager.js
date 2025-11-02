/**
 * Content Manager for Tolliver - Ai Agent Pay Collector
 * Handles content table interactions, filtering, sorting, and bulk operations
 */

(function($) {
    'use strict';
    
    // Debug mode detection
    const DEBUG_MODE = window.location.hostname === 'localhost' || 
                       window.location.hostname.includes('127.0.0.1') ||
                       window.location.search.includes('debug=true');
    const debugLog = DEBUG_MODE ? console.log.bind(console) : () => {};
    const debugWarn = DEBUG_MODE ? console.warn.bind(console) : () => {};
    
    let currentContent = [];
    let filteredContent = [];
    let sortColumn = 'published';
    let sortDirection = 'desc';
    
    /**
     * Initialize content manager when DOM is ready
     */
    $(document).ready(function() {
        debugLog('[ContentManager] Initializing content manager');
        
        // Load content when tab is clicked
        $(document).on('click', '[data-tab="my-content"]', function() {
            setTimeout(loadContent, 100);
        });
        
        // Search functionality
        $(document).on('keyup', '#content-search', handleSearch);
        
        // Sort columns
        $(document).on('click', '.sortable-column', handleSort);
        
        // Select all checkbox
        $(document).on('change', '#select-all-content', handleSelectAll);
        
        // Individual checkboxes
        $(document).on('change', '.content-checkbox', updateSelectAllState);
        
        // Bulk actions
        $(document).on('click', '#bulk-action-apply', handleBulkAction);
        
        // Generate link button
        $(document).on('click', '.generate-link-btn', handleGenerateLink);
        
        // Edit link button
        $(document).on('click', '.edit-link-btn', handleEditLink);
        
        // Human access toggle
        $(document).on('change', '.human-access-toggle', handleHumanAccessToggle);
        
        // Filter by type
        $(document).on('change', '#content-type-filter', handleTypeFilter);
        
        // Filter by link status
        $(document).on('change', '#content-link-filter', handleLinkFilter);
        
        debugLog('[ContentManager] Event handlers registered');
    });
    
    /**
     * Load content from WordPress
     */
    function loadContent() {
        debugLog('[ContentManager] Loading content list');
        
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'agent_hub_get_content',
                nonce: agentHubData.nonce
            },
            beforeSend: function() {
                $('.content-loading').show();
                $('#content-table-container').hide();
            },
            success: function(response) {
                debugLog('[ContentManager] Content loaded:', response);
                
                if (response.success && response.data.content) {
                    currentContent = response.data.content;
                    filteredContent = [...currentContent];
                    renderContent();
                    updateStats();
                } else {
                    console.error('[ContentManager] Failed to load content:', response);
                    showError('Failed to load content');
                }
            },
            error: function(xhr, status, error) {
                console.error('[ContentManager] AJAX error:', error);
                showError('Error loading content: ' + error);
            },
            complete: function() {
                $('.content-loading').hide();
                $('#content-table-container').show();
            }
        });
    }
    
    /**
     * Render content table
     */
    function renderContent() {
        const tbody = $('#content-table-body');
        tbody.empty();
        
        if (filteredContent.length === 0) {
            tbody.html(
                '<tr><td colspan="7" style="text-align:center; padding:40px; color:#666;">' +
                'No content found. Try adjusting your filters or publish some posts.</td></tr>'
            );
            return;
        }
        
        // Sort content
        const sorted = sortContent(filteredContent);
        
        sorted.forEach(item => {
            const linkStatus = item.has_link 
                ? '<span style="color:#00D091;">✓ Protected</span>' 
                : '<span style="color:#999;">Not Protected</span>';
            
            // Human access toggle
            const toggleChecked = item.block_humans ? 'checked' : '';
            const toggleLabel = item.block_humans ? 'Blocked' : 'Allowed';
            const humanAccessToggle = `
                <label class="human-access-toggle-wrapper">
                    <input type="checkbox" class="human-access-toggle" 
                           data-post-id="${item.id}" ${toggleChecked} />
                    <span class="toggle-slider"></span>
                    <span class="toggle-label">${toggleLabel}</span>
                </label>
            `;
            
            const row = `
                <tr>
                    <td>
                        <strong>${escapeHtml(item.title)}</strong>
                        <div style="color:#666; font-size:12px; margin-top:4px;">
                            <a href="${escapeHtml(item.url)}" target="_blank" style="color:#0073aa;">View Post</a>
                        </div>
                    </td>
                    <td>${ucfirst(item.type)}</td>
                    <td>$${formatMoney(item.price)}</td>
                    <td>${formatNumber(item.crawls)}</td>
                    <td>$${formatMoney(item.revenue)}</td>
                    <td>${linkStatus}</td>
                    <td>${humanAccessToggle}</td>
                </tr>
            `;
            tbody.append(row);
        });
        
        debugLog('[ContentManager] Rendered', sorted.length, 'content items');
    }
    
    /**
     * Sort content by column
     */
    function sortContent(content) {
        return [...content].sort((a, b) => {
            let aVal = a[sortColumn];
            let bVal = b[sortColumn];
            
            // Handle different data types
            if (typeof aVal === 'string') {
                aVal = aVal.toLowerCase();
                bVal = bVal.toLowerCase();
            }
            
            if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
            if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
            return 0;
        });
    }
    
    /**
     * Handle search input
     */
    function handleSearch(e) {
        const searchTerm = $(e.target).val().toLowerCase();
        debugLog('[ContentManager] Searching for:', searchTerm);
        
        if (!searchTerm) {
            filteredContent = [...currentContent];
        } else {
            filteredContent = currentContent.filter(item => {
                return item.title.toLowerCase().includes(searchTerm) ||
                       item.type.toLowerCase().includes(searchTerm);
            });
        }
        
        applyFilters();
        renderContent();
    }
    
    /**
     * Handle column sort
     */
    function handleSort(e) {
        const column = $(e.currentTarget).data('sort');
        
        if (sortColumn === column) {
            sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            sortColumn = column;
            sortDirection = 'asc';
        }
        
        // Update sort indicators
        $('.sortable-column').removeClass('sorted-asc sorted-desc');
        $(e.currentTarget).addClass('sorted-' + sortDirection);
        
        debugLog('[ContentManager] Sorting by', column, sortDirection);
        renderContent();
    }
    
    /**
     * Handle select all checkbox
     */
    function handleSelectAll(e) {
        const isChecked = $(e.target).is(':checked');
        $('.content-checkbox').prop('checked', isChecked);
        console.log('[ContentManager] Select all:', isChecked);
    }
    
    /**
     * Update select all state
     */
    function updateSelectAllState() {
        const totalCheckboxes = $('.content-checkbox').length;
        const checkedCheckboxes = $('.content-checkbox:checked').length;
        
        $('#select-all-content').prop('checked', totalCheckboxes === checkedCheckboxes);
    }
    
    /**
     * Handle bulk actions
     */
    function handleBulkAction(e) {
        e.preventDefault();
        
        const action = $('#bulk-action-select').val();
        const selectedIds = $('.content-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        if (!action) {
            showToast('No Action', 'Please select a bulk action', 'warning');
            return;
        }
        
        if (selectedIds.length === 0) {
            showToast('No Selection', 'Please select at least one item', 'warning');
            return;
        }
        
        debugLog('[ContentManager] Bulk action:', action, 'for', selectedIds.length, 'items');
        
        if (action === 'generate') {
            bulkGenerateLinks(selectedIds);
        }
    }
    
    /**
     * Handle bulk sync meta
     */
    
    /**
     * Bulk generate links
     */
    function bulkGenerateLinks(postIds) {
        debugLog('[ContentManager] Bulk generating links for', postIds.length, 'posts');
        
        let completed = 0;
        let failed = 0;
        
        const progressToast = showToast(
            'Generating Links', 
            `Processing ${postIds.length} items...`, 
            'info',
            10000
        );
        
        // Process each post sequentially to avoid overwhelming the server
        const processNext = (index) => {
            if (index >= postIds.length) {
                // All done
                const message = `Generated ${completed} links successfully` + 
                               (failed > 0 ? `, ${failed} failed` : '');
                showToast('Bulk Generation Complete', message, completed > 0 ? 'success' : 'error');
                loadContent(); // Reload content
                return;
            }
            
            const postId = postIds[index];
            
            $.ajax({
                url: agentHubData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'agent_hub_generate_link',
                    nonce: agentHubData.nonce,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        completed++;
                    } else {
                        failed++;
                    }
                },
                error: function() {
                    failed++;
                },
                complete: function() {
                    // Process next item
                    processNext(index + 1);
                }
            });
        };
        
        // Start processing
        processNext(0);
    }
    
    /**
     * Handle single link generation
     */
    function handleGenerateLink(e) {
        e.preventDefault();
        const postId = $(e.currentTarget).data('id');
        
        debugLog('[ContentManager] Generating link for post', postId);
        
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'agent_hub_generate_link',
                nonce: agentHubData.nonce,
                post_id: postId
            },
            beforeSend: function() {
                $(e.currentTarget).prop('disabled', true).text('Generating...');
            },
            success: function(response) {
                if (response.success) {
                    showToast('Success', 'Link generated successfully!', 'success');
                    loadContent(); // Reload content
                } else {
                    showToast('Error', response.data?.message || 'Failed to generate link', 'error');
                }
            },
            error: function(xhr, status, error) {
                showToast('Error', 'Failed to generate link: ' + error, 'error');
            },
            complete: function() {
                $(e.currentTarget).prop('disabled', false).text('Generate Link');
            }
        });
    }
    
    /**
     * Handle edit link
     */
    function handleEditLink(e) {
        e.preventDefault();
        const postId = $(e.currentTarget).data('id');
        
        // Find the content item
        const item = currentContent.find(c => c.id === postId);
        if (!item) return;
        
        debugLog('[ContentManager] Editing link for post', postId);
        
        // TODO: Open modal with link settings
        // For now, just show info
        showToast('Edit Link', 'Link editing modal coming soon!', 'info');
    }
    
    /**
     * Handle human access toggle
     */
    function handleHumanAccessToggle(e) {
        const checkbox = $(e.currentTarget);
        const postId = checkbox.data('post-id');
        const blockHumans = checkbox.is(':checked');
        
        debugLog('[ContentManager] Toggling human access for post', postId, 'block:', blockHumans);
        
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'agent_hub_toggle_human_access',
                nonce: agentHubData.nonce,
                post_id: postId,
                block_humans: blockHumans
            },
            success: function(response) {
                if (response.success) {
                    const label = blockHumans ? 'Blocked' : 'Allowed';
                    checkbox.siblings('.toggle-label').text(label);
                    showToast('Success', 'Human access updated', 'success');
                } else {
                    checkbox.prop('checked', !blockHumans);
                    showToast('Error', response.data?.message || 'Failed to update human access', 'error');
                }
            },
            error: function(xhr, status, error) {
                checkbox.prop('checked', !blockHumans);
                showToast('Error', 'Failed to update human access: ' + error, 'error');
            }
        });
    }
    
    /**
     * Handle type filter
     */
    function handleTypeFilter() {
        applyFilters();
        renderContent();
    }
    
    /**
     * Handle link status filter
     */
    function handleLinkFilter() {
        applyFilters();
        renderContent();
    }
    
    /**
     * Apply all active filters
     */
    function applyFilters() {
        const typeFilter = $('#content-type-filter').val();
        const linkFilter = $('#content-link-filter').val();
        const searchTerm = $('#content-search').val().toLowerCase();
        
        filteredContent = currentContent.filter(item => {
            // Type filter
            if (typeFilter && item.type !== typeFilter) return false;
            
            // Link status filter
            if (linkFilter === 'protected' && !item.has_link) return false;
            if (linkFilter === 'unprotected' && item.has_link) return false;
            
            // Search filter
            if (searchTerm && !item.title.toLowerCase().includes(searchTerm)) return false;
            
            return true;
        });
        
        debugLog('[ContentManager] Filtered to', filteredContent.length, 'items');
    }
    
    /**
     * Update content stats
     */
    function updateStats() {
        const total = currentContent.length;
        const protectedCount = currentContent.filter(c => c.has_link).length;
        const unprotected = total - protectedCount;
        
        $('#stat-total-content').text(formatNumber(total));
        $('#stat-protected-content').text(formatNumber(protectedCount));
        $('#stat-unprotected-content').text(formatNumber(unprotected));
    }
    
    /**
     * Utility functions
     */
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    
    function formatMoney(amount) {
        return parseFloat(amount || 0).toFixed(3);
    }
    
    function ucfirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function showError(message) {
        console.error('[ContentManager]', message);
        if (typeof showToast === 'function') {
            showToast('Content Manager Error', message, 'error');
        }
    }
    
    // Expose functions globally
    window.agentHubContent = {
        loadContent: loadContent,
        refreshContent: loadContent
    };
    
    console.log('[ContentManager] Module loaded successfully');
    
})(jQuery);
