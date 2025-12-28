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
        
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Required - <?php echo $description; ?></title>
    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone paywall page served via status_header(402), not a WordPress template ?>
    <script src="<?php echo esc_url('https://unpkg.com/@coinbase/onchainkit@' . $onchainkit_version . '/dist/onchainkit.umd.js'); ?>"></script>
    <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone paywall page served via status_header(402), not a WordPress template ?>
    <link href="<?php echo esc_url('https://unpkg.com/@coinbase/onchainkit@' . $onchainkit_version . '/dist/onchainkit.css'); ?>" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .paywall-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .paywall-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 32px;
        }
        h1 {
            font-size: 28px;
            color: #1a1a1a;
            margin-bottom: 16px;
            font-weight: 700;
        }
        .price {
            font-size: 48px;
            font-weight: 800;
            color: #667eea;
            margin: 24px 0;
        }
        .description {
            color: #666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        .payment-widget {
            margin: 32px 0;
            min-height: 200px;
        }
        .resource-info {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
            font-size: 14px;
            color: #666;
            word-break: break-all;
        }
        .powered-by {
            margin-top: 32px;
            color: #999;
            font-size: 14px;
        }
        .powered-by a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .loading {
            color: #666;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="paywall-container">
        <div class="paywall-icon">🔒</div>
        <h1>Payment Required</h1>
        <div class="price">$<?php echo number_format($amount_usd, 2); ?></div>
        <div class="description"><?php echo $description; ?></div>
        
        <div id="payment-widget" class="payment-widget">
            <div class="loading">Initializing payment widget...</div>
        </div>
        
        <div class="resource-info">
            <strong>Resource:</strong><br>
            <?php echo $resource_url; ?>
        </div>
        
        <div class="powered-by">
            Powered by <a href="https://402links.com" target="_blank">x402 Protocol</a>
        </div>
    </div>

    <script>
        // Inject x402 configuration into window object
        window.x402 = {
            amount: <?php echo esc_js($amount_usd); ?>,
            paymentRequirements: <?php echo wp_json_encode($requirements); ?>,
            x402Response: <?php echo wp_json_encode($x402_response); ?>,
            testnet: <?php echo $testnet ? 'true' : 'false'; ?>,
            currentUrl: <?php echo wp_json_encode($resource_url); ?>,
            network: <?php echo wp_json_encode($network); ?>,
            cdpClientKey: <?php echo wp_json_encode($cdp_client_key); ?>
        };

        console.log('x402 Payment Requirements:', window.x402);

        // Initialize payment widget
        document.addEventListener('DOMContentLoaded', function() {
            const widgetContainer = document.getElementById('payment-widget');
            
            // Display payment instructions
            widgetContainer.innerHTML = `
                <div style="text-align: left; padding: 20px; background: #f9fafb; border-radius: 8px; border: 2px solid #e5e7eb;">
                    <h3 style="margin-bottom: 16px; color: #1a1a1a; font-size: 18px;">How to Pay:</h3>
                    <ol style="margin-left: 20px; color: #666; line-height: 2;">
                        <li>Connect your wallet (MetaMask, Coinbase Wallet, etc.)</li>
                        <li>Ensure you have <strong>$${window.x402.amount.toFixed(2)} USDC</strong> on <strong>${window.x402.network === 'base' ? 'Base' : 'Base Sepolia'}</strong></li>
                        <li>Sign the payment authorization</li>
                        <li>Access will be granted immediately</li>
                    </ol>
                    <div style="margin-top: 20px; padding: 16px; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                        <strong>⚠️ Note:</strong> Payment widget integration in progress. 
                        Please use the AI agent payment flow or contact support.
                    </div>
                </div>
            `;
        });
    </script>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
