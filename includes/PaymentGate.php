<?php
namespace AgentHub;

class PaymentGate {
    /**
     * Intercept requests and apply 402 payment gate
     * Called on 'template_redirect' hook
     * 
     * HUMAN-FIRST ARCHITECTURE: Detect humans, everything else is agent
     * Flow: Human check → Payment check → 402 response → Violation tracking
     */
    public static function intercept_request() {
        // ============= STEP 1: OPTIONS PREFLIGHT (CORS) =============
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: X-PAYMENT, Content-Type, Authorization');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Max-Age: 86400'); // 24 hours
            status_header(200);
            exit;
        }
        
        global $post;
        
        // ============= STEP 2: PROTECTION CHECK =============
        $short_id = get_post_meta($post->ID, '_402links_short_id', true);
        
        if (empty($short_id)) {
            return; // Not protected - serve content normally
        }
        
        error_log('===== 402links PaymentGate: Protected Content Request =====');
        error_log('Post ID: ' . $post->ID . ' | Title: ' . get_the_title($post->ID));
        error_log('Short ID: ' . $short_id);
        error_log('User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'NONE'));
        
        // ============= STEP 3: ADMIN BYPASS =============
        if (current_user_can('manage_options')) {
            // If admin is viewing protected post, show preview notice
            if (!empty($short_id)) {
                add_action('admin_bar_menu', function($wp_admin_bar) use ($post) {
                    $block_humans = get_post_meta($post->ID, '_402links_block_humans', true);
                    $protection_type = ($block_humans === '1' || $block_humans === 1) 
                        ? 'Agents + Humans' 
                        : 'Agents Only';
                        
                    $wp_admin_bar->add_node([
                        'id' => '402links-preview-notice',
                        'title' => '⚠️ ADMIN PREVIEW - Paywall Active (' . $protection_type . ')',
                        'href' => false,
                        'meta' => [
                            'class' => '402links-preview-warning',
                            'title' => 'You are viewing as admin. Others will see paywall. Test in incognito to verify.'
                        ]
                    ]);
                }, 999);
                
                // Enqueue CSS for admin bar warning
                add_action('wp_enqueue_scripts', function() {
                    wp_add_inline_style('admin-bar', '
                        #wp-admin-bar-402links-preview-notice > .ab-item {
                            background-color: #ff9800 !important;
                            color: #000 !important;
                            font-weight: bold !important;
                            animation: pulse 2s infinite;
                        }
                        #wp-admin-bar-402links-preview-notice > .ab-item:hover {
                            background-color: #f57c00 !important;
                        }
                        @keyframes pulse {
                            0%, 100% { opacity: 1; }
                            50% { opacity: 0.7; }
                        }
                    ');
                });
            }
            return;
        }
        
        // ============= STEP 4: HUMAN DETECTION =============
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $human_check = HumanDetector::is_human($user_agent);
        
        error_log('Human Detection: ' . json_encode($human_check));
        
        // ============= STEP 5: HUMAN PATH =============
        if ($human_check['is_human']) {
            error_log('402links: HUMAN DETECTED');
            
            // Check if humans are blocked for this content
            $block_humans = get_post_meta($post->ID, '_402links_block_humans', true);
            
            if ($block_humans === '1' || $block_humans === 1) {
                error_log('402links: Human blocking enabled - redirecting to 402links.com/p/' . $short_id);
                
                $return_url = get_permalink($post->ID);
                $redirect_url = 'https://402links.com/p/' . $short_id . '?return_to=' . urlencode($return_url);
                
                status_header(302);
                header('Location: ' . $redirect_url);
                exit;
            }
            
            error_log('402links: Human allowed - serving content');
            return; // Serve content to human
        }
        
        // ============= STEP 6: AGENT PATH =============
        error_log('402links: AGENT DETECTED (not human)');
        
        $agent_name = HumanDetector::extract_agent_name($user_agent);
        error_log('402links: Agent name: ' . $agent_name);
        
        // Check if agent is blacklisted FIRST (before recording)
        $site_id = get_option('402links_site_id');
        if (AgentDetector::is_blacklisted($user_agent, $site_id)) {
            error_log('402links: Agent is blacklisted - denying access');
            status_header(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Access denied',
                'message' => 'This agent is blacklisted'
            ]);
            exit;
        }
        
        // Record agent visit (fire-and-forget, only if not blacklisted)
        $api = new API();
        $api->record_agent_visit($post->ID, $agent_name, $user_agent);
        
        // ============= STEP 7: CHECK X-PAYMENT HEADER =============
        $payment_header = $_SERVER['HTTP_X_PAYMENT'] ?? '';
        
        if (!empty($payment_header)) {
            error_log('402links: X-PAYMENT header present - verifying payment');
            
            $requirements = self::get_payment_requirements($post->ID);
            $verification = self::verify_payment($payment_header, $requirements);
            
            if (!$verification['isValid']) {
                error_log('402links: Payment verification FAILED - ' . ($verification['error'] ?? 'unknown'));
                
                // VIOLATION: Agent provided invalid payment
                self::report_violation($post->ID, $agent_name, $user_agent, 'invalid_payment');
                
                self::send_402_response($requirements, $verification['error'] ?? 'Invalid payment');
                exit;
            }
            
            error_log('402links: Payment VERIFIED - txHash: ' . ($verification['transaction'] ?? 'none'));
            
            // Log successful payment
            self::log_agent_payment($post->ID, $verification, [
                'agent_name' => $agent_name,
                'is_agent' => true
            ]);
            
            // Set settlement header
            add_filter('wp_headers', function($headers) use ($verification) {
                if (isset($verification['settlement_header'])) {
                    $headers['X-PAYMENT-RESPONSE'] = $verification['settlement_header'];
                }
                return $headers;
            });
            
            // Serve JSON content
            error_log('402links: Serving JSON content to agent');
            self::serve_json_content($post->ID);
            exit;
        }
        
        // ============= STEP 8: CHECK CACHED PAYMENT =============
        error_log('402links: No X-PAYMENT header - checking for cached payment');
        
        $cached_payment = $api->verify_agent_payment(
            $site_id,
            $post->ID,
            get_permalink($post->ID),
            $user_agent,
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
        
        if ($cached_payment && $cached_payment['payment_verified']) {
            error_log('402links: Cached payment found (crawl_id: ' . ($cached_payment['crawl_id'] ?? 'unknown') . ')');
            
            // Serve JSON content
            self::serve_json_content($post->ID);
            exit;
        }
        
        // ============= STEP 9: SEND 402 PAYMENT REQUIRED =============
        error_log('402links: No payment found - sending 402 Payment Required');
        
        // Track repeated failed access attempts for brute force detection
        $attempt_key = '402links_failed_attempts_' . md5($user_agent . $post->ID);
        $failed_attempts = (int) get_transient($attempt_key);
        
        if ($failed_attempts >= 3) {
            // VIOLATION: Agent is brute forcing
            error_log('402links: VIOLATION - Brute force detected after ' . $failed_attempts . ' attempts');
            self::report_violation($post->ID, $agent_name, $user_agent, 'brute_force_bypass');
        }
        
        // Increment counter (expires in 1 hour)
        set_transient($attempt_key, $failed_attempts + 1, 3600);
        
        $requirements = self::get_payment_requirements($post->ID);
        self::send_402_response($requirements);
        exit;
    }
    
    /**
     * Serve JSON content to agent
     * 
     * @param int $post_id
     */
    private static function serve_json_content($post_id) {
        $post = get_post($post_id);
        
        if (!$post) {
            status_header(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Content not found']);
            exit;
        }
        
        $content = [
            'id' => $post->ID,
            'title' => get_the_title($post->ID),
            'content' => apply_filters('the_content', $post->post_content),
            'excerpt' => get_the_excerpt($post->ID),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'published_at' => $post->post_date,
            'modified_at' => $post->post_modified,
            'url' => get_permalink($post->ID),
            'word_count' => str_word_count(strip_tags($post->post_content))
        ];
        
        status_header(200);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($content, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Report violation to backend
     * 
     * @param int $post_id
     * @param string $agent_name
     * @param string $user_agent
     * @param string $violation_type
     */
    private static function report_violation($post_id, $agent_name, $user_agent, $violation_type) {
        error_log('402links: VIOLATION DETECTED - ' . $violation_type);
        
        $api = new API();
        $api->report_violation([
            'wordpress_post_id' => $post_id,
            'agent_name' => $agent_name,
            'user_agent' => $user_agent,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'requested_url' => $_SERVER['REQUEST_URI'] ?? '',
            'violation_type' => $violation_type
        ]);
    }
    
    /**
     * Build x402 payment requirements
     * Mirrors access-link.ts payment structure
     * 
     * SECURITY FIX: bind_hash is now persisted in post meta to prevent replay attacks
     */
    private static function get_payment_requirements($post_id) {
        $settings = get_option('402links_settings');
        
        // Get post-specific price or use default
        $price = get_post_meta($post_id, '_402links_price', true);
        if (empty($price)) {
            $price = $settings['default_price'] ?? 0.10;
        }
        $price = floatval($price);
        
        $payment_wallet = $settings['payment_wallet'] ?? '';
        
        // Validate wallet is configured before sending 402 response
        if (empty($payment_wallet)) {
            error_log('402links PaymentGate: Payment wallet not configured - content NOT protected');
            return; // Skip 402 response, allow normal WordPress content delivery
        }
        
        $network = $settings['network'] ?? 'base';
        
        // Network configuration
        $network_config = [
            'base' => [
                'chain_id' => 8453,
                'usdc' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913'
            ],
            'base-sepolia' => [
                'chain_id' => 84532,
                'usdc' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e'
            ]
        ];
        
        $config = $network_config[$network] ?? $network_config['base'];
        $maxAmountRequired = (string)floor($price * 1000000); // Convert to USDC base units
        
        // SECURITY: Generate or retrieve persistent bind_hash and nonce
        $bind_hash = self::get_or_create_bind_hash($post_id, $payment_wallet, $price);
        $invoice_id = self::get_or_create_invoice_id($post_id);
        
        // Get short_id for API resource URL
        $short_id = get_post_meta($post_id, '_402links_short_id', true);
        $resource_url = $short_id 
            ? 'https://api.402links.com/v1/access-link?short_id=' . $short_id
            : get_permalink($post_id);
        
        return [
            'scheme' => 'exact',
            'network' => $network,
            'asset' => $config['usdc'],
            'maxAmountRequired' => $maxAmountRequired,
            'payTo' => $payment_wallet,
            'resource' => $resource_url,
            'description' => sprintf(
                'Access to "%s" - Premium WordPress content. Price: $%s USDC',
                get_the_title($post_id),
                number_format($price, 2)
            ),
            'mimeType' => 'application/json',
            'maxTimeoutSeconds' => 900,
            'outputSchema' => [
                'input' => [
                    'type' => 'http',
                    'method' => 'GET',
                    'discoverable' => true,
                    'headerFields' => [
                        'X-PAYMENT' => [
                            'type' => 'string',
                            'required' => true,
                            'description' => 'Base64-encoded JSON PaymentPayload containing EIP-3009 signature and authorization'
                        ]
                    ]
                ],
                'output' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'number',
                            'description' => 'WordPress post ID'
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Post title'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'Full post content (HTML)'
                        ],
                        'excerpt' => [
                            'type' => 'string',
                            'description' => 'Post excerpt/summary'
                        ],
                        'author' => [
                            'type' => 'string',
                            'description' => 'Author display name'
                        ],
                        'published_at' => [
                            'type' => 'string',
                            'description' => 'Publication date (ISO 8601)'
                        ],
                        'modified_at' => [
                            'type' => 'string',
                            'description' => 'Last modification date (ISO 8601)'
                        ],
                        'url' => [
                            'type' => 'string',
                            'description' => 'Permalink to the post'
                        ],
                        'word_count' => [
                            'type' => 'number',
                            'description' => 'Word count of content'
                        ]
                    ],
                    'required' => ['id', 'title', 'content', 'url']
                ]
            ],
            'extra' => [
                'name' => 'USD Coin',
                'version' => '2',
                'bind_hash' => $bind_hash,
                'invoice_id' => $invoice_id,
                'post_id' => $post_id,
                'site_url' => get_site_url(),
                'brand' => [
                    'name' => '402links',
                    'logo' => 'https://402links.com/assets/tolliver-character-Bn6kJH3V.png',
                    'icon' => 'https://402links.com/assets/tolliver-character-Bn6kJH3V.png',
                    'color' => '#0E7AFE'
                ]
            ]
        ];
    }
    
    /**
     * Generate or retrieve persistent bind_hash for payment verification
     * This prevents replay attacks by ensuring each post has a unique, reusable hash
     * 
     * @param int $post_id Post ID
     * @param string $payment_wallet Payment wallet address
     * @param float $price Post price
     * @return string Persistent bind_hash
     */
    private static function get_or_create_bind_hash($post_id, $payment_wallet, $price) {
        // Check if bind_hash already exists
        $existing_hash = get_post_meta($post_id, '_402links_bind_hash', true);
        if (!empty($existing_hash)) {
            return $existing_hash;
        }
        
        // Generate new unique nonce for this post (never changes after creation)
        $unique_nonce = wp_generate_password(32, false);
        update_post_meta($post_id, '_402links_nonce', $unique_nonce);
        
        // Generate bind_hash using post-specific nonce
        $bind_hash = hash('sha256', $post_id . $payment_wallet . $price . $unique_nonce);
        update_post_meta($post_id, '_402links_bind_hash', $bind_hash);
        
        return $bind_hash;
    }
    
    /**
     * Generate or retrieve persistent invoice_id
     * 
     * @param int $post_id Post ID
     * @return string Persistent invoice_id
     */
    private static function get_or_create_invoice_id($post_id) {
        // Check if invoice_id already exists
        $existing_invoice_id = get_post_meta($post_id, '_402links_invoice_id', true);
        if (!empty($existing_invoice_id)) {
            return $existing_invoice_id;
        }
        
        // Generate new invoice_id
        $invoice_id = 'wp_' . $post_id . '_' . time() . '_' . wp_generate_password(8, false);
        update_post_meta($post_id, '_402links_invoice_id', $invoice_id);
        
        return $invoice_id;
    }
    
    /**
     * Check if request is from a browser
     */
    private static function is_browser_request() {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Browser will request text/html and have Mozilla in user agent
        return (strpos($accept, 'text/html') !== false && 
                strpos($user_agent, 'Mozilla') !== false);
    }
    
    /**
     * Send 402 Payment Required response
     * x402 spec compliant with proper PaymentRequirements in body
     * Returns HTML paywall for browsers, JSON for agents
     */
    private static function send_402_response($requirements, $error_msg = 'Payment Required') {
        // Add provider URL to requirements for agent clarity
        if (!isset($requirements['extra']['provider'])) {
            $requirements['extra']['provider'] = $requirements['resource'];
        }
        
        $x402_response = [
            'x402Version' => 1,
            'error' => $error_msg,
            'accepts' => [$requirements]
        ];
        
        // Encode for WWW-Authenticate header
        $www_auth_payload = base64_encode(json_encode($x402_response));
        
        // Anti-cache headers (prevent proxies from caching 402)
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        header('Pragma: no-cache');
        header('Vary: Accept, User-Agent');
        
        // Set headers with x402 discovery info
        //header('WWW-Authenticate: x402="' . $www_auth_payload . '"');
        header('X-402-Version: 1');
        header('X-402-Scheme: exact');
        header('X-402-Network: ' . $requirements['network']);
        header('X-402-Amount: ' . $requirements['maxAmountRequired']);
        header('X-402-Currency: USDC');
        header('X-402-Asset: ' . $requirements['asset']);
        header('X-402-PayTo: ' . $requirements['payTo']);
        header('X-402-Resource: ' . $requirements['resource']);
        header('X-402-Discovery: ' . get_site_url() . '/.well-known/402.json');
        header('X-402-Discoverable: true');
        
        // Robots.txt reference (flow step d)
        header('X-402-Robots: ' . get_site_url() . '/robots.txt');
        header('X-Robots-Tag: noindex, nofollow');
        header('Link: <' . get_site_url() . '/robots.txt>; rel="robots"');
        
        // Advertise payment provider endpoint
        header('X-402-Provider: ' . ($requirements['resource'] ?? ''));
        header('Link: <' . ($requirements['resource'] ?? '') . '>; rel="payment", <' . get_site_url() . '/robots.txt>; rel="robots"');
        
        // Add CORS headers for x402 protocol
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: X-PAYMENT, Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Expose-Headers: WWW-Authenticate, X-402-Version, X-402-Scheme, X-402-Network, X-402-Amount, X-402-Currency, X-402-Asset, X-402-PayTo, X-402-Resource, X-402-Discovery, X-402-Provider, Link, X-PAYMENT-RESPONSE');
        
        // Force HTTP 402 AFTER all headers (prevents nginx override)
        $code = 402;
        status_header($code);      // WordPress-native status handling
        http_response_code($code); // PHP standard
        header('Status: 402 Payment Required'); // CGI/FastCGI compatibility
        
        // BROWSER vs AGENT: Return HTML for browsers, JSON for agents
        if (self::is_browser_request()) {
            require_once plugin_dir_path(__FILE__) . 'PaywallTemplate.php';
            header('Content-Type: text/html; charset=UTF-8');
            echo PaywallTemplate::render($x402_response, $requirements);
        } else {
            header('Content-Type: application/json');
            echo json_encode($x402_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        
        exit;
    }
    
    /**
     * Verify payment with backend edge function
     * Calls: verify-wordpress-payment edge function
     */
    private static function verify_payment($payment_header, $requirements) {
        $settings = get_option('402links_settings');
        $api_key = get_option('402links_api_key');
        $api_endpoint = $settings['api_endpoint'] ?? 'https://api.402links.com/v1';
        
        $response = wp_remote_post($api_endpoint . '/verify-wordpress-payment', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode([
                'payment_header' => $payment_header,
                'payment_requirements' => $requirements,
                'post_id' => get_the_ID(),
                'site_url' => get_site_url()
            ])
        ]);
        
        if (is_wp_error($response)) {
            return ['isValid' => false, 'error' => $response->get_error_message()];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['isValid' => false, 'error' => 'Invalid JSON response'];
        }
        
        return $data;
    }
    
    /**
     * Validate invoice with 402links API
     */
    private static function validate_invoice($invoice_id, $post_id, $site_url) {
        $api_url = 'https://402links.com/api/v1/invoices/validate';
        
        $response = wp_remote_post($api_url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'invoice_id' => $invoice_id,
                'site_url' => $site_url,
                'wordpress_post_id' => $post_id
            ])
        ]);
        
        if (is_wp_error($response)) {
            error_log('402links: Invoice validation request failed: ' . $response->get_error_message());
            return ['isValid' => false, 'error' => 'API request failed'];
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        // Handle new public API response format
        if (isset($result['success']) && $result['success'] === true) {
            // Convert new format to old format for backward compatibility
            $result['isValid'] = true;
            if (isset($result['data'])) {
                $result = array_merge($result, $result['data']);
            }
        } elseif (isset($result['success']) && $result['success'] === false) {
            $result['isValid'] = false;
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('402links: Invalid JSON response from validate-invoice: ' . $body);
            return ['isValid' => false, 'error' => 'Invalid JSON response'];
        }
        
        return $result;
    }
    
    /**
     * Log agent access for analytics
     */
    private static function log_agent_access($post_id, $invoice_id, $validation) {
        global $wpdb;
        $table_name = $wpdb->prefix . '402links_agent_logs';
        
        // Insert access log
        $wpdb->insert(
            $table_name,
            [
                'post_id' => $post_id,
                'invoice_id' => $invoice_id,
                'payment_tx_hash' => $validation['transaction_hash'] ?? null,
                'amount_paid' => $validation['amount'] ?? 0,
                'payment_status' => 'paid',
                'accessed_at' => current_time('mysql'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'ip_address' => AgentDetector::get_client_ip()
            ],
            ['%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s']
        );
        
        error_log('402links: Agent access logged to local database');
    }
    
    /**
     * Increment link usage count
     */
    private static function increment_link_usage($post_id) {
        $current_uses = (int) get_post_meta($post_id, '_402links_usage_count', true);
        $new_uses = $current_uses + 1;
        update_post_meta($post_id, '_402links_usage_count', $new_uses);
        
        error_log('402links: Link usage incremented: ' . $current_uses . ' → ' . $new_uses);
    }
    
    /**
     * Log successful payment to backend and local database
     */
    private static function log_agent_payment($post_id, $verification, $agent_check) {
        error_log('402links: Logging successful payment:');
        error_log('  - Post ID: ' . $post_id);
        error_log('  - Transaction: ' . ($verification['transaction'] ?? 'none'));
        error_log('  - Payer: ' . ($verification['payer'] ?? 'unknown'));
        error_log('  - Amount: ' . ($verification['amount'] ?? 0));
        error_log('  - Agent: ' . ($agent_check['agent_name'] ?? 'unknown'));
        
        global $wpdb;
        $table_name = $wpdb->prefix . '402links_agent_logs';
        
        // Update local log
        $wpdb->update(
            $table_name,
            [
                'payment_status' => 'paid',
                'payment_tx_hash' => $verification['transaction'] ?? '',
                'amount_paid' => $verification['amount'] ?? 0
            ],
            [
                'post_id' => $post_id,
                'payment_status' => 'pending'
            ],
            ['%s', '%s', '%f'],
            ['%d', '%s']
        );
        
        // Send to backend for aggregation
        $settings = get_option('402links_settings');
        $api_key = get_option('402links_api_key');
        $api_endpoint = $settings['api_endpoint'] ?? 'https://api.402links.com/v1';
        
        wp_remote_post($api_endpoint . '/log-agent-payment', [
            'timeout' => 15,
            'blocking' => false, // Don't wait for response
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ],
            'body' => json_encode([
                'post_id' => $post_id,
                'site_url' => get_site_url(),
                'agent_name' => $agent_check['agent_name'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip_address' => AgentDetector::get_client_ip(),
                'payment_tx_hash' => $verification['transaction'] ?? '',
                'amount' => $verification['amount'] ?? 0,
                'payer_address' => $verification['payer'] ?? ''
            ])
        ]);
    }
}
