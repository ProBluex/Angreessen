<?php
namespace AgentHub;

class BatchProcessor {
    const BATCH_SIZE = 10;
    const MAX_EXECUTION_TIME = 20; // seconds
    const PROGRESS_KEY = '402links_batch_progress';
    
    /**
     * Initialize batch processing
     */
    public static function start_batch() {
        // Get total count of posts without 402links
        $total_posts = self::get_pending_post_count();
        
        // Initialize progress tracking
        $progress = [
            'status' => 'running',
            'total' => $total_posts,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'current_offset' => 0,
            'errors' => [],
            'started_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        set_transient(self::PROGRESS_KEY, $progress, 3600);
        
        return $progress;
    }
    
    /**
     * Process next batch of posts
     */
    public static function process_next_batch() {
        $progress = get_transient(self::PROGRESS_KEY);
        
        if (!$progress || $progress['status'] !== 'running') {
            return ['success' => false, 'error' => 'No active batch process'];
        }
        
        $start_time = time();
        
        // Get next batch of posts (POSTS ONLY, not pages)
        $posts = get_posts([
            'post_type' => 'post',  // ✅ ONLY posts
            'post_status' => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'offset' => $progress['current_offset'],
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC'
        ]);
        
        if (empty($posts)) {
            // All done
            $progress['status'] = 'completed';
            $progress['updated_at'] = current_time('mysql');
            set_transient(self::PROGRESS_KEY, $progress, 3600);
            return ['success' => true, 'completed' => true, 'progress' => $progress];
        }
        
        // Process batch
        foreach ($posts as $post_id) {
            // Check timeout
            if ((time() - $start_time) > self::MAX_EXECUTION_TIME) {
                break;
            }
            
            $link_id = get_post_meta($post_id, '_402links_id', true);
            $api = new API();
            
            if ($link_id) {
                $result = $api->update_link($post_id, $link_id);
                if ($result['success']) {
                    $progress['updated']++;
                } else {
                    $progress['failed']++;
                    $progress['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown');
                }
            } else {
                $result = ContentSync::create_link($post_id);
                if ($result['success']) {
                    $progress['created']++;
                } else {
                    $progress['failed']++;
                    $progress['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown');
                }
            }
            
            $progress['processed']++;
        }
        
        $progress['current_offset'] += self::BATCH_SIZE;
        $progress['updated_at'] = current_time('mysql');
        set_transient(self::PROGRESS_KEY, $progress, 3600);
        
        return [
            'success' => true,
            'completed' => false,
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
     * Get count of posts (POSTS ONLY)
     */
    private static function get_pending_post_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'post'
            AND p.post_status = 'publish'
        ");
        
        return intval($count);
    }
}
