/**
 * Agent Angreessen - Paywall JavaScript
 * Initializes payment widget for 402 payment required pages
 * 
 * Configuration is passed via wp_localize_script as x402Config
 */
(function() {
    'use strict';

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        // x402Config is injected via wp_localize_script
        if (typeof x402Config === 'undefined') {
            console.error('x402 configuration not found');
            return;
        }

        // Copy config to window.x402 for compatibility
        window.x402 = x402Config;

        console.log('x402 Payment Requirements:', window.x402);

        var widgetContainer = document.getElementById('payment-widget');
        
        if (!widgetContainer) {
            console.error('Payment widget container not found');
            return;
        }

        // Determine network display name
        var networkName = window.x402.network === 'base' ? 'Base' : 'Base Sepolia';
        var amount = parseFloat(window.x402.amount).toFixed(2);

        // Display payment instructions
        widgetContainer.innerHTML = 
            '<div class="payment-instructions">' +
                '<h3>How to Pay:</h3>' +
                '<ol>' +
                    '<li>Connect your wallet (MetaMask, Coinbase Wallet, etc.)</li>' +
                    '<li>Ensure you have <strong>$' + amount + ' USDC</strong> on <strong>' + networkName + '</strong></li>' +
                    '<li>Sign the payment authorization</li>' +
                    '<li>Access will be granted immediately</li>' +
                '</ol>' +
                '<div class="payment-notice">' +
                    '<strong>⚠️ Note:</strong> Payment widget integration in progress. ' +
                    'Please use the AI agent payment flow or contact support.' +
                '</div>' +
            '</div>';
    });
})();
