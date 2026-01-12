<?php
namespace AgentHub;

/**
 * HTML Paywall Template for Browser Visitors
 * Renders OnchainKit payment widget with x402 configuration
 */

class PaywallTemplate {
    
    /**
     * Render the HTML paywall page
     */
    public static function render($x402_response, $requirements) {
        $amount_usd = floatval($requirements['maxAmountRequired']) / 1000000;
        $description = htmlspecialchars($requirements['description'] ?? 'Payment Required');
        $resource_url = htmlspecialchars($requirements['resource']);
        $network = $requirements['network'];
        $testnet = ($network === 'base-sepolia');
        
        // OnchainKit CDN
        $onchainkit_version = '1.1.1';
        $cdp_client_key = defined('CDP_CLIENT_KEY') ? CDP_CLIENT_KEY : '';
        
        // Register and enqueue scripts/styles properly
        wp_register_style(
            'onchainkit',
            'https://unpkg.com/@coinbase/onchainkit@' . $onchainkit_version . '/dist/onchainkit.css',
            array(),
            $onchainkit_version
        );
        wp_register_style(
            'agent-hub-paywall',
            AGENT_HUB_PLUGIN_URL . 'assets/css/paywall.css',
            array(),
            AGENT_HUB_VERSION
        );
        
        wp_register_script(
            'onchainkit',
            'https://unpkg.com/@coinbase/onchainkit@' . $onchainkit_version . '/dist/onchainkit.umd.js',
            array(),
            $onchainkit_version,
            false // Load in head
        );
        wp_register_script(
            'agent-hub-paywall',
            AGENT_HUB_PLUGIN_URL . 'assets/js/paywall.js',
            array( 'onchainkit' ),
            AGENT_HUB_VERSION,
            true // Load in footer
        );
        
        // Pass PHP data to JavaScript via wp_localize_script
        wp_localize_script('agent-hub-paywall', 'x402Config', array(
            'amount' => $amount_usd,
            'paymentRequirements' => $requirements,
            'x402Response' => $x402_response,
            'testnet' => $testnet,
            'currentUrl' => $resource_url,
            'network' => $network,
            'cdpClientKey' => $cdp_client_key
        ));
        
        // Enqueue all assets
        wp_enqueue_style('onchainkit');
        wp_enqueue_style('agent-hub-paywall');
        wp_enqueue_script('onchainkit');
        wp_enqueue_script('agent-hub-paywall');
        
        // Capture enqueued assets HTML
        ob_start();
        wp_print_styles(array( 'onchainkit', 'agent-hub-paywall' ));
        wp_print_scripts(array( 'onchainkit', 'agent-hub-paywall' ));
        $enqueued_assets = ob_get_clean();
        
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Required - <?php echo esc_html($description); ?></title>
    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output from wp_print_styles/wp_print_scripts is already escaped
    echo $enqueued_assets;
    ?>
</head>
<body>
    <div class="paywall-container">
        <div class="paywall-icon">🔒</div>
        <h1>Payment Required</h1>
        <div class="price">$<?php echo number_format($amount_usd, 2); ?></div>
        <div class="description"><?php echo esc_html($description); ?></div>
        
        <div id="payment-widget" class="payment-widget">
            <div class="loading">Initializing payment widget...</div>
        </div>
        
        <div class="resource-info">
            <strong>Resource:</strong><br>
            <?php echo esc_url($resource_url); ?>
        </div>
        
        <div class="powered-by">
            Powered by <a href="https://402links.com" target="_blank">x402 Protocol</a>
        </div>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
