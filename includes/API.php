<?php
namespace AgentHub;

class API {
    private $api_key;
    private $api_endpoint;
    
    public function __construct() {
        $this->api_key = get_option('402links_api_key');
        $settings = get_option('402links_settings');
        $this->api_endpoint = $settings['api_endpoint'] ?? 'https://api.402links.com/v1';
    }
    
    // Public getters for parallel requests
    public function get_api_endpoint() {
        return $this->api_endpoint;
    }
    
    public function get_api_key() {
        return $this->api_key;
    }
    
    /**
     * Record agent visit to database (if agent doesn't exist, create it)
     * 
     * @param int $post_id WordPress post ID
     * @param string $agent_name Agent name
     * @param string $user_agent Full user agent string
     * @return void
     */
    public function record_agent_visit($post_id, $agent_name, $user_agent) {
        if (!$this->api_endpoint) {
            error_log('402links: Cannot record agent visit - API endpoint not configured');
            return;
        }

        $endpoint = $this->api_endpoint . '/record-agent-visit';
        
        $payload = [
            'wordpress_post_id' => (int)$post_id,
            'site_url' => get_site_url(),
            'agent_name' => $agent_name,
            'user_agent' => $user_agent,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'page_url' => get_permalink($post_id)
        ];

        wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($payload),
            'timeout' => 5,
            'blocking' => false // Fire-and-forget
        ]);
    }
    
    /**
     * Verify if agent has already paid for content (within 24h cache)
     */
    public function verify_agent_payment($site_id, $wordpress_post_id, $page_url, $user_agent, $ip_address) {
        if (!$this->api_endpoint || !$this->api_key) {
            error_log('402links: Cannot verify agent payment - API credentials missing');
            return null;
        }

        $endpoint = $this->api_endpoint . '/verify-agent-payment';
        
        $payload = [
            'site_id' => $site_id,
            'wordpress_post_id' => (int)$wordpress_post_id,
            'page_url' => $page_url,
            'user_agent' => $user_agent,
            'ip_address' => $ip_address
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($payload),
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            error_log('402links: verify-agent-payment request failed: ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['payment_verified']) && $body['payment_verified'] === true) {
            error_log('402links: Agent payment verified (cached within 24h) - crawl_id: ' . ($body['crawl_id'] ?? 'unknown'));
            return $body;
        }

        return null;
    }
    
    /**
     * Register WordPress site with 402links backend
     */
    public function register_site() {
        // Get the stored API key ID from the api_keys table lookup
        $api_key_id = $this->get_api_key_id();
        
        $payload = [
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'admin_email' => get_bloginfo('admin_email'),
            'wordpress_version' => get_bloginfo('version'),
            'plugin_version' => AGENT_HUB_VERSION
        ];
        
        // Only include api_key_id if we have one
        if ($api_key_id) {
            $payload['api_key_id'] = $api_key_id;
        }
        
        $result = $this->request('POST', '/register-wordpress-site', $payload);
        
        // Handle specific API key reuse error
        if (!$result['success'] && isset($result['error']) && strpos($result['error'], 'already being used') !== false) {
            error_log('402links: API key is already in use by another site');
            return [
                'success' => false,
                'error' => $result['error'],
                'error_code' => 'API_KEY_IN_USE'
            ];
        }
        
        return $result;
    }
    
    /**
     * Sync site settings to Supabase registered_sites table
     * Now uses edge function instead of direct REST API call
     */
    public function sync_site_settings($settings) {
        $site_id = get_option('402links_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        error_log('🟦 [API] === SYNC SITE SETTINGS (via edge function) ===');
        error_log('🟦 [API] Site ID: ' . $site_id);
        error_log('🟦 [API] Settings: ' . json_encode($settings));
        
        $payload = ['site_id' => $site_id];
        if (isset($settings['default_price'])) {
            $payload['default_price'] = floatval($settings['default_price']);
        }
        if (isset($settings['payment_wallet'])) {
            $payload['payment_wallet'] = $settings['payment_wallet'];
        }
        
        return $this->request('POST', '/sync-wordpress-site-settings', $payload);
    }
    
    /**
     * Check if site has existing paid links
     * Now uses edge function instead of direct REST API call
     */
    public function check_existing_links_count() {
        $site_id = get_option('402links_site_id');
        
        if (!$site_id) {
            return ['count' => 0];
        }
        
        error_log('🟦 [API] === CHECK LINKS COUNT (via edge function) ===');
        error_log('🟦 [API] Site ID: ' . $site_id);
        
        $result = $this->request('GET', '/check-wordpress-links-count?site_id=' . $site_id);
        
        if (!$result['success']) {
            error_log('🔴 [API] Failed to count links: ' . ($result['error'] ?? 'Unknown error'));
            return ['count' => 0];
        }
        
        return [
            'count' => $result['count'] ?? 0
        ];
    }
    
    /**
     * Get the API key ID from the api_keys table
     * This queries the backend using the API key to find its ID
     */
    private function get_api_key_id() {
        if (!$this->api_key) {
            return null;
        }
        
        // Make authenticated request - the API key middleware will look it up
        $response = $this->request('GET', '/get-api-key-id');
        
        if ($response['success'] && isset($response['api_key_id'])) {
            return $response['api_key_id'];
        }
        
        return null;
    }
    
    /**
     * Create a 402link for a WordPress post
     */
    public function create_link($post_id) {
        $post = get_post($post_id);
        $settings = get_option('402links_settings');
        
        $price = get_post_meta($post_id, '_402links_price', true);
        if (empty($price)) {
            $price = $settings['default_price'] ?? 0.10;
        }
        
        // Get post excerpt
        $excerpt = $post->post_excerpt;
        if (empty($excerpt)) {
            $excerpt = wp_trim_words(strip_tags($post->post_content), 30);
        }
        
        // Get author information
        $author = get_the_author_meta('display_name', $post->post_author);
        
        // Get featured image
        $featured_image_url = get_the_post_thumbnail_url($post_id, 'large');
        
        // Calculate word count
        $word_count = str_word_count(strip_tags($post->post_content));
        
        // Get tags
        $tags = [];
        $post_tags = get_the_tags($post_id);
        if ($post_tags && !is_wp_error($post_tags)) {
            foreach ($post_tags as $tag) {
                $tags[] = $tag->name;
            }
        }
        
        // Get categories for description
        $categories = get_the_category($post_id);
        $category_names = [];
        if ($categories) {
            foreach ($categories as $category) {
                $category_names[] = $category->name;
            }
        }
        
        // Convert post content to agent-readable JSON format
        $json_content = [
            'version' => '1.0',
            'content_type' => 'blog_post',
            'title' => Helpers::get_clean_title($post_id),
            'body' => wp_strip_all_tags($post->post_content), // Strip HTML for clean text
            'excerpt' => $excerpt,
            'author' => $author,
            'published_at' => $post->post_date,
            'modified_at' => $post->post_modified,
            'word_count' => $word_count,
            'categories' => $category_names,
            'tags' => $tags,
            'featured_image_url' => $featured_image_url ?: null
        ];
        
        $payload = [
            'post_id' => $post_id,
            'title' => Helpers::get_clean_title($post_id),
            'url' => get_permalink($post_id),
            'price' => floatval($price),
            'site_url' => get_site_url(),
            'content_type' => $post->post_type,
            'published_at' => $post->post_date,
            'excerpt' => $excerpt,
            'author' => $author,
            'featured_image_url' => $featured_image_url ?: null,
            'word_count' => $word_count,
            'tags' => $tags,
            'description' => !empty($category_names) ? 'Filed under: ' . implode(', ', $category_names) : '',
            'json_content' => $json_content  // NEW: Full content in JSON format
        ];
        
        error_log('402links: Creating link for post ' . $post_id . ' with payload: ' . json_encode($payload));
        
        return $this->request('POST', '/create-wordpress-link', $payload);
    }
    
    /**
     * Create multiple links in parallel using wp_remote_request()
     * 
     * @param array $post_ids Array of WordPress post IDs
     * @return array Results with created/failed counts
     */
    public function create_links_parallel($post_ids) {
        if (empty($post_ids)) {
            return ['total' => 0, 'created' => 0, 'failed' => 0, 'errors' => []];
        }
        
        $results = [
            'total' => count($post_ids),
            'created' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $settings = get_option('402links_settings');
        $curl_handles = [];
        $post_map = []; // Map curl handle resource ID to post_id
        
        // Initialize cURL multi-handle
        $multi_handle = curl_multi_init();
        
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                $results['failed']++;
                continue;
            }
            
            $price = get_post_meta($post_id, '_402links_price', true);
            if (empty($price)) {
                $price = $settings['default_price'] ?? 0.10;
            }
            
            $excerpt = $post->post_excerpt;
            if (empty($excerpt)) {
                $excerpt = wp_trim_words(strip_tags($post->post_content), 30);
            }
            
            $author = get_the_author_meta('display_name', $post->post_author);
            $featured_image_url = get_the_post_thumbnail_url($post_id, 'large');
            $word_count = str_word_count(strip_tags($post->post_content));
            
            $tags = [];
            $post_tags = get_the_tags($post_id);
            if ($post_tags && !is_wp_error($post_tags)) {
                foreach ($post_tags as $tag) {
                    $tags[] = $tag->name;
                }
            }
            
            $categories = get_the_category($post_id);
            $category_names = [];
            if ($categories) {
                foreach ($categories as $category) {
                    $category_names[] = $category->name;
                }
            }
            
            $json_content = [
                'version' => '1.0',
                'content_type' => 'blog_post',
                'title' => Helpers::get_clean_title($post_id),
                'body' => wp_strip_all_tags($post->post_content),
                'excerpt' => $excerpt,
                'author' => $author,
                'published_at' => $post->post_date,
                'modified_at' => $post->post_modified,
                'word_count' => $word_count,
                'categories' => $category_names,
                'tags' => $tags,
                'featured_image_url' => $featured_image_url ?: null
            ];
            
            $payload = [
                'post_id' => $post_id,
                'title' => Helpers::get_clean_title($post_id),
                'url' => get_permalink($post_id),
                'price' => floatval($price),
                'site_url' => get_site_url(),
                'content_type' => $post->post_type,
                'published_at' => $post->post_date,
                'excerpt' => $excerpt,
                'author' => $author,
                'featured_image_url' => $featured_image_url ?: null,
                'word_count' => $word_count,
                'tags' => $tags,
                'description' => !empty($category_names) ? 'Filed under: ' . implode(', ', $category_names) : '',
                'json_content' => $json_content
            ];
            
            // Create individual cURL handle for true parallel execution
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_endpoint . '/create-wordpress-link',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->api_key,
                    'Content-Type: application/json'
                ]
            ]);
            
            // Add to multi-handle for parallel execution
            curl_multi_add_handle($multi_handle, $ch);
            
            // Store handle reference (use resource ID as key)
            $curl_handles[] = $ch;
            $post_map[(int)$ch] = $post_id;
        }
        
        // Execute all requests in parallel
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);
        
        // Collect and process all responses
        foreach ($curl_handles as $ch) {
            $post_id = $post_map[(int)$ch];
            $response = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            
            // Comprehensive logging for diagnostics
            error_log("🔍 [Parallel Link Creation] Post {$post_id}:");
            error_log("  - HTTP Code: {$http_code}");
            error_log("  - cURL Error: " . ($curl_error ?: 'none'));
            error_log("  - Response (first 200 chars): " . substr($response, 0, 200));
            
            if ($curl_error) {
                $results['failed']++;
                $results['errors'][] = "Post {$post_id}: cURL error - {$curl_error}";
            } elseif ($http_code !== 200) {
                $results['failed']++;
                $results['errors'][] = "Post {$post_id}: HTTP {$http_code}";
                error_log("  ❌ Failed: HTTP {$http_code}");
            } else {
                $data = json_decode($response, true);
                
                // Log parsed data structure
                error_log("  - Parsed data keys: " . implode(', ', array_keys($data ?: [])));
                error_log("  - Has 'success' key: " . (isset($data['success']) ? 'yes' : 'no'));
                error_log("  - Has 'data' key: " . (isset($data['data']) ? 'yes' : 'no'));
                
                // Handle nested response structure: {success: true, data: {link_id, short_id, link_url}}
                $link_data = $data['data'] ?? $data;
                
                error_log("  - Extracted link_data keys: " . implode(', ', array_keys($link_data ?: [])));
                error_log("  - link_id present: " . (isset($link_data['link_id']) ? 'yes' : 'no'));
                error_log("  - short_id present: " . (isset($link_data['short_id']) ? 'yes' : 'no'));
                error_log("  - link_url present: " . (isset($link_data['link_url']) ? 'yes' : 'no'));
                
                if (($data['success'] ?? false) && isset($link_data['link_id'])) {
                    $link_id = $link_data['link_id'];
                    $short_id = $link_data['short_id'] ?? '';
                    $link_url = $link_data['link_url'] ?? '';
                    
                    error_log("  ✅ Success - Updating post meta:");
                    error_log("    - link_id: {$link_id}");
                    error_log("    - short_id: {$short_id}");
                    error_log("    - link_url: {$link_url}");
                    
                    update_post_meta($post_id, '_402links_id', $link_id);
                    update_post_meta($post_id, '_402links_short_id', $short_id);
                    update_post_meta($post_id, '_402links_url', $link_url);
                    
                    // Verify meta was actually saved
                    $saved_url = get_post_meta($post_id, '_402links_url', true);
                    error_log("    - Verified saved URL: {$saved_url}");
                    
                    $results['created']++;
                } else {
                    $results['failed']++;
                    $error_msg = $data['error'] ?? $link_data['error'] ?? 'Unknown error - link_id missing';
                    $results['errors'][] = "Post {$post_id}: {$error_msg}";
                    error_log("  ❌ Failed: {$error_msg}");
                }
            }
            
            // Clean up individual handle
            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }
        
        // Close multi-handle
        curl_multi_close($multi_handle);
        
        return $results;
    }
    
    /**
     * Update existing 402link
     */
    public function update_link($post_id, $link_id) {
        $post = get_post($post_id);
        $settings = get_option('402links_settings');
        
        $price = get_post_meta($post_id, '_402links_price', true);
        if (empty($price)) {
            $price = $settings['default_price'] ?? 0.10;
        }
        
        // Get post metadata
        $excerpt = $post->post_excerpt;
        if (empty($excerpt)) {
            $excerpt = wp_trim_words(strip_tags($post->post_content), 30);
        }
        
        $author = get_the_author_meta('display_name', $post->post_author);
        $featured_image_url = get_the_post_thumbnail_url($post_id, 'large');
        $word_count = str_word_count(strip_tags($post->post_content));
        
        // Get tags
        $tags = [];
        $post_tags = get_the_tags($post_id);
        if ($post_tags && !is_wp_error($post_tags)) {
            foreach ($post_tags as $tag) {
                $tags[] = $tag->name;
            }
        }
        
        // Get categories
        $categories = get_the_category($post_id);
        $category_names = [];
        if ($categories) {
            foreach ($categories as $category) {
                $category_names[] = $category->name;
            }
        }
        
        // Convert post content to agent-readable JSON format
        $json_content = [
            'version' => '1.0',
            'content_type' => 'blog_post',
            'title' => Helpers::get_clean_title($post_id),
            'body' => wp_strip_all_tags($post->post_content),
            'excerpt' => $excerpt,
            'author' => $author,
            'published_at' => $post->post_date,
            'modified_at' => $post->post_modified,
            'word_count' => $word_count,
            'categories' => $category_names,
            'tags' => $tags,
            'featured_image_url' => $featured_image_url ?: null
        ];
        
        return $this->request('PUT', '/update-wordpress-link', [
            'site_url' => get_site_url(),
            'link_id' => $link_id,
            'post_id' => $post_id,
            'title' => Helpers::get_clean_title($post_id),
            'url' => get_permalink($post_id),
            'price' => floatval($price),
            'excerpt' => $excerpt,
            'author' => $author,
            'featured_image_url' => $featured_image_url ?: null,
            'word_count' => $word_count,
            'tags' => $tags,
            'modified_at' => $post->post_modified,
            'json_content' => $json_content  // NEW: Full content in JSON format
        ]);
    }
    
    /**
     * Normalize timeframe/period values to canonical format
     * Protects against frontend variations (7d vs 1w vs week)
     */
    private function normalize_period($value) {
        $value = strtolower(trim($value ?: '30d'));
        
        // Map variations → canonical values for edge functions
        if (in_array($value, ['7d', '1w', 'week'])) return 'week';
        if (in_array($value, ['30d', '1m', 'month'])) return '30d';
        if (in_array($value, ['90d', '3m', 'quarter'])) return '90d';
        if (in_array($value, ['365d', '1y', 'year', 'all'])) return 'all';
        
        return '30d'; // safe default
    }
    
    /**
     * Get site-specific analytics (agent_crawls + agent_payments)
     * Used for: Overview tab cards
     */
    public function get_site_analytics($period = '30d') {
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            error_log('[API.php] ⚠️ get_site_analytics() - No site_id found');
            return ['success' => false, 'error' => 'Site not registered'];
        }
        
        $period = $this->normalize_period($period);
        error_log('[API.php] 📊 get_site_analytics() - site_id: ' . $site_id . ', period: ' . $period);
        
        $result = $this->request('GET', '/get-site-analytics', [
            'site_id' => $site_id,
            'period'  => $period
        ]);
        
        error_log('[API.php] 📊 get_site_analytics() result: ' . json_encode([
            'success' => $result['success'] ?? false,
            'has_data' => isset($result['data'])
        ]));
        
        return $result;
    }
    
    /**
     * Get WordPress site analytics (agent + human payments combined)
     * Used for: Analytics tab lower fold (Top Performing Content)
     */
    public function get_wordpress_analytics($timeframe = '30d') {
        error_log('[API.php] 📊 get_wordpress_analytics() called with timeframe: ' . $timeframe);
        
        $result = $this->request('POST', '/wordpress-analytics', [
            'site_url'  => get_site_url(),
            'timeframe' => $timeframe ?: '30d'
        ]);
        
        error_log('[API.php] 📊 get_wordpress_analytics() result: ' . json_encode([
            'success' => $result['success'] ?? false,
            'has_data' => isset($result['data'])
        ]));
        
        return $result;
    }
    
    /**
     * @deprecated Use get_site_analytics() or get_wordpress_analytics() instead
     * Kept for backward compatibility - redirects to ecosystem stats
     */
    public function get_analytics($timeframe = '30d') {
        // Log caller context for debugging
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $backtrace[1]['function'] ?? 'unknown';
        $class = $backtrace[1]['class'] ?? '';
        $caller_info = $class ? "$class::$caller" : $caller;
        
        error_log('[API.php] ⚠️ DEPRECATED: get_analytics() called by ' . $caller_info . '. Use get_site_analytics() or get_wordpress_analytics().');
        return $this->get_ecosystem_stats($timeframe);
    }
    
    /**
     * Get ecosystem-wide statistics
     */
    public function get_ecosystem_stats($timeframe = '30d') {
        error_log('[API.php] 🌍 get_ecosystem_stats() called with timeframe: ' . $timeframe);
        
        $url = '/wordpress-ecosystem-stats';
        $payload = ['timeframe' => $timeframe];
        
        error_log('[API.php] 🌍 Request URL: ' . $this->api_endpoint . $url);
        error_log('[API.php] 🌍 Request payload: ' . json_encode($payload));
        error_log('[API.php] 🌍 API Key (first 8 chars): ' . substr($this->api_key, 0, 8) . '...');
        
        $result = $this->request('POST', $url, $payload);
        
        // Add detailed response logging
        error_log('[API.php] 🌍 get_ecosystem_stats() raw response: ' . json_encode($result));
        
        if (!isset($result['success'])) {
            error_log('[API.php] ❌ get_ecosystem_stats() - Invalid response structure (no success field)');
            return ['success' => false, 'error' => 'Invalid response from server'];
        }
        
        if (!$result['success']) {
            $error_msg = $result['error'] ?? $result['message'] ?? 'Unknown error';
            $status_code = $result['status_code'] ?? $result['status'] ?? 'unknown';
            error_log('[API.php] ❌ get_ecosystem_stats() failed: ' . $error_msg . ' (HTTP ' . $status_code . ')');
        } else {
            error_log('[API.php] ✅ get_ecosystem_stats() successful - has data: ' . (isset($result['data']) ? 'yes' : 'no'));
        }
        
        return $result;
    }
    
    /**
     * Check if agent is blacklisted
     */
    public function check_blacklist($user_agent, $site_id = null) {
        if (!$site_id) {
            $site_id = get_option('402links_site_id');
        }
        
        return $this->request('POST', '/check-agent-blacklist', [
            'user_agent' => $user_agent,
            'site_id' => $site_id
        ]);
    }
    
    /**
     * Get all links for this site
     */
    public function get_links($page = 1, $per_page = 20) {
        $site_id = get_option('402links_site_id');
        return $this->request('GET', "/wordpress-links?site_id={$site_id}&page={$page}&per_page={$per_page}");
    }
    
    /**
     * Get page analytics for all synced pages
     */
    public function get_pages_analytics($site_id) {
        return $this->request('GET', '/get-site-pages-analytics?site_id=' . $site_id);
    }
    
    /**
     * Get site info from Supabase
     */
    public function get_site_info($site_id) {
        return $this->request('GET', '/get-site-info?site_id=' . $site_id);
    }
    
    /**
     * Get site pages with their associated paid links
     */
    public function get_site_pages_with_links($site_id) {
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site ID is required'
            ];
        }
        
        return $this->request('GET', '/get-site-pages-with-links?site_id=' . $site_id);
    }
    
    /**
     * Get bot registry from Supabase
     * Returns all active bots with their detection patterns
     */
    public function get_bot_registry() {
        $result = $this->request('GET', '/get-bot-registry');
        
        if ($result['success'] && isset($result['bots'])) {
            return $result['bots'];
        }
        
        error_log('402links: Failed to fetch bot registry: ' . ($result['error'] ?? 'Unknown error'));
        return [];
    }
    
    /**
     * Report agent violation to backend
     * 
     * @param array $violation_data {
     *     @type string $site_id Site UUID
     *     @type int $wordpress_post_id WordPress post ID
     *     @type string $agent_name Bot/Agent name
     *     @type string $user_agent Full user agent string
     *     @type string $ip_address Client IP address
     *     @type string $requested_url The URL that was accessed
     *     @type string $violation_type Type: 'unpaid_access', 'ignored_402', 'scraped_content', 'robots_txt'
     *     @type string $detected_at ISO 8601 timestamp
     *     @type string $robots_txt_directive Optional robots.txt rule that was violated
     * }
     * @return array Response from backend
     */
    public function report_violation($violation_data) {
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            error_log('402links: Cannot report violation - site not registered');
            return ['success' => false, 'error' => 'Site not registered'];
        }
        
        // Ensure required fields
        $payload = array_merge([
            'site_id' => $site_id,
            'detected_at' => gmdate('Y-m-d\TH:i:s\Z')
        ], $violation_data);
        
        error_log('402links: Reporting violation: ' . json_encode($payload));
        
        return $this->request('POST', '/report-violation', $payload);
    }
    
    /**
     * Static wrapper for report_violation() for use in PaymentGate
     * Creates temporary API instance and reports violation
     * Non-blocking - failures won't prevent 402 response
     * 
     * @param array $violation_data Violation data array
     * @return array Response from backend (or error array)
     */
    public static function report_violation_static($violation_data) {
        error_log('402links: Static violation report called');
        
        try {
            $api = new self();
            $result = $api->report_violation($violation_data);
            
            if (isset($result['success']) && $result['success']) {
                error_log('402links: Violation reported successfully');
            } else {
                error_log('402links: Violation report failed: ' . json_encode($result));
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log('402links: EXCEPTION in report_violation_static: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get top performing pages via API proxy
     */
    public function get_top_pages($timeframe = '30d', $limit = 10, $offset = 0) {
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            error_log('[402links] get_top_pages: Site ID not found');
            return ['success' => false, 'error' => 'Site not registered'];
        }

        // Build query params
        $params = [
            'site_id' => $site_id,
            'limit' => $limit,
            'offset' => $offset
        ];

        // Call proxied endpoint via WordPress API key authentication
        $endpoint = '/agent-hub-top-pages?' . http_build_query($params);
        $result = $this->request('GET', $endpoint);

        if ($result && isset($result['pages'])) {
            return [
                'success' => true,
                'pages' => $result['pages'],
                'total' => $result['total'] ?? count($result['pages']),
                'limit' => $limit,
                'offset' => $offset
            ];
        }

        return ['success' => false, 'error' => 'Failed to fetch top pages'];
    }
    
    /**
     * Get agent violations from backend
     * 
     * @param string $site_id Site UUID
     * @param array $filters Optional filters (violation_type, agent_name, start_date, end_date)
     * @return array Response from backend
     */
    public function get_violations($site_id, $filters = []) {
        $params = array_merge(['site_id' => $site_id], $filters);
        $query_string = http_build_query($params);
        
        $result = $this->request('GET', '/get-violations?' . $query_string);
        
        if ($result['success'] && isset($result['violations'])) {
            return [
                'success' => true,
                'violations' => $result['violations'],
                'count' => $result['count'] ?? count($result['violations'])
            ];
        }
        
        error_log('402links: Failed to fetch violations: ' . ($result['error'] ?? 'Unknown error'));
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Failed to fetch violations',
            'violations' => [],
            'count' => 0
        ];
    }
    
    /**
     * Register REST API routes
     */
    public static function register_rest_routes() {
        register_rest_route('402links/v1', '/sync-meta', [
            'methods' => 'POST',
            'callback' => [self::class, 'rest_sync_meta'],
            'permission_callback' => [self::class, 'rest_permission_check']
        ]);
    }
    
    /**
     * REST API permission check
     */
    public static function rest_permission_check($request) {
        $auth_header = $request->get_header('X-402Links-Auth');
        if (empty($auth_header)) {
            return new \WP_Error('no_auth', 'Missing authentication', ['status' => 401]);
        }
        
        // Extract Bearer token
        $api_key = str_replace('Bearer ', '', $auth_header);
        $stored_key = get_option('402links_api_key');
        
        if ($api_key !== $stored_key) {
            return new \WP_Error('invalid_auth', 'Invalid API key', ['status' => 403]);
        }
        
        return true;
    }
    
    /**
     * REST API endpoint: Sync post meta from Supabase
     */
    public static function rest_sync_meta($request) {
        error_log('===== 402links REST SYNC CALLED =====');
        error_log('Request params: ' . json_encode($request->get_json_params()));
        
        $params = $request->get_json_params();
        
        $post_id = $params['post_id'] ?? null;
        $link_id = $params['link_id'] ?? null;
        $short_id = $params['short_id'] ?? null;
        $link_url = $params['link_url'] ?? null;
        $force_agent = $params['force_agent_payment'] ?? true;
        $force_human = $params['force_human_payment'] ?? false;
        
        if (!$post_id || !$link_id) {
            error_log('402links: SYNC FAILED - Missing required params');
            return new \WP_Error('missing_params', 'Missing required parameters', ['status' => 400]);
        }
        
        error_log('402links: Syncing meta for post ' . $post_id . ' with link ' . $link_id);
        error_log('402links: force_human_payment = ' . ($force_human ? 'true' : 'false'));
        
        // Update post meta to enable PaymentGate blocking
        update_post_meta($post_id, '_402links_id', $link_id);
        update_post_meta($post_id, '_402links_short_id', $short_id);
        update_post_meta($post_id, '_402links_url', $link_url);
        update_post_meta($post_id, '_402links_synced_at', current_time('mysql'));
        update_post_meta($post_id, '_402links_block_humans', $force_human ? '1' : '0');
        
        error_log('402links: SYNC SUCCESS - Post meta updated for post ' . $post_id);
        error_log('===== 402links REST SYNC COMPLETE =====');
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Post meta updated',
            'post_id' => $post_id
        ]);
    }
    
    /**
     * Bulk sync meta for all existing links from Supabase
     */
    public function bulk_sync_meta() {
        $site_id = get_option('402links_site_id');
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        // Get all pages from Supabase
        $result = $this->request('GET', '/get-site-pages-analytics?site_id=' . $site_id);
        
        if (!$result['success'] || !isset($result['data']['pages'])) {
            return [
                'success' => false,
                'error' => 'Failed to fetch pages from backend'
            ];
        }
        
        $pages = $result['data']['pages'];
        $updated = 0;
        
        foreach ($pages as $page) {
            if (!isset($page['wordpress_post_id']) || !isset($page['paid_link_id'])) {
                continue;
            }
            
            $post_id = $page['wordpress_post_id'];
            
            // Get paid_link details
            $paid_link_result = $this->request('GET', "/paid-links/{$page['paid_link_id']}");
            if (!$paid_link_result['success']) {
                continue;
            }
            
            $paid_link = $paid_link_result['data'];
            $link_url = 'https://api.402links.com/p/' . $paid_link['short_id'];
            
            // Update post meta
            update_post_meta($post_id, '_402links_id', $page['paid_link_id']);
            update_post_meta($post_id, '_402links_short_id', $paid_link['short_id']);
            update_post_meta($post_id, '_402links_url', $link_url);
            update_post_meta($post_id, '_402links_synced_at', current_time('mysql'));
            update_post_meta($post_id, '_402links_block_humans', $page['force_human_payment'] ? '1' : '0');
            
            $updated++;
        }
        
        return [
            'success' => true,
            'updated' => $updated,
            'message' => "Synced {$updated} posts"
        ];
    }
    
    /**
     * Get violations summary from backend
     */
    public function get_violations_summary() {
        $site_id = get_option('402links_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered. Please complete setup first.'
            ];
        }
        
        return $this->request('GET', '/get-agent-violations-summary', [
            'site_id' => $site_id
        ]);
    }
    
    /**
     * Get site bot policies from backend
     */
    public function get_site_bot_policies($site_id) {
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site ID is required'
            ];
        }
        
        return $this->request('GET', '/get-site-bot-policies', [
            'site_id' => $site_id
        ]);
    }
    
    /**
     * Update site bot policies
     */
    public function update_site_bot_policies($site_id, $policies) {
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site ID is required'
            ];
        }
        
        if (!is_array($policies)) {
            return [
                'success' => false,
                'error' => 'Policies must be an array'
            ];
        }
        
        // Validate policy structure
        foreach ($policies as $policy) {
            if (!isset($policy['bot_registry_id']) || !isset($policy['action'])) {
                return [
                    'success' => false,
                    'error' => 'Each policy must have bot_registry_id and action'
                ];
            }
        }
        
        return $this->request('POST', '/update-site-bot-policies', [
            'site_id' => $site_id,
            'policies' => $policies
        ]);
    }
    
    /**
     * Make HTTP request to API
     */
    private function request($method, $endpoint, $data = []) {
        $url = $this->api_endpoint . $endpoint;
        
        error_log('[API.php] 🚀 ==================== API REQUEST ====================');
        error_log('[API.php] 🚀 Method: ' . $method);
        error_log('[API.php] 🚀 URL: ' . $url);
        error_log('[API.php] 🚀 Endpoint: ' . $endpoint);
        
        // For GET requests, append data as query parameters
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
            error_log('[API.php] 🚀 GET query params: ' . json_encode($data));
        }
        
        $args = [
            'method' => $method,
            'timeout' => 8, // Increased from 3 to 8 seconds for stability
            'redirection' => 0, // Disable redirects to prevent delays
            'sslverify' => true, // Ensure SSL verification
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key
            ]
        ];
        
        error_log('[API.php] 🚀 Headers: ' . json_encode([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . substr($this->api_key, 0, 8) . '...' // Show first 8 chars only
        ]));
        
        if ($method === 'POST' || $method === 'PUT') {
            $args['body'] = json_encode($data);
            error_log('[API.php] 🚀 Request body: ' . json_encode($data));
        }
        
        error_log('[API.php] 🚀 Making request...');
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            error_log('[API.php] ❌ WP_Error: ' . $error_msg);
            error_log('[API.php] ❌ ==================== REQUEST FAILED ====================');
            return [
                'success' => false,
                'error' => $error_msg
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $status_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        
        error_log('[API.php] 📥 Response status: ' . $status_code);
        error_log('[API.php] 📥 Response headers: ' . json_encode($response_headers));
        error_log('[API.php] 📥 Response body (first 500 chars): ' . substr($body, 0, 500));
        
        $result = json_decode($body, true);
        
        if ($status_code >= 400) {
            error_log('[API.php] ❌ HTTP ERROR ' . $status_code . ': ' . ($result['error'] ?? 'Unknown error'));
            error_log('[API.php] ❌ Full error response: ' . json_encode($result));
            error_log('[API.php] ❌ ==================== REQUEST FAILED ====================');
            return [
                'success' => false,
                'error' => $result['error'] ?? 'API request failed',
                'status_code' => $status_code
            ];
        }
        
        error_log('[API.php] ✅ Request successful');
        error_log('[API.php] ✅ ==================== REQUEST COMPLETE ====================');
        
        return array_merge(['success' => true], $result ?? []);
    }
}
