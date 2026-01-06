<?php
$agent_hub_link_id = get_post_meta($post->ID, '_402links_id', true);
$agent_hub_link_url = get_post_meta($post->ID, '_402links_url', true);
$agent_hub_price = get_post_meta($post->ID, '_402links_price', true);
$agent_hub_synced_at = get_post_meta($post->ID, '_402links_synced_at', true);
$agent_hub_settings = get_option('402links_settings');
$agent_hub_default_price = $agent_hub_settings['default_price'] ?? 0.10;

global $wpdb;
$agent_hub_table_name = $wpdb->prefix . '402links_agent_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses trusted $wpdb->prefix with hardcoded suffix, display stats
$agent_hub_stats = $wpdb->get_row($wpdb->prepare(
    "SELECT 
        COUNT(*) as total_crawls,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_crawls,
        SUM(amount_paid) as total_revenue
    FROM {$agent_hub_table_name}
    WHERE post_id = %d",
    $post->ID
));
?>

<div class="agent-hub-meta-box">
    <?php wp_nonce_field('agent_hub_meta_box', 'agent_hub_meta_box_nonce'); ?>
    
    <div class="meta-box-section">
        <label for="agent_hub_price">
            <strong>AI Agent Access Price (USD)</strong>
        </label>
        <input type="number" 
               id="agent_hub_price" 
               name="agent_hub_price" 
               step="0.01" 
               min="0" 
               value="<?php echo esc_attr($agent_hub_price ?: $agent_hub_default_price); ?>"
               style="width: 100%;">
        <p class="description">
            Price for AI agents to access this page. Leave empty to use default ($<?php echo esc_html($agent_hub_default_price); ?>)
        </p>
    </div>
    
    <?php if ($agent_hub_link_id): ?>
        <div class="meta-box-section">
            <div class="status-indicator success">
                <span class="dashicons dashicons-yes-alt"></span>
                <strong>402link Active</strong>
            </div>
            
            <?php if ($agent_hub_link_url): ?>
                <p>
                    <strong>Link URL:</strong><br>
                    <a href="<?php echo esc_url($agent_hub_link_url); ?>" target="_blank">
                        <?php echo esc_html($agent_hub_link_url); ?>
                    </a>
                </p>
            <?php endif; ?>
            
            <?php if ($agent_hub_synced_at): ?>
                <p>
                    <strong>Last Synced:</strong><br>
                    <?php echo esc_html(wp_date('F j, Y, g:i a', strtotime($agent_hub_synced_at))); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="meta-box-section">
            <h4>AI Agent Activity</h4>
            <table class="widefat" style="width: 100%;">
                <tr>
                    <td><strong>Total Crawls:</strong></td>
                    <td><?php echo intval($agent_hub_stats->total_crawls ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Paid Crawls:</strong></td>
                    <td><?php echo intval($agent_hub_stats->paid_crawls ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Revenue:</strong></td>
                    <td>$<?php echo number_format(floatval($agent_hub_stats->total_revenue ?? 0), 2); ?></td>
                </tr>
            </table>
        </div>
    <?php else: ?>
        <div class="meta-box-section">
            <div class="status-indicator warning">
                <span class="dashicons dashicons-warning"></span>
                <strong>No Monetization Link</strong>
            </div>
            <p>This page is not yet protected by Agent Angreessen.</p>
            <button type="button"
                    class="button button-primary" 
                    onclick="generateLinkFromMetaBox(<?php echo absint($post->ID); ?>)">
                Generate Monetization Link
            </button>
        </div>
    <?php endif; ?>
</div>

