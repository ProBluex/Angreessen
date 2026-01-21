<?php
namespace Angreessen49;

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
        if (Helpers::get_sanitized_request_method() === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: X-PAYMENT, Content-Type, Authorization');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Max-Age: 86400'); // 24 hours
            status_header(200);
            exit;
        }
        
        global $post;
        
        // Early exit if no post context (e.g., .well-known endpoints, 404s, search pages)
        if (!$post || !isset($post->ID)) {
            return;
        }
        
        // ============= STEP 2: PROTECTION CHECK =============
        $short_id = get_post_meta($post->ID, '_angreessen49_short_id', true);
        
        if (empty($short_id)) {
            return; // Not protected - serve content normally
        }
        
        // ============= STEP 3: ADMIN BYPASS =============
        if (current_user_can('manage_options')) {
            // If admin is viewing protected post, show preview notice
            if (!empty($short_id)) {
                add_action('admin_bar_menu', function($wp_admin_bar) use ($post) {
                    $block_humans = get_post_meta($post->ID, '_angreessen49_block_humans', true);
                    $protection_type = ($block_humans === '1' || $block_humans === 1) 
                        ? 'Agents + Humans' 
                        : 'Agents Only';
                        
                    $wp_admin_bar->add_node([
                        'id' => 'angreessen49-preview-notice',
                        'title' => '⚠️ ADMIN PREVIEW - Paywall Active (' . $protection_type . ')',
                        'href' => false,
                        'meta' => [
                            'class' => 'angreessen49-preview-warning',
                            'title' => 'You are viewing as admin. Others will see paywall. Test in incognito to verify.'
                        ]
                    ]);
                }, 999);
                
                // Enqueue CSS for admin bar warning
                add_action('wp_enqueue_scripts', function() {
                    wp_enqueue_style(
                        'angreessen49-admin-bar-preview',
                        ANGREESSEN49_PLUGIN_URL . 'assets/css/admin-bar-preview.css',
                        array( 'admin-bar' ),
                        ANGREESSEN49_VERSION
                    );
                });
            }
            return;
        }
        
        // ============= STEP 4: HUMAN DETECTION =============
        $user_agent = Helpers::get_sanitized_user_agent();
        $human_check = HumanDetector::is_human($user_agent);
        
        // ============= STEP 5: HUMAN PATH =============
        if ($human_check['is_human']) {
            // Check if humans are blocked for this content
            $block_humans = get_post_meta($post->ID, '_angreessen49_block_humans', true);
            
            if ($block_humans === '1' || $block_humans === 1) {
                $return_url = get_permalink($post->ID);
                $redirect_url = 'https://402links.com/p/' . $short_id . '?return_to=' . urlencode($return_url);
                
                status_header(302);
                header('Location: ' . $redirect_url);
                exit;
            }
            
            return; // Serve content to human
        }
        
        // ============= STEP 6: AGENT PATH =============
        $agent_name = HumanDetector::extract_agent_name($user_agent);
        
        // Check if agent is blacklisted FIRST (before recording)
        $site_id = get_option('angreessen49_site_id');
        if (AgentDetector::is_blacklisted($user_agent, $site_id)) {
            status_header(403);
            header('Content-Type: application/json');
            echo wp_json_encode([
                'error' => 'Access denied',
                'message' => 'This agent is blacklisted'
            ]);
            exit;
        }
        
        // Record agent visit (fire-and-forget, only if not blacklisted)
        $api = new API();
        $api->record_agent_visit($post->ID, $agent_name, $user_agent);
        
        // ============= STEP 7: CHECK X-PAYMENT HEADER =============
        $payment_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_PAYMENT'] ?? ''));
        
        if (!empty($payment_header)) {
            $requirements = self::get_payment_requirements($post->ID);
            $verification = self::verify_payment($payment_header, $requirements);
            
            if (!$verification['isValid']) {
                // VIOLATION: Agent provided invalid payment
                self::report_violation($post->ID, $agent_name, $user_agent, 'invalid_payment');
                
                self::send_402_response($requirements, $verification['error'] ?? 'Invalid payment');
                exit;
            }
            
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
            self::serve_json_content($post->ID);
            exit;
        }
        
        // ============= STEP 8: CHECK CACHED PAYMENT =============
        // Check for X-Agent-Wallet header (primary identity for re-access)
        $agent_wallet = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_AGENT_WALLET'] ?? ''));
        
        $cached_payment = $api->verify_agent_payment(
            $site_id,
            $post->ID,
            get_permalink($post->ID),
            $user_agent,
            Helpers::get_validated_ip(),
            $agent_wallet // Pass wallet for wallet-based verification
        );
        
        if ($cached_payment && $cached_payment['payment_verified']) {
            // Serve JSON content
            self::serve_json_content($post->ID);
            exit;
        }
        
        // ============= STEP 9: SEND 402 PAYMENT REQUIRED =============
        // Track repeated failed access attempts for brute force detection
        $attempt_key = 'angreessen49_failed_attempts_' . md5($user_agent . $post->ID);
        $failed_attempts = (int) get_transient($attempt_key);
        
        if ($failed_attempts >= 3) {
            // VIOLATION: Agent is brute forcing
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
            echo wp_json_encode(['error' => 'Content not found']);
            exit;
        }
        
        $content = [
            'id' => $post->ID,
            'title' => Helpers::get_clean_title($post->ID),
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Using WordPress core filter, not defining
            'content' => apply_filters('the_content', $post->post_content),
            'excerpt' => get_the_excerpt($post->ID),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'published_at' => $post->post_date,
            'modified_at' => $post->post_modified,
            'url' => get_permalink($post->ID),
            'word_count' => str_word_count(wp_strip_all_tags($post->post_content))
        ];
        
        status_header(200);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($content, JSON_PRETTY_PRINT);
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
        $api = new API();
        $api->report_violation([
            'wordpress_post_id' => $post_id,
            'agent_name' => $agent_name,
            'user_agent' => $user_agent,
            'ip_address' => Helpers::get_validated_ip(),
            'requested_url' => Helpers::get_sanitized_request_uri(),
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
        $settings = get_option('angreessen49_settings');
        
        // Get post-specific price or use default
        $price = get_post_meta($post_id, '_angreessen49_price', true);
        if (empty($price)) {
            $price = $settings['default_price'] ?? 0.10;
        }
        $price = floatval($price);
        
        // CRITICAL: Use agent_payment_wallet (splitter address) for 402 responses
        // This is the address synced from backend with 99/1 revenue split
        // Fallback to payment_wallet only if agent_payment_wallet not yet synced
        $payment_wallet = get_option('angreessen49_agent_payment_wallet');
        if (empty($payment_wallet)) {
            $payment_wallet = $settings['payment_wallet'] ?? '';
        }
        
        // Validate wallet is configured before sending 402 response
        if (empty($payment_wallet)) {
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
        $short_id = get_post_meta($post_id, '_angreessen49_short_id', true);
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
            'discoverable' => true,
            'description' => sprintf(
                'Access to "%s" - Premium WordPress content. Price: $%s USDC',
                Helpers::get_clean_title($post_id),
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
                    'logo' => 'https://402links.com/assets/angreessen-character-BP8HCaEf.png',
                    'icon' => 'https://402links.com/assets/angreessen-character-BP8HCaEf.png',
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
        $existing_hash = get_post_meta($post_id, '_angreessen49_bind_hash', true);
        if (!empty($existing_hash)) {
            return $existing_hash;
        }
        
        // Generate new unique nonce for this post (never changes after creation)
        $unique_nonce = wp_generate_password(32, false);
        update_post_meta($post_id, '_angreessen49_nonce', $unique_nonce);
        
        // Generate bind_hash using post-specific nonce
        $bind_hash = hash('sha256', $post_id . $payment_wallet . $price . $unique_nonce);
        update_post_meta($post_id, '_angreessen49_bind_hash', $bind_hash);
        
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
        $existing_invoice_id = get_post_meta($post_id, '_angreessen49_invoice_id', true);
        if (!empty($existing_invoice_id)) {
            return $existing_invoice_id;
        }
        
        // Generate new invoice_id
        $invoice_id = 'wp_' . $post_id . '_' . time() . '_' . wp_generate_password(8, false);
        update_post_meta($post_id, '_angreessen49_invoice_id', $invoice_id);
        
        return $invoice_id;
    }
    
    /**
     * Check if request is from a browser
     */
    private static function is_browser_request() {
        $accept = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'] ?? ''));
        $user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        
        // Browser will request text/html and have Mozilla in user agent
        return (strpos($accept, 'text/html') !== false) && (strpos($user_agent, 'Mozilla') !== false);
    }
    
    /**
     * Send 402 Payment Required response
     */
    private static function send_402_response($requirements, $error = null) {
        status_header(402);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Expose-Headers: X-PAYMENT');
        
        $response = [
            'x402Version' => 1,
            'accepts' => [$requirements],
            'error' => $error
        ];
        
        echo wp_json_encode($response, JSON_PRETTY_PRINT);
    }
    
    /**
     * Verify payment header
     */
    private static function verify_payment($payment_header, $requirements) {
        // Decode base64 payment payload
        $decoded = base64_decode($payment_header);
        if (!$decoded) {
            return ['isValid' => false, 'error' => 'Invalid base64 encoding'];
        }
        
        $payload = json_decode($decoded, true);
        if (!$payload) {
            return ['isValid' => false, 'error' => 'Invalid JSON payload'];
        }
        
        // For now, forward to backend for verification
        $api = new API();
        $result = $api->verify_payment($payload, $requirements);
        
        return $result;
    }
    
    /**
     * Log successful agent payment
     */
    private static function log_agent_payment($post_id, $verification, $context) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'angreessen49_agent_logs';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $table_name,
            [
                'post_id' => $post_id,
                'agent_name' => $context['agent_name'] ?? 'Unknown',
                'user_agent' => Helpers::get_sanitized_user_agent(),
                'ip_address' => Helpers::get_validated_ip(),
                'payment_status' => 'paid',
                'amount_paid' => $verification['amount'] ?? 0,
                'tx_hash' => $verification['tx_hash'] ?? null,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s']
        );
    }
}
