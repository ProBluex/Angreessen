<?php
namespace AgentHub;

class WellKnown {
    /**
     * Register rewrite rules for .well-known endpoints
     */
    public static function register_rewrite_rules() {
        // x402 discovery endpoint
        add_rewrite_rule(
            '^\.well-known/402\.json$',
            'index.php?402_discovery=1',
            'top'
        );
        
        // AP2 agent-card endpoint
        add_rewrite_rule(
            '^\.well-known/agent-card\.json$',
            'index.php?agent_card=1',
            'top'
        );
    }
    
    /**
     * Register custom query vars
     */
    public static function register_query_vars($vars) {
        $vars[] = '402_discovery';
        $vars[] = 'agent_card';
        return $vars;
    }
    
    /**
     * Serve 402.json endpoint
     * x402 spec compliant discovery format
     */
    public static function serve_402_json() {
        if (get_query_var('402_discovery')) {
            DevLogger::log('WELL_KNOWN', '402_json_request', [
                'endpoint' => '/.well-known/402.json',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            
            $settings = get_option('402links_settings');
            DevLogger::log('DB', 'get_option', [
                'key' => '402links_settings',
                'found' => !empty($settings)
            ]);
            
            $site_url = get_site_url();
            
            // Get USDC contract address based on network
            $network = $settings['network'] ?? 'base';
            $usdc_address = $network === 'base' 
                ? '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'  // Base USDC
                : '0x036CbD53842c5426634e7929541eC2318f3dCF7e'; // Base Sepolia USDC
            
            // Build resources array (x402 spec format)
            $resources = [];
            $pages = self::get_all_402_pages();
            
            DevLogger::log('WELL_KNOWN', '402_pages_fetched', [
                'page_count' => count($pages),
                'network' => $network
            ]);
            
            foreach ($pages as $page) {
                $price_atomic = (int)($page['price'] * 1000000); // Convert to 6 decimal USDC
                
                $resources[] = [
                    'scheme' => 'exact',
                    'network' => $network,
                    'maxAmountRequired' => (string)$price_atomic,
                    'asset' => $usdc_address,
                    'payTo' => $settings['payment_wallet'] ?? '',
                    'resource' => $page['url'],
                    'description' => $page['title'],
                    'mimeType' => 'text/html',
                    'outputSchema' => null,
                    'maxTimeoutSeconds' => 60,
                    'extra' => [
                        'name' => 'USDC',
                        'version' => '2'
                    ]
                ];
            }
            
            $response = [
                'x402Version' => 1,
                'name' => get_bloginfo('name'),
                'description' => get_bloginfo('description') ?: 'Premium content access',
                'homepage' => $site_url,
                'discoveryEndpoint' => $site_url . '/.well-known/402.json',
                'resources' => $resources,
                'statistics' => [
                    'totalResources' => count($resources),
                    'defaultPrice' => $settings['default_price'] ?? 0.10,
                    'currency' => 'USDC'
                ]
            ];
            
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            header('X-402-Version: 1');
            header('X-402-Resources: ' . count($resources));
            
            DevLogger::log('WELL_KNOWN', '402_json_served', [
                'total_resources' => count($resources),
                'network' => $network,
                'payment_wallet' => substr($settings['payment_wallet'] ?? '', 0, 10) . '...'
            ]);
            
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    
    /**
     * Get all pages with 402 protection
     */
    private static function get_all_402_pages() {
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'meta_query' => [
                [
                    'key' => '_402links_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        $pages = [];
        $settings = get_option('402links_settings');
        
        foreach ($posts as $post) {
            $price = get_post_meta($post->ID, '_402links_price', true);
            if (empty($price)) {
                $price = $settings['default_price'] ?? 0.10;
            }
            
            $pages[] = [
                'url' => get_permalink($post->ID),
                'title' => get_the_title($post->ID),
                'price' => floatval($price),
                'type' => $post->post_type,
                'modified' => $post->post_modified
            ];
        }
        
        return $pages;
    }
    
    /**
     * Serve agent-card.json endpoint for AP2 discovery
     */
    public static function serve_agent_card() {
        if (get_query_var('agent_card')) {
            DevLogger::log('WELL_KNOWN', 'agent_card_request', [
                'endpoint' => '/.well-known/agent-card.json',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
            
            $settings = get_option('402links_settings');
            $site_url = get_site_url();
            
            // Call Supabase edge function to generate agent-card
            $supabase_url = defined('SUPABASE_URL') ? SUPABASE_URL : '';
            $api_endpoint = $supabase_url . '/functions/v1/generate-agent-card';
            
            DevLogger::log('EDGE_FUNCTION', 'edge_function_call', [
                'function' => 'generate-agent-card',
                'endpoint' => $api_endpoint,
                'site_url' => $site_url
            ]);
            
            $args = [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'timeout' => 15
            ];
            
            // Try to fetch from edge function
            $response = wp_remote_get($api_endpoint . '?site_url=' . urlencode($site_url), $args);
            
            if (is_wp_error($response)) {
                DevLogger::log('ERROR', 'edge_function_error', [
                    'function' => 'generate-agent-card',
                    'error' => $response->get_error_message()
                ]);
                // Fallback: Generate basic agent-card locally
                $agent_card = self::generate_fallback_agent_card();
            } else {
                $body = wp_remote_retrieve_body($response);
                $agent_card = json_decode($body, true);
                
                // If edge function failed, use fallback
                if (empty($agent_card) || isset($agent_card['error'])) {
                    DevLogger::log('WELL_KNOWN', 'edge_function_fallback', [
                        'reason' => 'empty_or_error_response',
                        'error' => $agent_card['error'] ?? 'empty'
                    ]);
                    $agent_card = self::generate_fallback_agent_card();
                } else {
                    DevLogger::log('WELL_KNOWN', 'edge_function_success', [
                        'function' => 'generate-agent-card',
                        'offers_count' => count($agent_card['offers'] ?? [])
                    ]);
                }
            }
            
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            
            DevLogger::log('WELL_KNOWN', 'agent_card_served', [
                'source' => isset($body) && !empty($agent_card) ? 'edge_function' : 'fallback',
                'offers_count' => count($agent_card['offers'] ?? [])
            ]);
            
            echo json_encode($agent_card, JSON_PRETTY_PRINT);
            exit;
        }
    }
    
    /**
     * Generate fallback agent-card when edge function is unavailable
     */
    private static function generate_fallback_agent_card() {
        $settings = get_option('402links_settings');
        $site_url = get_site_url();
        
        return [
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description') ?: 'Access premium content',
            'site_url' => $site_url,
            'offers' => [
                [
                    'name' => 'Content Access',
                    'price' => (string)($settings['default_price'] ?? '0.001'),
                    'currency' => 'USDC',
                    'network' => 'base',
                    'endpoint' => $site_url,
                    'payment_methods' => ['x402']
                ]
            ]
        ];
    }
    
    /**
     * Inject AI agent directives into robots.txt
     */
    public static function inject_robots_txt($output, $public) {
        if ($public) {
            DevLogger::log('WELL_KNOWN', 'robots_txt_injection_start', [
                'public' => $public,
                'site_url' => get_site_url()
            ]);
            
            $settings = get_option('402links_settings');
            DevLogger::log('DB', 'get_option', [
                'key' => '402links_settings',
                'found' => !empty($settings)
            ]);
            
            $site_url = get_site_url();
            
            $output .= "\n# AI Agent Payment Protocol\n";
            $output .= "User-agent: GPTBot\n";
            $output .= "User-agent: ClaudeBot\n";
            $output .= "User-agent: ChatGPT-User\n";
            $output .= "User-agent: Perplexitybot\n";
            $output .= "User-agent: cohere-ai\n";
            $output .= "User-agent: Bytespider\n";
            $output .= "User-agent: Google-Extended\n";
            $output .= "User-agent: Applebot-Extended\n";
            $output .= "User-agent: CCBot\n";
            $output .= "User-agent: anthropic-ai\n";
            $output .= "\n";
            $output .= "# x402 Protocol Discovery\n";
            $output .= "X-402-Discovery: {$site_url}/.well-known/402.json\n";
            $output .= "X-402-Payment-Required: true\n";
            $output .= "X-402-Version: 1\n";
            $output .= "X-402-Default-Price: " . ($settings['default_price'] ?? '0.10') . "\n";
            $output .= "X-402-Currency: USDC\n";
            $output .= "X-402-Network: " . ($settings['network'] ?? 'base') . "\n";
            $output .= "\n";
            $output .= "# AP2 Agent Discovery\n";
            $output .= "X-Agent-Card: {$site_url}/.well-known/agent-card.json\n";
            
            DevLogger::log('WELL_KNOWN', 'robots_txt_injected', [
                'directives_added' => true,
                'default_price' => $settings['default_price'] ?? '0.10',
                'network' => $settings['network'] ?? 'base'
            ]);
        }
        
        return $output;
    }
}
