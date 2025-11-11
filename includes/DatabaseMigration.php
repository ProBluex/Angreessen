<?php
namespace AgentHub;

class DatabaseMigration {
    const VERSION_OPTION = '402links_db_version';
    const CURRENT_VERSION = '1.0.0';
    
    /**
     * Run all pending migrations
     */
    public static function run() {
        $current_version = get_option(self::VERSION_OPTION, '0.0.0');
        
        if (version_compare($current_version, self::CURRENT_VERSION, '<')) {
            self::create_batch_queue_tables();
            update_option(self::VERSION_OPTION, self::CURRENT_VERSION);
        }
    }
    
    /**
     * Create batch queue and jobs tables
     */
    private static function create_batch_queue_tables() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Batch jobs table (batch-level tracking)
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        $jobs_sql = "CREATE TABLE IF NOT EXISTS $jobs_table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(40) NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NOT NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'foreground',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            total_posts INT UNSIGNED NOT NULL DEFAULT 0,
            completed_posts INT UNSIGNED NOT NULL DEFAULT 0,
            failed_posts INT UNSIGNED NOT NULL DEFAULT 0,
            created_posts INT UNSIGNED NOT NULL DEFAULT 0,
            updated_posts INT UNSIGNED NOT NULL DEFAULT 0,
            notification_sent TINYINT(1) NOT NULL DEFAULT 0,
            notification_email VARCHAR(255),
            started_at DATETIME,
            completed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_batch_id (batch_id),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) $charset_collate;";
        
        dbDelta($jobs_sql);
        
        // Batch queue table (per-post tracking)
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        $queue_sql = "CREATE TABLE IF NOT EXISTS $queue_table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(40) NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
            error_message TEXT,
            result_data TEXT,
            processed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_batch_id (batch_id),
            INDEX idx_post_id (post_id),
            INDEX idx_status (status),
            INDEX idx_batch_status (batch_id, status),
            UNIQUE KEY unique_batch_post (batch_id, post_id)
        ) $charset_collate;";
        
        dbDelta($queue_sql);
    }
    
    /**
     * Cleanup old completed batches (30+ days)
     */
    public static function cleanup_old_batches() {
        global $wpdb;
        
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        // Get batch IDs older than 30 days with completed/cancelled/failed status
        $old_batch_ids = $wpdb->get_col("
            SELECT batch_id 
            FROM $jobs_table 
            WHERE status IN ('completed', 'cancelled', 'failed')
            AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            LIMIT 100
        ");
        
        if (!empty($old_batch_ids)) {
            $placeholders = implode(',', array_fill(0, count($old_batch_ids), '%s'));
            
            // Delete queue entries
            $wpdb->query($wpdb->prepare("
                DELETE FROM $queue_table 
                WHERE batch_id IN ($placeholders)
            ", $old_batch_ids));
            
            // Delete job entries
            $wpdb->query($wpdb->prepare("
                DELETE FROM $jobs_table 
                WHERE batch_id IN ($placeholders)
            ", $old_batch_ids));
            
            error_log('🗑️ [DatabaseMigration] Cleaned up ' . count($old_batch_ids) . ' old batch records');
        }
    }
}
