<?php
/**
 * Plugin Name: Agent Angreessen - Ai Agent Pay Collector
 * Plugin URI: https://402links.com/agent-angreessen
 * Description: Convert any WordPress page into a paid API endpoint using HTTP 402 - requiring payment before AI agents access your content.
 * Version:           1.1.5
 * Author: Agent Angreessen Team
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
$agent_hub_header = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
define('AGENT_HUB_VERSION', $agent_hub_header['Version'] ?: '1.1.5');
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

// Load helper functions
require_once AGENT_HUB_PLUGIN_DIR . 'includes/Helpers.php';

// Load text domain at the correct time (WordPress 6.7+ requirement)
add_action('init', function() {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for self-hosted/non-WordPress.org distribution
    load_plugin_textdomain(
        'agent-angreessen',
        false,
        dirname(plugin_basename(AGENT_HUB_PLUGIN_FILE)) . '/languages'
    );
}, 10);

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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration fix for meta key typo, no caching needed for UPDATE
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
    
    // Register rewrite rules BEFORE flushing
    \AgentHub\WellKnown::register_rewrite_rules();
    
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
