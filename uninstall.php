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
$table_name = $wpdb->prefix . '402links_agent_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name uses trusted $wpdb->prefix with hardcoded suffix
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete all 402links core options
delete_option('402links_settings');
delete_option('402links_api_key');
delete_option('402links_api_key_id');
delete_option('402links_site_id');

// Delete provisioning-related options
delete_option('402links_provisioned_url');
delete_option('402links_provisioning_success');
delete_option('402links_provisioning_info');
delete_option('402links_provisioning_error');

// Delete migration flags
delete_option('402links_block_humans_migrated');

// Delete plugin state tracking
delete_option('402links_plugin_active');
delete_option('402links_last_activated');
delete_option('402links_last_deactivated');

// Delete plugin update checker options
delete_option('external_updates-agent-angreessen');
delete_option('angreessen_debug_updater');

// Clean up any remaining 402links or angreessen options (safety net)
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    '402links%',
    '%angreessen%'
));

// Delete all post meta
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    '_402links_%'
));

// Flush rewrite rules
flush_rewrite_rules();
