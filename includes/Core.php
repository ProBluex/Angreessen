<?php
namespace Angreessen49;

class Core {
    public function init() {
        // Agent interception (BEFORE WordPress serves content)
        add_action('template_redirect', [PaymentGate::class, 'intercept_request'], 1);
        
        // Admin UI
        if (is_admin()) {
            add_action('admin_menu', [Admin::class, 'register_menu']);
            add_action('admin_enqueue_scripts', [Admin::class, 'enqueue_assets']);
            add_action('admin_enqueue_scripts', [Admin::class, 'enqueue_meta_box_assets']);
            add_action('admin_enqueue_scripts', [Admin::class, 'enqueue_violations_page_assets']);
            add_action('add_meta_boxes', [Admin::class, 'add_meta_box']);
            add_action('admin_notices', [Admin::class, 'show_setup_notice']);
            add_action('admin_notices', [Admin::class, 'show_provisioning_notice']);
        }
        
        // Post save hook
        add_action('save_post', [ContentSync::class, 'sync_post_to_402links'], 10, 1);
        
        // .well-known endpoints
        add_action('init', [WellKnown::class, 'register_rewrite_rules']);
        add_filter('query_vars', [WellKnown::class, 'register_query_vars']);
        add_action('template_redirect', [WellKnown::class, 'serve_402_json'], 5);
        add_action('template_redirect', [WellKnown::class, 'serve_agent_card'], 5);
        
        // robots.txt injection
        add_filter('robots_txt', [WellKnown::class, 'inject_robots_txt'], 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_angreessen49_save_settings', [Admin::class, 'ajax_save_settings']);
        add_action('wp_ajax_angreessen49_register_site', [Admin::class, 'ajax_register_site']);
        add_action('wp_ajax_angreessen49_generate_link', [Admin::class, 'ajax_generate_link']);
        add_action('wp_ajax_angreessen49_get_analytics', [Admin::class, 'ajax_get_analytics']);
        add_action('wp_ajax_angreessen49_get_top_pages', [Admin::class, 'ajax_get_top_pages']);
        add_action('wp_ajax_angreessen49_save_wallet', [Admin::class, 'ajax_save_wallet']);
        add_action('wp_ajax_angreessen49_check_wallet_sync_status', [Admin::class, 'ajax_check_wallet_sync_status']);
        add_action('wp_ajax_angreessen49_check_existing_links', [Admin::class, 'ajax_check_existing_links']);
        add_action('wp_ajax_angreessen49_toggle_human_access', [Admin::class, 'ajax_toggle_human_access']);
        add_action('wp_ajax_angreessen49_get_content', [Admin::class, 'ajax_get_content']);
        add_action('wp_ajax_angreessen49_get_content_analytics', [Admin::class, 'ajax_get_content_analytics']);
        add_action('wp_ajax_angreessen49_bulk_generate', [Admin::class, 'ajax_bulk_generate']);
        add_action('wp_ajax_angreessen49_complete_setup', [Admin::class, 'ajax_complete_setup']);
        
        // Batch processing AJAX handlers
        add_action('wp_ajax_angreessen49_start_batch_generation', [Admin::class, 'ajax_start_batch_generation']);
        add_action('wp_ajax_angreessen49_process_batch', [Admin::class, 'ajax_process_batch']);
        add_action('wp_ajax_angreessen49_get_batch_status', [Admin::class, 'ajax_get_batch_status']);
        add_action('wp_ajax_angreessen49_cancel_batch', [Admin::class, 'ajax_cancel_batch']);
        
        // Violations AJAX handler
        add_action('wp_ajax_angreessen49_get_violations', [Admin::class, 'ajax_get_violations']);
        add_action('wp_ajax_angreessen49_get_violations_summary', [Admin::class, 'ajax_get_violations_summary']);
        add_action('wp_ajax_angreessen49_get_rogue_agents', [Admin::class, 'ajax_get_rogue_agents']);
        
        // Bot policy AJAX handlers
        add_action('wp_ajax_angreessen49_get_site_bot_policies', [Admin::class, 'ajax_get_site_bot_policies']);
        add_action('wp_ajax_angreessen49_update_site_bot_policies', [Admin::class, 'ajax_update_site_bot_policies']);
        
        // REST API routes
        add_action('rest_api_init', [API::class, 'register_rest_routes']);
    }
}
