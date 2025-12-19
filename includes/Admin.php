<?php
namespace AgentHub;

class Admin {
    /**
     * Show provisioning status notices
     */
    public static function show_provisioning_notice() {
        // Check if wallet is configured
        $settings = get_option('402links_settings', []);
        $wallet = $settings['payment_wallet'] ?? '';
        $site_id = get_option('402links_site_id');
        
        
        // Show success notice
        if (get_option('402links_provisioning_success')) {
            $site_id = get_option('402links_site_id');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>🎉 Agent Angreessen - Ai Agent Pay Collector:</strong> Your site has been automatically registered! 
                Site ID: <code><?php echo esc_html($site_id); ?></code></p>
                <p>Configure your payment wallet in the <a href="<?php echo admin_url('admin.php?page=agent-hub'); ?>">Agent Angreessen dashboard</a> to start protecting and monetizing your content.</p>
            </div>
            <?php
            delete_option('402links_provisioning_success');
        }
        
        // Show info notice (for already provisioned sites)
        $info = get_option('402links_provisioning_info');
        if ($info) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>ℹ️ Agent Angreessen:</strong> <?php echo esc_html($info); ?></p>
            </div>
            <?php
            delete_option('402links_provisioning_info');
        }
        
        // Show error notice
        $error = get_option('402links_provisioning_error');
        if ($error) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>⚠️ Agent Angreessen:</strong> Auto-provisioning failed: <?php echo esc_html($error); ?></p>
                <p>You can manually register at <a href="https://402links.com" target="_blank">402links.com</a> or contact support for assistance.</p>
            </div>
            <?php
            delete_option('402links_provisioning_error');
        }
    }
    
    /**
     * Show setup wizard notice (requires user consent before provisioning)
     */
    public static function show_setup_notice() {
        if (!get_option('402links_needs_setup')) {
            return;
        }
        
        $site_id = get_option('402links_site_id');
        if ($site_id) {
            // Already provisioned, clear flag
            delete_option('402links_needs_setup');
            return;
        }
        
        ?>
        <div class="notice notice-info is-dismissible" id="angreessen-setup-notice">
            <h3>🚀 Welcome to Agent Angreessen - Ai Agent Pay Collector!</h3>
            <p><strong>Setup Required:</strong> This plugin requires connecting to 402links.com to enable AI agent payments.</p>
            
            <p><strong>What will be sent to 402links.com:</strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li>Your site URL, name, and admin email</li>
                <li>WordPress version and plugin version</li>
                <li>Post/page data when you create paid links</li>
                <li>AI agent access logs and payment transactions</li>
            </ul>
            
            <p>By clicking "Complete Setup", you agree to the <a href="https://402links.com/terms" target="_blank">402links Terms of Service</a> and <a href="https://402links.com/privacy" target="_blank">Privacy Policy</a>.</p>
            
            <p>
                <button type="button" class="button button-primary" id="angreessen-complete-setup">Complete Setup</button>
                <a href="<?php echo admin_url('plugins.php'); ?>" class="button">Skip for Now</a>
            </p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#angreessen-complete-setup').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Setting up...');
                
                $.post(ajaxurl, {
                    action: 'agent_hub_complete_setup',
                    nonce: '<?php echo wp_create_nonce("angreessen_setup"); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#angreessen-setup-notice').html('<p>✅ Setup complete! Site ID: <code>' + response.data.site_id + '</code></p>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        alert('Setup failed: ' + response.data.message);
                        button.prop('disabled', false).text('Complete Setup');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Register admin menu
     */
    public static function register_menu() {
        add_menu_page(
            'Agent Angreessen - Ai Agent Pay Collector',
            'Angreessen',
            'manage_options',
            'agent-hub',
            [self::class, 'render_dashboard'],
            'dashicons-shield-alt',
            30
        );
    }
    
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_agent-hub') {
            return;
        }
        
        wp_enqueue_style(
            'agent-hub-admin',
            AGENT_HUB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            AGENT_HUB_VERSION
        );
        
        wp_enqueue_style(
            'agent-hub-batch-processor',
            AGENT_HUB_PLUGIN_URL . 'assets/css/batch-processor.css',
            [],
            AGENT_HUB_VERSION
        );
        
        // Feather Icons library
        wp_enqueue_script(
            'feather-icons',
            'https://unpkg.com/feather-icons@4.29.0/dist/feather.min.js',
            [],
            '4.29.0',
            true
        );
        
        // Preload Chart.js for faster Analytics rendering
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
        
        // Load admin.js first - other scripts depend on it
        wp_enqueue_script(
            'agent-hub-admin',
            AGENT_HUB_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            AGENT_HUB_VERSION,
            true
        );
        
        // Analytics depends on admin.js being loaded
        wp_enqueue_script(
            'agent-hub-analytics',
            AGENT_HUB_PLUGIN_URL . 'assets/js/analytics.js',
            ['jquery', 'agent-hub-admin', 'chartjs'],
            AGENT_HUB_VERSION,
            true
        );
        
        // Content manager depends on admin.js
        wp_enqueue_script(
            'agent-hub-content',
            AGENT_HUB_PLUGIN_URL . 'assets/js/content-manager.js',
            ['jquery', 'agent-hub-admin'],
            AGENT_HUB_VERSION,
            true
        );
        
        // Overview depends on admin.js
        wp_enqueue_script(
            'agent-hub-overview',
            AGENT_HUB_PLUGIN_URL . 'assets/js/overview.js',
            ['jquery', 'agent-hub-admin'],
            AGENT_HUB_VERSION,
            true
        );
        
        // Contact depends on admin.js
        wp_enqueue_script(
            'agent-hub-contact',
            AGENT_HUB_PLUGIN_URL . 'assets/js/contact.js',
            ['jquery', 'agent-hub-admin'],
            AGENT_HUB_VERSION,
            true
        );
        
        // Violations depends on admin.js
        wp_enqueue_script(
            'agent-hub-violations',
            AGENT_HUB_PLUGIN_URL . 'assets/js/violations.js',
            ['jquery', 'agent-hub-admin'],
            AGENT_HUB_VERSION,
            true
        );
        
        // Batch processor depends on admin.js
        wp_enqueue_script(
            'agent-hub-batch-processor',
            AGENT_HUB_PLUGIN_URL . 'assets/js/batch-processor.js',
            ['jquery', 'agent-hub-admin'],
            AGENT_HUB_VERSION,
            true
        );
        
        wp_localize_script('agent-hub-admin', 'agentHubData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('agent_hub_nonce'),
            'siteUrl' => get_site_url(),
            'siteName' => get_bloginfo('name'),
            'siteId' => get_option('402links_site_id'),
            'pluginUrl' => AGENT_HUB_PLUGIN_URL,
            'supabaseUrl' => 'https://cnionwnknwnzpwfuacse.supabase.co',
            'supabaseAnonKey' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNuaW9ud25rbnduenB3ZnVhY3NlIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NTkzODQ5NTAsImV4cCI6MjA3NDk2MDk1MH0.vgBlogXLSPd5AA_nt6ISv69xrMa4a--__EXdWgI79Dc'
        ]);
    }
    
    /**
     * Render dashboard
     */
    public static function render_dashboard() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        include AGENT_HUB_PLUGIN_DIR . 'templates/settings-page.php';
    }
    
    /**
     * Add meta box to posts
     */
    public static function add_meta_box() {
        add_meta_box(
            'agent-hub-meta-box',
            '402links Agent Protection',
            [self::class, 'render_meta_box'],
            ['post', 'page'],
            'side',
            'default'
        );
    }
    
    /**
     * Render meta box
     * 
     * SECURITY FIX: Added CSRF nonce field
     */
    public static function render_meta_box($post) {
        // Add nonce field for CSRF protection
        wp_nonce_field('402links_save_meta_' . $post->ID, '402links_meta_nonce');
        
        include AGENT_HUB_PLUGIN_DIR . 'templates/meta-box.php';
    }
    
    /**
     * AJAX: Save settings
     */
    public static function ajax_save_settings() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // Get old settings to detect price changes
        $old_settings = get_option('402links_settings', []);
        
        $settings = [
            'default_price' => floatval($_POST['default_price'] ?? 0.10),
            'auto_generate' => isset($_POST['auto_generate']) && $_POST['auto_generate'] === 'true',
            'payment_wallet' => sanitize_text_field($_POST['payment_wallet'] ?? ''),
            'network' => sanitize_text_field($_POST['network'] ?? 'base'),
            'api_endpoint' => sanitize_text_field($_POST['api_endpoint'] ?? 'https://api.402links.com/v1')
        ];
        
        update_option('402links_settings', $settings);
        
        if (isset($_POST['api_key'])) {
            update_option('402links_api_key', sanitize_text_field($_POST['api_key']));
        }
        
        // Sync default_price and payment_wallet to Supabase registered_sites table
        $site_id = get_option('402links_site_id');
        if ($site_id) {
            $api = new API();
            $sync_result = $api->sync_site_settings([
                'default_price' => $settings['default_price'],
                'payment_wallet' => $settings['payment_wallet']
            ]);
            
            error_log('🟦 [Admin] Synced settings to Supabase: ' . json_encode($sync_result));
            
            // CRITICAL: Store the agent_payment_wallet (splitter address) returned from backend
            // This is the address agents should pay to (99% creator / 1% platform split)
            if (isset($sync_result['data']['agent_payment_wallet'])) {
                update_option('402links_agent_payment_wallet', $sync_result['data']['agent_payment_wallet']);
                error_log('🟦 [Admin] Stored agent_payment_wallet: ' . $sync_result['data']['agent_payment_wallet']);
            }
        }
        
        $message = 'Settings saved successfully';
        
        // If default_price changed, inform user that prices were auto-updated
        if (isset($old_settings['default_price']) && 
            $old_settings['default_price'] != $settings['default_price']) {
            
            // Check if sync result includes links_updated count
            if (isset($sync_result['success']) && $sync_result['success'] && isset($sync_result['links_updated'])) {
                $links_count = intval($sync_result['links_updated']);
                if ($links_count > 0) {
                    $message .= ". Automatically updated prices for {$links_count} existing link(s) to \${$settings['default_price']}.";
                } else {
                    $message .= ". Price updated to \${$settings['default_price']} (no existing links to update).";
                }
            } else {
                $message .= ". Price updated to \${$settings['default_price']}.";
            }
        }
        
        wp_send_json_success(['message' => $message]);
    }
    
    /**
     * AJAX: Check if existing links exist
     */
    public static function ajax_check_existing_links() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $site_id = get_option('402links_site_id');
        
        if (!$site_id) {
            wp_send_json_success([
                'has_links' => false,
                'link_count' => 0
            ]);
            return;
        }
        
        $api = new API();
        $result = $api->check_existing_links_count();
        
        wp_send_json_success([
            'has_links' => $result['count'] > 0,
            'link_count' => $result['count']
        ]);
    }
    
    /**
     * AJAX: Register site
     */
    public static function ajax_register_site() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $api = new API();
        $result = $api->register_site();
        
        if ($result['success']) {
            if (isset($result['site_id'])) {
                update_option('402links_site_id', $result['site_id']);
            }
            
            // CRITICAL: Store the agent_payment_wallet from registration response
            // This is the splitter address that agents should pay to
            if (isset($result['agent_payment_wallet'])) {
                update_option('402links_agent_payment_wallet', $result['agent_payment_wallet']);
                error_log('🟦 [Admin] Stored agent_payment_wallet from registration: ' . $result['agent_payment_wallet']);
            }
            
            // Auto-generate 402links for all published content
            $bulk_result = ContentSync::bulk_sync_all();
            
            $message = sprintf(
                'Site registered successfully! Generated %d 402links for your content.',
                $bulk_result['created']
            );
            
            wp_send_json_success([
                'message' => $message,
                'site_id' => $result['site_id'],
                'auto_generated' => $bulk_result
            ]);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Generate link for post
     */
    public static function ajax_generate_link() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }
        
        $result = ContentSync::create_link($post_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get analytics
     */
    public static function ajax_get_analytics() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $timeframe = sanitize_text_field($_POST['timeframe'] ?? '30d');
        
        // Check cache first (30 second TTL for better real-time experience)
        $cache_key = 'agent_hub_analytics_' . $timeframe;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            error_log('[Admin.php] 📦 Returning cached analytics for timeframe: ' . $timeframe);
            wp_send_json_success($cached);
            return;
        }
        
        // Check for in-flight request (deduplication)
        $lock_key = 'agent_hub_api_lock_' . $timeframe;
        if (get_transient($lock_key)) {
            usleep(500000); // Wait 0.5s for in-flight request
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                error_log('[Admin.php] 📦 Returning cached data after lock wait');
                wp_send_json_success($cached);
                return;
            }
        }
        
        // Set lock to prevent duplicate requests
        set_transient($lock_key, true, 5);
        
        $api = new API();
        
        error_log('[Admin.php] 📊 ==================== ANALYTICS REQUEST ====================');
        error_log('[Admin.php] 📊 Timeframe: ' . $timeframe);
        
        // 🚀 PARALLEL API CALLS - Make both requests simultaneously
        $site_id = get_option('402links_site_id');
        $api_endpoint = $api->get_api_endpoint();
        $api_key = $api->get_api_key();
        
        $requests = [
            'site' => [
                'url' => $api_endpoint . '/get-site-analytics?site_id=' . $site_id . '&period=' . $timeframe,
                'type' => 'GET',
                'timeout' => 8, // Increased from 3 to 8 seconds
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ]
            ],
            'ecosystem' => [
                'url' => $api_endpoint . '/wordpress-ecosystem-stats',
                'type' => 'POST',
                'timeout' => 8, // Increased from 3 to 8 seconds
                'data' => ['timeframe' => $timeframe],
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ]
            ]
        ];
        
        // Execute parallel requests
        $responses = [];
        foreach ($requests as $key => $config) {
            $responses[$key] = wp_remote_request($config['url'], [
                'method' => $config['type'],
                'headers' => $config['headers'],
                'body' => isset($config['data']) ? json_encode($config['data']) : null,
                'timeout' => $config['timeout']
            ]);
        }
        
        // Process responses
        $site_result = ['success' => false];
        if (!is_wp_error($responses['site'])) {
            $body = json_decode(wp_remote_retrieve_body($responses['site']), true);
            $site_result = $body ?: ['success' => false];
        }
        
        $ecosystem_result = ['success' => false];
        if (!is_wp_error($responses['ecosystem'])) {
            $body = json_decode(wp_remote_retrieve_body($responses['ecosystem']), true);
            $ecosystem_result = $body ?: ['success' => false];
        }
        
        error_log('[Admin.php] 📊 Parallel requests completed');
        error_log('[Admin.php] 📊 site_result success: ' . ($site_result['success'] ? 'true' : 'false'));
        error_log('[Admin.php] 🌍 ecosystem_result success: ' . ($ecosystem_result['success'] ? 'true' : 'false'));
        
        if (($site_result['success'] ?? false) || ($ecosystem_result['success'] ?? false)) {
            // Add cache-busting headers
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Normalize shapes to avoid collisions (note key names!)
            $site_data = $site_result['data'] ?? $site_result ?? [];
            $ecosystem_data = $ecosystem_result['data'] ?? $ecosystem_result ?? [];
            
            // Extract metrics safely - tolerate both nested and flat structures
            $site_metrics = $site_data['metrics'] ?? $site_data;
            error_log('[Admin.php] 📊 Extracted $site_data structure: ' . json_encode(array_keys($site_data)));
            error_log('[Admin.php] 📊 Extracted $site_metrics: ' . json_encode($site_metrics));
            error_log('[Admin.php] 📊 $site_metrics keys: ' . json_encode(array_keys($site_metrics)));
            
            // Count protected pages with 5-minute cache
            $pages_cache_key = 'agent_hub_protected_pages_count';
            $protected_pages_count = get_transient($pages_cache_key);
            
            if ($protected_pages_count === false) {
                global $wpdb;
                $protected_pages_count = $wpdb->get_var("
                    SELECT COUNT(DISTINCT p.ID) 
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_status = 'publish'
                    AND p.post_type IN ('post', 'page')
                    AND pm.meta_key = '_402links_id'
                ");
                $protected_pages_count = intval($protected_pages_count);
                set_transient($pages_cache_key, $protected_pages_count, 300); // 5 min cache
                error_log('[Admin.php] 📄 Protected pages count (SQL): ' . $protected_pages_count);
            } else {
                error_log('[Admin.php] 📦 Protected pages count (CACHED): ' . $protected_pages_count);
            }
            
            $final_response = [
                'site' => [
                    // Site-only metrics (from /get-site-analytics)
                    'total_crawls'   => $site_metrics['total_crawls']   ?? 0,
                    'paid_crawls'    => $site_metrics['paid_crawls']    ?? 0,
                    'unpaid_crawls'  => $site_metrics['unpaid_crawls']  ?? 0,
                    'total_revenue'  => $site_metrics['total_revenue']  ?? 0.0,
                    'conversion_rate'=> $site_metrics['conversion_rate']?? 0.0,
                    'protected_pages'=> ($protected_pages_count > 0) ? $protected_pages_count : ($site_metrics['protected_pages'] ?? 0),
                    // Keep bucket for charts if endpoint returns it
                    'bucketed_data'  => $site_data['bucketed_data']  ?? []
                ],
                'ecosystem' => [
                    // Global metrics (from /wordpress-ecosystem-stats)
                    'total_transactions' => $ecosystem_data['total_transactions'] ?? 0,
                    'unique_buyers'      => $ecosystem_data['unique_buyers']      ?? 0,
                    'unique_sellers'     => $ecosystem_data['unique_sellers']     ?? 0,
                    'total_amount'       => $ecosystem_data['total_amount']       ?? 0.0,
                    'bucketed_data'      => $ecosystem_data['bucketed_data']      ?? []
                ]
            ];
            
            error_log('[Admin.php] 📊 Final site metrics being sent:');
            error_log('[Admin.php]    - total_crawls: ' . $final_response['site']['total_crawls']);
            error_log('[Admin.php]    - paid_crawls: ' . $final_response['site']['paid_crawls']);
            error_log('[Admin.php]    - total_revenue: ' . $final_response['site']['total_revenue']);
            error_log('[Admin.php]    - protected_pages: ' . $final_response['site']['protected_pages']);
            
            error_log('[Admin.php] ✅ Final response structure: ' . json_encode([
                'has_site' => isset($final_response['site']),
                'has_ecosystem' => isset($final_response['ecosystem']),
                'site_total_crawls' => $final_response['site']['total_crawls'],
                'ecosystem_total_transactions' => $final_response['ecosystem']['total_transactions']
            ]));
            
            // Cache the response for 30 seconds (better real-time experience)
            set_transient($cache_key, $final_response, 30);
            
            // Release lock
            delete_transient($lock_key);
            
            wp_send_json_success($final_response);
        }
        
        // If both failed, release lock
        delete_transient($lock_key);
        $site_err = $site_result['error'] ?? $site_result['message'] ?? 'unknown';
        $eco_err  = $ecosystem_result['error'] ?? $ecosystem_result['message'] ?? 'unknown';
        error_log('[Admin.php] ❌ Analytics request failed');
        wp_send_json_error(['message' => "Site analytics: $site_err | Ecosystem stats: $eco_err"]);
    }
    
    /**
     * AJAX: Get content list with pagination
     */
    public static function ajax_get_content() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // Get pagination params
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;
        $offset = ($page - 1) * $per_page;
        
        error_log("[402links] === CONTENT TABLE DATA FLOW ===");
        error_log("[402links] Page: $page, Per Page: $per_page, Offset: $offset");
        
        // Get site_id
        $site_id = get_option('402links_site_id');
        
        // Fetch page analytics from backend API
        $api = new API();
        $analytics_result = $api->get_pages_analytics($site_id);
        
        error_log('[402links] Analytics API response: ' . json_encode($analytics_result));
        
        $page_stats = [];
        if ($analytics_result['success'] && isset($analytics_result['data']['pages'])) {
            foreach ($analytics_result['data']['pages'] as $page_data) {
                $wp_post_id = $page_data['wordpress_post_id'];
                $page_stats[$wp_post_id] = [
                    'crawls' => intval($page_data['crawls'] ?? 0),
                    'revenue' => floatval($page_data['revenue'] ?? 0)
                ];
                
                error_log("402links: Page stats for WP Post ID {$wp_post_id}: {$page_stats[$wp_post_id]['crawls']} crawls, \${$page_stats[$wp_post_id]['revenue']} revenue");
            }
        }
        
        error_log('[402links] Page stats extracted: ' . json_encode($page_stats));
        
        // Get total count first
        $total_posts = wp_count_posts('post')->publish;
        $total_pages = ceil($total_posts / $per_page);
        
        // Get paginated posts
        $posts = get_posts([
            'post_type' => 'post',  // ✅ ONLY posts, not pages
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        error_log("[402links] WordPress posts count: " . count($posts) . " (total: $total_posts)");
        
        $wp_post_ids = array_map(function($p) { return $p->ID; }, $posts);
        error_log('402links: WordPress query returned post IDs: ' . implode(', ', $wp_post_ids));
        
        $content_list = [];
        
        foreach ($posts as $post) {
            $post_id = $post->ID;
            
            $link_id = get_post_meta($post_id, '_402links_id', true);
            $link_url = get_post_meta($post_id, '_402links_url', true);
            $price = get_post_meta($post_id, '_402links_price', true);
            $block_humans = get_post_meta($post_id, '_402links_block_humans', true);
            
            // Robust lookup with fallback
            $crawls = 0;
            $revenue = 0;
            
            if (isset($page_stats[$post_id])) {
                $crawls = $page_stats[$post_id]['crawls'];
                $revenue = $page_stats[$post_id]['revenue'];
                error_log("402links: Post #{$post_id} '{$post->post_title}' - MATCHED: {$crawls} crawls, \${$revenue} revenue");
            } else {
                error_log("402links: Post #{$post_id} '{$post->post_title}' - NO MATCH in analytics data");
            }
            
            $content_list[] = [
                'id' => $post_id,
                'title' => Helpers::get_clean_title($post_id),
                'url' => get_permalink($post_id),
                'type' => $post->post_type,
                'link_id' => $link_id,
                'link_url' => $link_url,
                'price' => $price ?: (get_option('402links_settings')['default_price'] ?? 0.10),
                'has_link' => !empty($link_id),
                'crawls' => $crawls,
                'revenue' => $revenue,
                'published' => $post->post_date,
                'block_humans' => (bool)$block_humans
            ];
        }
        
        wp_send_json_success([
            'content' => $content_list,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_posts' => $total_posts,
                'per_page' => $per_page
            ]
        ]);
    }
    
    /**
     * AJAX: Save wallet address
     */
    public static function ajax_save_wallet() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $wallet = sanitize_text_field($_POST['wallet'] ?? '');
        $default_price = floatval($_POST['default_price'] ?? 0.10);
        
        error_log('402links: ajax_save_wallet called');
        error_log('402links: Wallet: ' . $wallet);
        error_log('402links: Default Price: ' . $default_price);
        
        if (empty($wallet)) {
            wp_send_json_error(['message' => 'Wallet address is required']);
        }
        
        // Enhanced validation: Check Ethereum/Base address format
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            wp_send_json_error([
                'message' => 'Invalid wallet address format. Must be a valid Ethereum/Base address (0x + 40 hex characters)',
                'sync_success' => false
            ]);
        }
        
        // Save locally first
        $settings = get_option('402links_settings', []);
        $settings['payment_wallet'] = $wallet;
        $settings['default_price'] = $default_price;
        update_option('402links_settings', $settings);
        
        // CHECK IF SITE IS PROVISIONED
        $site_id = get_option('402links_site_id');
        $api_key = get_option('402links_api_key');
        
        // SCENARIO 1: Site not provisioned at all
        if (!$site_id) {
            error_log('402links: Site not provisioned - triggering auto-provision');
            
            // Trigger auto-provisioning
            Installer::activate();
            
            // Wait a moment for provisioning to complete
            sleep(2);
            
            // Re-check if provisioning succeeded
            $site_id = get_option('402links_site_id');
            $api_key = get_option('402links_api_key');
            
            if (!$site_id) {
                $provision_error = get_option('402links_provisioning_error', 'Auto-provisioning failed');
                error_log('402links: Auto-provisioning failed: ' . $provision_error);
                
                wp_send_json_success([
                    'message' => 'Configuration saved locally',
                    'sync_success' => false,
                    'sync_error' => 'Site registration pending. ' . $provision_error,
                    'wallet' => $wallet
                ]);
                return;
            }
            
            error_log('402links: Auto-provisioning completed. Site ID: ' . $site_id);
        }
        
        // SCENARIO 2: Site provisioned but no API key
        if (!$api_key) {
            error_log('402links: Site provisioned but no API key found');
            wp_send_json_success([
                'message' => 'Configuration saved locally',
                'sync_success' => false,
                'sync_error' => 'Site registered but API key is missing. Please contact support.',
                'wallet' => $wallet
            ]);
            return;
        }
        
        // SCENARIO 3: Everything is ready - sync to backend
        error_log('402links: Site ID: ' . $site_id . ' - Syncing settings to backend');
        
        $api = new API();
        $result = $api->sync_site_settings([
            'default_price' => $default_price,
            'payment_wallet' => $wallet
        ]);
        
        error_log('402links: Sync settings API result: ' . json_encode($result));
        
        // Store the agent_payment_wallet (splitter address) returned from backend
        // This ensures reinstallations get the correct payment address
        if (isset($result['data']['agent_payment_wallet']) && !empty($result['data']['agent_payment_wallet'])) {
            update_option('402links_agent_payment_wallet', $result['data']['agent_payment_wallet']);
            error_log('402links: Stored agent_payment_wallet from sync: ' . $result['data']['agent_payment_wallet']);
        }
        
        if ($result['success']) {
            $links_updated = $result['links_updated'] ?? 0;
            $message = $links_updated > 0 
                ? "Configuration saved and synced successfully. Automatically updated prices for {$links_updated} existing link(s) to \${$default_price}."
                : 'Configuration saved and synced successfully.';
            
            wp_send_json_success([
                'message' => $message,
                'sync_success' => true,
                'wallet' => $wallet,
                'links_updated' => $links_updated
            ]);
        } else {
            $sync_error = $result['error'] ?? 'Unknown sync error';
            error_log('402links: Failed to sync settings: ' . $sync_error);
            
            wp_send_json_success([
                'message' => 'Configuration saved locally',
                'sync_success' => false,
                'sync_error' => $sync_error,
                'wallet' => $wallet
            ]);
        }
    }
    
    /**
     * AJAX: Toggle human access for a post
     */
    public static function ajax_toggle_human_access() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $block_humans = isset($_POST['block_humans']) && $_POST['block_humans'] === 'true';
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }
        
        update_post_meta($post_id, '_402links_block_humans', $block_humans);
        
        wp_send_json_success([
            'message' => 'Human access updated',
            'block_humans' => $block_humans
        ]);
    }
    
    /**
     * AJAX: Get top performing pages
     */
    public static function ajax_get_top_pages() {
        error_log('🟦 [Admin] === AJAX GET TOP PAGES START ===');
        error_log('🟦 [Admin] POST data: ' . print_r($_POST, true));
        
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            error_log('🔴 [Admin] ERROR: Unauthorized user');
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $timeframe = sanitize_text_field($_POST['timeframe'] ?? '30d');
        $limit = intval($_POST['limit'] ?? 10);
        $offset = intval($_POST['offset'] ?? 0);
        
        error_log('🟦 [Admin] Calling API with: timeframe=' . $timeframe . ', limit=' . $limit . ', offset=' . $offset);
        
        $site_id = get_option('402links_site_id');
        error_log('🟦 [Admin] Site ID from options: ' . ($site_id ?: 'NOT SET'));
        
        $api = new API();
        $result = $api->get_top_pages($timeframe, $limit, $offset);
        
        error_log('🟢 [Admin] API result: ' . print_r($result, true));
        
        if ($result['success']) {
            error_log('🟢 [Admin] Sending success response with ' . count($result['pages'] ?? []) . ' pages');
            wp_send_json_success($result);
        } else {
            error_log('🔴 [Admin] Sending error response: ' . ($result['error'] ?? 'Unknown error'));
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Bulk generate links
     */
    public static function ajax_bulk_generate() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // Generate for all published posts
        $result = ContentSync::bulk_sync_all();
        
        if ($result['created'] > 0 || $result['updated'] > 0) {
            $message = sprintf(
                'Successfully generated %d new links and updated %d existing links.',
                $result['created'],
                $result['updated']
            );
            
            if ($result['failed'] > 0) {
                $message .= sprintf(' %d failed.', $result['failed']);
            }
            
            wp_send_json_success([
                'message' => $message,
                'stats' => $result
            ]);
        } else {
            wp_send_json_error([
                'message' => 'No links were generated. Please check your content.',
                'errors' => $result['errors'] ?? []
            ]);
        }
    }
    
    /**
     * AJAX: Start batch generation
     */
    public static function ajax_start_batch_generation() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // REMOVED: repair_existing_links() was causing 30-60s delay before batch start
        // Use manual "Repair Links" button if WordPress meta needs syncing
        
        $progress = BatchProcessor::start_batch();
        wp_send_json_success($progress);
    }
    
    /**
     * Repair existing links that have short_id in database but missing WordPress meta
     */
    private static function repair_existing_links() {
        global $wpdb;
        
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            error_log('[Link Repair] No site_id configured, skipping repair');
            return;
        }
        
        error_log('[Link Repair] Starting repair process for site: ' . $site_id);
        
        // Query Supabase to get all existing links for this site
        $api = new API();
        $response = $api->get_site_pages_with_links($site_id);
        
        if (!($response['success'] ?? false)) {
            error_log('[Link Repair] Failed to fetch existing links from database');
            return;
        }
        
        $links = $response['links'] ?? [];
        $repaired_count = 0;
        
        error_log('[Link Repair] Found ' . count($links) . ' existing links in database');
        
        // Update WordPress post meta for each existing link
        foreach ($links as $link) {
            $post_id = $link['wordpress_post_id'] ?? null;
            $link_id = $link['link_id'] ?? null;
            $short_id = $link['short_id'] ?? null;
            $link_url = $link['link_url'] ?? null;
            
            if (!$post_id || !$link_id || !$short_id || !$link_url) {
                error_log("[Link Repair] Skipping incomplete link data for post {$post_id}");
                continue;
            }
            
            // Check if WordPress post meta is missing or incorrect
            $existing_url = get_post_meta($post_id, '_402links_url', true);
            
            if (empty($existing_url) || $existing_url !== $link_url) {
                error_log("[Link Repair] Repairing post {$post_id}: setting URL to {$link_url}");
                
                // Update WordPress post meta
                update_post_meta($post_id, '_402links_id', $link_id);
                update_post_meta($post_id, '_402links_short_id', $short_id);
                update_post_meta($post_id, '_402links_url', $link_url);
                
                $repaired_count++;
            }
        }
        
        error_log("[Link Repair] Repair complete: {$repaired_count} links repaired");
    }
    
    /**
     * AJAX: Process next batch
     */
    public static function ajax_process_batch() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $result = BatchProcessor::process_next_batch();
        
        if ($result['success']) {
            // Extract progress from nested structure for consistency with start_batch
            $progress = $result['progress'] ?? $result;
            
            wp_send_json_success($progress);  // Return progress directly like start_batch
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get batch status
     */
    public static function ajax_get_batch_status() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        $status = BatchProcessor::get_status();
        wp_send_json_success($status);
    }
    
    /**
     * AJAX: Get violations summary
     */
    public static function ajax_get_violations_summary() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }
        
        $api = new API();
        $result = $api->get_violations_summary();
        
        error_log('402links: Violations AJAX handler - API result: ' . print_r($result, true));
        
        if ($result['success']) {
            // Extract agents and totals from flat result structure
            $response_data = [
                'agents' => $result['agents'] ?? [],
                'totals' => $result['totals'] ?? []
            ];
            
            error_log('402links: Violations AJAX handler - Sending response: ' . print_r($response_data, true));
            wp_send_json_success($response_data);
        } else {
            error_log('402links: Violations AJAX handler - Error: ' . ($result['error'] ?? 'Unknown error'));
            wp_send_json_error([
                'message' => $result['error'] ?? 'Failed to fetch violations data'
            ]);
        }
    }
    
    /**
     * AJAX: Cancel batch
     */
    public static function ajax_cancel_batch() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        $result = BatchProcessor::cancel_batch();
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Check wallet sync status
     * Returns whether the current wallet is already synced to Supabase
     */
    public static function ajax_check_wallet_sync_status() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $settings = get_option('402links_settings', []);
        $local_wallet = $settings['payment_wallet'] ?? '';
        $site_id = get_option('402links_site_id');
        
        if (!$site_id || !$local_wallet) {
            wp_send_json_success([
                'synced' => false,
                'wallet' => $local_wallet,
                'reason' => 'no_site_or_wallet'
            ]);
            return;
        }
        
        // Check if wallet matches what's in Supabase
        $api = new API();
        $result = $api->get_site_info($site_id);
        
        if ($result['success'] && isset($result['data']['agent_payment_wallet'])) {
            $remote_wallet = strtolower($result['data']['agent_payment_wallet'] ?? '');
            $is_synced = (strtolower($local_wallet) === $remote_wallet);
            
            wp_send_json_success([
                'synced' => $is_synced,
                'wallet' => $local_wallet,
                'remote_wallet' => $result['data']['agent_payment_wallet']
            ]);
        } else {
            wp_send_json_success([
                'synced' => false,
                'wallet' => $local_wallet,
                'reason' => 'api_error'
            ]);
        }
    }
    
    /**
     * AJAX: Get agent violations
     */
    public static function ajax_get_violations() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            wp_send_json_error(['message' => 'Site not registered']);
        }
        
        $api = new API();
        $result = $api->get_violations($site_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get site bot policies
     */
    public static function ajax_get_site_bot_policies() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            wp_send_json_error(['message' => 'Site not registered']);
        }
        
        $api = new API();
        $result = $api->get_site_bot_policies($site_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Update site bot policies
     */
    public static function ajax_update_site_bot_policies() {
        check_ajax_referer('agent_hub_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            wp_send_json_error(['message' => 'Site not registered']);
        }
        
        $policies = $_POST['policies'] ?? [];
        
        if (empty($policies)) {
            wp_send_json_error(['message' => 'No policies provided']);
        }
        
        $api = new API();
        $result = $api->update_site_bot_policies($site_id, $policies);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX handler for completing setup (triggers provisioning)
     */
    public static function ajax_complete_setup() {
        check_ajax_referer('angreessen_setup', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // Trigger provisioning
        require_once AGENT_HUB_PLUGIN_DIR . 'includes/Installer.php';
        $result = Installer::manual_provision();
        
        if ($result['success']) {
            delete_option('402links_needs_setup');
            wp_send_json_success([
                'site_id' => $result['site_id'],
                'message' => 'Setup completed successfully'
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? 'Unknown error'
            ]);
        }
    }
}
