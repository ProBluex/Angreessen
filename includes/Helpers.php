<?php
namespace AgentHub;

class Helpers {
    /**
     * Get post title with HTML entities decoded for clean display/storage
     * 
     * @param int $post_id WordPress post ID
     * @return string Decoded title
     */
    public static function get_clean_title($post_id) {
        return html_entity_decode(get_the_title($post_id), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * Get sanitized user agent from $_SERVER
     * 
     * @return string Sanitized user agent
     */
    public static function get_sanitized_user_agent() {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
    
    /**
     * Get validated and sanitized IP address from $_SERVER
     * Checks common proxy headers in order of reliability
     * 
     * @return string Validated IP address or empty string
     */
    public static function get_validated_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                // Handle comma-separated IPs (X-Forwarded-For can have multiple)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Get sanitized request URI from $_SERVER
     * 
     * @return string Sanitized request URI
     */
    public static function get_sanitized_request_uri() {
        return esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    }
    
    /**
     * Get sanitized HTTP referer from $_SERVER
     * 
     * @return string Sanitized referer URL
     */
    public static function get_sanitized_referer() {
        return esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'] ?? ''));
    }
    
    /**
     * Get sanitized request method from $_SERVER
     * 
     * @return string Sanitized request method
     */
    public static function get_sanitized_request_method() {
        return sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}
