=== Agent Angreessen - Ai Agent Pay Collector ===
Contributors: 402links, ProBluex
Tags: payment, ai, agent, monetization, x402
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.0.0
License: GPLv2 or later

Automatically monetize WordPress content with AI agent payments via x402 protocol.

== Description ==

Agent Angreessen - Ai Agent Pay Collector enables seamless monetization of WordPress content through the x402 payment protocol. AI agents can automatically discover and pay for access to premium content on wordpress using micropayments. Read more on x402 here: https://github.com/coinbase/x402.

**Key Features:**

* Automatic content monetization with x402 protocol
* AI agent payment detection and processing
* Flexible pricing per post/page
* Payment tracking and analytics
* Base network support
* Universal payment page integration

**How It Works:**

1. Mark posts/pages as premium content
2. Set pricing in USD 
3. AI agents discover your content via x402 protocol
4. Agents pay automatically and gain instant access
5. Track payments and agent access in real-time

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/agent-angreessen/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure your payment wallet address in Settings → Angreessen
4. Start marking content as premium with custom pricing

== Frequently Asked Questions ==

= What is x402? =

x402 is an open protocol that enables AI agents to automatically discover and pay for premium content using micropayments.

= Which blockchain networks are supported? =

Currently supports Base network with more networks coming soon.

= Do I need a blockchain-based wallet? =

Yes, you need a wallet address on Base network to receive payments.

= How do AI agents discover my content? =

The plugin automatically exposes payment metadata through the x402 protocol that AI agents can discover and process.

== Changelog ==

= 1.0.0 =
* Initial public release on WordPress.org
* AI agent payment processing via x402 protocol
* Flexible per-post/page pricing
* Payment tracking and analytics
* Base network support

== Support ==

For support, visit: https://402links.com/contact
Documentation: https://402links.com/docs

== External Services ==

This plugin relies on the 402links.com service to enable AI agent payments and content monetization. 

**What data is transmitted:**
* Site URL, site name, and admin email (during initial setup)
* WordPress version and plugin version (for compatibility)
* Post/page titles, URLs, and pricing information (when creating paid links)
* AI agent user-agents and IP addresses (when agents access protected content)
* Payment transaction metadata (when payments are processed)

**When connections occur:**
* During plugin activation (automatic site registration)
* When you create or update paid links for posts/pages
* When AI agents request protected content
* When viewing analytics and access logs in the admin dashboard

**Service Information:**
* Service URL: https://402links.com
* Privacy Policy: https://402links.com/privacy
* Terms of Service: https://402links.com/terms

**Important:** By using this plugin, you agree to the 402links.com Terms of Service and Privacy Policy. The plugin will not function without connecting to 402links.com services.

For more information about the x402 protocol used by this plugin, visit: https://github.com/coinbase/x402

== Privacy Policy ==

**Data Collected:**

* Site URL, site name, and admin email (during initial setup for account creation and DMCA compliance)
* AI agent/bot IP addresses and user-agent strings (for payment verification and rate limiting)
* Post/page titles, URLs, and pricing (when creating paid links)
* Payment transaction metadata (processed through 402links.com)

**Important Clarifications:**

* Human visitors are NOT tracked - only AI agents/bots accessing protected content
* AI agent IPs are used for payment caching (24-hour access) and security, not individual tracking
* Admin email is used solely for service communication and legal compliance
* Access logs and payment records are stored in your WordPress database

For complete details, see: https://402links.com/privacy
