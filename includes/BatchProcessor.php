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
        DevLogger::log('BATCH_PROCESSOR', 'batch_start_initiated', [
            'batch_size' => self::BATCH_SIZE,
            'max_execution_time' => self::MAX_EXECUTION_TIME
        ]);
        
        // Get total count of posts without 402links
        $total_posts = self::get_pending_post_count();
        
        DevLogger::log('BATCH_PROCESSOR', 'pending_posts_counted', [
            'total_posts' => $total_posts,
            'query_type' => 'published_posts'
        ]);
        
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
        DevLogger::log('CACHE', 'set_transient', [
            'key' => self::PROGRESS_KEY,
            'expiration' => 3600,
            'initial_progress' => $progress
        ]);
        
        DevLogger::log('BATCH_PROCESSOR', 'batch_started', [
            'total_posts' => $total_posts,
            'status' => 'running'
        ]);
        
        return $progress;
    }
    
    /**
     * Process next batch of posts
     */
    public static function process_next_batch() {
        $progress = get_transient(self::PROGRESS_KEY);
        DevLogger::log('CACHE', 'get_transient', [
            'key' => self::PROGRESS_KEY,
            'hit' => $progress !== false,
            'status' => $progress['status'] ?? 'not_found'
        ]);
        
        if (!$progress || $progress['status'] !== 'running') {
            DevLogger::log('ERROR', 'batch_processor_error', [
                'error' => 'No active batch process',
                'progress_exists' => $progress !== false
            ]);
            return ['success' => false, 'error' => 'No active batch process'];
        }
        
        $start_time = time();
        DevLogger::log('BATCH_PROCESSOR', 'batch_chunk_start', [
            'offset' => $progress['current_offset'],
            'batch_size' => self::BATCH_SIZE,
            'already_processed' => $progress['processed']
        ]);
        
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
            
            DevLogger::log('BATCH_PROCESSOR', 'batch_completed', [
                'total_processed' => $progress['processed'],
                'created' => $progress['created'],
                'updated' => $progress['updated'],
                'failed' => $progress['failed']
            ]);
            DevLogger::log('CACHE', 'set_transient', [
                'key' => self::PROGRESS_KEY,
                'final_progress' => $progress
            ]);
            
            return ['success' => true, 'completed' => true, 'progress' => $progress];
        }
        
        DevLogger::log('BATCH_PROCESSOR', 'batch_chunk_processing', [
            'post_count' => count($posts),
            'post_ids' => $posts
        ]);
        
        // Process batch
        foreach ($posts as $post_id) {
            // Check timeout
            if ((time() - $start_time) > self::MAX_EXECUTION_TIME) {
                DevLogger::log('BATCH_PROCESSOR', 'batch_timeout', [
                    'elapsed' => time() - $start_time,
                    'max_time' => self::MAX_EXECUTION_TIME,
                    'processed_in_chunk' => $progress['processed']
                ]);
                break;
            }
            
            $link_id = get_post_meta($post_id, '_402links_id', true);
            DevLogger::log('DB', 'get_post_meta', [
                'post_id' => $post_id,
                'meta_key' => '_402links_id',
                'value' => $link_id,
                'found' => !empty($link_id)
            ]);
            
            $api = new API();
            
            if ($link_id) {
                DevLogger::log('BATCH_PROCESSOR', 'post_update_start', [
                    'post_id' => $post_id,
                    'link_id' => $link_id
                ]);
                
                $result = $api->update_link($post_id, $link_id);
                if ($result['success']) {
                    $progress['updated']++;
                    DevLogger::log('BATCH_PROCESSOR', 'post_updated', [
                        'post_id' => $post_id,
                        'link_id' => $link_id
                    ]);
                } else {
                    $progress['failed']++;
                    $progress['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown');
                    DevLogger::log('ERROR', 'batch_processor_error', [
                        'action' => 'update_link',
                        'post_id' => $post_id,
                        'error' => $result['error'] ?? 'Unknown'
                    ]);
                }
            } else {
                DevLogger::log('BATCH_PROCESSOR', 'post_create_start', [
                    'post_id' => $post_id
                ]);
                
                $result = ContentSync::create_link($post_id);
                if ($result['success']) {
                    $progress['created']++;
                    DevLogger::log('BATCH_PROCESSOR', 'post_created', [
                        'post_id' => $post_id,
                        'link_id' => $result['link_id'] ?? null
                    ]);
                } else {
                    $progress['failed']++;
                    $progress['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown');
                    DevLogger::log('ERROR', 'batch_processor_error', [
                        'action' => 'create_link',
                        'post_id' => $post_id,
                        'error' => $result['error'] ?? 'Unknown'
                    ]);
                }
            }
            
            $progress['processed']++;
        }
        
        $progress['current_offset'] += self::BATCH_SIZE;
        $progress['updated_at'] = current_time('mysql');
        set_transient(self::PROGRESS_KEY, $progress, 3600);
        
        DevLogger::log('BATCH_PROCESSOR', 'batch_chunk_completed', [
            'total_processed' => $progress['processed'],
            'created_in_chunk' => $progress['created'],
            'updated_in_chunk' => $progress['updated'],
            'failed_in_chunk' => $progress['failed'],
            'next_offset' => $progress['current_offset']
        ]);
        DevLogger::log('CACHE', 'set_transient', [
            'key' => self::PROGRESS_KEY,
            'progress' => $progress
        ]);
        
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
        DevLogger::log('CACHE', 'get_transient', [
            'key' => self::PROGRESS_KEY,
            'hit' => $progress !== false
        ]);
        
        if ($progress) {
            $progress['status'] = 'cancelled';
            $progress['updated_at'] = current_time('mysql');
            set_transient(self::PROGRESS_KEY, $progress, 3600);
            
            DevLogger::log('BATCH_PROCESSOR', 'batch_cancelled', [
                'processed' => $progress['processed'],
                'created' => $progress['created'],
                'updated' => $progress['updated']
            ]);
            DevLogger::log('CACHE', 'set_transient', [
                'key' => self::PROGRESS_KEY,
                'cancelled_progress' => $progress
            ]);
        }
        
        return ['success' => true];
    }
    
    /**
     * Get count of posts (POSTS ONLY)
     */
    private static function get_pending_post_count() {
        global $wpdb;
        
        $query = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'post'
            AND p.post_status = 'publish'
        ";
        $count = $wpdb->get_var($query);
        
        DevLogger::log('DB', 'wpdb_query', [
            'query_type' => 'SELECT COUNT',
            'tables' => ['posts'],
            'conditions' => ['post_type=post', 'post_status=publish'],
            'result' => $count,
            'error' => $wpdb->last_error
        ]);
        
        return intval($count);
    }
}
