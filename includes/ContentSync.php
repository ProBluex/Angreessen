<?php
namespace AgentHub;

class ContentSync {
    /**
     * Auto-sync post to 402links on publish/update
     * 
     * SECURITY FIX: Added CSRF nonce verification
     */
    public static function sync_post_to_402links($post_id) {
        error_log('402links: sync_post_to_402links triggered for post ' . $post_id);
        
        // Skip autosaves/revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            error_log('402links: Skipping autosave for post ' . $post_id);
            return;
        }
        
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            error_log('402links: Skipping autosave/revision for post ' . $post_id);
            return;
        }
        
        // SECURITY: Verify nonce for manual saves (not for programmatic updates)
        if (isset($_POST['402links_meta_nonce'])) {
            if (!wp_verify_nonce($_POST['402links_meta_nonce'], '402links_save_meta_' . $post_id)) {
                error_log('402links: Invalid nonce for post ' . $post_id);
                return;
            }
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            error_log('402links: User lacks permission to edit post ' . $post_id);
            return;
        }
        
        // Skip if post is not published
        $post = get_post($post_id);
        if ($post->post_status !== 'publish') {
            error_log('402links: Post ' . $post_id . ' is not published (status: ' . $post->post_status . ')');
            return;
        }
        
        // Check if auto-generate is enabled
        $settings = get_option('402links_settings');
        if (!isset($settings['auto_generate']) || !$settings['auto_generate']) {
            error_log('402links: Auto-generate is disabled for post ' . $post_id);
            return;
        }
        
        // Check if API key is set
        $api_key = get_option('402links_api_key');
        if (empty($api_key)) {
            error_log('402links: API key not set, cannot sync post ' . $post_id);
            return;
        }
        
        // Check if already synced
        $link_id = get_post_meta($post_id, '_402links_id', true);
        
        $api = new API();
        
        if ($link_id) {
            // Update existing
            error_log('402links: Updating existing link ' . $link_id . ' for post ' . $post_id);
            $result = $api->update_link($post_id, $link_id);
        } else {
            // Create new
            error_log('402links: Creating new link for post ' . $post_id);
            $result = self::create_link($post_id);
        }
        
        // Log result
        if (!$result['success']) {
            error_log('402links: sync failed for post ' . $post_id . ': ' . json_encode($result));
        } else {
            error_log('402links: Successfully synced post ' . $post_id);
        }
    }
    
    /**
     * Create new 402link for post
     */
    public static function create_link($post_id) {
        error_log('402links: create_link called for post ' . $post_id);
        
        $api = new API();
        $result = $api->create_link($post_id);
        
        error_log('402links: API response for post ' . $post_id . ': ' . json_encode($result));
        
        if ($result['success']) {
            // Store link metadata
            if (isset($result['link_id'])) {
                update_post_meta($post_id, '_402links_id', $result['link_id']);
                error_log('402links: Saved link_id ' . $result['link_id'] . ' for post ' . $post_id);
            }
            
            // ⭐ CRITICAL FIX: Store short_id in post meta
            if (isset($result['short_id'])) {
                update_post_meta($post_id, '_402links_short_id', $result['short_id']);
                error_log('402links: Saved short_id ' . $result['short_id'] . ' for post ' . $post_id);
            }
            
            // Store the correct 402link URL using production domain
            if (isset($result['link_url'])) {
                update_post_meta($post_id, '_402links_url', $result['link_url']);
                error_log('402links: Saved link_url for post ' . $post_id);
            } elseif (isset($result['short_id'])) {
                // Construct the URL from short_id using production domain
                $link_url = 'https://402links.com/p/' . $result['short_id'];
                update_post_meta($post_id, '_402links_url', $link_url);
                error_log('402links: Constructed and saved link_url from short_id for post ' . $post_id);
            }
            
            update_post_meta($post_id, '_402links_synced_at', current_time('mysql'));
        } else {
            error_log('402links: Failed to create link for post ' . $post_id . ': ' . ($result['error'] ?? 'Unknown error'));
        }
        
        return $result;
    }
    
    /**
     * Bulk sync all published posts
     */
    public static function bulk_sync_all() {
        $posts = get_posts([
            'post_type' => 'post',  // ✅ ONLY posts, not pages
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        
        $results = [
            'total' => count($posts),
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($posts as $post_id) {
            $link_id = get_post_meta($post_id, '_402links_id', true);
            $api = new API();
            
            if ($link_id) {
                $result = $api->update_link($post_id, $link_id);
                if ($result['success']) {
                    $results['updated']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown error');
                }
            } else {
                $result = self::create_link($post_id);
                if ($result['success']) {
                    $results['created']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown error');
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Bulk sync specific posts
     */
    public static function bulk_sync_specific($post_ids) {
        $results = [
            'total' => count($post_ids),
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($post_ids as $post_id) {
            // Verify post exists and is published
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                $results['failed']++;
                $results['errors'][] = "Post {$post_id}: Not found or not published";
                continue;
            }
            
            $link_id = get_post_meta($post_id, '_402links_id', true);
            $api = new API();
            
            if ($link_id) {
                $result = $api->update_link($post_id, $link_id);
                if ($result['success']) {
                    $results['updated']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown error');
                }
            } else {
                $result = self::create_link($post_id);
                if ($result['success']) {
                    $results['created']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Post {$post_id}: " . ($result['error'] ?? 'Unknown error');
                }
            }
        }
        
        return $results;
    }
}