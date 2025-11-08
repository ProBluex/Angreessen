<?php
namespace AgentHub;

class Installer {
    /**
     * Plugin activation hook - auto-provision the site
     */
    public static function activate() {
        DevLogger::log('INSTALL', 'activation_started', [
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name')
        ]);
        
        // Check if already provisioned
        $existing_key = get_option('402links_api_key');
        $existing_site_id = get_option('402links_site_id');
        
        if ($existing_key && $existing_site_id) {
            error_log('402links: Already provisioned, skipping auto-provision');
            DevLogger::log('INSTALL', 'already_provisioned', [
                'site_id' => $existing_site_id,
                'has_api_key' => !empty($existing_key)
            ]);
            return;
        }
        
        // Auto-provision the site
        DevLogger::log('INSTALL', 'starting_auto_provision', null);
        self::auto_provision();
    }
    
    /**
     * Automatically provision the WordPress site with 402links (with retry logic)
     */
    private static function auto_provision() {
        $max_attempts = 3;
        $backoff_delays = [1, 2, 4]; // seconds
        
        DevLogger::log('PROVISION', 'auto_provision_started', [
            'max_attempts' => $max_attempts
        ]);
        
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            error_log("402links: Auto-provision attempt {$attempt}/{$max_attempts}...");
            DevLogger::log('PROVISION', 'attempt', [
                'attempt' => $attempt,
                'max_attempts' => $max_attempts
            ]);
            
            $result = self::attempt_provision();
            
            if ($result['success']) {
                // Store credentials on success
                if (isset($result['api_key'])) {
                    update_option('402links_api_key', $result['api_key']);
                    update_option('402links_api_key_id', $result['api_key_id']);
                    update_option('402links_site_id', $result['site_id']);
                    update_option('402links_provisioned_url', get_site_url());
                    
                    error_log('402links: Auto-provisioning successful! Site ID: ' . $result['site_id']);
                    DevLogger::log('PROVISION', 'success', [
                        'site_id' => $result['site_id'],
                        'api_key_id' => $result['api_key_id'],
                        'api_key_length' => strlen($result['api_key'])
                    ]);
                    update_option('402links_provisioning_success', true);
                    return;
                } elseif (isset($result['already_provisioned']) && $result['already_provisioned']) {
                    // Site was already provisioned
                    update_option('402links_site_id', $result['site_id']);
                    update_option('402links_api_key_id', $result['api_key_id']);
                    
                    error_log('402links: Site already provisioned: ' . $result['site_id']);
                    DevLogger::log('PROVISION', 'already_provisioned', [
                        'site_id' => $result['site_id'],
                        'api_key_id' => $result['api_key_id']
                    ]);
                    update_option('402links_provisioning_info', 'Site was already registered. Please contact support if you need your API key.');
                    return;
                }
            }
            
            // If not last attempt, wait before retry
            if ($attempt < $max_attempts) {
                $delay = $backoff_delays[$attempt - 1];
                error_log("402links: Attempt {$attempt} failed. Retrying in {$delay}s... Error: " . $result['error']);
                DevLogger::log('PROVISION', 'retry_after_failure', [
                    'attempt' => $attempt,
                    'error' => $result['error'],
                    'retry_delay' => $delay
                ]);
                sleep($delay);
            } else {
                // All attempts failed
                error_log('402links: All provisioning attempts failed. Final error: ' . $result['error']);
                DevLogger::log('ERROR', 'provision_failed_all_attempts', [
                    'attempts' => $max_attempts,
                    'final_error' => $result['error']
                ]);
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
        
        DevLogger::log('PROVISION', 'api_request_started', [
            'endpoint' => $api_endpoint . '/auto-provision-wordpress-site',
            'payload' => $payload
        ]);
        
        $response = wp_remote_post($api_endpoint . '/auto-provision-wordpress-site', [
            'method' => 'POST',
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($payload)
        ]);
        
        if (is_wp_error($response)) {
            DevLogger::log('ERROR', 'provision_api_error', [
                'error' => $response->get_error_message()
            ]);
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        DevLogger::log('PROVISION', 'api_response_received', [
            'status_code' => wp_remote_retrieve_response_code($response),
            'response' => $result
        ]);
        
        if (!isset($result['success']) || !$result['success']) {
            DevLogger::log('ERROR', 'provision_api_failed', [
                'error' => $result['error'] ?? 'Unknown error',
                'response' => $result
            ]);
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
}
