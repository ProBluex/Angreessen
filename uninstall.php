<?php
/**
 * Uninstall script for Agent Angreessen - Ai Agent Pay Collector
 * Fired when the plugin is uninstalled
 */

// Exit if accessed directly
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Drop custom table
global $wpdb;
$angreessen49_table_name = $wpdb->prefix . 'angreessen49_agent_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses trusted $wpdb->prefix with hardcoded suffix, uninstall cleanup
$wpdb->query("DROP TABLE IF EXISTS {$angreessen49_table_name}");

// Also drop legacy table if exists
$legacy_table_name = $wpdb->prefix . '402links_agent_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses trusted $wpdb->prefix with hardcoded suffix, uninstall cleanup
$wpdb->query("DROP TABLE IF EXISTS {$legacy_table_name}");

// Delete all angreessen49 core options
delete_option('angreessen49_settings');
delete_option('angreessen49_api_key');
delete_option('angreessen49_api_key_id');
delete_option('angreessen49_site_id');

// Delete provisioning-related options
delete_option('angreessen49_provisioned_url');
delete_option('angreessen49_provisioning_success');
delete_option('angreessen49_provisioning_info');
delete_option('angreessen49_provisioning_error');

// Delete migration flags
delete_option('angreessen49_block_humans_migrated');
delete_option('angreessen49_legacy_migrated');

// Delete plugin state tracking
delete_option('angreessen49_plugin_active');
delete_option('angreessen49_last_activated');
delete_option('angreessen49_last_deactivated');

// Delete agent payment wallet
delete_option('angreessen49_agent_payment_wallet');
delete_option('angreessen49_needs_setup');

// Delete plugin update checker options
delete_option('external_updates-agent-angreessen');
delete_option('angreessen_debug_updater');

// Clean up any remaining angreessen49 or legacy options (safety net)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires bulk DELETE, no caching needed
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    'angreessen49%',
    '402links%',
    '%angreessen%'
));

// Delete all post meta (new and legacy)
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup requires bulk DELETE, no caching needed
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
    '_angreessen49_%',
    '_402links_%'
));

// Flush rewrite rules
flush_rewrite_rules();
