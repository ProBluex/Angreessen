<?php
namespace AgentHub;

class AgentDetector {
    // Cache for bot registry (loaded from Supabase via API)
    private static $bot_registry_cache = null;
    private static $cache_duration = 3600; // 1 hour cache
    
    // Fallback patterns if registry unavailable
    private static $fallback_patterns = [
        // AI/Data Agents (Priority)
        'GPTBot', 'ChatGPT-User', 'OpenAI', 'ClaudeBot', 'Claude-Web', 'anthropic-ai',
        'Perplexitybot', 'PerplexityBot', 'amazonbot', 'CCBot', 'Bytespider', 'SerpApi',
        'Google-Extended', 'Applebot-Extended', 'cohere-ai', 'YouBot',
        
        // Social/Link Preview Crawlers
        'facebookexternalhit', 'Facebot', 'Twitterbot', 'LinkedInBot', 'Slackbot',
        'Discordbot', 'embedly', 'redditbot', 'Quora Link Preview',
        
        // Search Engines (Extended Crawlers)
        'Googlebot', 'AdsBot-Google', 'bingbot', 'DuckDuckBot', 'Baiduspider',
        'YandexBot', 'Applebot',
        
        // Test Agent (for our tests)
        'x402-agent-crawler'
    ];
    
    /**
     * Get bot details from bot_registry via API
     * 
     * @param string $user_agent
     * @return array|null Bot details or null if not found
     */
    public static function get_bot_from_registry($user_agent) {
        if (empty($user_agent)) {
            return null;
        }
        
        // Load registry from cache or API
        $registry = self::load_bot_registry();
        
        if (empty($registry)) {
            return null;
        }
        
        // Match user agent against all bot patterns
        foreach ($registry as $bot) {
            if (empty($bot['user_agent_patterns'])) {
                continue;
            }
            
            foreach ($bot['user_agent_patterns'] as $pattern) {
                if (stripos($user_agent, $pattern) !== false) {
                    return $bot;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Load bot registry from cache or API
     * 
     * @return array Bot registry data
     */
    private static function load_bot_registry() {
        // Check memory cache
        if (self::$bot_registry_cache !== null) {
            return self::$bot_registry_cache;
        }
        
        // Check transient cache
        $cached = get_transient('402links_bot_registry');
        if ($cached !== false) {
            self::$bot_registry_cache = $cached;
            return $cached;
        }
        
        // Track bot registry load time for monitoring
        $start_time = microtime(true);
        
        // Fetch from API
        $api = new API();
        $registry = $api->get_bot_registry();
        
        if (!empty($registry)) {
            $elapsed = microtime(true) - $start_time;
            error_log(sprintf('402links: Bot registry loaded in %.2f seconds (%d bots)', $elapsed, count($registry)));
            
            // Cache for 1 hour
            set_transient('402links_bot_registry', $registry, self::$cache_duration);
            self::$bot_registry_cache = $registry;
            return $registry;
        }
        
        error_log('402links: Bot registry API failed or returned empty data');
        return [];
    }
    
    /**
     * Check if the user agent is an AI agent
     * Enhanced to use bot_registry
     * 
     * @param string $user_agent
     * @return array ['is_agent' => bool, 'agent_name' => string|null, 'bot_id' => string|null, 'category' => string|null, 'company' => string|null]
     */
    public static function is_ai_agent($user_agent) {
        if (empty($user_agent)) {
            return [
                'is_agent' => false,
                'agent_name' => null,
                'bot_id' => null,
                'category' => null,
                'company' => null
            ];
        }
        
        // PRIORITY: Detect major AI agents immediately (before API call)
        $ua_lower = strtolower($user_agent);
        $priority_agents = [
            'claude' => ['anthropic', 'claude'],
            'ChatGPT' => ['gptbot', 'chatgpt-user', 'openai'],
            'Perplexity' => ['perplexitybot'],
            'Google AI' => ['google-extended', 'googlebot-news'],
            'Bing AI' => ['bingbot'],
        ];
        
        foreach ($priority_agents as $name => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($ua_lower, $pattern) !== false) {
                    error_log('402links: Major AI agent detected immediately: ' . $name);
                    return [
                        'is_agent' => true,
                        'agent_name' => $name,
                        'bot_id' => null,
                        'category' => 'ai_agent',
                        'company' => null,
                        'detection_method' => 'priority_pattern'
                    ];
                }
            }
        }
        
        // Try registry next (after priority detection)
        $bot = self::get_bot_from_registry($user_agent);
        if ($bot !== null) {
            return [
                'is_agent' => true,
                'agent_name' => $bot['bot_name'] ?? 'Unknown Bot',
                'bot_id' => $bot['id'] ?? null,
                'category' => $bot['bot_category'] ?? null,
                'company' => $bot['company'] ?? null
            ];
        }
        
        // Fallback to pattern matching
        $ua_lower = strtolower($user_agent);
        foreach (self::$fallback_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                return [
                    'is_agent' => true,
                    'agent_name' => $pattern,
                    'bot_id' => null,
                    'category' => 'ai_crawler',
                    'company' => null
                ];
            }
        }
        
        // Generic bot detection
        $generic_keywords = ['bot', 'crawler', 'spider', 'scraper'];
        foreach ($generic_keywords as $keyword) {
            if (strpos($ua_lower, $keyword) !== false) {
                return [
                    'is_agent' => true,
                    'agent_name' => 'Generic Agent',
                    'bot_id' => null,
                    'category' => 'unknown',
                    'company' => null
                ];
            }
        }
        
        return [
            'is_agent' => false,
            'agent_name' => null,
            'bot_id' => null,
            'category' => null,
            'company' => null
        ];
    }
    
    /**
     * Check if bot respects robots.txt for given path
     * 
     * @param string $user_agent
     * @param string $request_path
     * @return array|null Violation details or null if compliant
     */
    public static function check_robots_txt_compliance($user_agent, $request_path) {
        $bot = self::get_bot_from_registry($user_agent);
        
        if (!$bot || empty($bot['robots_txt_user_agent'])) {
            return null; // Can't check compliance without robots.txt user agent
        }
        
        // Get robots.txt rules for this bot
        $robots_rules = self::get_robots_txt_rules($bot['robots_txt_user_agent']);
        
        // Check if current path is disallowed
        foreach ($robots_rules['disallow'] as $disallowed_path) {
            if (strpos($request_path, $disallowed_path) === 0) {
                return [
                    'violation_type' => 'robots_txt',
                    'robots_txt_directive' => 'Disallow: ' . $disallowed_path,
                    'expected_behavior' => 'Bot should not access this path',
                    'actual_behavior' => 'Bot accessed disallowed path',
                    'respected_robots_txt' => false
                ];
            }
        }
        
        return null; // No violation
    }
    
    /**
     * Parse robots.txt rules for specific user agent
     * 
     * @param string $user_agent
     * @return array ['disallow' => array, 'allow' => array]
     */
    private static function get_robots_txt_rules($user_agent) {
        // Get WordPress robots.txt content
        $robots_content = self::get_wordpress_robots_txt();
        
        $rules = ['disallow' => [], 'allow' => []];
        $current_agent = null;
        $lines = explode("\n", $robots_content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (stripos($line, 'User-agent:') === 0) {
                $agent = trim(substr($line, 11));
                $current_agent = ($agent === '*' || stripos($agent, $user_agent) !== false) ? $agent : null;
            } elseif ($current_agent && stripos($line, 'Disallow:') === 0) {
                $path = trim(substr($line, 9));
                if (!empty($path)) {
                    $rules['disallow'][] = $path;
                }
            } elseif ($current_agent && stripos($line, 'Allow:') === 0) {
                $path = trim(substr($line, 6));
                if (!empty($path)) {
                    $rules['allow'][] = $path;
                }
            }
        }
        
        return $rules;
    }
    
    /**
     * Get WordPress robots.txt content
     * 
     * @return string
     */
    private static function get_wordpress_robots_txt() {
        // Generate robots.txt using WordPress function
        ob_start();
        do_action('do_robots');
        $robots_content = ob_get_clean();
        
        return $robots_content;
    }
    
    /**
     * Check if agent is blacklisted
     * 
     * @param string $user_agent
     * @param string $site_id
     * @return bool
     */
    public static function is_blacklisted($user_agent, $site_id = null) {
        $api = new API();
        $result = $api->check_blacklist($user_agent, $site_id);
        
        return $result['is_blacklisted'] ?? false;
    }
    
    /**
     * Log agent crawl attempt
     * 
     * @param int $post_id
     * @param array $agent_info
     * @param string $payment_status
     * @param array $violation_data Optional violation data
     */
    public static function log_crawl($post_id, $agent_info, $payment_status = 'unpaid', $violation_data = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . '402links_agent_logs';
        
        $data = [
            'post_id' => $post_id,
            'agent_name' => $agent_info['agent_name'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => self::get_client_ip(),
            'payment_status' => $payment_status,
            'created_at' => current_time('mysql')
        ];
        
        // Add violation data if present
        if ($violation_data) {
            $data['robots_txt_directive'] = $violation_data['robots_txt_directive'] ?? null;
            $data['violation_type'] = $violation_data['violation_type'] ?? null;
            $data['expected_behavior'] = $violation_data['expected_behavior'] ?? null;
            $data['actual_behavior'] = $violation_data['actual_behavior'] ?? null;
            
            // Report violation to backend
            self::report_violation_to_backend($post_id, $agent_info, $violation_data);
        }
        
        $wpdb->insert($table_name, $data, ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }
    
    /**
     * Report violation to 402links backend
     * 
     * @param int $post_id WordPress post ID
     * @param array $agent_info Agent detection info
     * @param array $violation_data Violation details
     */
    private static function report_violation_to_backend($post_id, $agent_info, $violation_data) {
        $api = new API();
        
        $payload = [
            'wordpress_post_id' => $post_id,
            'agent_name' => $agent_info['agent_name'] ?? 'Unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => self::get_client_ip(),
            'requested_url' => $_SERVER['REQUEST_URI'] ?? '',
            'violation_type' => $violation_data['violation_type'] ?? 'unknown',
            'robots_txt_directive' => $violation_data['robots_txt_directive'] ?? null
        ];
        
        $result = $api->report_violation($payload);
        
        if (!$result['success']) {
            error_log('402links: Failed to report violation: ' . ($result['error'] ?? 'Unknown error'));
        } else {
            error_log('402links: Violation reported successfully (ID: ' . ($result['violation_id'] ?? 'unknown') . ')');
        }
    }
    
    /**
     * Get client IP address
     * 
     * @return string
     */
    public static function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
