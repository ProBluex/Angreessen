<?php
/**
 * Violations Dashboard Template
 * Displays agent violations with filtering and sorting
 */

if (!defined('ABSPATH')) {
    exit;
}

$angreessen49_site_id = get_option('angreessen49_site_id');
?>

<div class="wrap angreessen49-violations">
    <div class="violations-header-row">
        <div class="violations-title-section">
            <div class="violations-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div>
                <h1>Agent Violations</h1>
                <p class="violations-subtitle">Monitor AI agents violating your access policies</p>
            </div>
        </div>
        <div class="violations-actions">
            <button id="btn-save-policies" class="button-save-policies" style="display: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                Save Policy Changes
            </button>
        </div>
    </div>
    
    <?php if (!$angreessen49_site_id): ?>
        <div class="notice notice-error">
            <p><strong>Site Not Registered:</strong> Please register your site in the main <a href="<?php echo esc_url(admin_url('admin.php?page=angreessen49')); ?>">Angreessen dashboard</a> first.</p>
        </div>
        <?php return; ?>
    <?php endif; ?>
    
    <!-- Statistics Cards -->
    <div class="violations-stats-grid">
        <div class="stat-card-modern">
            <div class="stat-icon stat-icon-rose">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value" id="stat-total">-</span>
                <span class="stat-label">Total Violations</span>
            </div>
        </div>
        <div class="stat-card-modern">
            <div class="stat-icon stat-icon-orange">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="9" y1="15" x2="15" y2="15"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value" id="stat-robots">-</span>
                <span class="stat-label">Robots.txt Violations</span>
            </div>
        </div>
        <div class="stat-card-modern">
            <div class="stat-icon stat-icon-amber">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value" id="stat-unpaid">-</span>
                <span class="stat-label">Unpaid Access</span>
            </div>
        </div>
        <div class="stat-card-modern">
            <div class="stat-icon stat-icon-indigo">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <div class="stat-content">
                <span class="stat-value" id="stat-unique-agents">-</span>
                <span class="stat-label">Unique Agents</span>
            </div>
        </div>
    </div>
    
    <!-- Table Card -->
    <div class="violations-card">
        <!-- Search Bar -->
        <div class="violations-search-bar">
            <div class="violations-search-input-wrapper">
                <svg class="violations-search-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" id="violations-search" class="violations-search-input" placeholder="Search agents...">
            </div>
            <div class="filter-dropdown-wrapper">
                <select id="filter-violation-type">
                    <option value="">All Types</option>
                    <option value="robots_txt">Robots.txt</option>
                    <option value="unpaid_access">Unpaid Access</option>
                    <option value="ignored_402">Ignored 402</option>
                </select>
            </div>
        </div>
        
        <!-- Loading State -->
        <div id="violations-loading" class="violations-state-message">
            <div class="loading-spinner"></div>
            <p>Loading violations...</p>
        </div>
        
        <!-- Error State -->
        <div id="violations-error" class="violations-state-message violations-error" style="display: none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <line x1="15" y1="9" x2="9" y2="15"/>
                <line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
            <span id="error-message">Failed to load violations</span>
        </div>
        
        <!-- Empty State -->
        <div id="violations-empty" class="violations-state-message violations-empty" style="display: none;">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <h3>No Violations Detected</h3>
            <p>All AI agents are respecting your access policies.</p>
        </div>
        
        <!-- Table -->
        <table id="violations-table" class="violations-table" style="display: none;">
            <thead>
                <tr>
                    <th>Agent Name</th>
                    <th>Total</th>
                    <th>Robots.txt</th>
                    <th>Unpaid Access</th>
                    <th>Last Seen</th>
                    <th>Policy</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="violations-tbody">
                <!-- Populated by JavaScript -->
            </tbody>
        </table>
    </div>
</div>
