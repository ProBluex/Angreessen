<?php
namespace AgentHub;

class Installer {
    /**
     * Plugin activation hook - auto-provision the site
     */
    public static function activate() {
        $site_id = get_option('402links_site_id');
        $api_key = get_option('402links_api_key');
        
        // Recovery scenario: site_id exists but api_key missing
        if (!empty($site_id) && empty($api_key)) {
            // Set flag to show recovery notice
            update_option('402links_recovery_needed', true);
            error_log('[Tolliver] Recovery scenario detected on activation - site_id exists but api_key missing');
            return;
        }
        
        // Check if already provisioned
        $existing_key = get_option('402links_api_key');
        $existing_site_id = get_option('402links_site_id');
        
        if ($existing_key && $existing_site_id) {
            error_log('402links: Already provisioned, skipping auto-provision');
            return;
        }
        
        // Auto-provision the site
        self::auto_provision();
    }
    
    /**
     * Automatically provision the WordPress site with 402links (with retry logic)
     */
    private static function auto_provision() {
        $max_attempts = 3;
        $backoff_delays = [1, 2, 4]; // seconds
        
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            error_log("402links: Auto-provision attempt {$attempt}/{$max_attempts}...");
            
            $result = self::attempt_provision();
            
            if ($result['success']) {
                // Store credentials on success
                if (isset($result['api_key'])) {
                    update_option('402links_api_key', $result['api_key']);
                    update_option('402links_api_key_id', $result['api_key_id']);
                    update_option('402links_site_id', $result['site_id']);
                    update_option('402links_provisioned_url', get_site_url());
                    
                    // NEW: Store service role key if provided
                    if (isset($result['service_role_key'])) {
                        update_option('402links_supabase_service_key', $result['service_role_key']);
                        error_log('[402links] Service role key stored successfully');
                    }
                    
                    error_log('402links: Auto-provisioning successful! Site ID: ' . $result['site_id']);
                    update_option('402links_provisioning_success', true);
                    return;
                } elseif (isset($result['already_provisioned']) && $result['already_provisioned']) {
                    // Site was already provisioned
                    update_option('402links_site_id', $result['site_id']);
                    update_option('402links_api_key_id', $result['api_key_id']);
                    
                    // NEW: Store service role key if provided
                    if (isset($result['service_role_key'])) {
                        update_option('402links_supabase_service_key', $result['service_role_key']);
                        error_log('[402links] Service role key stored successfully (already provisioned)');
                    }
                    
                    error_log('402links: Site already provisioned: ' . $result['site_id']);
                    update_option('402links_provisioning_info', 'Site was already registered. Please contact support if you need your API key.');
                    return;
                }
            }
            
            // If not last attempt, wait before retry
            if ($attempt < $max_attempts) {
                $delay = $backoff_delays[$attempt - 1];
                error_log("402links: Attempt {$attempt} failed. Retrying in {$delay}s... Error: " . $result['error']);
                sleep($delay);
            } else {
                // All attempts failed
                error_log('402links: All provisioning attempts failed. Final error: ' . $result['error']);
                update_option('402links_provisioning_error', 'Failed after ' . $max_attempts . ' attempts. Last error: ' . $result['error']);
            }
        }
    }
    
    /**
     * Single provisioning attempt (used by retry logic)
     */
    private static function attempt_provision() {
        $settings = get_option('402links_settings', []);
        $api_endpoint = $settings['api_endpoint'] ?? 'https://api.402links.com/v1';
        
        $payload = [
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'admin_email' => get_bloginfo('admin_email'),
            'wordpress_version' => get_bloginfo('version'),
            'plugin_version' => AGENT_HUB_VERSION,
            'site_fingerprint' => self::generate_site_fingerprint()
        ];
        
        $response = wp_remote_post($api_endpoint . '/auto-provision-wordpress-site', [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($payload)
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!isset($result['success']) || !$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error'
            ];
        }
        
        // Return the full result for processing by auto_provision()
        return array_merge(['success' => true], $result);
    }
    
    /**
     * Generate a unique fingerprint for this WordPress installation
     */
    private static function generate_site_fingerprint() {
        return hash('sha256', get_site_url() . ABSPATH . (defined('AUTH_KEY') ? AUTH_KEY : ''));
    }
    
    /**
     * One-time migration: Populate missing service keys for recovered sites
     */
    public static function migrate_service_key() {
        $service_key = get_option('402links_supabase_service_key');
        $site_id = get_option('402links_site_id');
        $api_key = get_option('402links_api_key');
        
        // Only run if site is provisioned but service key is missing
        if ($site_id && $api_key && !$service_key) {
            error_log('[402links] Migration: Fetching missing service key for site ' . $site_id);
            
            $settings = get_option('402links_settings', []);
            $api_endpoint = $settings['api_endpoint'] ?? 'https://api.402links.com/v1';
            
            // Call Supabase edge function to get the service key
            $response = wp_remote_get('https://cnionwnknwnzpwfuacse.supabase.co/functions/v1/get-service-key', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 10
            ]);
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($body['service_role_key'])) {
                    update_option('402links_supabase_service_key', $body['service_role_key']);
                    error_log('[402links] Service key migrated successfully');
                } else {
                    error_log('[402links] Migration failed: No service_role_key in response');
                }
            } else {
                $error = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
                error_log('[402links] Migration failed: ' . $error);
            }
        }
    }
}
