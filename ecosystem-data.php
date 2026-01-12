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
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Verify nonce
if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'agent_hub_nonce')) {
    wp_send_json_error(['message' => 'Invalid nonce']);
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized']);
    exit;
}

// Get timeframe parameter
$agent_hub_timeframe = in_array($_POST['timeframe'] ?? '30d', ['7d', '30d', '90d', 'all'], true) 
    ? sanitize_text_field(wp_unslash($_POST['timeframe'])) 
    : '30d';

// Get API key
$agent_hub_api_key = get_option('402links_api_key');
if (empty($agent_hub_api_key)) {
    wp_send_json_error(['message' => 'API key not configured']);
    exit;
}

// Get site ID
$agent_hub_site_id = get_option('402links_site_id');
if (empty($agent_hub_site_id)) {
    wp_send_json_error(['message' => 'Site not registered']);
    exit;
}

// Check last-known-good cache
$agent_hub_cache_key = 'angreessen_ecosystem_last_good_' . $agent_hub_timeframe;
$agent_hub_cached = get_transient($agent_hub_cache_key);

// Direct call to wordpress-ecosystem-stats via api.402links.com proxy
$agent_hub_edge_url = 'https://api.402links.com/v1/wordpress-ecosystem-stats';

$agent_hub_request_body = [
    'timeframe' => $agent_hub_timeframe
];

$agent_hub_start_time = microtime(true);

// Retry logic for handling cold starts and transient failures
$agent_hub_max_retries = 2;
$agent_hub_retry_count = 0;
$agent_hub_response = null;

while ($agent_hub_retry_count <= $agent_hub_max_retries) {
    $agent_hub_response = wp_remote_post($agent_hub_edge_url, [
        'timeout' => 15, // Increased from 10 to 15 seconds for cold starts
        'redirection' => 0, // Disable redirects to prevent delays
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $agent_hub_api_key,
            'x-site-id' => $agent_hub_site_id
        ],
        'body' => json_encode($agent_hub_request_body),
        'sslverify' => true // Ensure SSL verification
    ]);
    
    // If successful or last retry, break
    if (!is_wp_error($agent_hub_response) || $agent_hub_retry_count === $agent_hub_max_retries) {
        break;
    }
    
    // Exponential backoff: 500ms, 1000ms, 2000ms
    $agent_hub_retry_count++;
    $agent_hub_wait_ms = pow(2, $agent_hub_retry_count) * 250;
    usleep($agent_hub_wait_ms * 1000);
}

// Handle errors - serve last-known-good if available
if (is_wp_error($agent_hub_response)) {
    $agent_hub_error_message = $agent_hub_response->get_error_message();
    $agent_hub_error_code = $agent_hub_response->get_error_code();
    
    if ($agent_hub_cached) {
        $agent_hub_cached['cache'] = ($agent_hub_cached['cache'] ?? []) + [
            'served_from_wp_cache' => true, 
            'stale' => true,
            'error_reason' => $agent_hub_error_code
        ];
        header('Content-Type: application/json');
        echo json_encode($agent_hub_cached);
        exit;
    }
    
    wp_send_json_error([
        'message' => 'edge_unreachable',
        'error' => $agent_hub_error_message,
        'error_code' => $agent_hub_error_code
    ]);
    exit;
}

$agent_hub_status_code = wp_remote_retrieve_response_code($agent_hub_response);
$agent_hub_body = wp_remote_retrieve_body($agent_hub_response);

if ($agent_hub_status_code !== 200) {
    if ($agent_hub_cached) {
        $agent_hub_cached['cache'] = ($agent_hub_cached['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
        header('Content-Type: application/json');
        echo json_encode($agent_hub_cached);
        exit;
    }
    
    wp_send_json_error(['message' => 'bad_edge_response', 'status_code' => $agent_hub_status_code]);
    exit;
}

// Parse response
$agent_hub_data = json_decode($agent_hub_body, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($agent_hub_data)) {
    if ($agent_hub_cached) {
        $agent_hub_cached['cache'] = ($agent_hub_cached['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
        header('Content-Type: application/json');
        echo json_encode($agent_hub_cached);
        exit;
    }
    
    wp_send_json_error(['message' => 'invalid_json']);
    exit;
}

// If edge claims success with data, cache it
if (!empty($agent_hub_data['success']) && !empty($agent_hub_data['data'])) {
    set_transient($agent_hub_cache_key, $agent_hub_data, 5 * MINUTE_IN_SECONDS);
} elseif ($agent_hub_cached) {
    // Edge not happy but we have last-known-good
    $agent_hub_data = $agent_hub_cached;
    $agent_hub_data['cache'] = ($agent_hub_data['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
}

// Return response
header('Content-Type: application/json');
echo json_encode($agent_hub_data);
exit;
