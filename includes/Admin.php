<?php
namespace Angreessen49;

class Admin {
    /**
     * Show provisioning status notices
     */
    public static function show_provisioning_notice() {
        // Check if wallet is configured
        $settings = get_option('angreessen49_settings', []);
        $wallet = $settings['payment_wallet'] ?? '';
        $site_id = get_option('angreessen49_site_id');
        
        
        // Show success notice
        if (get_option('angreessen49_provisioning_success')) {
            $site_id = get_option('angreessen49_site_id');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>🎉 Agent Angreessen - Ai Agent Pay Collector:</strong> Your site has been automatically registered! 
                Site ID: <code><?php echo esc_html($site_id); ?></code></p>
                <p>Configure your payment wallet in the <a href="<?php echo esc_url(admin_url('admin.php?page=angreessen49')); ?>">Agent Angreessen dashboard</a> to start protecting and monetizing your content.</p>
            </div>
            <?php
            delete_option('angreessen49_provisioning_success');
        }
        
        // Show info notice (for already provisioned sites)
        $info = get_option('angreessen49_provisioning_info');
        if ($info) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>ℹ️ Agent Angreessen:</strong> <?php echo esc_html($info); ?></p>
            </div>
            <?php
            delete_option('angreessen49_provisioning_info');
        }
        
        // Show error notice
        $error = get_option('angreessen49_provisioning_error');
        if ($error) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>⚠️ Agent Angreessen:</strong> Auto-provisioning failed: <?php echo esc_html($error); ?></p>
                <p>You can manually register at <a href="https://402links.com" target="_blank">402links.com</a> or contact support for assistance.</p>
            </div>
            <?php
            delete_option('angreessen49_provisioning_error');
        }
    }
    
    /**
     * Show setup wizard notice (requires user consent before provisioning)
     */
    public static function show_setup_notice() {
        if (!get_option('angreessen49_needs_setup')) {
            return;
        }
        
        $site_id = get_option('angreessen49_site_id');
        if ($site_id) {
            // Already provisioned, clear flag
            delete_option('angreessen49_needs_setup');
            return;
        }
        
        // Enqueue the setup wizard script
        wp_enqueue_script(
            'angreessen49-setup-wizard',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/setup-wizard.js',
            ['jquery'],
            ANGREESSEN49_VERSION,
            true
        );
        
        wp_localize_script('angreessen49-setup-wizard', 'angreessen49Setup', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('angreessen49_setup')
        ]);
        
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
                <a href="<?php echo esc_url(admin_url('plugins.php')); ?>" class="button">Skip for Now</a>
            </p>
        </div>
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
            'angreessen49',
            [self::class, 'render_dashboard'],
            'dashicons-shield-alt',
            30
        );
    }
    
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_angreessen49') {
            return;
        }
        
        wp_enqueue_style(
            'angreessen49-admin',
            ANGREESSEN49_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ANGREESSEN49_VERSION
        );
        
        wp_enqueue_style(
            'angreessen49-batch-processor',
            ANGREESSEN49_PLUGIN_URL . 'assets/css/batch-processor.css',
            [],
            ANGREESSEN49_VERSION
        );
        
        // Feather Icons library (bundled locally)
        wp_enqueue_script(
            'feather-icons',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/vendor/feather.min.js',
            [],
            ANGREESSEN49_VERSION,
            true
        );
        
        // Chart.js for Analytics rendering (bundled locally)
        wp_enqueue_script(
            'chartjs',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/vendor/chart.umd.min.js',
            [],
            ANGREESSEN49_VERSION,
            true
        );
        
        // Load admin.js first - other scripts depend on it
        wp_enqueue_script(
            'angreessen49-admin',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            ANGREESSEN49_VERSION,
            true
        );
        
        // Analytics depends on admin.js being loaded - use filemtime for cache busting
        $analytics_version = ANGREESSEN49_VERSION . '.' . filemtime(ANGREESSEN49_PLUGIN_DIR . 'assets/js/analytics.js');
        wp_enqueue_script(
            'angreessen49-analytics',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/analytics.js',
            ['jquery', 'angreessen49-admin', 'chartjs'],
            $analytics_version,
            true
        );
        
        // Content manager depends on admin.js
        wp_enqueue_script(
            'angreessen49-content',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/content-manager.js',
            ['jquery', 'angreessen49-admin'],
            ANGREESSEN49_VERSION,
            true
        );
        
        // Overview depends on admin.js
        wp_enqueue_script(
            'angreessen49-overview',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/overview.js',
            ['jquery', 'angreessen49-admin'],
            ANGREESSEN49_VERSION,
            true
        );
        
        // Contact depends on admin.js
        wp_enqueue_script(
            'angreessen49-contact',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/contact.js',
            ['jquery', 'angreessen49-admin'],
            ANGREESSEN49_VERSION,
            true
        );
        
        // Violations depends on admin.js
        wp_enqueue_script(
            'angreessen49-violations',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/violations.js',
            ['jquery', 'angreessen49-admin'],
            ANGREESSEN49_VERSION,
            true
        );
        
        // Batch processor depends on admin.js
        wp_enqueue_script(
            'angreessen49-batch-processor',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/batch-processor.js',
            ['jquery', 'angreessen49-admin'],
            ANGREESSEN49_VERSION,
            true
        );
        
        self::localize_angreessen49_data('angreessen49-admin');
    }
    
    /**
     * Enqueue meta box assets for post/page editors
     */
    public static function enqueue_meta_box_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, ['post', 'page'], true)) {
            return;
        }
        
        wp_enqueue_style(
            'angreessen49-meta-box',
            ANGREESSEN49_PLUGIN_URL . 'assets/css/meta-box.css',
            [],
            ANGREESSEN49_VERSION
        );
        
        wp_enqueue_script(
            'angreessen49-meta-box',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/meta-box.js',
            ['jquery'],
            ANGREESSEN49_VERSION,
            true
        );
        
        self::localize_angreessen49_data('angreessen49-meta-box');
    }
    
    /**
     * Enqueue violations page assets
     */
    public static function enqueue_violations_page_assets($hook) {
        if ($hook !== 'toplevel_page_angreessen49') {
            return;
        }
        
        wp_enqueue_style(
            'angreessen49-violations-page',
            ANGREESSEN49_PLUGIN_URL . 'assets/css/violations-page.css',
            [],
            ANGREESSEN49_VERSION
        );
        
        wp_enqueue_script(
            'angreessen49-violations-page',
            ANGREESSEN49_PLUGIN_URL . 'assets/js/violations-page.js',
            ['jquery'],
            ANGREESSEN49_VERSION,
            true
        );
        
        self::localize_angreessen49_data('angreessen49-violations-page');
    }
    
    /**
     * Localize angreessen49Data for scripts
     * 
     * @param string $handle Script handle to attach data to
     */
    private static function localize_angreessen49_data($handle) {
        wp_localize_script($handle, 'angreessen49Data', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('angreessen49_nonce'),
            'siteUrl' => get_site_url(),
            'siteName' => get_bloginfo('name'),
            'siteId' => get_option('angreessen49_site_id'),
            'pluginUrl' => ANGREESSEN49_PLUGIN_URL,
            'apiEndpoint' => 'https://api.402links.com/v1'
        ]);
    }
    
    /**
     * Render dashboard
     */
    public static function render_dashboard() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        include ANGREESSEN49_PLUGIN_DIR . 'templates/settings-page.php';
    }
    
    /**
     * Add meta box to posts
     */
    public static function add_meta_box() {
        add_meta_box(
            'angreessen49-meta-box',
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
        wp_nonce_field('angreessen49_save_meta_' . $post->ID, 'angreessen49_meta_nonce');
        
        include ANGREESSEN49_PLUGIN_DIR . 'templates/meta-box.php';
    }
    
    /**
     * AJAX: Save settings
     */
    public static function ajax_save_settings() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        // Get old settings to detect price changes
        $old_settings = get_option('angreessen49_settings', []);
        
        $settings = [
            'default_price' => floatval($_POST['default_price'] ?? 0.10),
            'auto_generate' => isset($_POST['auto_generate']) && $_POST['auto_generate'] === 'true',
            'payment_wallet' => sanitize_text_field(wp_unslash($_POST['payment_wallet'] ?? '')),
            'network' => sanitize_text_field(wp_unslash($_POST['network'] ?? 'base')),
            'api_endpoint' => sanitize_text_field(wp_unslash($_POST['api_endpoint'] ?? 'https://api.402links.com/v1'))
        ];
        
        update_option('angreessen49_settings', $settings);
        
        if (isset($_POST['api_key'])) {
            update_option('angreessen49_api_key', sanitize_text_field(wp_unslash($_POST['api_key'])));
        }
        
        // Sync default_price and payment_wallet to Supabase registered_sites table
        $site_id = get_option('angreessen49_site_id');
        if ($site_id) {
            $api = new API();
            $sync_result = $api->sync_site_settings([
                'default_price' => $settings['default_price'],
                'payment_wallet' => $settings['payment_wallet']
            ]);
            
            // CRITICAL: Store the agent_payment_wallet (splitter address) returned from backend
            // This is the address agents should pay to (99% creator / 1% platform split)
            if (isset($sync_result['data']['agent_payment_wallet'])) {
                update_option('angreessen49_agent_payment_wallet', $sync_result['data']['agent_payment_wallet']);
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
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $site_id = get_option('angreessen49_site_id');
        
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
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $api = new API();
        $result = $api->register_site();
        
        if ($result['success']) {
            if (isset($result['site_id'])) {
                update_option('angreessen49_site_id', $result['site_id']);
            }
            
            // CRITICAL: Store the agent_payment_wallet from registration response
            // This is the splitter address that agents should pay to
            if (isset($result['agent_payment_wallet'])) {
                update_option('angreessen49_agent_payment_wallet', $result['agent_payment_wallet']);
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
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
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
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $timeframe = sanitize_text_field(wp_unslash($_POST['timeframe'] ?? '30d'));
        
        $api = new API();
        $result = $api->get_analytics($timeframe);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get content list
     */
    public static function ajax_get_content() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $page = intval($_POST['page'] ?? 1);
        $per_page = intval($_POST['per_page'] ?? 20);
        
        $args = [
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        
        $query = new \WP_Query($args);
        $content = [];
        
        foreach ($query->posts as $post) {
            $link_id = get_post_meta($post->ID, '_angreessen49_link_id', true);
            $link_url = get_post_meta($post->ID, '_angreessen49_url', true);
            $short_id = get_post_meta($post->ID, '_angreessen49_short_id', true);
            $price = get_post_meta($post->ID, '_angreessen49_price', true);
            $block_humans = get_post_meta($post->ID, '_angreessen49_block_humans', true);
            
            $settings = get_option('angreessen49_settings', []);
            $default_price = $settings['default_price'] ?? 0.10;
            
            $content[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'url' => get_permalink($post->ID),
                'has_link' => !empty($link_id),
                'link_url' => $link_url,
                'short_id' => $short_id,
                'price' => !empty($price) ? floatval($price) : floatval($default_price),
                'block_humans' => $block_humans === '1' || $block_humans === 1,
                'paid_link' => $short_id ? 'https://402links.com/p/' . $short_id : null,
                'human_paid_link' => $short_id ? 'https://402links.com/p/' . $short_id : null
            ];
        }
        
        wp_send_json_success([
            'content' => $content,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $query->max_num_pages,
                'total_posts' => $query->found_posts
            ]
        ]);
    }
    
    /**
     * AJAX: Toggle human access
     */
    public static function ajax_toggle_human_access() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $block_humans = $_POST['block_humans'] === 'true' ? '1' : '0';
        
        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }
        
        update_post_meta($post_id, '_angreessen49_block_humans', $block_humans);
        
        wp_send_json_success(['message' => 'Human access updated']);
    }
    
    /**
     * AJAX: Save wallet configuration
     */
    public static function ajax_save_wallet() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $wallet = sanitize_text_field(wp_unslash($_POST['wallet'] ?? ''));
        $default_price = floatval($_POST['default_price'] ?? 0.10);
        
        $settings = get_option('angreessen49_settings', []);
        $settings['payment_wallet'] = $wallet;
        $settings['default_price'] = $default_price;
        
        update_option('angreessen49_settings', $settings);
        
        // Sync to backend
        $site_id = get_option('angreessen49_site_id');
        $sync_success = false;
        $sync_error = '';
        
        if ($site_id) {
            $api = new API();
            $sync_result = $api->sync_site_settings([
                'default_price' => $default_price,
                'payment_wallet' => $wallet
            ]);
            
            $sync_success = $sync_result['success'] ?? false;
            $sync_error = $sync_result['error'] ?? '';
            
            if (isset($sync_result['data']['agent_payment_wallet'])) {
                update_option('angreessen49_agent_payment_wallet', $sync_result['data']['agent_payment_wallet']);
            }
        } else {
            $sync_error = 'Site not provisioned';
        }
        
        wp_send_json_success([
            'message' => 'Configuration saved',
            'sync_success' => $sync_success,
            'sync_error' => $sync_error
        ]);
    }
    
    /**
     * AJAX: Get rogue agents
     */
    public static function ajax_get_rogue_agents() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $timeframe = sanitize_text_field(wp_unslash($_POST['timeframe'] ?? '30d'));
        
        $api = new API();
        $result = $api->get_rogue_agents($timeframe);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get violations
     */
    public static function ajax_get_violations() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $api = new API();
        $result = $api->get_violations();
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get violations summary
     */
    public static function ajax_get_violations_summary() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $api = new API();
        $result = $api->get_violations_summary();
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get site bot policies
     */
    public static function ajax_get_site_bot_policies() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $api = new API();
        $result = $api->get_site_bot_policies();
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Save site bot policies
     */
    public static function ajax_save_site_bot_policies() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $policies = isset($_POST['policies']) ? json_decode(stripslashes($_POST['policies']), true) : [];
        
        $api = new API();
        $result = $api->save_site_bot_policies($policies);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get content analytics
     */
    public static function ajax_get_content_analytics() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $api = new API();
        $result = $api->get_content_analytics();
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Start batch generation
     */
    public static function ajax_start_batch_generation() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $result = BatchProcessor::start_batch();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Process batch
     */
    public static function ajax_process_batch() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $result = BatchProcessor::process_next_batch();
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Cancel batch
     */
    public static function ajax_cancel_batch() {
        check_ajax_referer('angreessen49_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        BatchProcessor::cancel_batch();
        
        wp_send_json_success(['message' => 'Batch cancelled']);
    }
}
