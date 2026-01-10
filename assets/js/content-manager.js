/**
 * Content Manager for Agent Angreessen - Ai Agent Pay Collector
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
    let currentPage = 1;
    let totalPages = 1;
    let totalPosts = 0;
    let perPage = 20; // Default to 20 posts per page
    
    /**
     * Initialize content manager when DOM is ready
     */
    $(document).ready(function() {
        debugLog('[ContentManager] Initializing content manager');
        
        // Load content when tab is clicked (data-tab="content" matches the button)
        $(document).on('click', '[data-tab="content"]', function() {
            setTimeout(() => loadContent(1), 100);
        });
        
        // Pagination click handlers
        $(document).on('click', '.content-page-link', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page && page !== currentPage) {
                loadContent(page);
            }
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
        
        // Refresh button
        $(document).on('click', '#refresh-content', function() {
            loadContent(currentPage);
        });
        
        // Retry link button for failed posts
        $(document).on('click', '.retry-link-btn', function(e) {
            e.preventDefault();
            const postId = $(this).data('post-id');
            const btn = $(this);
            
            debugLog('[ContentManager] Retrying link generation for post', postId);
            
            btn.prop('disabled', true).text('Retrying...');
            
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
                        showToast('Success', 'Link generated successfully!', 'success');
                        loadContent(currentPage);
                    } else {
                        showToast('Error', response.data?.message || 'Failed to generate link', 'error');
                        btn.prop('disabled', false).text('Retry');
                    }
                },
                error: function() {
                    showToast('Error', 'Network error occurred', 'error');
                    btn.prop('disabled', false).text('Retry');
                }
            });
        });
        
        debugLog('[ContentManager] Event handlers registered');
    });
    
    /**
     * Load content from WordPress with pagination (instant - no blocking API calls)
     */
    function loadContent(page = 1) {
        debugLog('[ContentManager] Loading content list - Page:', page);
        
        currentPage = page;
        
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            timeout: 10000, // 10 second timeout to prevent infinite hang
            data: {
                action: 'agent_hub_get_content',
                nonce: agentHubData.nonce,
                page: page,
                per_page: perPage
            },
            beforeSend: function() {
                $('.content-loading').show();
                $('#content-table-container').hide();
                $('#content-pagination').hide();
            },
            success: function(response) {
                debugLog('[ContentManager] Content loaded:', response);
                
                if (response.success && response.data.content) {
                    currentContent = response.data.content;
                    filteredContent = [...currentContent];
                    
                    // Update pagination info
                    if (response.data.pagination) {
                        currentPage = response.data.pagination.current_page;
                        totalPages = response.data.pagination.total_pages;
                        totalPosts = response.data.pagination.total_posts;
                        debugLog('[ContentManager] Pagination:', currentPage, 'of', totalPages, '- Total posts:', totalPosts);
                    }
                    
                    renderContent();
                    renderPagination();
                    updateStats();
                    updatePostCountIndicator();
                    
                    // Fetch analytics in background (non-blocking)
                    loadContentAnalytics();
                } else {
                    console.error('[ContentManager] Failed to load content:', response);
                    showError('Failed to load content');
                }
            },
            error: function(xhr, status, error) {
                console.error('[ContentManager] AJAX error:', status, error);
                if (status === 'timeout') {
                    showError('Content loading timed out. Please try refreshing.');
                } else {
                    showError('Error loading content: ' + error);
                }
            },
            complete: function() {
                $('.content-loading').hide();
                $('#content-table-container').show();
                $('#content-pagination').show();
            }
        });
    }
    
    /**
     * Load content analytics asynchronously (non-blocking)
     * Called after content is displayed to fetch crawls/revenue data
     */
    function loadContentAnalytics() {
        debugLog('[ContentManager] Loading analytics in background...');
        
        $.ajax({
            url: agentHubData.ajaxUrl,
            type: 'POST',
            timeout: 15000, // Allow more time for analytics API
            data: {
                action: 'agent_hub_get_content_analytics',
                nonce: agentHubData.nonce
            },
            success: function(response) {
                if (response.success && response.data.page_stats) {
                    const stats = response.data.page_stats;
                    debugLog('[ContentManager] Analytics loaded:', Object.keys(stats).length, 'pages');
                    
                    // Update currentContent with analytics data
                    currentContent.forEach(item => {
                        if (stats[item.id]) {
                            item.crawls = stats[item.id].crawls || 0;
                            item.revenue = stats[item.id].revenue || 0;
                        }
                    });
                    
                    // Update filteredContent as well
                    filteredContent.forEach(item => {
                        if (stats[item.id]) {
                            item.crawls = stats[item.id].crawls || 0;
                            item.revenue = stats[item.id].revenue || 0;
                        }
                    });
                    
                    // Analytics data is now available in the content objects
                    debugLog('[ContentManager] Analytics merged into content data');
                }
            },
            error: function(xhr, status, error) {
                // Silently fail - analytics are non-critical
                debugLog('[ContentManager] Analytics fetch failed (non-critical):', status);
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
                : `<span style="color:#999;">Not Protected</span>
                   <button class="retry-link-btn button-link" data-post-id="${item.id}" 
                           style="margin-left:8px; padding:2px 8px; font-size:11px; cursor:pointer; background:#2563EB; color:white; border:none; border-radius:4px;">
                     Retry
                   </button>`;
            
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
                loadContent(currentPage); // Reload current page
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
                    loadContent(currentPage); // Reload current page
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
     * Update post count indicator
     */
    function updatePostCountIndicator() {
        const startIndex = (currentPage - 1) * perPage + 1;
        const endIndex = Math.min(currentPage * perPage, totalPosts);
        const countText = `Showing ${startIndex}-${endIndex} of ${formatNumber(totalPosts)} posts`;
        $('#post-count-indicator').text(countText);
    }
    
    /**
     * Render pagination controls
     */
    function renderPagination() {
        const container = $('#content-pagination');
        container.empty();
        
        if (totalPages <= 1) {
            return;
        }
        
        const paginationHtml = [];
        paginationHtml.push('<div class="tablenav-pages">');
        paginationHtml.push(`<span class="displaying-num">${formatNumber(totalPosts)} items</span>`);
        paginationHtml.push('<span class="pagination-links">');
        
        // First page
        if (currentPage > 1) {
            paginationHtml.push(`<a class="first-page button content-page-link" data-page="1" href="#">«</a>`);
            paginationHtml.push(`<a class="prev-page button content-page-link" data-page="${currentPage - 1}" href="#">‹</a>`);
        } else {
            paginationHtml.push('<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>');
            paginationHtml.push('<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>');
        }
        
        // Page numbers
        paginationHtml.push('<span class="paging-input">');
        paginationHtml.push(`<span class="tablenav-paging-text">${currentPage} of <span class="total-pages">${totalPages}</span></span>`);
        paginationHtml.push('</span>');
        
        // Last page
        if (currentPage < totalPages) {
            paginationHtml.push(`<a class="next-page button content-page-link" data-page="${currentPage + 1}" href="#">›</a>`);
            paginationHtml.push(`<a class="last-page button content-page-link" data-page="${totalPages}" href="#">»</a>`);
        } else {
            paginationHtml.push('<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>');
            paginationHtml.push('<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>');
        }
        
        paginationHtml.push('</span>');
        paginationHtml.push('</div>');
        
        container.html(paginationHtml.join(''));
        debugLog('[ContentManager] Rendered pagination:', currentPage, 'of', totalPages);
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
    
    // Expose to agentHub namespace (used by admin.js)
    window.agentHub = window.agentHub || {};
    window.agentHub.loadContent = loadContent;
    window.agentHub.refreshContent = loadContent;
    
    // Also expose to agentHubContent for backwards compatibility
    window.agentHubContent = {
        loadContent: loadContent,
        refreshContent: loadContent
    };
    
    console.log('[ContentManager] Module loaded successfully');
    
})(jQuery);
