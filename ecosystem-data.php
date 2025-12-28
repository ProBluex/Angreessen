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

// Get API key
$api_key = get_option('402links_api_key');
if (empty($api_key)) {
    wp_send_json_error(['message' => 'API key not configured']);
    exit;
}

// Get site ID
$site_id = get_option('402links_site_id');
if (empty($site_id)) {
    wp_send_json_error(['message' => 'Site not registered']);
    exit;
}

// Check last-known-good cache
$cache_key = 'angreessen_ecosystem_last_good_' . $timeframe;
$cached = get_transient($cache_key);

// Direct call to wordpress-ecosystem-stats edge function
$edge_function_url = 'https://cnionwnknwnzpwfuacse.supabase.co/functions/v1/wordpress-ecosystem-stats';

$request_body = [
    'timeframe' => $timeframe
];

$start_time = microtime(true);

// Retry logic for handling cold starts and transient failures
$max_retries = 2;
$retry_count = 0;
$response = null;

while ($retry_count <= $max_retries) {
    $response = wp_remote_post($edge_function_url, [
        'timeout' => 15, // Increased from 10 to 15 seconds for cold starts
        'redirection' => 0, // Disable redirects to prevent delays
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
            'x-site-id' => $site_id
        ],
        'body' => json_encode($request_body),
        'sslverify' => true // Ensure SSL verification
    ]);
    
    // If successful or last retry, break
    if (!is_wp_error($response) || $retry_count === $max_retries) {
        break;
    }
    
    // Exponential backoff: 500ms, 1000ms, 2000ms
    $retry_count++;
    $wait_ms = pow(2, $retry_count) * 250;
    usleep($wait_ms * 1000);
}

// Handle errors - serve last-known-good if available
if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    $error_code = $response->get_error_code();
    
    if ($cached) {
        $cached['cache'] = ($cached['cache'] ?? []) + [
            'served_from_wp_cache' => true, 
            'stale' => true,
            'error_reason' => $error_code
        ];
        header('Content-Type: application/json');
        echo json_encode($cached);
        exit;
    }
    
    wp_send_json_error([
        'message' => 'edge_unreachable',
        'error' => $error_message,
        'error_code' => $error_code
    ]);
    exit;
}

$status_code = wp_remote_retrieve_response_code($response);
$body = wp_remote_retrieve_body($response);

if ($status_code !== 200) {
    if ($cached) {
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
    if ($cached) {
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
} elseif ($cached) {
    // Edge not happy but we have last-known-good
    $data = $cached;
    $data['cache'] = ($data['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
}

// Return response
header('Content-Type: application/json');
echo json_encode($data);
exit;
