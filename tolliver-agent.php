<?php
/**
 * Plugin Name: Tolliver - Ai Agent Pay Collector
 * Plugin URI: https://402links.com
 * Description: Convert any WordPress page into a paid API endpoint using HTTP 402 - requiring payment before AI agents access your content.
 * Version:           3.18.8
 * Author: Tolliver Team
 * Author URI: https://402links.com
 * License: GPLv2 or later
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
// Use get_file_data instead of get_plugin_data to avoid early translation triggers
if (!function_exists('get_file_data')) {
    require_once(ABSPATH . 'wp-includes/functions.php');
}
$header = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
define('AGENT_HUB_VERSION', $header['Version'] ?: '3.18.8');
define('AGENT_HUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AGENT_HUB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AGENT_HUB_PLUGIN_FILE', __FILE__);

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'AgentHub\\';
    $base_dir = AGENT_HUB_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load text domain at the correct time (WordPress 6.7+ requirement)
add_action('init', function() {
    load_plugin_textdomain(
        'tolliver-agent',
        false,
        dirname(plugin_basename(AGENT_HUB_PLUGIN_FILE)) . '/languages'
    );
}, 10);

// GitHub Auto-Update Integration
// CRITICAL: Must be initialized at file scope (not deferred to hooks)
// to ensure PUC can hook into pre_set_site_transient_update_plugins
// before WordPress checks for updates

// Context guards: skip initialization where not needed
$should_init_puc = true;

// Skip during WordPress installation
if (defined('WP_INSTALLING') && WP_INSTALLING) {
    $should_init_puc = false;
}

// Skip during REST API requests (unless it's an admin context)
if (defined('REST_REQUEST') && REST_REQUEST && !is_admin()) {
    $should_init_puc = false;
}

// Skip during frontend requests (unless it's wp-cron)
if (!is_admin() && !wp_doing_cron() && !defined('DOING_CRON')) {
    $should_init_puc = false;
}

// Initialize PUC immediately if context is appropriate
if ($should_init_puc) {
    $puc_path = AGENT_HUB_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    
    if (file_exists($puc_path)) {
        require_once $puc_path;
        
        if (class_exists('YahnisElsts\\PluginUpdateChecker\\v5p6\\PucFactory')) {
            $updateChecker = YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker(
                'https://github.com/ProBluex/Tolliver',
                AGENT_HUB_PLUGIN_FILE,
                'tolliver-agent'
            );
            
            $updateChecker->getVcsApi()->enableReleaseAssets();
            $updateChecker->setBranch('main');
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $context = wp_doing_cron() ? 'wp-cron' : (is_admin() ? 'admin' : 'other');
                error_log("Tolliver PUC: Initialized at file scope (context: {$context})");
            }
        }
    }
}

// Add cache-clearing mechanism for PUC updates
add_action('admin_init', function() {
    // Handle manual cache clear request
    if (isset($_GET['tolliver_clear_update_cache']) && current_user_can('update_plugins')) {
        check_admin_referer('tolliver-clear-cache');
        
        // Clear all PUC-related caches
        delete_site_option('external_updates-tolliver-agent');
        delete_site_transient('puc_request_info_result-tolliver-agent');
        delete_transient('puc_request_info_result-tolliver-agent');
        
        // Clear WordPress plugin update cache
        delete_site_transient('update_plugins');
        
        // Force PUC to check immediately
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }
        
        wp_redirect(admin_url('plugins.php?tolliver_cache_cleared=1'));
        exit;
    }
    
    // Show success notice
    if (isset($_GET['tolliver_cache_cleared'])) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Tolliver:</strong> Update cache cleared! Please check for updates again.</p>';
            echo '</div>';
        });
    }
}, 15);

// Auto-clear cache if version mismatch detected
add_action('admin_init', function() {
    // Only check on plugins page
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'plugins') {
        return;
    }
    
    // Get cached update info
    $cached_update = get_site_option('external_updates-tolliver-agent');
    
    if ($cached_update && isset($cached_update->update)) {
        $cached_version = $cached_update->update->version ?? null;
        $current_version = AGENT_HUB_VERSION;
        
        // If cached version shows "no update" but we're not on that version,
        // the cache is stale
        if ($cached_version && version_compare($current_version, $cached_version, '=')) {
            // Check GitHub directly for newer version
            $github_api_url = 'https://api.github.com/repos/ProBluex/Tolliver/releases/latest';
            $response = wp_remote_get($github_api_url, ['timeout' => 5]);
            
            if (!is_wp_error($response)) {
                $release = json_decode(wp_remote_retrieve_body($response), true);
                $latest_version = isset($release['tag_name']) ? ltrim($release['tag_name'], 'v') : null;
                
                // If GitHub has a newer version, clear cache
                if ($latest_version && version_compare($current_version, $latest_version, '<')) {
                    delete_site_option('external_updates-tolliver-agent');
                    delete_site_transient('update_plugins');
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Tolliver: Auto-cleared stale cache (current: {$current_version}, GitHub: {$latest_version})");
                    }
                }
            }
        }
    }
}, 20);

// Add "Clear Update Cache" action link on plugins page
add_filter('plugin_action_links_' . plugin_basename(AGENT_HUB_PLUGIN_FILE), function($links) {
    if (current_user_can('update_plugins')) {
        $clear_cache_url = wp_nonce_url(
            admin_url('admin.php?tolliver_clear_update_cache=1'),
            'tolliver-clear-cache'
        );
        $clear_cache_link = sprintf(
            '<a href="%s" style="color: #d63638;">%s</a>',
            esc_url($clear_cache_url),
            __('Clear Update Cache', 'tolliver-agent')
        );
        array_unshift($links, $clear_cache_link);
    }
    return $links;
}, 10, 1);

// Add custom update checker button on plugins page
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'plugins') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Add custom check button for Tolliver updates
            const clearCacheUrl = '<?php echo wp_nonce_url(admin_url('admin.php?tolliver_clear_update_cache=1'), 'tolliver-clear-cache'); ?>';
            
            $('<a>', {
                href: clearCacheUrl,
                class: 'button button-secondary',
                text: 'Check Tolliver Updates',
                css: {
                    marginLeft: '10px',
                    marginTop: '10px'
                }
            }).insertAfter($('.subsubsub'));
        });
        </script>
        <?php
    }
});

// Activation hook - now using Installer class
register_activation_hook(__FILE__, ['\AgentHub\Installer', 'activate']);
register_activation_hook(__FILE__, 'agent_hub_activate');
function agent_hub_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . '402links_agent_logs';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        agent_name VARCHAR(255),
        user_agent TEXT,
        ip_address VARCHAR(45),
        payment_status VARCHAR(20) DEFAULT 'unpaid',
        payment_tx_hash VARCHAR(255),
        amount_paid DECIMAL(20,6) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_post_id (post_id),
        INDEX idx_created_at (created_at),
        INDEX idx_payment_status (payment_status)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // One-time migration: fix typo in meta key (missing 's' after '402link')
    if (!get_option('402links_block_humans_migrated')) {
        $wpdb->query("
            UPDATE {$wpdb->postmeta} 
            SET meta_key = '_402links_block_humans' 
            WHERE meta_key = '_402link_block_humans'
        ");
        update_option('402links_block_humans_migrated', '1');
    }
    
    // Set default options
    if (!get_option('402links_settings')) {
        add_option('402links_settings', [
            'default_price' => 0.10,
            'auto_generate' => true,
            'payment_wallet' => '',
            'network' => 'base',
            'api_endpoint' => 'https://api.402links.com/v1'
        ]);
    }
    
    // Track plugin activation state
    update_option('402links_plugin_active', true);
    update_option('402links_last_activated', current_time('mysql'));
    
    // Flush rewrite rules for .well-known endpoint
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'agent_hub_deactivate');
function agent_hub_deactivate() {
    // Track plugin deactivation state
    update_option('402links_plugin_active', false);
    update_option('402links_last_deactivated', current_time('mysql'));
    
    // IMPORTANT: DO NOT DELETE credentials or 402links data
    // We want to preserve all 402-compatible pages in the Agent Hub
    // even if the plugin is temporarily disabled
    
    flush_rewrite_rules();
}

// Initialize plugin core after translations are ready
add_action('init', function() {
    if (class_exists('\AgentHub\Core')) {
        $core = new \AgentHub\Core();
        $core->init();
    }
}, 13); // Priority 13 ensures it runs after textdomain loading and update checker
