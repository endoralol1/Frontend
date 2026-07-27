<?php
/**
 * External reputation lookups that ScamAdviser-style UIs surface:
 * Trustpilot reviews, multi-RBL abuse/spam, multi-engine web safety (URLVoid),
 * reverse-IP neighbors, and origin IP DNSBL checks.
 */
class ExternalReputation
{
    private string $domain;
    private string $cacheDir;
    /** @var array{ip?:?string,is_cloudflare?:bool,uses_cdn?:bool} */
    private array $ctx;

    public function __construct(string $domain, array $ctx = [])
    {
        $this->domain = strtolower(trim($domain));
        $this->ctx = $ctx;
        $this->cacheDir = __DIR__ . '/../storage/cache/reputation';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }
    }

    /** @return array{signals:array<int,array>,score_delta:int,spam_hit:int,review_penalty:int} */
    public function collect(): array
    {
        $signals = [];
        $delta = 0;
        $spamHit = 0;
        $reviewPenalty = 0;

        $tp = $this->trustpilot();
        $signals[] = $tp['signal'];
        $reviewPenalty += (int) $tp['penalty'];
        $delta -= (int) $tp['penalty'];
        if (!empty($tp['bonus'])) {
            $delta += (int) $tp['bonus'];
        }

        $sj = $this->sitejabber();
        $signals[] = $sj['signal'];
        $reviewPenalty += (int) $sj['penalty'];
        $delta -= (int) $sj['penalty'];

        $abuse = $this->abuseBlacklists();
        $signals[] = $abuse['signal'];
        if (!empty($abuse['hit'])) {
            $spamHit = 1;
            $delta -= (int) $abuse['penalty'];
        } else {
            $delta += (int) ($abuse['bonus'] ?? 0);
        }

        $safety = $this->urlvoidSafety();
        $signals[] = $safety['signal'];
        $delta += (int) $safety['delta'];

        $neighbors = $this->reverseIpNeighbors();
        $signals[] = $neighbors['signal'];
        $delta += (int) ($neighbors['delta'] ?? 0);

        $ipBl = $this->originIpBlacklists();
        $signals[] = $ipBl['signal'];
        $delta += (int) ($ipBl['delta'] ?? 0);
        if (!empty($ipBl['hit'])) {
            $spamHit = 1;
        }

        return [
            'signals' => $signals,
            'score_delta' => $delta,
            'spam_hit' => $spamHit,
            'review_penalty' => $reviewPenalty,
        ];
    }

    /** @return array{signal:array,penalty:int,bonus:int} */
    private function trustpilot(): array
    {
        $cached = $this->cacheGet('trustpilot');
        if ($cached === null) {
            $md = $this->httpGet('https://r.jina.ai/https://www.trustpilot.com/review/' . rawurlencode($this->domain), 25);
            $cached = [
                'ok' => is_string($md) && $md !== '' && !str_contains($md, 'Just a moment'),
                'raw' => is_string($md) ? substr($md, 0, 120000) : '',
            ];
            $this->cacheSet('trustpilot', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return [
                'signal' => $this->sig(
                    'reputation',
                    'Trustpilot reviews',
                    'Unavailable',
                    'Could not fetch Trustpilot profile right now.',
                    'neutral'
                ),
                'penalty' => 0,
                'bonus' => 0,
            ];
        }

        $raw = (string) $cached['raw'];
        $score = null;
        $count = null;

        if (preg_match('/rated\s+"([^"]+)"\s+with\s+([0-9.]+)\s*\/\s*5/i', $raw, $m)) {
            $score = (float) $m[2];
        } elseif (preg_match('/TrustScore\s+([0-9.]+)\s+out of\s+5/i', $raw, $m)) {
            $score = (float) $m[1];
        } elseif (preg_match('/\n([0-9]\.[0-9])\n/', $raw, $m)) {
            $score = (float) $m[1];
        }

        if (preg_match('/Reviews\s*\n\s*\n?\s*([0-9][0-9,]*)/i', $raw, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        } elseif (preg_match('/\b([0-9]{1,4})\s*\n\s*•/u', $raw, $m)) {
            $count = (int) $m[1];
        }

        $scamish = (bool) preg_match('/\b(scam|fraud|stolen|nulled|malware|fake|ripoff|rip-off)\b/i', $raw);

        if ($score === null && $count === null) {
            return [
                'signal' => $this->sig(
                    'reputation',
                    'Trustpilot reviews',
                    'No profile / unreadable',
                    'Trustpilot page fetched but score could not be parsed.',
                    'neutral'
                ),
                'penalty' => 0,
                'bonus' => 0,
            ];
        }

        $label = ($score !== null ? number_format($score, 1) . '/5' : 'n/a')
            . ($count !== null ? ' · ' . number_format($count) . ' reviews' : '');

        $penalty = 0;
        $bonus = 0;
        $tone = 'neutral';
        $note = 'Live Trustpilot consumer reviews.';

        if ($score !== null && $count !== null && $count >= 3) {
            if ($score <= 2.0 && $scamish) {
                $penalty = min(28, 12 + (int) floor($count / 3));
                $tone = 'bad';
                $note = 'Low Trustpilot score with scam/fraud language in reviews.';
            } elseif ($score <= 2.5) {
                $penalty = min(16, 6 + (int) floor($count / 8));
                $tone = 'warn';
                $note = 'Low Trustpilot score — treat with caution (can also happen on large brands).';
            } elseif ($score >= 4.0 && $count >= 10) {
                $bonus = 6;
                $tone = 'good';
                $note = 'Strong Trustpilot score from multiple reviews.';
            } else {
                $tone = 'neutral';
            }
        } elseif ($score !== null && $score <= 2.0) {
            $penalty = 8;
            $tone = 'warn';
        }

        return [
            'signal' => $this->sig('reputation', 'Trustpilot reviews', $label, $note, $tone),
            'penalty' => $penalty,
            'bonus' => $bonus,
        ];
    }

    /** @return array{signal:array,penalty:int} */
    private function sitejabber(): array
    {
        $cached = $this->cacheGet('sitejabber');
        if ($cached === null) {
            $json = $this->httpGet('https://api.smartcustomer.com/v2/businesses/' . rawurlencode($this->domain), 12);
            $payload = json_decode($json ?: '', true);
            $cached = is_array($payload) ? $payload : ['error' => true];
            $this->cacheSet('sitejabber', $cached, 21600);
        }

        if (!empty($cached['error']) || empty($cached['display_address'])) {
            return [
                'signal' => $this->sig(
                    'reputation',
                    'Sitejabber / SmartCustomer',
                    'Unavailable',
                    'Could not load Sitejabber business profile.',
                    'neutral'
                ),
                'penalty' => 0,
            ];
        }

        $reviews = (int) ($cached['review_numbers']['reviews'] ?? 0);
        $sj = isset($cached['sj_score']) ? (float) $cached['sj_score'] : null;
        $neg = (int) ($cached['reviews_distribution']['negative'] ?? 0);
        $pos = (int) ($cached['positive_reviews_number'] ?? 0);

        // Sitejabber sometimes returns 0-100 style scores.
        $sjStars = $sj;
        $sjLabel = null;
        if ($sj !== null) {
            if ($sj > 5) {
                $sjStars = max(0, min(5, $sj / 20));
                $sjLabel = number_format($sj, 0) . '/100';
            } else {
                $sjLabel = number_format($sj, 1) . '/5';
            }
        }

        $penalty = 0;
        $tone = 'neutral';
        $value = ($sjLabel ?: 'Profile found') . ' · ' . $reviews . ' reviews';

        if ($reviews > 0 && $sjStars !== null && $sjStars <= 2.0) {
            $penalty = min(18, 8 + $neg * 2);
            $tone = 'bad';
            $note = 'Negative Sitejabber/SmartCustomer rating.';
        } elseif ($reviews === 0) {
            $note = 'No Sitejabber reviews yet.';
            $tone = 'neutral';
        } elseif ($sjStars !== null && $sjStars >= 4.0) {
            $note = 'Positive Sitejabber rating.';
            $tone = 'good';
        } else {
            $note = "Sitejabber reviews: {$pos} positive mentioned in profile metadata.";
        }

        return [
            'signal' => $this->sig('reputation', 'Sitejabber / SmartCustomer', $value, $note, $tone),
            'penalty' => $penalty,
        ];
    }

    /** Public RBL sweep via MXToolbox (closest free stand-in for iQ Abuse Scan). */
    private function abuseBlacklists(): array
    {
        $cached = $this->cacheGet('mxtoolbox');
        if ($cached === null) {
            $url = 'https://r.jina.ai/https://mxtoolbox.com/SuperTool.aspx?action=blacklist%3a'
                . rawurlencode($this->domain) . '&run=toolpage';
            $md = $this->httpGet($url, 35);
            $cached = [
                'ok' => is_string($md) && str_contains($md, 'Blacklist'),
                'raw' => is_string($md) ? substr($md, 0, 80000) : '',
            ];
            $this->cacheSet('mxtoolbox', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Abuse / spam blacklists',
                    'Unavailable',
                    'Public RBL sweep failed. (iQ Abuse Scan itself is partner-only; this is the free equivalent.)',
                    'neutral'
                ),
                'hit' => 0,
                'penalty' => 0,
                'bonus' => 0,
            ];
        }

        $raw = (string) $cached['raw'];
        $listed = 0;
        if (preg_match('/Listed\s+\*\*?(\d+)\*\*?\s+times/i', $raw, $m)) {
            $listed = (int) $m[1];
        } elseif (preg_match('/Listed\s+(\d+)\s+times/i', $raw, $m)) {
            $listed = (int) $m[1];
        }

        if ($listed > 0) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Abuse / spam blacklists',
                    'Listed on ' . $listed . ' list(s)',
                    'Public multi-RBL scan (MXToolbox). Closest free equivalent to ScamAdviser iQ Abuse Scan.',
                    'bad'
                ),
                'hit' => 1,
                'penalty' => min(25, 10 + $listed * 3),
                'bonus' => 0,
            ];
        }

        return [
            'signal' => $this->sig(
                'threat',
                'Abuse / spam blacklists',
                'Clean on public RBLs',
                'Swept ~68 public blacklists via MXToolbox. Not the proprietary iQ Abuse feed, but same class of signal.',
                'good'
            ),
            'hit' => 0,
            'penalty' => 0,
            'bonus' => 3,
        ];
    }

    /**
     * Same-server neighbor domains via reverse IP (HackerTarget).
     * Skipped on Cloudflare / shared CDN anycast IPs — too noisy.
     */
    private function reverseIpNeighbors(): array
    {
        $ip = trim((string) ($this->ctx['ip'] ?? ''));
        $skip = !empty($this->ctx['is_cloudflare']) || !empty($this->ctx['uses_cdn']);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'No origin IPv4',
                    'Need a public A record to reverse-look up co-hosted domains.',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }
        if ($skip) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'Skipped (CDN / Cloudflare)',
                    'Reverse-IP neighbor checks are noisy on shared CDN anycast addresses.',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }

        $cached = $this->cacheGet('revip');
        if ($cached === null) {
            $body = $this->httpGet('https://api.hackertarget.com/reverseiplookup/?q=' . rawurlencode($ip), 12);
            $lines = [];
            $error = false;
            if (is_string($body) && $body !== '') {
                if (preg_match('/error|api count|limit|no records/i', $body) && !str_contains($body, "\n")) {
                    $error = true;
                } else {
                    foreach (preg_split('/\r?\n/', trim($body)) as $line) {
                        $line = strtolower(trim($line));
                        if ($line === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $line)) {
                            continue;
                        }
                        if ($line === $this->domain || str_ends_with($line, '.' . $this->domain)) {
                            continue;
                        }
                        $lines[] = $line;
                    }
                    $lines = array_values(array_unique($lines));
                }
            } else {
                $error = true;
            }
            $cached = [
                'ok' => !$error,
                'count' => count($lines),
                'sample' => array_slice($lines, 0, 8),
            ];
            $this->cacheSet('revip', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'Unavailable',
                    'Reverse IP lookup failed or rate-limited.',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }

        $count = (int) ($cached['count'] ?? 0);
        $sample = is_array($cached['sample'] ?? null) ? $cached['sample'] : [];
        $sampleNote = $sample ? (' e.g. ' . implode(', ', array_slice($sample, 0, 4))) : '';

        if ($count === 0) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'None found',
                    'No other hostnames reported on this origin IP.',
                    'good'
                ),
                'delta' => 2,
            ];
        }

        // Dense shared hosts are weakly correlated with disposable scam kits.
        if ($count >= 80) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    $count . ' co-hosted domains',
                    'Very crowded origin IP — common on cheap shared hosting used by disposable sites.' . $sampleNote,
                    'warn'
                ),
                'delta' => -8,
            ];
        }
        if ($count >= 25) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    $count . ' co-hosted domains',
                    'Busy shared host; soft caution only.' . $sampleNote,
                    'warn'
                ),
                'delta' => -4,
            ];
        }

        return [
            'signal' => $this->sig(
                'hosting',
                'Same-server neighbors',
                $count . ' co-hosted domain(s)',
                'Other hostnames share this origin IP.' . $sampleNote,
                'neutral'
            ),
            'delta' => 0,
        ];
    }

    /**
     * Origin IP DNSBL checks (skipped on Cloudflare/CDN).
     * Complements domain RBL sweep with host-level abuse lists.
     */
    private function originIpBlacklists(): array
    {
        $ip = trim((string) ($this->ctx['ip'] ?? ''));
        $skip = !empty($this->ctx['is_cloudflare']) || !empty($this->ctx['uses_cdn']);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Origin IP blacklists',
                    'No origin IPv4',
                    'Cannot DNSBL-check without a public A record.',
                    'neutral'
                ),
                'delta' => 0,
                'hit' => 0,
            ];
        }
        if ($skip) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Origin IP blacklists',
                    'Skipped (CDN / Cloudflare)',
                    'CDN edge IPs are shared; listing them would false-positive many sites.',
                    'neutral'
                ),
                'delta' => 0,
                'hit' => 0,
            ];
        }

        $cached = $this->cacheGet('ipdnsbl');
        if ($cached === null) {
            $lists = [
                'zen.spamhaus.org',
                'bl.spamcop.net',
                'b.barracudacentral.org',
                'dnsbl.sorbs.net',
                'cbl.abuseat.org',
            ];
            $hits = [];
            $parts = explode('.', $ip);
            $rev = $parts[3] . '.' . $parts[2] . '.' . $parts[1] . '.' . $parts[0];
            foreach ($lists as $zone) {
                $q = $rev . '.' . $zone;
                $answers = @dns_get_record($q, DNS_A);
                if (!is_array($answers) || !$answers) {
                    continue;
                }
                foreach ($answers as $ans) {
                    $a = (string) ($ans['ip'] ?? '');
                    // Real listings are typically 127.0.0.2–127.0.0.255; skip policy/error codes.
                    if (preg_match('/^127\.0\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/', $a)) {
                        $hits[] = $zone;
                        break;
                    }
                }
            }
            $cached = [
                'ok' => true,
                'hits' => array_values(array_unique($hits)),
            ];
            $this->cacheSet('ipdnsbl', $cached, 21600);
        }

        $hits = is_array($cached['hits'] ?? null) ? $cached['hits'] : [];
        $n = count($hits);
        if ($n === 0) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Origin IP blacklists',
                    'Clean on checked DNSBLs',
                    'Origin IP not listed on Spamhaus/SpamCop/Barracuda/SORBS/CBL.',
                    'good'
                ),
                'delta' => 3,
                'hit' => 0,
            ];
        }

        return [
            'signal' => $this->sig(
                'threat',
                'Origin IP blacklists',
                'Listed on ' . $n . ' DNSBL(s)',
                'Hit: ' . implode(', ', $hits),
                'bad'
            ),
            'delta' => -min(22, 8 + $n * 4),
            'hit' => 1,
        ];
    }

    /** Multi-engine web filter reputation (URLVoid) — stand-in for DNSFilter labels. */
    private function urlvoidSafety(): array
    {
        $cached = $this->cacheGet('urlvoid');
        if ($cached === null) {
            $html = $this->httpGet('https://www.urlvoid.com/scan/' . rawurlencode($this->domain) . '/', 20);
            $dets = null;
            $total = null;
            if (is_string($html) && preg_match('/Detections Counts.*?(\d+)\s*\/\s*(\d+)/is', $html, $m)) {
                $dets = (int) $m[1];
                $total = (int) $m[2];
            } elseif (is_string($html) && preg_match('/label-[a-z]+[^>]*>\s*(\d+)\s*\/\s*(\d+)/i', $html, $m)) {
                $dets = (int) $m[1];
                $total = (int) $m[2];
            }
            $cached = [
                'ok' => $dets !== null,
                'detections' => $dets,
                'total' => $total,
            ];
            $this->cacheSet('urlvoid', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return [
                'signal' => $this->sig(
                    'reputation',
                    'Web safety engines',
                    'Unavailable',
                    'URLVoid multi-engine lookup failed. (DNSFilter itself needs a paid API key.)',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }

        $d = (int) $cached['detections'];
        $t = (int) $cached['total'];
        $value = $d . '/' . $t . ' detections';

        if ($d === 0) {
            return [
                'signal' => $this->sig(
                    'reputation',
                    'Web safety engines',
                    $value,
                    'URLVoid engines report clean — free stand-in for DNSFilter-style safety labeling.',
                    'good'
                ),
                'delta' => 5,
            ];
        }

        return [
            'signal' => $this->sig(
                'reputation',
                'Web safety engines',
                $value,
                'One or more URLVoid safety/blacklist engines flagged this host.',
                'bad'
            ),
            'delta' => -min(20, 6 + $d * 4),
        ];
    }

    private function sig(string $group, string $label, string $value, string $note, string $tone): array
    {
        return compact('group', 'label', 'value', 'note', 'tone');
    }

    private function cacheGet(string $key): ?array
    {
        $file = $this->cacheDir . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', $this->domain . '__' . $key) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || empty($payload['expires']) || $payload['expires'] < time()) {
            return null;
        }
        return is_array($payload['data'] ?? null) ? $payload['data'] : null;
    }

    private function cacheSet(string $key, array $data, int $ttl): void
    {
        $file = $this->cacheDir . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', $this->domain . '__' . $key) . '.json';
        @file_put_contents($file, json_encode([
            'expires' => time() + $ttl,
            'data' => $data,
        ]));
    }

    private function httpGet(string $url, int $timeout = 12): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ScamGuardBot/1.3; +' . (defined('SITE_URL') ? SITE_URL : 'https://www.chillflix.lol/scamguard') . ')',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/json,text/plain,*/*',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            return null;
        }
        return (string) $body;
    }
}
