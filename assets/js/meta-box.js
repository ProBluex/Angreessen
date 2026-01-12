/**
 * Agent Angreessen - Meta Box JavaScript
 * Handles meta box interactions for post/page editor
 */
(function($) {
    'use strict';

    /**
     * Generate monetization link from meta box
     * @param {number} postId - The post ID to generate link for
     * @param {HTMLElement} button - The button element that was clicked
     */
    function generateLinkFromMetaBox(postId, button) {
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
                    button.textContent = 'Generate Monetization Link';
                    console.error('Link generation failed:', message);
                }
            },
            error: function() {
                button.disabled = false;
                button.textContent = 'Generate Monetization Link';
                console.error('Network error during link generation');
            }
        });
    }

    /**
     * Initialize meta box functionality
     */
    $(document).ready(function() {
        // Handle generate link button click via event delegation
        $(document).on('click', '#agent-hub-generate-link-btn', function(e) {
            e.preventDefault();
            var postId = $(this).data('post-id');
            if (postId) {
                generateLinkFromMetaBox(postId, this);
            }
        });

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
