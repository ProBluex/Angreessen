<?php
namespace Angreessen49;

class ContentSync {
    /**
     * Auto-sync post to 402links on publish/update
     * 
     * SECURITY FIX: Added CSRF nonce verification
     */
    public static function sync_post_to_402links($post_id) {
        // Skip autosaves/revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // SECURITY: Verify nonce for manual saves (not for programmatic updates)
        if (isset($_POST['angreessen49_meta_nonce'])) {
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['angreessen49_meta_nonce'])), 'angreessen49_save_meta_' . $post_id)) {
                return;
            }
        }
        
        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Skip if post is not published
        $post = get_post($post_id);
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Check if auto-generate is enabled
        $settings = get_option('angreessen49_settings');
        if (!isset($settings['auto_generate']) || !$settings['auto_generate']) {
            return;
        }
        
        // Check if API key is set
        $api_key = get_option('angreessen49_api_key');
        if (empty($api_key)) {
            return;
        }
        
        // Check if already synced
        $link_id = get_post_meta($post_id, '_angreessen49_link_id', true);
        
        $api = new API();
        
        if ($link_id) {
            // Update existing
            $result = $api->update_link($post_id, $link_id);
        } else {
            // Create new
            $result = self::create_link($post_id);
        }
    }
    
    /**
     * Create new 402link for post
     */
    public static function create_link($post_id) {
        $api = new API();
        $result = $api->create_link($post_id);
        
        if ($result['success']) {
            // Store link metadata
            if (isset($result['link_id'])) {
                update_post_meta($post_id, '_angreessen49_link_id', $result['link_id']);
            }
            
            // ⭐ CRITICAL FIX: Store short_id in post meta
            if (isset($result['short_id'])) {
                update_post_meta($post_id, '_angreessen49_short_id', $result['short_id']);
            }
            
            // Store the correct 402link URL using production domain
            if (isset($result['link_url'])) {
                update_post_meta($post_id, '_angreessen49_url', $result['link_url']);
            } elseif (isset($result['short_id'])) {
                // Construct the URL from short_id using production domain
                $link_url = 'https://402links.com/p/' . $result['short_id'];
                update_post_meta($post_id, '_angreessen49_url', $link_url);
            }
            
            update_post_meta($post_id, '_angreessen49_synced_at', current_time('mysql'));
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
            $link_id = get_post_meta($post_id, '_angreessen49_link_id', true);
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
            
            $link_id = get_post_meta($post_id, '_angreessen49_link_id', true);
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
