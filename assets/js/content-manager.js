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
                url: angreessen49Data.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'angreessen49_generate_link',
                    nonce: angreessen49Data.nonce,
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
            url: angreessen49Data.ajaxUrl,
            type: 'POST',
            timeout: 10000, // 10 second timeout to prevent infinite hang
            data: {
                action: 'angreessen49_get_content',
                nonce: angreessen49Data.nonce,
                page: page,
                per_page: perPage
            },
            beforeSend: function() {
                $('#content-loading-state').show();
                $('#content-table-container').hide();
                $('#content-empty-state').hide();
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
                    
                    // Fetch analytics in background (non-blocking)
                    loadContentAnalytics();
                } else {
                    console.error('[ContentManager] Failed to load content:', response);
                    $('#content-empty-state').show();
                }
            },
            error: function(xhr, status, error) {
                console.error('[ContentManager] AJAX error:', status, error);
                if (status === 'timeout') {
                    showToast('Timeout', 'Content loading timed out. Please try refreshing.', 'warning');
                }
                $('#content-empty-state').show();
            },
            complete: function() {
                $('#content-loading-state').hide();
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
            url: angreessen49Data.ajaxUrl,
            type: 'POST',
            timeout: 15000, // Allow more time for analytics API
            data: {
                action: 'angreessen49_get_content_analytics',
                nonce: angreessen49Data.nonce
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
            $('#content-table-container').hide();
            $('#content-empty-state').show();
            $('#content-pagination').hide();
            return;
        }
        
        $('#content-empty-state').hide();
        $('#content-table-container').show();
        
        // Sort content
        const sorted = sortContent(filteredContent);
        
        sorted.forEach(item => {
            // Status badge for agent protection
            const agentStatusClass = item.has_link ? 'status-badge-active' : 'status-badge-inactive';
            const agentStatusText = item.has_link ? 'Active' : 'Inactive';
            
            // Human access toggle
            const toggleChecked = item.block_humans ? 'checked' : '';
            const humanStatusClass = item.block_humans ? 'status-badge-blocked' : 'status-badge-allowed';
            const humanStatusText = item.block_humans ? 'Blocked' : 'Allowed';
            
            // Build action links (WordPress style - under title)
            let actionLinks = [];
            
            // Post View link (primary action - uses paid link if available)
            const viewUrl = item.has_link && item.paid_link ? item.paid_link : item.url;
            actionLinks.push(`<a href="${escapeHtml(viewUrl)}" target="_blank" class="action-link-primary">
                <svg class="action-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
                Post View
            </a>`);
            
            // View Link for Humans (user icon) - only if humans blocked
            if (item.block_humans && item.has_link) {
                actionLinks.push(`<a href="${escapeHtml(item.human_paid_link || item.url)}" target="_blank" class="action-link-human">
                    <svg class="action-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                    Human View
                </a>`);
            }
            
            // Generate link button if no link
            if (!item.has_link) {
                actionLinks.push(`<button class="retry-link-btn action-link-generate" data-post-id="${item.id}">Generate Link</button>`);
            }
            
            const row = `
                <tr>
                    <td>
                        <div class="content-title-cell">
                            <div class="content-title-text">${escapeHtml(item.title)}</div>
                            <div class="content-actions-row">
                                ${actionLinks.join(' <span class="action-separator">|</span> ')}
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="type-badge">${ucfirst(item.type)}</span>
                    </td>
                    <td>
                        <span class="price-value">$${formatMoney(item.price)}</span>
                    </td>
                    <td>
                        <span class="status-badge ${agentStatusClass}">${agentStatusText}</span>
                    </td>
                    <td>
                        <label class="human-toggle-wrapper">
                            <input type="checkbox" class="human-access-toggle" 
                                   data-post-id="${item.id}" ${toggleChecked} />
                            <span class="toggle-slider"></span>
                            <span class="toggle-label ${humanStatusClass}">${humanStatusText}</span>
                        </label>
                    </td>
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
                url: angreessen49Data.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'angreessen49_generate_link',
                    nonce: angreessen49Data.nonce,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        completed++;
                    } else {
                        failed++;
                    }
                    processNext(index + 1);
                },
                error: function() {
                    failed++;
                    processNext(index + 1);
                }
            });
        };
        
        processNext(0);
    }
    
    /**
     * Handle generate link button
     */
    function handleGenerateLink(e) {
        e.preventDefault();
        const postId = $(e.currentTarget).data('post-id');
        const btn = $(e.currentTarget);
        
        debugLog('[ContentManager] Generating link for post', postId);
        
        btn.prop('disabled', true).text('Generating...');
        
        $.ajax({
            url: angreessen49Data.ajaxUrl,
            type: 'POST',
            data: {
                action: 'angreessen49_generate_link',
                nonce: angreessen49Data.nonce,
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    showToast('Success', 'Link generated successfully!', 'success');
                    loadContent(currentPage);
                } else {
                    showToast('Error', response.data?.message || 'Failed to generate link', 'error');
                    btn.prop('disabled', false).text('Generate');
                }
            },
            error: function() {
                showToast('Error', 'Network error occurred', 'error');
                btn.prop('disabled', false).text('Generate');
            }
        });
    }
    
    /**
     * Handle edit link button
     */
    function handleEditLink(e) {
        e.preventDefault();
        const postId = $(e.currentTarget).data('post-id');
        debugLog('[ContentManager] Edit link for post', postId);
        // TODO: Open edit modal
        showToast('Coming Soon', 'Link editing will be available in a future update', 'info');
    }
    
    /**
     * Handle human access toggle
     */
    function handleHumanAccessToggle(e) {
        const postId = $(e.currentTarget).data('post-id');
        const blockHumans = $(e.currentTarget).is(':checked');
        const label = $(e.currentTarget).siblings('.toggle-label');
        
        debugLog('[ContentManager] Toggle human access for post', postId, '- Block:', blockHumans);
        
        $.ajax({
            url: angreessen49Data.ajaxUrl,
            type: 'POST',
            data: {
                action: 'angreessen49_toggle_human_access',
                nonce: angreessen49Data.nonce,
                post_id: postId,
                block_humans: blockHumans ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    // Update label
                    if (blockHumans) {
                        label.removeClass('status-badge-allowed').addClass('status-badge-blocked').text('Blocked');
                    } else {
                        label.removeClass('status-badge-blocked').addClass('status-badge-allowed').text('Allowed');
                    }
                    showToast('Updated', `Human access ${blockHumans ? 'blocked' : 'allowed'}`, 'success');
                } else {
                    // Revert toggle
                    $(e.currentTarget).prop('checked', !blockHumans);
                    showToast('Error', response.data?.message || 'Failed to update', 'error');
                }
            },
            error: function() {
                // Revert toggle
                $(e.currentTarget).prop('checked', !blockHumans);
                showToast('Error', 'Network error occurred', 'error');
            }
        });
    }
    
    /**
     * Handle type filter
     */
    function handleTypeFilter(e) {
        applyFilters();
        renderContent();
    }
    
    /**
     * Handle link filter
     */
    function handleLinkFilter(e) {
        applyFilters();
        renderContent();
    }
    
    /**
     * Apply all filters
     */
    function applyFilters() {
        const typeFilter = $('#content-type-filter').val();
        const linkFilter = $('#content-link-filter').val();
        const searchTerm = $('#content-search').val().toLowerCase();
        
        filteredContent = currentContent.filter(item => {
            // Type filter
            if (typeFilter && item.type !== typeFilter) return false;
            
            // Link filter
            if (linkFilter === 'has_link' && !item.has_link) return false;
            if (linkFilter === 'no_link' && item.has_link) return false;
            
            // Search filter
            if (searchTerm) {
                const matchesSearch = item.title.toLowerCase().includes(searchTerm) ||
                                     item.type.toLowerCase().includes(searchTerm);
                if (!matchesSearch) return false;
            }
            
            return true;
        });
    }
    
    /**
     * Render pagination
     */
    function renderPagination() {
        const container = $('#content-pagination');
        container.empty();
        
        if (totalPages <= 1) {
            container.hide();
            return;
        }
        
        container.show();
        
        let html = '<div class="content-pagination-controls">';
        
        // Previous button
        if (currentPage > 1) {
            html += `<a href="#" class="content-page-link" data-page="${currentPage - 1}">← Previous</a>`;
        }
        
        // Page numbers
        html += `<span class="content-page-info">Page ${currentPage} of ${totalPages}</span>`;
        
        // Next button
        if (currentPage < totalPages) {
            html += `<a href="#" class="content-page-link" data-page="${currentPage + 1}">Next →</a>`;
        }
        
        html += '</div>';
        container.html(html);
    }
    
    /**
     * Update stats
     */
    function updateStats() {
        // Could update any stats displays here
    }
    
    /**
     * Show toast notification
     */
    function showToast(title, message, type, duration = 4000) {
        if (typeof window.showToast === 'function') {
            return window.showToast(title, message, type, duration);
        }
        console.log(`[${type.toUpperCase()}] ${title}: ${message}`);
    }
    
    /**
     * Utility functions
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function ucfirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }
    
    function formatMoney(amount) {
        const n = Number(amount || 0);
        return Number.isFinite(n) ? n.toFixed(2) : '0.00';
    }
    
})(jQuery);
