<?php
namespace Angreessen49;

class API {
    private $api_key;
    private $api_endpoint;
    
    public function __construct() {
        $this->api_key = get_option('angreessen49_api_key');
        $settings = get_option('angreessen49_settings');
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
            return;
        }

        $endpoint = $this->api_endpoint . '/record-agent-visit';
        
        $payload = [
            'wordpress_post_id' => (int)$post_id,
            'site_url' => get_site_url(),
            'agent_name' => $agent_name,
            'user_agent' => $user_agent,
            'ip_address' => Helpers::get_validated_ip(),
            'page_url' => get_permalink($post_id)
        ];

        wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 5,
            'blocking' => false // Fire-and-forget
        ]);
    }
    
    /**
     * Verify if agent has already paid for content (within 24h cache)
     * UPDATED: Now supports wallet-based identity (primary) + fallback to user_agent
     */
    public function verify_agent_payment($site_id, $wordpress_post_id, $page_url, $user_agent, $ip_address, $agent_wallet = null) {
        if (!$this->api_endpoint || !$this->api_key) {
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
        
        // Add wallet if provided (primary identity method)
        if (!empty($agent_wallet)) {
            $payload['agent_wallet'] = $agent_wallet;
        }

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['payment_verified']) && $body['payment_verified'] === true) {
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
            'plugin_version' => ANGREESSEN49_VERSION
        ];
        
        // Only include api_key_id if we have one
        if ($api_key_id) {
            $payload['api_key_id'] = $api_key_id;
        }
        
        $result = $this->request('POST', '/register-wordpress-site', $payload);
        
        // Handle specific API key reuse error
        if (!$result['success'] && isset($result['error']) && strpos($result['error'], 'already being used') !== false) {
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
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
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
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return ['count' => 0];
        }
        
        $result = $this->request('GET', '/check-wordpress-links-count?site_id=' . $site_id);
        
        if (!$result['success']) {
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
        $settings = get_option('angreessen49_settings');
        
        $price = get_post_meta($post_id, '_angreessen49_price', true);
        if (empty($price)) {
            $price = $settings['default_price'] ?? 0.10;
        }
        
        // Get post excerpt
        $excerpt = $post->post_excerpt;
        if (empty($excerpt)) {
            $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 30);
        }
        
        // Get author information
        $author = get_the_author_meta('display_name', $post->post_author);
        
        // Get featured image
        $featured_image_url = get_the_post_thumbnail_url($post_id, 'large');
        
        // Calculate word count
        $word_count = str_word_count(wp_strip_all_tags($post->post_content));
        
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
            return ['total' => 0, 'created' => 0, 'already_linked' => 0, 'failed' => 0, 'errors' => []];
        }
        
        $results = [
            'total' => count($post_ids),
            'created' => 0,
            'already_linked' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $settings = get_option('angreessen49_settings');
        $curl_handles = [];
        $post_map = []; // Map curl handle resource ID to post_id
        
        /**
         * PHPCS Ignore: Using cURL multi-handle for parallel HTTP requests.
         * 
         * WordPress's wp_remote_*() functions are synchronous and would require
         * N × timeout seconds for N posts. cURL multi-handle enables true parallel
         * execution, reducing batch link creation from minutes to seconds.
         * 
         * Alternative approaches considered:
         * - wp_remote_post() loop: Would be 10-100× slower for batch operations
         * - wp_remote_post() non-blocking: Cannot capture responses
         * - Batch API endpoint: Would require backend changes
         */
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_exec
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_select
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close
        // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_close
        
        // Initialize cURL multi-handle
        $multi_handle = curl_multi_init();
        
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                $results['failed']++;
                continue;
            }
            
            $price = get_post_meta($post_id, '_angreessen49_price', true);
            if (empty($price)) {
                $price = $settings['default_price'] ?? 0.10;
            }
            
            $excerpt = $post->post_excerpt;
            if (empty($excerpt)) {
                $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 30);
            }
            
            $author = get_the_author_meta('display_name', $post->post_author);
            $featured_image_url = get_the_post_thumbnail_url($post_id, 'large');
            $word_count = str_word_count(wp_strip_all_tags($post->post_content));
            
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
                CURLOPT_POSTFIELDS => wp_json_encode($payload),
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
            
            if ($curl_error) {
                $results['failed']++;
                $results['errors'][] = "Post {$post_id}: cURL error - {$curl_error}";
            } elseif ($http_code !== 200) {
                $results['failed']++;
                $results['errors'][] = "Post {$post_id}: HTTP {$http_code}";
            } else {
                $data = json_decode($response, true);
                
                // Handle nested response structure: {success: true, data: {link_id, short_id, link_url}}
                $link_data = $data['data'] ?? $data;
                
                if (($data['success'] ?? false) && isset($link_data['link_id'])) {
                    $link_id = $link_data['link_id'];
                    $short_id = $link_data['short_id'] ?? '';
                    $link_url = $link_data['link_url'] ?? '';
                    
                    // Check if this was an "already exists" response
                    $message = $data['message'] ?? $link_data['message'] ?? '';
                    $is_existing = (stripos($message, 'already exists') !== false);
                    
                    update_post_meta($post_id, '_angreessen49_link_id', $link_id);
                    update_post_meta($post_id, '_angreessen49_short_id', $short_id);
                    update_post_meta($post_id, '_angreessen49_url', $link_url);
                    
                    // Verify meta was actually saved
                    $saved_url = get_post_meta($post_id, '_angreessen49_url', true);
                    
                    // Count as already_linked or created
                    if ($is_existing) {
                        $results['already_linked']++;
                    } else {
                        $results['created']++;
                    }
                } else {
                    $results['failed']++;
                    $error_msg = $data['error'] ?? $data['message'] ?? 'Unknown error';
                    $results['errors'][] = "Post {$post_id}: {$error_msg}";
                }
            }
            
            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multi_handle);
        // phpcs:enable
        
        return $results;
    }
    
    /**
     * Get analytics data
     */
    public function get_analytics($timeframe = '30d') {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        return $this->request('GET', '/wordpress-analytics?site_id=' . $site_id . '&timeframe=' . $timeframe);
    }
    
    /**
     * Get rogue agents data
     */
    public function get_rogue_agents($timeframe = '30d') {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        return $this->request('GET', '/wordpress-rogue-agents?site_id=' . $site_id . '&timeframe=' . $timeframe);
    }
    
    /**
     * Get violations data
     */
    public function get_violations() {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        return $this->request('GET', '/wordpress-violations?site_id=' . $site_id);
    }
    
    /**
     * Get violations summary
     */
    public function get_violations_summary() {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        return $this->request('GET', '/wordpress-violations-summary?site_id=' . $site_id);
    }
    
    /**
     * Get site bot policies
     */
    public function get_site_bot_policies() {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        return $this->request('GET', '/wordpress-bot-policies?site_id=' . $site_id);
    }
    
    /**
     * Save site bot policies
     */
    public function save_site_bot_policies($policies) {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        return $this->request('POST', '/wordpress-bot-policies', [
            'site_id' => $site_id,
            'policies' => $policies
        ]);
    }
    
    /**
     * Get content analytics
     */
    public function get_content_analytics() {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return [
                'success' => false,
                'error' => 'Site not registered'
            ];
        }
        
        return $this->request('GET', '/wordpress-content-analytics?site_id=' . $site_id);
    }
    
    /**
     * Report violation
     */
    public function report_violation($data) {
        $site_id = get_option('angreessen49_site_id');
        
        if (!$site_id) {
            return;
        }
        
        $data['site_id'] = $site_id;
        
        // Fire-and-forget
        wp_remote_post($this->api_endpoint . '/report-violation', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($data),
            'timeout' => 5,
            'blocking' => false
        ]);
    }
    
    /**
     * Make API request
     */
    private function request($method, $endpoint, $payload = null) {
        $url = $this->api_endpoint . $endpoint;
        
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ];
        
        if ($payload && $method !== 'GET') {
            $args['body'] = wp_json_encode($payload);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code >= 400) {
            return [
                'success' => false,
                'error' => $body['error'] ?? $body['message'] ?? 'API error',
                'code' => $code
            ];
        }
        
        return array_merge(['success' => true], $body ?? []);
    }
}
