/**
 * Agent Angreessen - Meta Box JavaScript
 * Handles meta box interactions for post/page editor
 */
(function($) {
    'use strict';

    /**
     * Generate monetization link from meta box
     * @param {number} postId - The post ID to generate link for
     */
    window.generateLinkFromMetaBox = function(postId) {
        var button = event.target;
        button.disabled = true;
        button.textContent = 'Generating...';

        $.ajax({
            url: agentHubData.ajaxUrl,
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
                    var message = response.data && response.data.message ? response.data.message : 'Failed to generate link';
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
    };

    /**
     * Initialize meta box functionality
     */
    $(document).ready(function() {
        // Save custom price when post is saved
        $('#post').on('submit', function() {
            var price = $('#agent_hub_price').val();
            if (price) {
                $('<input>').attr({
                    type: 'hidden',
                    name: 'agent_hub_price',
                    value: price
                }).appendTo('#post');
            }
        });
    });
})(jQuery);
