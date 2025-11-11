<?php
namespace AgentHub;

class BackgroundProcessor {
    const CHUNK_SIZE = 50;
    const MAX_ATTEMPTS = 3;
    const ACTION_PROCESS_BATCH = '402links_process_background_batch';
    const ACTION_FINALIZE_BATCH = '402links_finalize_background_batch';
    
    /**
     * Queue a new batch job
     */
    public static function queue_batch($post_ids, $mode = 'background') {
        global $wpdb;
        
        if (empty($post_ids)) {
            return ['success' => false, 'error' => 'No posts to process'];
        }
        
        $user = wp_get_current_user();
        $batch_id = self::generate_batch_id();
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        // Create batch job
        $wpdb->insert($jobs_table, [
            'batch_id' => $batch_id,
            'user_id' => $user->ID,
            'mode' => $mode,
            'status' => 'pending',
            'total_posts' => count($post_ids),
            'notification_email' => $user->user_email,
            'started_at' => current_time('mysql'),
            'created_at' => current_time('mysql')
        ]);
        
        // Queue individual posts
        foreach ($post_ids as $post_id) {
            $wpdb->insert($queue_table, [
                'batch_id' => $batch_id,
                'post_id' => $post_id,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ]);
        }
        
        // Schedule first chunk with Action Scheduler
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), self::ACTION_PROCESS_BATCH, [$batch_id], 'tolliver-batches');
            error_log("✅ [BackgroundProcessor] Scheduled batch {$batch_id} with " . count($post_ids) . " posts");
        } else {
            // Fallback: process immediately if Action Scheduler not available
            error_log("⚠️ [BackgroundProcessor] Action Scheduler not available, processing synchronously");
            self::process_batch($batch_id);
        }
        
        return [
            'success' => true,
            'batch_id' => $batch_id,
            'mode' => $mode,
            'total' => count($post_ids)
        ];
    }
    
    /**
     * Process next chunk of a batch
     */
    public static function process_batch($batch_id) {
        global $wpdb;
        
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        // Update job status to running
        $wpdb->update($jobs_table, 
            ['status' => 'running', 'updated_at' => current_time('mysql')],
            ['batch_id' => $batch_id]
        );
        
        // Get next chunk of pending posts
        $pending_posts = $wpdb->get_col($wpdb->prepare("
            SELECT post_id 
            FROM $queue_table 
            WHERE batch_id = %s 
            AND status = 'pending'
            ORDER BY id ASC
            LIMIT %d
        ", $batch_id, self::CHUNK_SIZE));
        
        if (empty($pending_posts)) {
            // No more posts, finalize batch
            self::finalize_batch($batch_id);
            return;
        }
        
        error_log("🔵 [BackgroundProcessor] Processing chunk of " . count($pending_posts) . " posts for batch {$batch_id}");
        
        // Mark posts as processing
        $placeholders = implode(',', array_fill(0, count($pending_posts), '%d'));
        $wpdb->query($wpdb->prepare("
            UPDATE $queue_table 
            SET status = 'processing', updated_at = %s
            WHERE batch_id = %s AND post_id IN ($placeholders)
        ", array_merge([current_time('mysql'), $batch_id], $pending_posts)));
        
        // Process posts using BatchProcessor logic
        $api = new API();
        $posts_to_create = [];
        $posts_to_update = [];
        
        foreach ($pending_posts as $post_id) {
            $link_id = get_post_meta($post_id, '_402links_id', true);
            if ($link_id) {
                $posts_to_update[] = ['post_id' => $post_id, 'link_id' => $link_id];
            } else {
                $posts_to_create[] = $post_id;
            }
        }
        
        // Bulk create new links
        if (!empty($posts_to_create)) {
            $bulk_data = self::prepare_bulk_create_data($posts_to_create);
            if (!empty($bulk_data)) {
                $bulk_result = $api->bulk_create_links($bulk_data);
                
                if ($bulk_result['success']) {
                    foreach ($bulk_result['results'] as $item) {
                        if ($item['success']) {
                            // Save metadata
                            if (isset($item['link_id'])) {
                                update_post_meta($item['wordpress_post_id'], '_402links_id', $item['link_id']);
                            }
                            if (isset($item['short_id'])) {
                                update_post_meta($item['wordpress_post_id'], '_402links_short_id', $item['short_id']);
                            }
                            if (isset($item['link_url'])) {
                                update_post_meta($item['wordpress_post_id'], '_402links_url', $item['link_url']);
                            }
                            update_post_meta($item['wordpress_post_id'], '_402links_synced_at', current_time('mysql'));
                            
                            // Mark as completed
                            self::mark_post_completed($batch_id, $item['wordpress_post_id'], 'created');
                        } else {
                            // Mark for retry
                            self::mark_post_failed($batch_id, $item['wordpress_post_id'], $item['error']);
                        }
                    }
                } else {
                    // Bulk API failed, mark all for retry
                    foreach ($posts_to_create as $post_id) {
                        self::mark_post_failed($batch_id, $post_id, $bulk_result['error'] ?? 'Bulk API failed');
                    }
                }
            }
        }
        
        // Update existing links
        foreach ($posts_to_update as $update_data) {
            $result = $api->update_link($update_data['post_id'], $update_data['link_id']);
            
            if ($result['success']) {
                update_post_meta($update_data['post_id'], '_402links_synced_at', current_time('mysql'));
                self::mark_post_completed($batch_id, $update_data['post_id'], 'updated');
            } else {
                self::mark_post_failed($batch_id, $update_data['post_id'], $result['error'] ?? 'Update failed');
            }
        }
        
        // Schedule next chunk
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 2, self::ACTION_PROCESS_BATCH, [$batch_id], 'tolliver-batches');
        } else {
            // Fallback: process next chunk immediately
            self::process_batch($batch_id);
        }
    }
    
    /**
     * Finalize completed batch
     */
    public static function finalize_batch($batch_id) {
        global $wpdb;
        
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        // Get final counts
        $stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'completed' AND result_data = 'created' THEN 1 END) as created,
                COUNT(CASE WHEN status = 'completed' AND result_data = 'updated' THEN 1 END) as updated,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed
            FROM $queue_table
            WHERE batch_id = %s
        ", $batch_id));
        
        // Update job record
        $wpdb->update($jobs_table, [
            'status' => 'completed',
            'completed_posts' => $stats->completed,
            'failed_posts' => $stats->failed,
            'created_posts' => $stats->created,
            'updated_posts' => $stats->updated,
            'completed_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ], ['batch_id' => $batch_id]);
        
        error_log("✅ [BackgroundProcessor] Batch {$batch_id} completed: {$stats->created} created, {$stats->updated} updated, {$stats->failed} failed");
        
        // Send notification
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), '402links_send_batch_notification', [$batch_id], 'tolliver-notifications');
        } else {
            Notifications::send_completion_email($batch_id);
        }
    }
    
    /**
     * Get batch progress
     */
    public static function get_batch_progress($batch_id) {
        global $wpdb;
        
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        $job = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM $jobs_table WHERE batch_id = %s
        ", $batch_id), ARRAY_A);
        
        if (!$job) {
            return null;
        }
        
        $queue_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending
            FROM $queue_table
            WHERE batch_id = %s
        ", $batch_id), ARRAY_A);
        
        return array_merge($job, $queue_stats);
    }
    
    /**
     * Get batch history for user
     */
    public static function get_batch_history($user_id, $limit = 20) {
        global $wpdb;
        
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $jobs_table 
            WHERE user_id = %d 
            ORDER BY created_at DESC 
            LIMIT %d
        ", $user_id, $limit), ARRAY_A);
    }
    
    /**
     * Retry failed posts from a batch
     */
    public static function retry_failed_posts($batch_id) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        $failed_post_ids = $wpdb->get_col($wpdb->prepare("
            SELECT post_id FROM $queue_table 
            WHERE batch_id = %s AND status = 'failed'
        ", $batch_id));
        
        if (empty($failed_post_ids)) {
            return ['success' => false, 'error' => 'No failed posts to retry'];
        }
        
        return self::queue_batch($failed_post_ids, 'background');
    }
    
    // Helper methods
    
    private static function generate_batch_id() {
        return 'batch_' . time() . '_' . wp_generate_password(8, false);
    }
    
    private static function mark_post_completed($batch_id, $post_id, $result_type) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        $wpdb->update($queue_table, [
            'status' => 'completed',
            'result_data' => $result_type,
            'processed_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ], [
            'batch_id' => $batch_id,
            'post_id' => $post_id
        ]);
    }
    
    private static function mark_post_failed($batch_id, $post_id, $error) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . '402links_batch_queue';
        
        // Get current attempts
        $queue_item = $wpdb->get_row($wpdb->prepare("
            SELECT attempts, max_attempts 
            FROM $queue_table 
            WHERE batch_id = %s AND post_id = %d
        ", $batch_id, $post_id));
        
        $attempts = $queue_item ? $queue_item->attempts + 1 : 1;
        $max_attempts = $queue_item ? $queue_item->max_attempts : self::MAX_ATTEMPTS;
        
        // If under max attempts, mark as pending for retry
        $status = ($attempts < $max_attempts) ? 'pending' : 'failed';
        
        $wpdb->update($queue_table, [
            'status' => $status,
            'attempts' => $attempts,
            'error_message' => $error,
            'updated_at' => current_time('mysql')
        ], [
            'batch_id' => $batch_id,
            'post_id' => $post_id
        ]);
        
        error_log("🔴 [BackgroundProcessor] Post {$post_id} marked as {$status} (attempt {$attempts}/{$max_attempts}): {$error}");
    }
    
    private static function prepare_bulk_create_data($post_ids) {
        $bulk_data = [];
        $settings = get_option('402links_settings');
        $default_price = $settings['default_price'] ?? 0.10;
        
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) continue;
            
            $price = get_post_meta($post_id, '_402links_price', true);
            if (empty($price)) {
                $price = $default_price;
            }
            
            $excerpt = $post->post_excerpt;
            if (empty($excerpt)) {
                $excerpt = wp_trim_words(strip_tags($post->post_content), 30);
            }
            
            $author = get_the_author_meta('display_name', $post->post_author);
            $featured_image_url = get_the_post_thumbnail_url($post_id, 'large');
            $word_count = str_word_count(strip_tags($post->post_content));
            
            $tags = [];
            $post_tags = get_the_tags($post_id);
            if ($post_tags && !is_wp_error($post_tags)) {
                foreach ($post_tags as $tag) {
                    $tags[] = $tag->name;
                }
            }
            
            $categories = get_the_category($post_id);
            $category_names = [];
            if ($categories) {
                foreach ($categories as $category) {
                    $category_names[] = $category->name;
                }
            }
            
            $json_content = [
                'version' => '1.0',
                'content_type' => 'blog_post',
                'title' => get_the_title($post_id),
                'body' => wp_strip_all_tags($post->post_content),
                'excerpt' => $excerpt,
                'author' => $author,
                'published_at' => $post->post_date,
                'modified_at' => $post->post_modified,
                'word_count' => $word_count,
                'categories' => $category_names,
                'tags' => $tags,
                'featured_image_url' => $featured_image_url ?: null
            ];
            
            $bulk_data[] = [
                'wordpress_post_id' => $post_id,
                'target_url' => get_permalink($post_id),
                'price' => floatval($price),
                'page_title' => get_the_title($post_id),
                'page_excerpt' => $excerpt,
                'author' => $author,
                'featured_image_url' => $featured_image_url ?: null,
                'word_count' => $word_count,
                'tags' => $tags,
                'published_at' => $post->post_date,
                'json_content' => $json_content
            ];
        }
        
        return $bulk_data;
    }
}
