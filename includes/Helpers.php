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
}
