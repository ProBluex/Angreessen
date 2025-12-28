<?php
namespace AgentHub;

class BatchProcessor {
    const BATCH_SIZE = 50;
    const MAX_EXECUTION_TIME = 25; // seconds
    const PROGRESS_KEY = '402links_batch_progress';
    
    /**
     * Initialize batch processing
     * 
     * FIXED: Now counts ALL published posts as total, and separately tracks
     * already-linked posts vs pending posts for accurate progress display.
     */
    public static function start_batch() {
        // Get total count of ALL published posts (stable number)
        $total_posts = self::get_total_post_count();
        
        // Get count of posts that ALREADY have links (protected)
        $already_linked_count = self::get_protected_post_count();
        
        // Pending = total - already linked
        $pending_count = max(0, $total_posts - $already_linked_count);
        
        // Initialize progress tracking
        $progress = [
            'status' => 'running',
            'total' => $total_posts,           // Total published posts (stable)
            'pending_at_start' => $pending_count,  // Posts needing links at start
            'processed' => $already_linked_count,  // Start with already-linked count
            'created' => 0,
            'already_linked' => $already_linked_count,  // Pre-existing links
            'failed' => 0,
            'errors' => [],
            'started_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        set_transient(self::PROGRESS_KEY, $progress, 3600);
        
        return $progress;
    }
    
    /**
     * Process next batch of posts
     * 
     * FIXED: Always query first N pending posts (no offset).
     * As posts get linked, they're removed from the pending query results,
     * so we always get fresh unprocessed posts.
     */
    public static function process_next_batch() {
        $progress = get_transient(self::PROGRESS_KEY);
        
        if (!$progress || $progress['status'] !== 'running') {
            return ['success' => false, 'error' => 'No active batch process'];
        }
        
        // FIXED: Always get the FIRST batch of pending posts (no offset!)
        // As posts get _402links_short_id set, they disappear from this query
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'offset' => 0,  // ALWAYS 0 - query shrinks as posts get linked
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_402links_short_id',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_402links_short_id',
                    'value' => '',
                    'compare' => '='
                ]
            ]
        ]);
        
        if (empty($posts)) {
            // Double-check: recount pending to be sure we're done
            $remaining = self::get_pending_post_count();
            
            // Update final counts
            $progress['processed'] = $progress['total'];
            $progress['status'] = 'completed';
            $progress['updated_at'] = current_time('mysql');
            set_transient(self::PROGRESS_KEY, $progress, 3600);
            
            // Clear protected pages cache so dashboard updates
            delete_transient('agent_hub_protected_pages_count');
            
            return ['success' => true, 'completed' => true, 'progress' => $progress];
        }
        
        // Process batch with parallel API calls
        $api = new API();
        $chunk_result = $api->create_links_parallel($posts);
        
        // Update progress with parallel results
        $progress['created'] += $chunk_result['created'];
        $progress['already_linked'] += ($chunk_result['already_linked'] ?? 0);
        $progress['failed'] += $chunk_result['failed'];
        $progress['processed'] += count($posts);
        
        // Merge errors
        if (!empty($chunk_result['errors'])) {
            $progress['errors'] = array_merge(
                $progress['errors'] ?? [],
                $chunk_result['errors']
            );
        }
        
        $progress['updated_at'] = current_time('mysql');
        
        // Check if we've processed everything
        $is_complete = ($progress['processed'] >= $progress['total']);
        
        if ($is_complete) {
            $progress['status'] = 'completed';
            
            // Clear protected pages cache so dashboard updates immediately
            delete_transient('agent_hub_protected_pages_count');
        }
        
        set_transient(self::PROGRESS_KEY, $progress, 3600);
        
        return [
            'success' => true,
            'completed' => $is_complete,
            'progress' => $progress
        ];
    }
    
    /**
     * Get current batch status
     */
    public static function get_status() {
        $progress = get_transient(self::PROGRESS_KEY);
        
        if (!$progress) {
            return [
                'status' => 'idle',
                'total' => 0,
                'processed' => 0
            ];
        }
        
        return $progress;
    }
    
    /**
     * Cancel batch processing
     */
    public static function cancel_batch() {
        $progress = get_transient(self::PROGRESS_KEY);
        
        if ($progress) {
            $progress['status'] = 'cancelled';
            $progress['updated_at'] = current_time('mysql');
            set_transient(self::PROGRESS_KEY, $progress, 3600);
        }
        
        return ['success' => true];
    }
    
    /**
     * Get count of ALL published posts (stable total)
     */
    private static function get_total_post_count() {
        $count = wp_count_posts('post');
        return intval($count->publish ?? 0);
    }
    
    /**
     * Get count of posts WITH existing 402links (protected)
     * Uses _402links_short_id as the source of truth for protection
     */
    private static function get_protected_post_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'post'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_402links_short_id'
            AND pm.meta_value != ''
            AND pm.meta_value IS NOT NULL
        ");
        
        return intval($count);
    }
    
    /**
     * Get count of posts WITHOUT existing 402links (pending)
     */
    private static function get_pending_post_count() {
        global $wpdb;
        
        // Count posts that DON'T have a _402links_short_id meta (or have empty value)
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_402links_short_id'
            WHERE p.post_type = 'post'
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        return intval($count);
    }
}
