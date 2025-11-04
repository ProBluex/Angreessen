<?php
$settings = get_option('402links_settings');
$api_key = get_option('402links_api_key');
$site_id = get_option('402links_site_id');
$is_connected = !empty($api_key) && !empty($site_id);
?>

<div class="wrap agent-hub-dashboard">
    <h1 style="display: flex; align-items: center; gap: 12px;">
        <img src="<?php echo AGENT_HUB_PLUGIN_URL; ?>assets/images/tolliver-logo.png" 
             alt="Tolliver Logo" 
             style="width: 64px; height: 64px; border-radius: 50%; object-fit: cover;">
        Tolliver - Ai Agent Pay Collector
    </h1>
    
    <div class="agent-hub-connection-status">
        <?php if ($is_connected): ?>
            <span class="status-badge connected">
                <span class="dashicons dashicons-yes-alt"></span>
                Connected
            </span>
        <?php else: ?>
            <span class="status-badge disconnected">
                <span class="dashicons dashicons-warning"></span>
                Not Connected
            </span>
        <?php endif; ?>
    </div>
    
    <?php
    // Show reconnection notice if site was recently reconnected with new API key
    // This can be triggered when API key is saved via recovery or new registration
    $show_sync_notice = get_transient('402links_show_sync_notice');
    if ($show_sync_notice && $is_connected):
    ?>
    <div class="notice notice-warning is-dismissible" id="reconnection-notice" style="margin: 15px 0;">
        <p>
            <strong>⚠️ Site reconnected successfully!</strong> 
            Your protected pages may need to be re-synced. Click "Sync Protection Status" below to restore your content protection.
        </p>
    </div>
    <?php
    endif;
    ?>
    
    <div class="agent-hub-tabs">
        <button class="tab-button active" data-tab="overview">
            <span class="dashicons dashicons-dashboard"></span>
            Overview
        </button>
        <button class="tab-button" data-tab="content">
            <span class="dashicons dashicons-admin-page"></span>
            My Content
        </button>
        <button class="tab-button" data-tab="analytics">
            <span class="dashicons dashicons-chart-line"></span>
            Analytics
        </button>
        <button class="tab-button" data-tab="violations">
            <span class="dashicons dashicons-shield-alt"></span>
            Violations
        </button>
        <button class="tab-button" data-tab="contact">
            <span class="dashicons dashicons-email"></span>
            Contact Us
        </button>
    </div>
    
    <!-- Overview Tab -->
    <div id="tab-overview" class="tab-content active">
        <div class="agent-hub-stats-grid">
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-visibility"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total AI Agent Crawls</div>
                    <div class="stat-value" id="total-crawls">Loading...</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Paid Crawls</div>
                    <div class="stat-value" id="paid-crawls">Loading...</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-money-alt"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value" id="total-revenue">$0.000</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-overview">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-admin-page"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Protected Pages</div>
                    <div class="stat-value" id="protected-pages">Loading...</div>
                </div>
            </div>
        </div>
        
        <div class="agent-hub-config-card">
            <h3><span class="dashicons dashicons-admin-settings"></span> Configuration</h3>
            
            <?php if ($site_id): ?>
                <div class="config-site-id">
                    <span class="site-id-label">Site ID:</span>
                    <?php if ($is_connected): ?>
                        <span class="site-id-badge connected">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <code><?php echo esc_html($site_id); ?></code>
                        </span>
                    <?php else: ?>
                        <span class="site-id-badge disconnected">
                            <span class="dashicons dashicons-warning"></span>
                            <code><?php echo esc_html($site_id); ?></code>
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
                    $saved_wallet = $settings['payment_wallet'] ?? '';
                    $has_wallet = !empty(trim($saved_wallet));
                    
                    // Set indicator state
                    if ($has_wallet) {
                        $indicator_class = 'wallet-sync-indicator wallet-status-synced';
                        $dot_color = 'green';
                        $status_text = 'Synced';
                    } else {
                        $indicator_class = 'wallet-sync-indicator wallet-status-empty';
                        $dot_color = 'gray';
                        $status_text = 'Not synced';
                    }
                    ?>
                    <div class="wallet-input-wrapper">
                        <input type="text" id="overview-payment-wallet" class="config-input" 
                               value="<?php echo esc_attr($settings['payment_wallet'] ?? ''); ?>" 
                               placeholder="0x..." />
                        <div id="wallet-sync-indicator" class="<?php echo esc_attr($indicator_class); ?>" data-server-rendered="true">
                            <span class="status-dot <?php echo esc_attr($dot_color); ?>"></span>
                            <span class="status-text"><?php echo esc_html($status_text); ?></span>
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
                           value="<?php echo esc_attr($settings['default_price'] ?? 0.10); ?>" />
                    <p class="config-description">Default price for AI agents to access each page</p>
                </div>
                
                <div class="config-actions">
                    <button type="button" id="save-overview-config" class="button button-primary">
                        <span class="dashicons dashicons-saved"></span>
                        Save Configuration
                    </button>
                    
                    <?php if ($is_connected): ?>
                    <button type="button" id="sync-protection-status" class="button button-secondary" style="margin-left: 10px;">
                        <span class="dashicons dashicons-update"></span>
                        Sync Protection Status
                    </button>
                    <p class="description" style="margin-top: 8px; font-style: italic;">
                        Use this to restore protected pages after reconnecting your site or recovering your API key.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="agent-hub-info-box">
            <h3>
                <span class="dashicons dashicons-info-outline"></span> 
                About Tolliver - Ai Agent Pay Collector
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
        <div class="content-toolbar">
            <button id="bulk-generate-links" class="button button-primary">
                <span class="dashicons dashicons-update"></span>
                Generate Paid Links (Posts Only)
            </button>
            <button id="refresh-content" class="button">
                <span class="dashicons dashicons-update-alt"></span>
                Refresh
            </button>
            <span id="post-count-indicator" style="margin-left: 15px; font-weight: 500; color: #666;"></span>
        </div>
        
        <table class="wp-list-table widefat fixed striped" id="content-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Price</th>
                    <th>Force Agents to Pay</th>
                    <th>Force Humans to Pay</th>
                </tr>
            </thead>
            <tbody id="content-table-body">
                <tr>
                    <td colspan="5" style="text-align: center;">
                        <span class="spinner is-active" style="float: none; margin: 20px auto;"></span>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- Pagination Controls -->
        <div id="content-pagination" style="margin-top: 20px;"></div>
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
        
        <!-- Ecosystem Stats Cards -->
        <div class="analytics-section">
            <h3>x402 Ecosystem Overview</h3>
            <div class="agent-hub-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                <div class="stat-card stat-card-analytics">
                    <div class="stat-icon stat-icon-flat">
                        <span class="dashicons dashicons-groups"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Active Buyers</div>
                        <div class="stat-value" id="stat-ecosystem-buyers">0</div>
                    </div>
                </div>
                
                <div class="stat-card stat-card-analytics">
                    <div class="stat-icon stat-icon-flat">
                        <span class="dashicons dashicons-store"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Active Sellers</div>
                        <div class="stat-value" id="stat-ecosystem-sellers">0</div>
                    </div>
                </div>
                
                <div class="stat-card stat-card-analytics">
                    <div class="stat-icon stat-icon-flat">
                        <span class="dashicons dashicons-chart-bar"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Total Transactions</div>
                        <div class="stat-value" id="stat-ecosystem-transactions">0</div>
                    </div>
                </div>
                
                <div class="stat-card stat-card-analytics">
                    <div class="stat-icon stat-icon-flat">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Market Revenue</div>
                        <div class="stat-value" id="stat-market-revenue">$0.000</div>
                    </div>
                </div>
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
        
        <!-- Top Performing Content -->
        <div class="analytics-section">
            <h3>Top Performing Content</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Page Title</th>
                        <th>Revenue</th>
                    </tr>
                </thead>
                <tbody id="top-content-body">
                    <tr><td colspan="2" style="text-align:center;">Loading...</td></tr>
                </tbody>
            </table>
            <div id="top-content-pagination" style="margin-top: 15px; text-align: center;"></div>
        </div>
    </div>
    
    <!-- Violations Tab -->
    <div id="tab-violations" class="tab-content">
        <div class="violations-header">
            <h2>
                <span class="dashicons dashicons-shield-alt"></span>
                Agent Violations Dashboard
            </h2>
            <p class="violations-description">Track and monitor AI agents that violate robots.txt rules, ignore 402 payment requirements, or attempt unauthorized access to your content.</p>
        </div>
        
        <div class="agent-hub-stats-grid">
            <div class="stat-card stat-card-violations">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Violations</div>
                    <div class="stat-value" id="violations-total">0</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-violations">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-shield-alt"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Robots.txt Violations</div>
                    <div class="stat-value" id="violations-robots">0</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-violations">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-money-alt"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Unpaid Access Attempts</div>
                    <div class="stat-value" id="violations-unpaid">0</div>
                </div>
            </div>
            
            <div class="stat-card stat-card-violations">
                <div class="stat-icon stat-icon-flat">
                    <span class="dashicons dashicons-groups"></span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Unique Violating Agents</div>
                    <div class="stat-value" id="violations-unique-agents">0</div>
                </div>
            </div>
        </div>
        
        <div class="violations-table-section">
            <h3>Agent Violation Summary</h3>
            <div id="violations-loading" style="text-align: center; padding: 40px;">
                <span class="spinner is-active"></span> Loading violations data...
            </div>
            <div id="violations-error" class="notice notice-error" style="display:none; margin: 20px 0;">
                <p><strong>Error:</strong> <span id="violations-error-message"></span></p>
            </div>
            <table class="wp-list-table widefat fixed striped" id="violations-table" style="display:none;">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="agent_name">
                            Agent Name <span class="sort-arrow"></span>
                        </th>
                        <th class="sortable" data-sort="total_violations">
                            Total Violations <span class="sort-arrow"></span>
                        </th>
                        <th class="sortable" data-sort="robots_txt_violations">
                            Robots.txt Violations <span class="sort-arrow"></span>
                        </th>
                        <th class="sortable" data-sort="unpaid_access_violations">
                            Unpaid Access <span class="sort-arrow"></span>
                        </th>
                        <th class="sortable" data-sort="last_seen">
                            Last Seen <span class="sort-arrow"></span>
                        </th>
                        <th>Policy</th>
                    </tr>
                </thead>
                <tbody id="violations-table-body">
                </tbody>
            </table>

            <!-- Policy Management Actions -->
            <div id="violations-policy-actions" style="display:none; margin-top: 20px;">
                <button type="button" id="violations-save-policies" class="button button-primary" style="display:none;">
                    <span class="dashicons dashicons-saved"></span>
                    Save Policy Changes
                </button>
                <span id="violations-save-loading" style="display:none; margin-left: 12px;">
                    <span class="spinner is-active"></span>
                    Saving policies...
                </span>
                <div id="violations-save-error" class="notice notice-error" style="display:none; margin: 12px 0;">
                    <p><strong>Error:</strong> <span id="violations-save-error-message"></span></p>
                </div>
                <div id="violations-save-success" class="notice notice-success" style="display:none; margin: 12px 0;">
                    <p>✅ <strong>Success!</strong> Bot policies updated successfully.</p>
                </div>
            </div>

            <div id="violations-empty" class="notice notice-info" style="display:none; margin: 20px 0;">
                <p>✅ <strong>Great news!</strong> No violations detected. All AI agents are respecting your site's access rules.</p>
            </div>
        </div>
        
    </div>
    
    <!-- Contact Us Tab -->
    <div id="tab-contact" class="tab-content">
        <?php
        // Check if this is a recovery scenario (site_id exists but api_key missing)
        $is_recovery_scenario = !empty($site_id) && empty($api_key);
        ?>
        
        <div class="contact-us-layout <?php echo $is_recovery_scenario ? 'with-recovery' : ''; ?>">
            <div class="contact-main">
                <div class="contact-intro">
                    <h2>About Tolliver Plugin</h2>
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
        
        <?php if ($is_recovery_scenario): ?>
        <!-- Recovery Sidebar -->
        <div class="recovery-sidebar" id="recovery-sidebar">
            <div class="recovery-header">
                <span class="dashicons dashicons-admin-network"></span>
                <h3>Account Recovery</h3>
            </div>
            
            <div class="recovery-content">
                <p class="recovery-description">
                    Reconnect your site instantly if you've reinstalled the plugin.
                </p>
                
                <div class="recovery-info">
                    <div class="info-row">
                        <span class="label">Site ID:</span>
                        <code id="recovery-site-id"><?php echo esc_html($site_id); ?></code>
                    </div>
                    <div class="info-row">
                        <span class="label">Site URL:</span>
                        <span id="recovery-site-url"><?php echo esc_html(get_site_url()); ?></span>
                    </div>
                </div>
                
                <button type="button" id="reconnect-site-btn" class="button button-primary button-recovery">
                    <span class="dashicons dashicons-admin-network"></span>
                    Reconnect Site
                </button>
                
                <div id="recovery-status" class="recovery-status" style="display:none;"></div>
                
                <div class="recovery-help">
                    <p class="help-text">
                        <span class="dashicons dashicons-info"></span>
                        If you've reinstalled the plugin, use this to restore your connection instantly.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    </div>
    
    <div id="agent-hub-toast" class="agent-hub-toast"></div>
</div>
