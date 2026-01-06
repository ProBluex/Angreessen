/**
 * Agent Angreessen - Setup Wizard JavaScript
 * Handles the initial setup consent flow
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        $('#angreessen-complete-setup').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('Setting up...');

            $.post(angreessenSetup.ajaxUrl, {
                action: 'agent_hub_complete_setup',
                nonce: angreessenSetup.nonce
            }, function(response) {
                if (response.success) {
                    $('#angreessen-setup-notice').html(
                        '<p>✅ Setup complete! Site ID: <code>' + response.data.site_id + '</code></p>'
                    );
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    alert('Setup failed: ' + (response.data && response.data.message ? response.data.message : 'Unknown error'));
                    button.prop('disabled', false).text('Complete Setup');
                }
            });
        });
    });
})(jQuery);
