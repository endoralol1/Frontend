<?php
/**
 * Seed and refresh well-known legitimate domains so the database isn't only threat-feed scams.
 *
 * Suggested cron (hourly):
 *   php /path/to/scamguard/cron/seed-popular.php
 */

require_once __DIR__ . '/../includes/DomainRepository.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    if (($_GET['key'] ?? '') !== CRON_SECRET) {
        http_response_code(403);
        die('Forbidden.');
    }
    header('Content-Type: text/plain');
}

function seed_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

$popular = [
    'google.com', 'youtube.com', 'facebook.com', 'wikipedia.org', 'amazon.com',
    'apple.com', 'microsoft.com', 'github.com', 'reddit.com', 'netflix.com',
    'twitter.com', 'x.com', 'instagram.com', 'linkedin.com', 'yahoo.com',
    'bing.com', 'cloudflare.com', 'bbc.com', 'nytimes.com', 'cnn.com',
    'paypal.com', 'ebay.com', 'walmart.com', 'target.com', 'bestbuy.com',
    'adobe.com', 'dropbox.com', 'spotify.com', 'twitch.tv', 'discord.com',
    'steamcommunity.com', 'steampowered.com', 'openai.com', 'chatgpt.com',
    'mozilla.org', 'wordpress.com', 'blogger.com', 'medium.com', 'substack.com',
    'craigslist.org', 'imdb.com', 'booking.com', 'airbnb.com', 'uber.com',
    'zoom.us', 'slack.com', 'shopify.com', 'stripe.com', 'visa.com',
    'mastercard.com', 'chase.com', 'bankofamerica.com', 'wellsfargo.com',
    'irs.gov', 'usa.gov', 'nhs.uk', 'gov.uk', 'europa.eu',
    'chillflix.lol', 'example.com', 'google.co.uk', 'amazon.co.uk',
];

$batch = max(3, min(25, (int) get_setting('seed_popular_batch_size', '10')));
$repo = new DomainRepository();

// Rotate through the list by hour so we gradually cover everyone.
$offset = ((int) date('G') * 3 + (int) date('j')) % count($popular);
$slice = [];
for ($i = 0; $i < $batch; $i++) {
    $slice[] = $popular[($offset + $i) % count($popular)];
}

$checked = 0;
foreach ($slice as $domain) {
    $domain = normalize_domain($domain);
    if (!$domain) {
        continue;
    }
    $existing = $repo->find($domain);
    if ($existing && !$repo->isStale($existing) && !$repo->isIncomplete($existing)) {
        seed_log("Skip fresh: $domain");
        continue;
    }
    try {
        $repo->getOrCheck($domain, 'popular_seed');
        $checked++;
        seed_log("Checked: $domain");
    } catch (Throwable $e) {
        seed_log("Error $domain: " . $e->getMessage());
    }
}

seed_log("Popular seed complete. checked=$checked");
