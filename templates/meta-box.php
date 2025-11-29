<?php
$link_id = get_post_meta($post->ID, '_402links_id', true);
$link_url = get_post_meta($post->ID, '_402links_url', true);
$price = get_post_meta($post->ID, '_402links_price', true);
$synced_at = get_post_meta($post->ID, '_402links_synced_at', true);
$settings = get_option('402links_settings');
$default_price = $settings['default_price'] ?? 0.10;

global $wpdb;
$table_name = $wpdb->prefix . '402links_agent_logs';
$stats = $wpdb->get_row($wpdb->prepare(
    "SELECT 
        COUNT(*) as total_crawls,
        SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_crawls,
        SUM(amount_paid) as total_revenue
    FROM {$table_name}
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
               value="<?php echo esc_attr($price ?: $default_price); ?>"
               style="width: 100%;">
        <p class="description">
            Price for AI agents to access this page. Leave empty to use default ($<?php echo $default_price; ?>)
        </p>
    </div>
    
    <?php if ($link_id): ?>
        <div class="meta-box-section">
            <div class="status-indicator success">
                <span class="dashicons dashicons-yes-alt"></span>
                <strong>402link Active</strong>
            </div>
            
            <?php if ($link_url): ?>
                <p>
                    <strong>Link URL:</strong><br>
                    <a href="<?php echo esc_url($link_url); ?>" target="_blank">
                        <?php echo esc_html($link_url); ?>
                    </a>
                </p>
            <?php endif; ?>
            
            <?php if ($synced_at): ?>
                <p>
                    <strong>Last Synced:</strong><br>
                    <?php echo esc_html(date('F j, Y, g:i a', strtotime($synced_at))); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <div class="meta-box-section">
            <h4>AI Agent Activity</h4>
            <table class="widefat" style="width: 100%;">
                <tr>
                    <td><strong>Total Crawls:</strong></td>
                    <td><?php echo intval($stats->total_crawls ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Paid Crawls:</strong></td>
                    <td><?php echo intval($stats->paid_crawls ?? 0); ?></td>
                </tr>
                <tr>
                    <td><strong>Revenue:</strong></td>
                    <td>$<?php echo number_format(floatval($stats->total_revenue ?? 0), 2); ?></td>
                </tr>
            </table>
        </div>
    <?php else: ?>
        <div class="meta-box-section">
            <div class="status-indicator warning">
                <span class="dashicons dashicons-warning"></span>
                <strong>No Monetization Link</strong>
            </div>
            <p>This page is not yet protected by Tolliver.</p>
            <button type="button" 
                    class="button button-primary" 
                    onclick="generateLinkFromMetaBox(<?php echo $post->ID; ?>)">
                Generate Monetization Link
            </button>
        </div>
    <?php endif; ?>
</div>

<style>
.agent-hub-meta-box {
    padding: 10px 0;
}
.meta-box-section {
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #ddd;
}
.meta-box-section:last-child {
    border-bottom: none;
}
.status-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    border-radius: 4px;
    margin-bottom: 10px;
}
.status-indicator.success {
    background: #d4edda;
    color: #155724;
}
.status-indicator.warning {
    background: #fff3cd;
    color: #856404;
}
</style>

<script>
function generateLinkFromMetaBox(postId) {
    const button = event.target;
    button.disabled = true;
    button.textContent = 'Generating...';
    
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'agent_hub_generate_link',
            nonce: agentHubData.nonce,
            post_id: postId
        },
        success: function(response) {
            if (response.success) {
                location.reload();
            } else {
                const message = response.data?.message || 'Failed to generate link';
                button.disabled = false;
                button.textContent = 'Generate 402link';
                console.error('Link generation failed:', message);
            }
        },
        error: function() {
            button.disabled = false;
            button.textContent = 'Generate 402link';
            console.error('Network error during link generation');
        }
    });
}

// Save custom price when post is saved
jQuery(document).ready(function($) {
    $('#post').on('submit', function() {
        const price = $('#agent_hub_price').val();
        if (price) {
            $('<input>').attr({
                type: 'hidden',
                name: 'agent_hub_price',
                value: price
            }).appendTo('#post');
        }
    });
});
</script>
