<?php
namespace AgentHub;

class HumanDetector {
    /**
     * Detect if the user-agent is a human browser
     * Inverted logic: if we can't prove it's human, it's an agent
     * 
     * @param string $user_agent
     * @return array ['is_human' => bool, 'confidence' => string, 'indicators' => array]
     */
    public static function is_human($user_agent) {
        if (empty($user_agent)) {
            return [
                'is_human' => false,
                'confidence' => 'high',
                'indicators' => ['empty_user_agent']
            ];
        }
        
        $indicators = [];
        $human_signals = 0;
        $agent_signals = 0;
        
        // SIGNAL 1: Browser patterns (strong human indicator)
        $browser_patterns = [
            'Mozilla/5.0',
            'Chrome/',
            'Safari/',
            'Firefox/',
            'Edge/',
            'Opera/',
            'AppleWebKit/',
            'Gecko/',
            'MSIE',
            'Trident/'
        ];
        
        foreach ($browser_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                $human_signals++;
                $indicators[] = 'browser_pattern_' . strtolower(str_replace('/', '_', $pattern));
                break;
            }
        }
        
        // SIGNAL 2: Agent/Bot keywords (strong agent indicator)
        $agent_keywords = [
            'bot', 'crawler', 'spider', 'scraper', 'agent', 'headless',
            'curl', 'wget', 'python-requests', 'java/', 'go-http-client',
            'gptbot', 'claude', 'perplexity', 'anthropic', 'openai',
            'chatgpt', 'googlebot', 'bingbot', 'slackbot', 'facebookexternalhit',
            'twitterbot', 'linkedinbot', 'discordbot', 'whatsapp'
        ];
        
        foreach ($agent_keywords as $keyword) {
            if (stripos($user_agent, $keyword) !== false) {
                $agent_signals++;
                $indicators[] = 'agent_keyword_' . $keyword;
            }
        }
        
        // SIGNAL 3: Platform/OS indicators (moderate human indicator)
        $platform_patterns = [
            'Windows NT', 'Macintosh', 'Mac OS X', 'Linux', 'Ubuntu',
            'Android', 'iPhone', 'iPad', 'iOS'
        ];
        
        foreach ($platform_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                $human_signals++;
                $indicators[] = 'platform_' . strtolower(str_replace(' ', '_', $pattern));
                break;
            }
        }
        
        // SIGNAL 4: Accept headers (moderate human indicator)
        $accept_header = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (stripos($accept_header, 'text/html') !== false) {
            $human_signals++;
            $indicators[] = 'accepts_html';
        }
        
        // SIGNAL 5: Accept-Language header (moderate human indicator)
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $human_signals++;
            $indicators[] = 'has_language_header';
        }
        
        // DECISION LOGIC
        // If any agent keywords found, it's definitely an agent
        if ($agent_signals > 0) {
            return [
                'is_human' => false,
                'confidence' => 'high',
                'indicators' => $indicators,
                'signals' => [
                    'human' => $human_signals,
                    'agent' => $agent_signals
                ]
            ];
        }
        
        // If browser patterns + platform + headers = human
        if ($human_signals >= 2) {
            return [
                'is_human' => true,
                'confidence' => $human_signals >= 3 ? 'high' : 'medium',
                'indicators' => $indicators,
                'signals' => [
                    'human' => $human_signals,
                    'agent' => $agent_signals
                ]
            ];
        }
        
        // Default: insufficient evidence = assume agent
        return [
            'is_human' => false,
            'confidence' => 'low',
            'indicators' => ['insufficient_evidence'],
            'signals' => [
                'human' => $human_signals,
                'agent' => $agent_signals
            ]
        ];
    }
    
    /**
     * Extract agent name from user-agent string
     * Only called when is_human() returns false
     * 
     * @param string $user_agent
     * @return string Agent name or 'Unknown Agent'
     */
    public static function extract_agent_name($user_agent) {
        if (empty($user_agent)) {
            return 'Unknown Agent';
        }
        
        // Priority agent patterns
        $priority_agents = [
            'claude' => ['anthropic', 'claude'],
            'ChatGPT' => ['gptbot', 'chatgpt-user', 'chatgpt', 'openai'],
            'Perplexity' => ['perplexitybot', 'perplexity'],
            'Google' => ['googlebot', 'google-extended'],
            'Bing' => ['bingbot'],
            'Facebook' => ['facebookexternalhit', 'facebot'],
            'Twitter' => ['twitterbot'],
            'LinkedIn' => ['linkedinbot'],
            'Slack' => ['slackbot'],
            'Discord' => ['discordbot'],
            'WhatsApp' => ['whatsapp']
        ];
        
        $ua_lower = strtolower($user_agent);
        
        foreach ($priority_agents as $name => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($ua_lower, $pattern) !== false) {
                    return $name;
                }
            }
        }
        
        // Generic classification
        if (strpos($ua_lower, 'bot') !== false) return 'Generic Bot';
        if (strpos($ua_lower, 'crawler') !== false) return 'Generic Crawler';
        if (strpos($ua_lower, 'spider') !== false) return 'Generic Spider';
        if (strpos($ua_lower, 'curl') !== false) return 'cURL';
        if (strpos($ua_lower, 'python') !== false) return 'Python Script';
        if (strpos($ua_lower, 'java') !== false) return 'Java Client';
        if (strpos($ua_lower, 'go-http') !== false) return 'Go Client';
        
        return 'Unknown Agent';
    }
}
