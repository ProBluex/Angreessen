<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

$agent_hub_settings = get_option('402links_settings');
$agent_hub_api_key = get_option('402links_api_key');
$agent_hub_site_id = get_option('402links_site_id');
$agent_hub_is_connected = !empty($agent_hub_api_key) && !empty($agent_hub_site_id);
?>

<div class="wrap agent-hub-dashboard">
    <!-- Modern Header -->
    <div class="angreessen-header">
        <header class="angreessen-header-inner">
            <!-- Brand Section -->
            <div class="header-brand">
                <div class="brand-logo-wrapper">
                    <img src="<?php echo esc_url(AGENT_HUB_PLUGIN_URL . 'assets/images/angreessen-logo.png'); ?>" 
                         alt="Agent Angreessen" class="brand-logo">
                </div>
                <div class="brand-info">
                    <span class="brand-name">Agent Angreessen</span>
                    <span class="brand-tagline">AI Agent Pay Collector</span>
                </div>
            </div>
            
            <!-- Connection Status -->
            <div class="connection-pill <?php echo $agent_hub_is_connected ? 'connected' : 'disconnected'; ?>">
                <span class="status-dot"></span>
                <?php echo $agent_hub_is_connected ? 'Connected' : 'Offline'; ?>
            </div>
        </header>
    </div>
    
    <div class="agent-hub-tabs">
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
        <div class="agent-hub-stats-grid">
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
        
        <div class="agent-hub-config-card">
            <h3><i data-feather="settings"></i> Configuration</h3>
            
            <?php if ($agent_hub_site_id): ?>
                <div class="config-site-id">
                    <span class="site-id-label">Site ID:</span>
                    <?php if ($agent_hub_is_connected): ?>
                        <span class="site-id-badge connected">
                            <i data-feather="check-circle"></i>
                            <code><?php echo esc_html($agent_hub_site_id); ?></code>
                        </span>
                    <?php else: ?>
                        <span class="site-id-badge disconnected">
                            <i data-feather="alert-circle"></i>
                            <code><?php echo esc_html($agent_hub_site_id); ?></code>
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
                    $agent_hub_saved_wallet = $agent_hub_settings['payment_wallet'] ?? '';
                    $agent_hub_has_wallet = !empty(trim($agent_hub_saved_wallet));
                    
                    // Set indicator state
                    if ($agent_hub_has_wallet) {
                        $agent_hub_indicator_class = 'wallet-sync-indicator wallet-status-synced';
                        $agent_hub_dot_color = 'green';
                        $agent_hub_status_text = 'Synced';
                    } else {
                        $agent_hub_indicator_class = 'wallet-sync-indicator wallet-status-empty';
                        $agent_hub_dot_color = 'gray';
                        $agent_hub_status_text = 'Not synced';
                    }
                    ?>
                    <div class="wallet-input-wrapper">
                        <input type="text" id="overview-payment-wallet" class="config-input" 
                               value="<?php echo esc_attr($agent_hub_settings['payment_wallet'] ?? ''); ?>" 
                               placeholder="0x..." />
                        <div id="wallet-sync-indicator" class="<?php echo esc_attr($agent_hub_indicator_class); ?>" data-server-rendered="true">
                            <span class="status-dot <?php echo esc_attr($agent_hub_dot_color); ?>"></span>
                            <span class="status-text"><?php echo esc_html($agent_hub_status_text); ?></span>
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
                           value="<?php echo esc_attr($agent_hub_settings['default_price'] ?? 0.10); ?>" />
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
        
        <div class="agent-hub-info-box">
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
            <div class="agent-hub-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
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
        
        <!-- Top Facilitators Section -->
        <div class="analytics-section">
            <h3>Top Facilitators</h3>
            <div id="facilitators-loading" style="text-align: center; padding: 40px;">
                <span class="spinner is-active"></span> Loading facilitators...
            </div>
            <div id="facilitators-grid" class="facilitators-grid" style="display: none;"></div>
            <div id="facilitators-error" style="display: none; text-align: center; padding: 40px; color: #999;">
                No facilitator data available
            </div>
        </div>
        
        <div class="analytics-loading" style="display:none; text-align:center; padding:40px;">
            <span class="spinner is-active"></span> Loading analytics...
        </div>
        
        <!-- Market Overview Chart -->
        <div class="analytics-section">
            <h3>Market Overview</h3>
            <div class="chart-controls" style="margin-bottom: 15px;">
                <button class="metric-toggle active" data-metric="transactions">Transactions</button>
                <button class="metric-toggle active" data-metric="volume">Volume</button>
                <button class="metric-toggle active" data-metric="buyers">Buyers</button>
                <button class="metric-toggle active" data-metric="sellers">Sellers</button>
            </div>
            <div id="market-chart-container" style="position: relative; height: 400px; width: 100%;">
                <canvas id="market-chart"></canvas>
            </div>
            <div class="chart-empty-state" style="display:none;"></div>
        </div>
        
    </div>
    
    <!-- Violations Tab -->
    <div id="tab-violations" class="tab-content">
        <!-- Header with title and save button -->
        <div class="violations-page-header">
            <div class="violations-header-left">
                <h2 class="violations-title">
                    <i data-feather="shield-off"></i>
                    Agent Violations Dashboard
                </h2>
                <p class="violations-subtitle">Monitor and manage AI agent access policies for your site.</p>
            </div>
            <div class="violations-header-right">
                <button type="button" id="violations-save-policies" class="violations-save-btn" style="display:none;">
                    Save Policy Changes
                </button>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="violations-stats-grid">
            <div class="stat-card-modern">
                <div class="stat-icon-modern stat-icon-rose">
                    <i data-feather="alert-triangle"></i>
                </div>
                <div class="stat-content-modern">
                    <span class="stat-value-modern" id="violations-total">0</span>
                    <span class="stat-label-modern">Total Violations</span>
                </div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-icon-modern stat-icon-amber">
                    <i data-feather="shield"></i>
                </div>
                <div class="stat-content-modern">
                    <span class="stat-value-modern" id="violations-robots">0</span>
                    <span class="stat-label-modern">Robots.txt</span>
                </div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-icon-modern stat-icon-yellow">
                    <i data-feather="dollar-sign"></i>
                </div>
                <div class="stat-content-modern">
                    <span class="stat-value-modern" id="violations-unpaid">0</span>
                    <span class="stat-label-modern">Unpaid Access</span>
                </div>
            </div>
            <div class="stat-card-modern">
                <div class="stat-icon-modern stat-icon-indigo">
                    <i data-feather="users"></i>
                </div>
                <div class="stat-content-modern">
                    <span class="stat-value-modern" id="violations-unique-agents">0</span>
                    <span class="stat-label-modern">Unique Agents</span>
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
                    <input type="text" id="violations-search" class="violations-search-input" placeholder="">
                </div>
            </div>
            
            <!-- Loading State -->
            <div id="violations-loading" class="violations-loading-state">
                <span class="spinner is-active"></span>
                <span>Loading violations data...</span>
            </div>
            
            <!-- Error State -->
            <div id="violations-error" class="violations-error-state" style="display:none;">
                <i data-feather="alert-circle"></i>
                <span id="violations-error-message">Failed to load data</span>
            </div>
            
            <!-- Empty State -->
            <div id="violations-empty" class="violations-empty-state" style="display:none;">
                <div class="violations-empty-icon">
                    <i data-feather="check-circle"></i>
                </div>
                <h3>No violations detected!</h3>
                <p>All AI agents are respecting your site's access rules.</p>
            </div>
            
            <!-- Table -->
            <table id="violations-table" class="violations-table" style="display:none;">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="agent_name">AGENT NAME</th>
                        <th class="sortable" data-sort="total_violations">TOTAL</th>
                        <th class="sortable" data-sort="robots_txt_violations">ROBOTS.TXT</th>
                        <th class="sortable" data-sort="unpaid_access_violations">UNPAID</th>
                        <th class="sortable" data-sort="last_seen">LAST SEEN</th>
                        <th>POLICY</th>
                    </tr>
                </thead>
                <tbody id="violations-table-body">
                </tbody>
            </table>
            
            <!-- Save Status Messages -->
            <div id="violations-policy-actions" class="violations-policy-actions" style="display:none;">
                <span id="violations-save-loading" style="display:none;">
                    <span class="spinner is-active"></span>
                    Saving policies...
                </span>
                <div id="violations-save-error" class="violations-save-error" style="display:none;">
                    <i data-feather="x-circle"></i>
                    <span id="violations-save-error-message"></span>
                </div>
                <div id="violations-save-success" class="violations-save-success" style="display:none;">
                    <i data-feather="check-circle"></i>
                    Bot policies updated successfully.
                </div>
            </div>
        </div>
    </div>
    
    <!-- Contact Us Tab -->
    <div id="tab-contact" class="tab-content">
        <div class="contact-intro">
            <h2>About Agent Angreessen</h2>
            <div class="contact-description">
                <p>This plugin is developed by the team at <a href="https://402links.com" target="_blank">402links.com</a> by using the emerging "HTTP 402 Payment Required" standard. Leveraging novel advancements in agentic payment technologies, the plugin enables websites to convert any page or endpoint into a monetizable digital SKU. This means that both humans and AI agents can seamlessly pay for access, data, or functionality - directly through standard web requests - with instant settlement in stablecoins on the Base blockchain.</p>
                
                <p>The 402 Links project is experimental and open to collaboration. We're continuously refining the protocol and invite feedback, suggestions, and partnership ideas from early adopters and developers.</p>
                
                <p>If you have questions, encounter issues, or wish to contribute, please reach out using the form below. Your feedback helps shape the next generation of web-native payments.</p>
                
        <div class="contact-links">
            <p>
                🔗 <a href="https://402links.com/details" target="_blank">Learn more about the technology</a>
            </p>
            <p>
                🧠 <a href="https://402links.com/developers" target="_blank">For developers & integration docs</a>
            </p>
            <p style="margin-left: 20px; margin-top: 8px;">
                <strong>Open Source Protocol Implementations:</strong>
            </p>
            <p style="margin-left: 20px;">
                📦 <a href="https://github.com/coinbase/x402" target="_blank">x402 Protocol (Coinbase)</a> - The core HTTP 402 payment protocol implementation that enables AI agents to make payments through standard HTTP headers. This is the foundation of our agent payment system.
            </p>
            <p style="margin-left: 20px;">
                📦 <a href="https://github.com/google-agentic-commerce/AP2" target="_blank">AP2 Protocol (Google Agentic Commerce)</a> - Google's Agent Payment Protocol 2 specification that defines how AI agents discover and interact with paywall-protected content. We've integrated this to ensure broad agent compatibility.
            </p>
        </div>
            </div>
        </div>
        
        <div class="contact-form-wrapper">
            <h3>Send Us a Message</h3>
            <form id="contact-form" class="contact-form">
                <div class="form-row">
                    <div class="form-field">
                        <label for="contact-name">Name <span class="required">*</span></label>
                        <input type="text" id="contact-name" name="name" placeholder="Your name" required maxlength="100" />
                        <span class="field-error" id="name-error"></span>
                    </div>
                    
                    <div class="form-field">
                        <label for="contact-email">Email <span class="required">*</span></label>
                        <input type="email" id="contact-email" name="email" placeholder="your.email@example.com" required maxlength="255" />
                        <span class="field-error" id="email-error"></span>
                    </div>
                </div>
                
                <div class="form-field">
                    <label for="contact-subject">Subject (Optional)</label>
                    <input type="text" id="contact-subject" name="subject" placeholder="What's this about?" maxlength="200" />
                </div>
                
                <div class="form-field">
                    <label for="contact-message">Message <span class="required">*</span></label>
                    <textarea id="contact-message" name="message" placeholder="Tell us what's on your mind..." required maxlength="2000" rows="6"></textarea>
                    <span class="field-error" id="message-error"></span>
                    <div class="character-count">
                        <span id="message-count">0</span> / 2000 characters
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="button button-primary" id="contact-submit">
                        <span class="dashicons dashicons-email-alt"></span>
                        Send Message
                    </button>
                </div>
            </form>
            
            <div id="contact-success" class="contact-success" style="display: none;">
                <div class="success-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <h3>Thank you - Message sent!</h3>
                <p>We've received your message and will get back to you as soon as possible.</p>
                <button class="button" id="send-another">Send Another Message</button>
            </div>
        </div>
    </div>
    
    <div id="agent-hub-toast" class="agent-hub-toast"></div>
</div>
