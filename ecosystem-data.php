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
if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'angreessen49_nonce')) {
    wp_send_json_error(['message' => 'Invalid nonce']);
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_send_json_error(['message' => 'Unauthorized']);
    exit;
}

// Get timeframe parameter
$angreessen49_timeframe = in_array($_POST['timeframe'] ?? '30d', ['7d', '30d', '90d', 'all'], true) 
    ? sanitize_text_field(wp_unslash($_POST['timeframe'])) 
    : '30d';

// Get API key
$angreessen49_api_key = get_option('angreessen49_api_key');
if (empty($angreessen49_api_key)) {
    wp_send_json_error(['message' => 'API key not configured']);
    exit;
}

// Get site ID
$angreessen49_site_id = get_option('angreessen49_site_id');
if (empty($angreessen49_site_id)) {
    wp_send_json_error(['message' => 'Site not registered']);
    exit;
}

// Check last-known-good cache
$angreessen49_cache_key = 'angreessen_ecosystem_last_good_' . $angreessen49_timeframe;
$angreessen49_cached = get_transient($angreessen49_cache_key);

// Direct call to wordpress-ecosystem-stats via api.402links.com proxy
$angreessen49_edge_url = 'https://api.402links.com/v1/wordpress-ecosystem-stats';

$angreessen49_request_body = [
    'timeframe' => $angreessen49_timeframe
];

$angreessen49_start_time = microtime(true);

// Retry logic for handling cold starts and transient failures
$angreessen49_max_retries = 2;
$angreessen49_retry_count = 0;
$angreessen49_response = null;

while ($angreessen49_retry_count <= $angreessen49_max_retries) {
    $angreessen49_response = wp_remote_post($angreessen49_edge_url, [
        'timeout' => 15, // Increased from 10 to 15 seconds for cold starts
        'redirection' => 0, // Disable redirects to prevent delays
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $angreessen49_api_key,
            'x-site-id' => $angreessen49_site_id
        ],
        'body' => json_encode($angreessen49_request_body),
        'sslverify' => true // Ensure SSL verification
    ]);
    
    // If successful or last retry, break
    if (!is_wp_error($angreessen49_response) || $angreessen49_retry_count === $angreessen49_max_retries) {
        break;
    }
    
    // Exponential backoff: 500ms, 1000ms, 2000ms
    $angreessen49_retry_count++;
    $angreessen49_wait_ms = pow(2, $angreessen49_retry_count) * 250;
    usleep($angreessen49_wait_ms * 1000);
}

// Handle errors - serve last-known-good if available
if (is_wp_error($angreessen49_response)) {
    $angreessen49_error_message = $angreessen49_response->get_error_message();
    $angreessen49_error_code = $angreessen49_response->get_error_code();
    
    if ($angreessen49_cached) {
        $angreessen49_cached['cache'] = ($angreessen49_cached['cache'] ?? []) + [
            'served_from_wp_cache' => true, 
            'stale' => true,
            'error_reason' => $angreessen49_error_code
        ];
        header('Content-Type: application/json');
        echo json_encode($angreessen49_cached);
        exit;
    }
    
    wp_send_json_error([
        'message' => 'edge_unreachable',
        'error' => $angreessen49_error_message,
        'error_code' => $angreessen49_error_code
    ]);
    exit;
}

$angreessen49_status_code = wp_remote_retrieve_response_code($angreessen49_response);
$angreessen49_body = wp_remote_retrieve_body($angreessen49_response);

if ($angreessen49_status_code !== 200) {
    if ($angreessen49_cached) {
        $angreessen49_cached['cache'] = ($angreessen49_cached['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
        header('Content-Type: application/json');
        echo json_encode($angreessen49_cached);
        exit;
    }
    
    wp_send_json_error(['message' => 'bad_edge_response', 'status_code' => $angreessen49_status_code]);
    exit;
}

// Parse response
$angreessen49_data = json_decode($angreessen49_body, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($angreessen49_data)) {
    if ($angreessen49_cached) {
        $angreessen49_cached['cache'] = ($angreessen49_cached['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
        header('Content-Type: application/json');
        echo json_encode($angreessen49_cached);
        exit;
    }
    
    wp_send_json_error(['message' => 'invalid_json']);
    exit;
}

// If edge claims success with data, cache it
if (!empty($angreessen49_data['success']) && !empty($angreessen49_data['data'])) {
    set_transient($angreessen49_cache_key, $angreessen49_data, 5 * MINUTE_IN_SECONDS);
} elseif ($angreessen49_cached) {
    // Edge not happy but we have last-known-good
    $angreessen49_data = $angreessen49_cached;
    $angreessen49_data['cache'] = ($angreessen49_data['cache'] ?? []) + ['served_from_wp_cache' => true, 'stale' => true];
}

// Return response
header('Content-Type: application/json');
echo json_encode($angreessen49_data);
exit;
