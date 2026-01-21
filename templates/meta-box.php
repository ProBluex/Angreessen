<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

$angreessen49_link_id = get_post_meta($post->ID, '_angreessen49_link_id', true);
$angreessen49_link_url = get_post_meta($post->ID, '_angreessen49_url', true);
$angreessen49_price = get_post_meta($post->ID, '_angreessen49_price', true);
$angreessen49_synced_at = get_post_meta($post->ID, '_angreessen49_synced_at', true);
$angreessen49_settings = get_option('angreessen49_settings');
$angreessen49_default_price = $angreessen49_settings['default_price'] ?? 0.10;

global $wpdb;
$angreessen49_table_name = $wpdb->prefix . 'angreessen49_agent_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name uses trusted $wpdb->prefix with hardcoded suffix, display stats
$angreessen49_stats = $wpdb->get_row($wpdb->prepare(
    "SELECT 
        COUNT(*) as total_crawls,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_crawls,
        SUM(amount_paid) as total_revenue
    FROM {$angreessen49_table_name}
    WHERE post_id = %d",
    $post->ID
));
?>

<div class="angreessen49-meta-box">
    <?php wp_nonce_field('angreessen49_meta_box', 'angreessen49_meta_box_nonce'); ?>
    
    <div class="meta-box-section">
        <label for="angreessen49_price">
            <strong>AI Agent Access Price (USD)</strong>
        </label>
        <input type="number" 
               id="angreessen49_price" 
               name="angreessen49_price" 
               step="0.01" 
               min="0" 
               value="<?php echo esc_attr($angreessen49_price ?: $angreessen49_default_price); ?>"
               style="width: 100%;">
        <p class="description">
            Price for AI agents to access this page. Leave empty to use default ($<?php echo esc_html($angreessen49_default_price); ?>)
        </p>
    </div>
    
    <?php if ($angreessen49_link_id): ?>
        <div class="meta-box-section">
            <div class="status-indicator success">
                <span class="dashicons dashicons-yes-alt"></span>
                <strong>402link Active</strong>
            </div>
            
            <?php if ($angreessen49_link_url): ?>
                <p>
                    <strong>Link URL:</strong><br>
                    <a href="<?php echo esc_url($angreessen49_link_url); ?>" target="_blank">
                        <?php echo esc_html($angreessen49_link_url); ?>
                    </a>
                </p>
            <?php endif; ?>
            
            <?php if ($angreessen49_synced_at): ?>
                <p>
                    <strong>Last Synced:</strong><br>
                    <?php echo esc_html(wp_date('F j, Y, g:i a', strtotime($angreessen49_synced_at))); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="meta-box-section">
            <h4>AI Agent Activity</h4>
            <table class="widefat" style="width: 100%;">
                <tr>
                    <td><strong>Total Crawls:</strong></td>
                    <td><?php echo intval($angreessen49_stats->total_crawls ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Paid Crawls:</strong></td>
                    <td><?php echo intval($angreessen49_stats->paid_crawls ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Revenue:</strong></td>
                    <td>$<?php echo number_format(floatval($angreessen49_stats->total_revenue ?? 0), 2); ?></td>
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
                    id="angreessen49-generate-link-btn"
                    data-post-id="<?php echo absint($post->ID); ?>">
                Generate Monetization Link
            </button>
        </div>
    <?php endif; ?>
</div>
