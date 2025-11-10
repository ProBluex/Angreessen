<?php
namespace AgentHub;

/**
 * Dev-Only Debug Logger
 * 
 * Controlled by TOLLIVER_DEV_MODE constant in tolliver-agent.php
 * Set to true for development, false for production
 * 
 * Enhanced Category Guide:
 * 
 * CORE - Plugin initialization, hooks registration
 * INSTALL - Plugin activation, provisioning, migrations
 * PROVISION - Site provisioning, API key generation
 * BATCH_PROCESSOR - Batch operations, chunking, progress
 * LINK_CREATE - Link creation attempts, pre-validation
 * LINK_UPDATE - Link update attempts
 * API - HTTP requests, responses, payload details
 * ERROR - All failures with full context
 * VALIDATION - Data validation, pre-flight checks
 * PERFORMANCE - Timeout warnings, slow operations
 * DB - Database queries, meta operations
 * CACHE - Transient operations
 * EDGE_FUNCTION - Edge function calls, responses
 * AJAX - AJAX handler start/success/error
 * PAYMENT_GATE - Payment gate checks, access control
 */
class DevLogger {
    private static $logs = [];
    private static $initialized = false;
    
    /**
     * Log an event
     * 
     * @param string $category Category: INSTALL, PROVISION, API, LINK_CREATE, LINK_UPDATE, BATCH, POST_SYNC, EDGE_FUNCTION, ERROR
     * @param string $action Specific action being performed
     * @param mixed $data Additional data to log
     */
    public static function log($category, $action, $data = null) {
        // Zero overhead when disabled
        if (!defined('TOLLIVER_DEV_MODE') || !TOLLIVER_DEV_MODE) {
            return;
        }
        
        if (!self::$initialized) {
            self::load_logs();
        }
        
        $entry = [
            'timestamp' => microtime(true),
            'datetime' => current_time('mysql'),
            'category' => $category,
            'action' => $action,
            'data' => $data
        ];
        
        self::$logs[] = $entry;
        
        // In DEV MODE, save IMMEDIATELY for real-time comprehensive logging
        self::save_logs();
        
        // Also log to error_log for real-time debugging
        $log_message = sprintf(
            '[DevLogger] [%s] %s: %s',
            $category,
            $action,
            is_array($data) || is_object($data) ? json_encode($data) : $data
        );
        error_log($log_message);
    }
    
    /**
     * Get all logs
     */
    public static function get_logs() {
        if (!self::$initialized) {
            self::load_logs();
        }
        return self::$logs;
    }
    
    /**
     * Clear all logs
     */
    public static function clear_logs() {
        self::$logs = [];
        delete_option('tolliver_dev_logs');
    }
    
    /**
     * Load logs from database
     */
    private static function load_logs() {
        self::$logs = get_option('tolliver_dev_logs', []);
        self::$initialized = true;
        
        // Limit to last 1000 entries to prevent memory issues
        if (count(self::$logs) > 1000) {
            self::$logs = array_slice(self::$logs, -1000);
        }
    }
    
    /**
     * Save logs to database
     */
    private static function save_logs() {
        // Limit to last 1000 entries before saving
        if (count(self::$logs) > 1000) {
            self::$logs = array_slice(self::$logs, -1000);
        }
        
        update_option('tolliver_dev_logs', self::$logs, false); // autoload = false
    }
    
    /**
     * Force save logs (called on shutdown)
     */
    public static function shutdown_save() {
        if (!defined('TOLLIVER_DEV_MODE') || !TOLLIVER_DEV_MODE) {
            return;
        }
        
        if (!empty(self::$logs)) {
            self::save_logs();
        }
    }
}

// Register shutdown hook to save logs on script end
if (defined('TOLLIVER_DEV_MODE') && TOLLIVER_DEV_MODE) {
    register_shutdown_function(['\AgentHub\DevLogger', 'shutdown_save']);
}
