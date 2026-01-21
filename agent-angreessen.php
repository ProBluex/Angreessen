<?php
/**
 * Plugin Name: Agent Angreessen - Ai Agent Pay Collector
 * Plugin URI: https://402links.com/agent-angreessen
 * Description: Convert any WordPress page into a paid API endpoint using HTTP 402 - requiring payment before AI agents access your content.
 * Version:           1.1.8
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
$angreessen49_header = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
define('ANGREESSEN49_VERSION', $angreessen49_header['Version'] ?: '1.1.8');
define('ANGREESSEN49_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ANGREESSEN49_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ANGREESSEN49_PLUGIN_FILE', __FILE__);

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'Angreessen49\\';
    $base_dir = ANGREESSEN49_PLUGIN_DIR . 'includes/';

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
require_once ANGREESSEN49_PLUGIN_DIR . 'includes/Helpers.php';

// Load text domain at the correct time (WordPress 6.7+ requirement)
add_action('init', function() {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for self-hosted/non-WordPress.org distribution
    load_plugin_textdomain(
        'agent-angreessen',
        false,
        dirname(plugin_basename(ANGREESSEN49_PLUGIN_FILE)) . '/languages'
    );
}, 10);

// Activation hook - now using Installer class
register_activation_hook(__FILE__, ['\Angreessen49\Installer', 'activate']);
register_activation_hook(__FILE__, 'angreessen49_activate');
function angreessen49_activate() {
    global $wpdb;
    
    // Run migration from legacy prefixes first
    angreessen49_migrate_from_legacy();
    
    $table_name = $wpdb->prefix . 'angreessen49_agent_logs';
    
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
    if (!get_option('angreessen49_block_humans_migrated')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration fix for meta key typo, no caching needed for UPDATE
        $wpdb->query("
            UPDATE {$wpdb->postmeta}
            SET meta_key = '_angreessen49_block_humans' 
            WHERE meta_key = '_402link_block_humans'
        ");
        update_option('angreessen49_block_humans_migrated', '1');
    }
    
    // Set default options
    if (!get_option('angreessen49_settings')) {
        add_option('angreessen49_settings', [
            'default_price' => 0.10,
            'auto_generate' => true,
            'payment_wallet' => '',
            'network' => 'base',
            'api_endpoint' => 'https://api.402links.com/v1'
        ]);
    }
    
    // Track plugin activation state
    update_option('angreessen49_plugin_active', true);
    update_option('angreessen49_last_activated', current_time('mysql'));
    
    // Register rewrite rules BEFORE flushing
    \Angreessen49\WellKnown::register_rewrite_rules();
    
    // Flush rewrite rules for .well-known endpoint
    flush_rewrite_rules();
}

/**
 * Migrate from legacy 402links_ and agent_hub_ prefixes to angreessen49_
 * This ensures existing installs don't lose their data
 */
function angreessen49_migrate_from_legacy() {
    global $wpdb;
    
    // Skip if already migrated
    if (get_option('angreessen49_legacy_migrated')) {
        return;
    }
    
    // 1. Rename database table (preserves agent visit logs)
    $old_table = $wpdb->prefix . '402links_agent_logs';
    $new_table = $wpdb->prefix . 'angreessen49_agent_logs';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time migration, table rename
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $old_table)) === $old_table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table rename with trusted prefix
        $wpdb->query("RENAME TABLE `{$old_table}` TO `{$new_table}`");
    }
    
    // 2. Migrate all options
    $option_map = [
        '402links_settings' => 'angreessen49_settings',
        '402links_api_key' => 'angreessen49_api_key',
        '402links_api_key_id' => 'angreessen49_api_key_id',
        '402links_site_id' => 'angreessen49_site_id',
        '402links_provisioned_url' => 'angreessen49_provisioned_url',
        '402links_provisioning_success' => 'angreessen49_provisioning_success',
        '402links_provisioning_info' => 'angreessen49_provisioning_info',
        '402links_provisioning_error' => 'angreessen49_provisioning_error',
        '402links_needs_setup' => 'angreessen49_needs_setup',
        '402links_block_humans_migrated' => 'angreessen49_block_humans_migrated',
        '402links_plugin_active' => 'angreessen49_plugin_active',
        '402links_last_activated' => 'angreessen49_last_activated',
        '402links_last_deactivated' => 'angreessen49_last_deactivated',
        '402links_agent_payment_wallet' => 'angreessen49_agent_payment_wallet',
    ];
    
    foreach ($option_map as $old => $new) {
        $value = get_option($old);
        if ($value !== false && get_option($new) === false) {
            update_option($new, $value);
            delete_option($old);
        }
    }
    
    // 3. Migrate post meta keys (bulk update for performance)
    $meta_map = [
        '_402links_id' => '_angreessen49_link_id',
        '_402links_short_id' => '_angreessen49_short_id',
        '_402links_url' => '_angreessen49_url',
        '_402links_price' => '_angreessen49_price',
        '_402links_block_humans' => '_angreessen49_block_humans',
        '_402links_synced_at' => '_angreessen49_synced_at',
        '_402links_bind_hash' => '_angreessen49_bind_hash',
        '_402links_nonce' => '_angreessen49_nonce',
        '_402links_invoice_id' => '_angreessen49_invoice_id',
    ];
    
    foreach ($meta_map as $old => $new) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration bulk update
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
            $new, $old
        ));
    }
    
    // 4. Migrate transients
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration bulk update
    $wpdb->query("UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, '_transient_agent_hub_', '_transient_angreessen49_') WHERE option_name LIKE '_transient_agent_hub_%'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration bulk update
    $wpdb->query("UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, '_transient_timeout_agent_hub_', '_transient_timeout_angreessen49_') WHERE option_name LIKE '_transient_timeout_agent_hub_%'");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration bulk update
    $wpdb->query("UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, '_transient_402links_', '_transient_angreessen49_') WHERE option_name LIKE '_transient_402links_%'");
    
    // Mark migration complete
    update_option('angreessen49_legacy_migrated', current_time('mysql'));
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'angreessen49_deactivate');
function angreessen49_deactivate() {
    // Track plugin deactivation state
    update_option('angreessen49_plugin_active', false);
    update_option('angreessen49_last_deactivated', current_time('mysql'));
    
    // IMPORTANT: DO NOT DELETE credentials or data
    // We want to preserve all 402-compatible pages in the Agent Hub
    // even if the plugin is temporarily disabled
    
    flush_rewrite_rules();
}

// Initialize plugin core after translations are ready
add_action('init', function() {
    if (class_exists('\Angreessen49\Core')) {
        $core = new \Angreessen49\Core();
        $core->init();
    }
}, 13); // Priority 13 ensures it runs after textdomain loading and update checker
