<?php
/**
 * Plugin Name: Tolliver - Ai Agent Pay Collector
 * Plugin URI: https://402links.com
 * Description: Convert any WordPress page into a paid API endpoint using HTTP 402 - requiring payment before AI agents access your content.
 * Version:           3.15.8
 * Author: Tolliver Team
 * Author URI: https://402links.com
 * License: MIT
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
define('AGENT_HUB_VERSION', $header['Version'] ?: '3.15.8');
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

// GitHub Auto-Update Integration - deferred to admin_init to avoid early translation triggers
add_action('admin_init', function () {
    static $booted = false;
    if ($booted) return;                 // One-time guard per request
    if (wp_doing_ajax()) return;         // Skip AJAX requests
    if (defined('WP_INSTALLING') && WP_INSTALLING) return; // Skip installation
    
    // Skip during plugin activation flow
    $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
    if (in_array($action, ['activate', 'activate-plugin'], true)) return;

    // Deferred include: load vendor only now, NOT at file scope
    $puc_path = AGENT_HUB_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5p6\\PucFactory') && file_exists($puc_path)) {
        require_once $puc_path;
    }

    // Instantiate PUC now that WordPress is fully ready
    if (class_exists('YahnisElsts\\PluginUpdateChecker\\v5p6\\PucFactory')) {
        $booted = true;
        $updateChecker = YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker(
            'https://github.com/ProBluex/wordpress-plugin-aiagentpaywall',
            AGENT_HUB_PLUGIN_FILE,
            'tolliver-agent'
        );
        $updateChecker->getVcsApi()->enableReleaseAssets();
        $updateChecker->setBranch('main');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Tolliver: PUC initialized on admin_init');
        }
    }
}, 12);

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
