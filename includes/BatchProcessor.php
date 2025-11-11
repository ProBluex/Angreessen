<?php
namespace AgentHub;

class BatchProcessor {
    const BATCH_SIZE = 50;
    const MAX_EXECUTION_TIME = 30; // seconds
    const PARALLEL_REQUESTS = 5; // Process 5 posts concurrently
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
        
        // Process batch with parallel requests
        $chunks = array_chunk($posts, self::PARALLEL_REQUESTS);
        
        foreach ($chunks as $chunk) {
            // Check timeout
            if ((time() - $start_time) > self::MAX_EXECUTION_TIME) {
                break;
            }
            
            // Process chunk in parallel
            $results = self::process_chunk_parallel($chunk);
            
            // Aggregate results
            foreach ($results as $result) {
                if ($result['type'] === 'created') {
                    $progress['created']++;
                } elseif ($result['type'] === 'updated') {
                    $progress['updated']++;
                } elseif ($result['type'] === 'failed') {
                    $progress['failed']++;
                    $progress['errors'][] = $result['error'];
                }
                $progress['processed']++;
            }
        }
        
        $progress['current_offset'] += self::BATCH_SIZE;
        $progress['updated_at'] = current_time('mysql');
        set_transient(self::PROGRESS_KEY, $progress, 3600);
        
        // Check if batch is actually complete
        $is_complete = ($progress['processed'] >= $progress['total']);
        
        if ($is_complete) {
            $progress['status'] = 'completed';
            $progress['updated_at'] = current_time('mysql');
            set_transient(self::PROGRESS_KEY, $progress, 3600);
        }
        
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
     * Process chunk of posts in parallel using bulk API (Sprint 2)
     */
    private static function process_chunk_parallel($post_ids) {
        $results = [];
        $api = new API();
        
        // Separate posts that need creation vs update
        $posts_to_create = [];
        $posts_to_update = [];
        
        foreach ($post_ids as $post_id) {
            $link_id = get_post_meta($post_id, '_402links_id', true);
            
            if ($link_id) {
                $posts_to_update[] = [
                    'post_id' => $post_id,
                    'link_id' => $link_id
                ];
            } else {
                $posts_to_create[] = $post_id;
            }
        }
        
        // Bulk create new links
        if (!empty($posts_to_create)) {
            $bulk_data = self::prepare_bulk_create_data($posts_to_create);
            
            if (!empty($bulk_data)) {
                error_log('🔵 [BatchProcessor] Bulk creating ' . count($bulk_data) . ' links');
                
                $bulk_result = $api->bulk_create_links($bulk_data);
                
                if ($bulk_result['success']) {
                    // Process each result
                    foreach ($bulk_result['results'] as $item) {
                        if ($item['success']) {
                            // Save metadata to WordPress
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
                            
                            $results[] = ['type' => 'created', 'post_id' => $item['wordpress_post_id']];
                        } else {
                            // Mark for retry
                            self::mark_for_retry($item['wordpress_post_id'], $item['error']);
                            $results[] = [
                                'type' => 'failed',
                                'post_id' => $item['wordpress_post_id'],
                                'error' => $item['error']
                            ];
                        }
                    }
                } else {
                    // Bulk API failed, mark all for retry
                    error_log('🔴 [BatchProcessor] Bulk create failed: ' . ($bulk_result['error'] ?? 'Unknown'));
                    foreach ($posts_to_create as $post_id) {
                        self::mark_for_retry($post_id, $bulk_result['error'] ?? 'Bulk API failed');
                        $results[] = [
                            'type' => 'failed',
                            'post_id' => $post_id,
                            'error' => $bulk_result['error'] ?? 'Bulk API failed'
                        ];
                    }
                }
            }
        }
        
        // Update existing links individually (updates are less common)
        foreach ($posts_to_update as $update_data) {
            $result = $api->update_link($update_data['post_id'], $update_data['link_id']);
            
            if ($result['success']) {
                update_post_meta($update_data['post_id'], '_402links_synced_at', current_time('mysql'));
                $results[] = ['type' => 'updated', 'post_id' => $update_data['post_id']];
            } else {
                self::mark_for_retry($update_data['post_id'], $result['error'] ?? 'Update failed');
                $results[] = [
                    'type' => 'failed',
                    'post_id' => $update_data['post_id'],
                    'error' => $result['error'] ?? 'Update failed'
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Prepare bulk create data from post IDs
     */
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
            
            // Get post metadata
            $excerpt = $post->post_excerpt;
            if (empty($excerpt)) {
                $excerpt = wp_trim_words(strip_tags($post->post_content), 30);
            }
            
            $author = get_the_author_meta('display_name', $post->post_author);
            $featured_image_url = get_the_post_thumbnail_url($post_id, 'large');
            $word_count = str_word_count(strip_tags($post->post_content));
            
            // Get tags
            $tags = [];
            $post_tags = get_the_tags($post_id);
            if ($post_tags && !is_wp_error($post_tags)) {
                foreach ($post_tags as $tag) {
                    $tags[] = $tag->name;
                }
            }
            
            // Get categories
            $categories = get_the_category($post_id);
            $category_names = [];
            if ($categories) {
                foreach ($categories as $category) {
                    $category_names[] = $category->name;
                }
            }
            
            // Prepare JSON content
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
    
    /**
     * Mark post for retry on next batch run
     */
    private static function mark_for_retry($post_id, $error) {
        $retry_count = intval(get_post_meta($post_id, '_402links_retry_count', true));
        $retry_count++;
        
        update_post_meta($post_id, '_402links_retry_count', $retry_count);
        update_post_meta($post_id, '_402links_last_error', $error);
        update_post_meta($post_id, '_402links_last_retry_at', current_time('mysql'));
        
        error_log("🔴 [BatchProcessor] Marked post {$post_id} for retry (attempt {$retry_count}): {$error}");
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
