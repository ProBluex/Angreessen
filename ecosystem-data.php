<?php
/**
 * Direct Ecosystem Data Endpoint
 * 
 * Bypasses Admin.php and API.php to directly call wordpress-ecosystem-stats edge function.
 * This isolates the data flow for debugging ecosystem statistics display issues.
 */

// Load WordPress core
require_once(dirname(__FILE__) . '/../../../wp-load.php');

// Security check
if (!defined('ABSPATH')) {
    die('Direct access not allowed');
}

// Prevent PHP notices/warnings from printing on JSON endpoints
// This ensures headers can be sent cleanly for JSON responses
if (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
    @ini_set('display_errors', '0');
}

// Verify nonce
if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'agent_hub_nonce')) {
    wp_send_json_error(['message' => 'Invalid nonce']);
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized']);
    exit;
}

// Get timeframe parameter
$timeframe = in_array($_POST['timeframe'] ?? '30d', ['7d', '30d', '90d', 'all'], true) 
    ? sanitize_text_field($_POST['timeframe']) 
    : '30d';

error_log('[ecosystem-data.php] 🌍 Direct ecosystem request for timeframe: ' . $timeframe);

// Get API key
$api_key = get_option('402links_api_key');
if (empty($api_key)) {
    error_log('[ecosystem-data.php] ❌ No API key found');
    wp_send_json_error(['message' => 'API key not configured']);
    exit;
}

// Get site ID
$site_id = get_option('402links_site_id');
if (empty($site_id)) {
    error_log('[ecosystem-data.php] ❌ No site ID found');
    wp_send_json_error(['message' => 'Site not registered']);
    exit;
}

// Check last-known-good cache
$cache_key = 'tolliver_ecosystem_last_good_' . $timeframe;
$cached = get_transient($cache_key);

// Direct call to wordpress-ecosystem-stats edge function
$edge_function_url = 'https://cnionwnknwnzpwfuacse.supabase.co/functions/v1/wordpress-ecosystem-stats';

$request_body = [
    'timeframe' => $timeframe
];

$start_time = microtime(true);

$response = wp_remote_post($edge_function_url, [
    'timeout' => 8,
    'headers' => [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
        'x-site-id' => $site_id
    ],
    'body' => json_encode($request_body)
]);

// Handle errors - serve last-known-good if available
if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    error_log('[ecosystem-data.php] ❌ WP Error: ' . $error_message);
    
    if ($cached) {
        error_log('[ecosystem-data.php] ⚠️ Serving cached data (WP error fallback)');
        $cached['cache'] = ($cached['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
        header('Content-Type: application/json');
        echo json_encode($cached);
        exit;
    }
    
    wp_send_json_error(['message' => 'edge_unreachable']);
    exit;
}

$status_code = wp_remote_retrieve_response_code($response);
$body = wp_remote_retrieve_body($response);
$elapsed_time = round((microtime(true) - $start_time) * 1000);

error_log('[ecosystem-data.php] Response: ' . $status_code . ' in ' . $elapsed_time . 'ms');

if ($status_code !== 200) {
    error_log('[ecosystem-data.php] ❌ Non-200 status: ' . $status_code);
    
    if ($cached) {
        error_log('[ecosystem-data.php] ⚠️ Serving cached data (non-200 fallback)');
        $cached['cache'] = ($cached['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
        header('Content-Type: application/json');
        echo json_encode($cached);
        exit;
    }
    
    wp_send_json_error(['message' => 'bad_edge_response', 'status_code' => $status_code]);
    exit;
}

// Parse response
$data = json_decode($body, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    error_log('[ecosystem-data.php] ❌ JSON decode error: ' . json_last_error_msg());
    
    if ($cached) {
        error_log('[ecosystem-data.php] ⚠️ Serving cached data (JSON error fallback)');
        $cached['cache'] = ($cached['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
        header('Content-Type: application/json');
        echo json_encode($cached);
        exit;
    }
    
    wp_send_json_error(['message' => 'invalid_json']);
    exit;
}

// If edge claims success with data, cache it
if (!empty($data['success']) && !empty($data['data'])) {
    set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
    error_log('[ecosystem-data.php] ✅ Cached fresh data');
} elseif ($cached) {
    // Edge not happy but we have last-known-good
    error_log('[ecosystem-data.php] ⚠️ Serving cached data (edge success:false fallback)');
    $data = $cached;
    $data['cache'] = ($data['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
}

// Return response
header('Content-Type: application/json');
echo json_encode($data);
exit;
