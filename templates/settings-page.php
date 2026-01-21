<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

$angreessen49_settings = get_option('angreessen49_settings');
$angreessen49_api_key = get_option('angreessen49_api_key');
$angreessen49_site_id = get_option('angreessen49_site_id');
$angreessen49_is_connected = !empty($angreessen49_api_key) && !empty($angreessen49_site_id);
?>

<div class="wrap angreessen49-dashboard">
    <!-- Modern Header -->
    <div class="angreessen-header">
        <header class="angreessen-header-inner">
            <!-- Brand Section -->
            <div class="header-brand">
                <div class="brand-logo-wrapper">
                    <img src="<?php echo esc_url(ANGREESSEN49_PLUGIN_URL . 'assets/images/angreessen-logo.png'); ?>" 
                         alt="Agent Angreessen" class="brand-logo">
                </div>
                <div class="brand-info">
                    <span class="brand-name">Agent Angreessen</span>
                    <span class="brand-tagline">AI Agent Pay Collector</span>
                </div>
            </div>
            
            <!-- Connection Status -->
            <div class="connection-pill <?php echo $angreessen49_is_connected ? 'connected' : 'disconnected'; ?>">
                <span class="status-dot"></span>
                <?php echo $angreessen49_is_connected ? 'Connected' : 'Offline'; ?>
            </div>
        </header>
    </div>
    
    <div class="angreessen49-tabs">
        <button class="tab-button active" data-tab="overview">
            <i data-feather="grid"></i>
            Overview
        </button>
        <button class="tab-button" data-tab="content">
            <i data-feather="file-text"></i>
            My Content
        </button>
        <button class="tab-button" data-tab="analytics">
            <i data-feather="trending-up"></i>
            Analytics
        </button>
        <button class="tab-button" data-tab="violations">
            <i data-feather="shield-off"></i>
            Violations
        </button>
        <button class="tab-button" data-tab="contact">
            <i data-feather="mail"></i>
            Contact Us
        </button>
    </div>
    
    <!-- Overview Tab -->
    <div id="tab-overview" class="tab-content active">
        <div class="angreessen49-stats-grid">
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <i data-feather="eye"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total AI Agent Crawls</div>
                    <div class="stat-value" id="total-crawls">Loading...</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <i data-feather="check-circle"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Paid Crawls</div>
                    <div class="stat-value" id="paid-crawls">Loading...</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <i data-feather="dollar-sign"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value" id="total-revenue">$0.000</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <i data-feather="lock"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Protected Pages</div>
                    <div class="stat-value" id="protected-pages">Loading...</div>
                </div>
            </div>
        </div>
        
        <div class="angreessen49-config-card">
            <h3><i data-feather="settings"></i> Configuration</h3>
            
            <?php if ($angreessen49_site_id): ?>
                <div class="config-site-id">
                    <span class="site-id-label">Site ID:</span>
                    <?php if ($angreessen49_is_connected): ?>
                        <span class="site-id-badge connected">
                            <i data-feather="check-circle"></i>
                            <code><?php echo esc_html($angreessen49_site_id); ?></code>
                        </span>
                    <?php else: ?>
                        <span class="site-id-badge disconnected">
                            <i data-feather="alert-circle"></i>
                            <code><?php echo esc_html($angreessen49_site_id); ?></code>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="config-fields">
                <div class="config-field">
                    <label for="overview-payment-wallet">
                        <strong>Payment Wallet Address (Base Network)</strong>
                        <span class="required-indicator">*</span>
                    </label>
                    <?php
                    // Determine initial sync status based on saved wallet
                    $angreessen49_saved_wallet = $angreessen49_settings['payment_wallet'] ?? '';
                    $angreessen49_has_wallet = !empty(trim($angreessen49_saved_wallet));
                    
                    // Set indicator state
                    if ($angreessen49_has_wallet) {
                        $angreessen49_indicator_class = 'wallet-sync-indicator wallet-status-synced';
                        $angreessen49_dot_color = 'green';
                        $angreessen49_status_text = 'Synced';
                    } else {
                        $angreessen49_indicator_class = 'wallet-sync-indicator wallet-status-empty';
                        $angreessen49_dot_color = 'gray';
                        $angreessen49_status_text = 'Not synced';
                    }
                    ?>
                    <div class="wallet-input-wrapper">
                        <input type="text" id="overview-payment-wallet" class="config-input" 
                               value="<?php echo esc_attr($angreessen49_settings['payment_wallet'] ?? ''); ?>" 
                               placeholder="0x..." />
                        <div id="wallet-sync-indicator" class="<?php echo esc_attr($angreessen49_indicator_class); ?>" data-server-rendered="true">
                            <span class="status-dot <?php echo esc_attr($angreessen49_dot_color); ?>"></span>
                            <span class="status-text"><?php echo esc_html($angreessen49_status_text); ?></span>
                        </div>
                    </div>
                    <p class="config-description">
                        Wallet service providers: 
                        <a href="https://metamask.io/" target="_blank" rel="noopener noreferrer">Metamask</a>, 
                        <a href="https://phantom.app/" target="_blank" rel="noopener noreferrer">Phantom</a>, 
                        <a href="https://www.coinbase.com/wallet" target="_blank" rel="noopener noreferrer">Coinbase</a>
                    </p>
                </div>
                
                <div class="config-field">
                    <label for="overview-default-price">
                        <strong>Default Price Per Page (USD)</strong>
                    </label>
                    <input type="number" id="overview-default-price" class="config-input" 
                           step="0.01" min="0"
                           value="<?php echo esc_attr($angreessen49_settings['default_price'] ?? 0.10); ?>" />
                    <p class="config-description">Default price for AI agents to access each page</p>
                </div>
                
                <div class="config-actions">
                    <button type="button" id="save-overview-config" class="button button-primary">
                        <i data-feather="save"></i>
                        Save Configuration
                    </button>
                </div>
            </div>
        </div>
        
        <div class="angreessen49-info-box">
            <h3>
                <i data-feather="info"></i> 
                About Agent Angreessen - Ai Agent Pay Collector
            </h3>
            <p>This plugin automatically detects AI agents (like GPTBot, ClaudeBot, etc.) accessing your WordPress content and requires payment via the x402 protocol before granting access.</p>
            
            <h4>How It Works:</h4>
            <ol>
                <li><strong>Detection:</strong> AI agents are identified by their user-agent strings</li>
                <li><strong>402 Response:</strong> Agents receive a "Payment Required" response with payment details</li>
                <li><strong>Payment:</strong> Agents pay in USDC on Base network via CDP facilitator</li>
                <li><strong>Access:</strong> After payment verification, agents get instant access</li>
                <li><strong>Revenue:</strong> Payments flow directly to your wallet address</li>
            </ol>
        </div>
    </div>
    
    <!-- Content Tab -->
    <div id="tab-content" class="tab-content">
        <!-- Page Header (matches violations design) -->
        <div class="content-page-header">
            <div class="content-header-left">
                <h2 class="content-title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                    My Content
                </h2>
                <p class="content-subtitle">Manage your protected content and payment links</p>
            </div>
            <button id="bulk-generate-links" class="content-generate-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                    <path d="M3 3v5h5"/>
                    <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                    <path d="M16 16h5v5"/>
                </svg>
                Generate Paid Links
            </button>
        </div>
        
        <!-- Card Container -->
        <div class="content-card">
            <!-- Search & Filter Bar -->
            <div class="content-search-bar">
                <div class="content-search-input-wrapper">
                    <svg class="content-search-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="content-search" class="content-search-input" placeholder="Search content...">
                </div>
                <button id="refresh-content" class="content-refresh-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                        <path d="M3 3v5h5"/>
                        <path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/>
                        <path d="M16 16h5v5"/>
                    </svg>
                    Refresh
                </button>
            </div>
            
            <!-- Loading State (hidden by default, shown when loading) -->
            <div id="content-loading-state" class="content-loading-state" style="display: none;">
                <span class="spinner is-active"></span>
                Loading content...
            </div>
            
            <!-- Table Container -->
            <div id="content-table-container" style="display: none;">
                <table class="content-table" id="content-table">
                    <thead>
                        <tr>
                            <th data-sort="title" class="sortable-column">Title</th>
                            <th data-sort="type" class="sortable-column">Type</th>
                            <th data-sort="price" class="sortable-column">Price</th>
                            <th>Agents Pay</th>
                            <th>Humans Pay</th>
                        </tr>
                    </thead>
                    <tbody id="content-table-body"></tbody>
                </table>
            </div>
            
            <!-- Empty State -->
            <div id="content-empty-state" class="content-empty-state" style="display: none;">
                <div class="content-empty-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                </div>
                <h3>No content found</h3>
                <p>Publish some posts to get started with monetization</p>
            </div>
            
            <!-- Pagination Footer -->
            <div id="content-pagination" class="content-pagination"></div>
        </div>
    </div>
    
    <!-- Analytics Tab -->
    <div id="tab-analytics" class="tab-content">
        <div class="analytics-filters">
            <label>Timeframe:</label>
            <select id="analytics-timeframe">
                <option value="7d">Last 7 Days</option>
                <option value="30d" selected>Last 30 Days</option>
                <option value="90d">Last 90 Days</option>
                <option value="all">All Time</option>
            </select>
        </div>
        
        <!-- Ecosystem Stats Cards with Sparklines -->
        <div class="analytics-section">
            <h3>HTTP 402 Ecosystem</h3>
            <div class="angreessen49-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                <div class="stat-card stat-card-with-sparkline">
                    <div class="stat-header">
                        <div class="stat-icon stat-icon-flat">
                            <i data-feather="bar-chart-2"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Total Transactions</div>
                            <div class="stat-value" id="stat-ecosystem-transactions">0</div>
                            <div class="stat-trend" id="trend-transactions"></div>
                        </div>
                    </div>
                    <div class="sparkline-container">
                        <canvas id="sparkline-transactions"></canvas>
                    </div>
                </div>
                
                <div class="stat-card stat-card-with-sparkline">
                    <div class="stat-header">
                        <div class="stat-icon stat-icon-flat">
                            <i data-feather="dollar-sign"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Total Volume</div>
                            <div class="stat-value" id="stat-market-revenue">$0.000</div>
                            <div class="stat-trend" id="trend-volume"></div>
                        </div>
                    </div>
                    <div class="sparkline-container">
                        <canvas id="sparkline-volume"></canvas>
                    </div>
                </div>
                
                <div class="stat-card stat-card-with-sparkline">
                    <div class="stat-header">
                        <div class="stat-icon stat-icon-flat">
                            <i data-feather="users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Unique Buyers</div>
                            <div class="stat-value" id="stat-ecosystem-buyers">0</div>
                            <div class="stat-trend" id="trend-buyers"></div>
                        </div>
                    </div>
                    <div class="sparkline-container">
                        <canvas id="sparkline-buyers"></canvas>
                    </div>
                </div>
                
                <div class="stat-card stat-card-with-sparkline">
                    <div class="stat-header">
                        <div class="stat-icon stat-icon-flat">
                            <i data-feather="shopping-bag"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-label">Unique Sellers</div>
                            <div class="stat-value" id="stat-ecosystem-sellers">0</div>
                            <div class="stat-trend" id="trend-sellers"></div>
                        </div>
                    </div>
                    <div class="sparkline-container">
                        <canvas id="sparkline-sellers"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Market Overview Chart -->
        <div class="analytics-section">
            <h3>Market Overview</h3>
            <div class="chart-container">
                <canvas id="market-overview-chart" height="300"></canvas>
            </div>
        </div>
        
        <!-- Top Pages Section -->
        <div class="analytics-section">
            <h3>Top Performing Pages</h3>
            <div id="top-pages-list" class="top-pages-list"></div>
            <div id="top-pages-pagination" class="top-pages-pagination"></div>
        </div>
        
        <!-- Facilitators Section -->
        <div class="analytics-section">
            <h3>Payment Facilitators</h3>
            <div class="facilitators-grid">
                <div id="facilitators-list" class="facilitators-list"></div>
                <div id="facilitators-chart" class="facilitators-chart">
                    <canvas id="facilitators-pie-chart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Violations Tab -->
    <div id="tab-violations" class="tab-content">
        <!-- Stats Row -->
        <div class="violations-stats-row">
            <div class="violations-stat-card">
                <div class="stat-icon stat-icon-rose">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                </div>
                <div class="stat-content">
                    <div class="stat-value" id="violations-total">0</div>
                    <div class="stat-label">Total Violations</div>
                </div>
            </div>
            
            <div class="violations-stat-card">
                <div class="stat-icon stat-icon-amber">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg>
                </div>
                <div class="stat-content">
                    <div class="stat-value" id="violations-robots">0</div>
                    <div class="stat-label">Robots.txt</div>
                </div>
            </div>
            
            <div class="violations-stat-card">
                <div class="stat-icon stat-icon-purple">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                </div>
                <div class="stat-content">
                    <div class="stat-value" id="violations-unpaid">0</div>
                    <div class="stat-label">Unpaid Access</div>
                </div>
            </div>
            
            <div class="violations-stat-card">
                <div class="stat-icon stat-icon-blue">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <div class="stat-content">
                    <div class="stat-value" id="violations-unique-agents">0</div>
                    <div class="stat-label">Unique Agents</div>
                </div>
            </div>
        </div>
        
        <!-- Violations Table -->
        <div class="violations-table-card">
            <div class="violations-table-header">
                <h3>Agent Violations</h3>
                <div class="violations-search-wrapper">
                    <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="violations-search" placeholder="Search agents...">
                </div>
            </div>
            
            <!-- Loading State -->
            <div id="violations-loading" class="violations-loading">
                <span class="spinner is-active"></span>
                <span>Loading violations data...</span>
            </div>
            
            <!-- Table -->
            <table id="violations-table" class="violations-table" style="display: none;">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="agent_name">Agent</th>
                        <th class="sortable" data-sort="total_violations">Total</th>
                        <th class="sortable" data-sort="robots_txt_violations">Robots.txt</th>
                        <th class="sortable" data-sort="unpaid_access_violations">Unpaid</th>
                        <th class="sortable" data-sort="last_seen">Last Seen</th>
                        <th>Policy</th>
                    </tr>
                </thead>
                <tbody id="violations-table-body"></tbody>
            </table>
            
            <!-- Empty State -->
            <div id="violations-empty" class="violations-empty" style="display: none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <h4>All Clear!</h4>
                <p>No violations detected. All AI agents are respecting your access policies.</p>
            </div>
            
            <!-- Policy Actions -->
            <div id="violations-policy-actions" class="violations-policy-actions" style="display: none;">
                <button id="violations-save-policies" class="button button-primary" style="display: none;">
                    Save Policy Changes
                </button>
            </div>
        </div>
    </div>
    
    <!-- Contact Tab -->
    <div id="tab-contact" class="tab-content">
        <div class="contact-section">
            <h3><i data-feather="mail"></i> Contact Us</h3>
            <p>Have questions or need support? Reach out to us!</p>
            
            <div class="contact-form">
                <div class="form-field">
                    <label for="contact-name">Your Name</label>
                    <input type="text" id="contact-name" placeholder="Enter your name">
                </div>
                
                <div class="form-field">
                    <label for="contact-email">Email Address</label>
                    <input type="email" id="contact-email" placeholder="Enter your email">
                </div>
                
                <div class="form-field">
                    <label for="contact-subject">Subject</label>
                    <input type="text" id="contact-subject" placeholder="What is this about?">
                </div>
                
                <div class="form-field">
                    <label for="contact-message">Message</label>
                    <textarea id="contact-message" rows="5" placeholder="Your message..."></textarea>
                </div>
                
                <button type="button" id="send-contact" class="button button-primary">
                    <i data-feather="send"></i>
                    Send Message
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Tab switching
jQuery(document).ready(function($) {
    $('.tab-button').on('click', function() {
        const tabId = $(this).data('tab');
        
        // Update active tab button
        $('.tab-button').removeClass('active');
        $(this).addClass('active');
        
        // Show active tab content
        $('.tab-content').removeClass('active');
        $('#tab-' + tabId).addClass('active');
    });
    
    // Initialize Feather icons
    if (typeof feather !== 'undefined') {
        feather.replace();
    }
});
</script>
