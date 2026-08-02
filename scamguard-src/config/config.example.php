<?php
/**
 * ScamGuard — Main Configuration
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'scamguard');
define('DB_USER', 'scamguard_user');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

define('SITE_URL', 'https://www.chillflix.lol/scamguard');
define('BASE_PATH', '/scamguard');
define('SITE_ENV', 'production');

define('GOOGLE_SAFE_BROWSING_API_KEY', '');
define('IPINFO_API_TOKEN', '');
// Optional remote HTML fetch when Cloudflare blocks curl (no local Chrome).
// Put {url} where the target URL should be inserted (URL-encoded by ScamGuard).
// Example ScrapingBee: 'https://app.scrapingbee.com/api/v1/?api_key=KEY&url={url}&render_js=false'
define('CONTENT_FETCH_API_URL', '');
// Optional AI second opinion (OpenAI-compatible chat completions). Empty = rule-based analyst only.
// Groq example: AI_API_URL=https://api.groq.com/openai/v1/chat/completions  AI_MODEL=llama-3.1-8b-instant
define('AI_API_KEY', '');
define('AI_API_URL', 'https://api.openai.com/v1/chat/completions');
define('AI_MODEL', 'gpt-4o-mini');

define('APP_SECRET', 'CHANGE_ME');
define('CRON_SECRET', 'CHANGE_ME');

if (SITE_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

date_default_timezone_set('UTC');
