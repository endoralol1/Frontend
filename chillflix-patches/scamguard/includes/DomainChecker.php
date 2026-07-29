<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ThreatFeeds.php';
require_once __DIR__ . '/ExternalReputation.php';
require_once __DIR__ . '/AiAnalyst.php';

/**
 * DomainChecker — multi-source trust signals (curl only; no headless browser).
 * When Cloudflare blocks the live homepage, we fall back to Wayback / optional
 * remote fetch API — never local Chrome (too much RAM on the VPS).
 */
class DomainChecker
{
    private string $domain;
    private array $data = [];
    private array $signals = [];
    private bool $turboFast = false;
    private ?int $lastRedirectCount = null;
    private ?string $lastFinalUrl = null;
    private ?int $lastHttpStatus = null;
    private ?int $lastCurlErrno = null;
    private ?string $lastCurlError = null;

    /** Real Chrome UA for homepage fetches — ScamGuardBot UA triggers CF harder. */
    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

    /** Where a discovery candidate came from: 'malware'|'phishing'|'popular_strong'|'popular'|null */
    private ?string $sourceClass = null;

    public function __construct(string $domain, private bool $fast = false, ?string $sourceClass = null)
    {
        $this->domain = $domain;
        $this->sourceClass = $sourceClass;
        // Discovery mode optimized for maximum throughput. It skips slow external
        // waits (RDAP/WHOIS, TLS socket, IP metadata) and stores a lightweight row.
        // User-opened pages still run full scans.
        $this->turboFast = $fast && get_setting('discovery_turbo_fast', '1') === '1';
    }

    public function run(): array
    {
        $this->data['domain'] = $this->domain;
        $this->data['malware_hit'] = 0;
        $this->data['phishing_hit'] = 0;
        $this->data['heuristic_flags'] = null;
        $this->data['verdict'] = 'unknown';
        $this->data['verdict_reasons'] = null;

        $this->data['content_incomplete'] = 0;
        $this->data['content_source'] = null;
        $this->data['site_availability'] = 'unknown'; // up|challenge|down|unreachable|unknown
        $this->data['domain_age_scope'] = 'unknown'; // exact|parent|platform|unknown
        $this->data['parent_domain'] = null;
        $this->data['parent_domain_age_days'] = null;
        $this->data['ai_score_delta'] = 0;
        $this->data['analyst_lean'] = null;
        $this->data['analyst_summary'] = null;
        $this->data['registrar_risk'] = 0;
        $this->data['spam_hit'] = 0;
        $this->data['tranco_rank'] = null;
        $this->data['tranco_bonus'] = 0;
        $this->data['review_penalty'] = 0;
        $this->data['check_mode'] = $this->turboFast ? 'turbo' : ($this->fast ? 'fast' : 'full');

        if ($this->turboFast) {
            $this->setUnknownRegistration();
            $this->setUnknownSsl();
        } else {
            $this->checkWhois();
            $this->checkSsl();
        }
        $this->checkDns();
        // Fast discovery skips slow HTML + external reputation (Trustpilot/RBL/URLVoid).
        if (!$this->fast) {
            $this->checkContent();
            $this->checkSpamReputation();
        } else {
            $this->data['has_contact_info'] = 0;
            $this->data['has_privacy_policy'] = 0;
            $this->data['has_phone'] = 0;
            $this->data['free_email_contact'] = 0;
            $this->data['noindex'] = 0;
            $this->data['crypto_only_payment'] = 0;
            $this->data['suspicious_keyword_hits'] = 0;
            $this->data['redirect_count'] = 0;
            $this->addSignal(
                'content',
                'Check mode',
                $this->turboFast ? 'Turbo discovery' : 'Fast discovery',
                $this->turboFast
                    ? 'Throughput mode: DNS + local feeds + heuristics only. Full scan runs when someone opens the report.'
                    : 'Full page + review engines run when someone opens the report',
                'neutral'
            );
        }
        if ($this->turboFast) {
            $this->applyProvenanceClassification();
        } else {
            $this->checkThreatFeeds();
        }
        $this->checkHeuristics();
        $this->scoreRegistrarReputation();
        if (!$this->fast) {
            $this->checkReputationExtras();
            $this->applyAnalystOpinion();
        } else {
            $this->data['external_score_delta'] = 0;
            $this->data['local_review_penalty'] = 0;
            $this->data['ai_score_delta'] = 0;
            $this->data['analyst_lean'] = null;
            $this->data['analyst_summary'] = null;
        }

        $score = $this->calculateScore();
        $this->data['trust_score'] = $score;
        $this->buildVerdict($score);
        $this->data['status'] = $this->mapVerdictToStatus((string) $this->data['verdict'], $score);
        $this->data['signals_json'] = json_encode($this->signals, JSON_UNESCAPED_SLASHES);

        return $this->data;
    }

    private function addSignal(string $group, string $label, $value, string $note = '', string $tone = 'neutral'): void
    {
        $this->signals[] = [
            'group' => $group,
            'label' => $label,
            'value' => $value,
            'note' => $note,
            'tone' => $tone,
        ];
    }

    private function setUnknownRegistration(): void
    {
        $this->data['whois_registrar'] = null;
        $this->data['whois_created_at'] = null;
        $this->data['whois_expires_at'] = null;
        $this->data['whois_privacy_protected'] = null;
        $this->data['domain_age_days'] = null;
        $this->data['registration_length_days'] = null;
        $this->addSignal(
            'registration',
            'WHOIS / RDAP',
            'Skipped (turbo)',
            'Discovery throughput mode skips slow registry lookups. Full report refreshes this.',
            'neutral'
        );
    }

    private function setUnknownSsl(): void
    {
        $this->data['ssl_valid'] = null;
        $this->data['ssl_issuer'] = null;
        $this->data['ssl_expires_at'] = null;
        $this->addSignal(
            'ssl',
            'HTTPS / TLS',
            'Skipped (turbo)',
            'Discovery throughput mode skips TLS handshakes. Full report refreshes this.',
            'neutral'
        );
    }

    // -------------------------------------------------------------
    // WHOIS / RDAP (+ whois CLI fallback)
    // -------------------------------------------------------------
    private function checkWhois(): void
    {
        $this->data['whois_registrar'] = null;
        $this->data['whois_created_at'] = null;
        $this->data['whois_expires_at'] = null;
        $this->data['whois_privacy_protected'] = null;
        $this->data['domain_age_days'] = null;
        $this->data['registration_length_days'] = null;
        $this->data['domain_age_scope'] = 'unknown';
        $this->data['parent_domain'] = null;
        $this->data['parent_domain_age_days'] = null;

        $apex = registrable_domain($this->domain) ?: $this->domain;
        $isSub = is_subdomain_hostname($this->domain);
        $isPlatform = is_platform_hosted_domain($this->domain);
        // Subdomains rarely have their own registry record — look up the registrable parent.
        $lookupDomain = ($isSub || $isPlatform) ? $apex : $this->domain;

        $parsed = $this->fetchRdap($lookupDomain);
        if (!$parsed) {
            $parsed = $this->fetchWhoisCli($lookupDomain);
        }
        // If apex lookup failed and we queried a subdomain path somehow, try exact once.
        if (!$parsed && strcasecmp($lookupDomain, $this->domain) !== 0) {
            $parsed = $this->fetchRdap($this->domain) ?: $this->fetchWhoisCli($this->domain);
            if ($parsed) {
                $lookupDomain = $this->domain;
                $isSub = false;
            }
        }

        if (!$parsed) {
            $this->addSignal('registration', 'WHOIS / RDAP', 'Unavailable', 'Registry lookup timed out or returned no data', 'warn');
            return;
        }

        $this->data['whois_registrar'] = $parsed['registrar'] ?? null;
        $this->data['whois_privacy_protected'] = !empty($parsed['privacy']) ? 1 : 0;

        $createdTs = !empty($parsed['created']) ? strtotime($parsed['created']) : false;
        $ageDays = ($createdTs) ? (int) floor((time() - $createdTs) / 86400) : null;
        if ($createdTs) {
            $this->data['whois_created_at'] = date('Y-m-d', $createdTs);
        }

        if (!empty($parsed['expires'])) {
            $expiresTs = strtotime($parsed['expires']);
            if ($expiresTs) {
                $this->data['whois_expires_at'] = date('Y-m-d', $expiresTs);
                if ($createdTs) {
                    $this->data['registration_length_days'] = (int) floor(($expiresTs - $createdTs) / 86400);
                }
            }
        }

        if ($isPlatform) {
            // AWS/Azure/Vercel/etc. platform age is not the tenant site's age.
            $this->data['domain_age_scope'] = 'platform';
            $this->data['parent_domain'] = $apex;
            $this->data['parent_domain_age_days'] = $ageDays;
            $this->data['domain_age_days'] = null; // do not score age trust
        } elseif ($isSub) {
            $this->data['domain_age_scope'] = 'parent';
            $this->data['parent_domain'] = $apex;
            $this->data['parent_domain_age_days'] = $ageDays;
            $this->data['domain_age_days'] = null; // hostname age unknown
        } else {
            $this->data['domain_age_scope'] = 'exact';
            $this->data['domain_age_days'] = $ageDays;
        }

        $this->addSignal(
            'registration',
            'Registrar',
            $this->data['whois_registrar'] ?? 'Unknown',
            ($parsed['source'] ?? '') . ($lookupDomain !== $this->domain ? (' · looked up ' . $lookupDomain) : ''),
            'neutral'
        );

        if ($this->data['domain_age_scope'] === 'exact') {
            $age = $this->data['domain_age_days'];
            $ageTone = 'neutral';
            if ($age !== null) {
                $ageTone = $age < 30 ? 'bad' : ($age < 180 ? 'warn' : 'good');
            }
            $this->addSignal(
                'registration',
                'Domain age',
                $age !== null ? number_format($age) . ' days' : 'Unknown',
                $this->data['whois_created_at'] ? ('Registered ' . $this->data['whois_created_at']) : '',
                $ageTone
            );
        } elseif ($this->data['domain_age_scope'] === 'parent') {
            $parentAge = $this->data['parent_domain_age_days'];
            $this->addSignal(
                'registration',
                'Parent domain age',
                $parentAge !== null
                    ? (number_format($parentAge) . ' days · ' . ($this->data['parent_domain'] ?? $apex))
                    : ('Unknown · ' . ($this->data['parent_domain'] ?? $apex)),
                'This is a subdomain. Parent registration age is informational only — the subdomain itself could be new.',
                'neutral'
            );
            $this->addSignal(
                'registration',
                'Domain age',
                'Unknown (subdomain)',
                'Hostname age is not the same as parent domain age, so we do not treat this site as “old”.',
                'neutral'
            );
        } else { // platform
            $parentAge = $this->data['parent_domain_age_days'];
            $this->addSignal(
                'registration',
                'Platform host age',
                $parentAge !== null
                    ? (number_format($parentAge) . ' days · shared platform')
                    : 'Shared cloud / SaaS host',
                'Hosted on a third-party platform (AWS/Azure/Vercel/etc.). Platform age is not this site’s age.',
                'neutral'
            );
            $this->addSignal(
                'registration',
                'Domain age',
                'Unknown (platform host)',
                'No tenant-level registration age — not scored as an established domain.',
                'neutral'
            );
        }

        $this->addSignal(
            'registration',
            'Expires',
            $this->data['whois_expires_at'] ?? 'Unknown',
            $this->data['registration_length_days']
                ? ('Term ≈ ' . number_format($this->data['registration_length_days']) . ' days'
                    . ($this->data['domain_age_scope'] !== 'exact' ? ' (parent/platform record)' : ''))
                : '',
            'neutral'
        );
        $this->addSignal(
            'registration',
            'WHOIS privacy',
            $this->data['whois_privacy_protected'] ? 'Enabled' : 'Not detected',
            'Privacy redaction is common and not proof of fraud',
            'neutral'
        );
    }

    private function fetchRdap(string $domain): ?array
    {
        $urls = [
            'https://rdap.org/domain/' . rawurlencode($domain),
            'https://www.rdap.net/domain/' . rawurlencode($domain),
        ];

        // TLD-specific common RDAP servers
        $tld = strtolower((string) substr(strrchr($domain, '.'), 1));
        $tldMap = [
            'com' => 'https://rdap.verisign.com/com/v1/domain/',
            'net' => 'https://rdap.verisign.com/net/v1/domain/',
            'org' => 'https://rdap.publicinterestregistry.org/rdap/domain/',
            'lol' => 'https://rdap.centralnic.com/lol/domain/',
            'io' => 'https://rdap.nic.io/domain/',
            'app' => 'https://rdap.nic.google/domain/',
            'dev' => 'https://rdap.nic.google/domain/',
        ];
        if (isset($tldMap[$tld])) {
            array_unshift($urls, $tldMap[$tld] . rawurlencode($domain));
        }

        foreach ($urls as $url) {
            try {
                $json = $this->httpGet($url, 10);
                if (!$json) {
                    continue;
                }
                $rdap = json_decode($json, true);
                if (!is_array($rdap)) {
                    continue;
                }

                $created = null;
                $expires = null;
                foreach (($rdap['events'] ?? []) as $event) {
                    $action = strtolower((string) ($event['eventAction'] ?? ''));
                    if (in_array($action, ['registration', 'registered'], true)) {
                        $created = $event['eventDate'] ?? $created;
                    }
                    if (in_array($action, ['expiration', 'expired'], true)) {
                        $expires = $event['eventDate'] ?? $expires;
                    }
                }

                $registrar = null;
                foreach (($rdap['entities'] ?? []) as $entity) {
                    if (!in_array('registrar', $entity['roles'] ?? [], true)) {
                        continue;
                    }
                    $registrar = $this->extractVcardFn($entity) ?? ($entity['handle'] ?? null);
                    break;
                }

                $raw = strtolower($json);
                $privacy = (
                    str_contains($raw, 'privacy') ||
                    str_contains($raw, 'redacted') ||
                    str_contains($raw, 'whoisguard') ||
                    str_contains($raw, 'data protected')
                );

                if ($created || $expires || $registrar) {
                    return [
                        'created' => $created,
                        'expires' => $expires,
                        'registrar' => is_string($registrar) ? $registrar : null,
                        'privacy' => $privacy,
                        'source' => 'RDAP (' . parse_url($url, PHP_URL_HOST) . ')',
                    ];
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function extractVcardFn(array $entity): ?string
    {
        $vcard = $entity['vcardArray'][1] ?? null;
        if (!is_array($vcard)) {
            return null;
        }
        foreach ($vcard as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row[0] ?? '') === 'fn' && !empty($row[3])) {
                return (string) $row[3];
            }
            if (($row[0] ?? '') === 'org' && !empty($row[3])) {
                return is_array($row[3]) ? (string) ($row[3][0] ?? '') : (string) $row[3];
            }
        }
        return null;
    }

    private function fetchWhoisCli(string $domain): ?array
    {
        if (!is_executable('/usr/bin/whois') && !is_executable('/bin/whois')) {
            return null;
        }
        $bin = is_executable('/usr/bin/whois') ? '/usr/bin/whois' : '/bin/whois';
        $cmd = escapeshellcmd($bin) . ' -I ' . escapeshellarg($domain) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        if (!$out || strlen($out) < 40) {
            $out = @shell_exec(escapeshellcmd($bin) . ' ' . escapeshellarg($domain) . ' 2>/dev/null');
        }
        if (!$out || strlen($out) < 40) {
            return null;
        }

        $created = $this->matchWhoisDate($out, [
            '/Creation Date:\s*(.+)/i',
            '/Created:\s*(.+)/i',
            '/Created On:\s*(.+)/i',
            '/Domain Registration Date:\s*(.+)/i',
            '/registered:\s*(.+)/i',
        ]);
        $expires = $this->matchWhoisDate($out, [
            '/Registry Expiry Date:\s*(.+)/i',
            '/Registrar Registration Expiration Date:\s*(.+)/i',
            '/Expiration Date:\s*(.+)/i',
            '/Expiry Date:\s*(.+)/i',
            '/paid-till:\s*(.+)/i',
        ]);
        $registrar = null;
        if (preg_match('/Registrar:\s*(.+)/i', $out, $m)) {
            $registrar = trim($m[1]);
        } elseif (preg_match('/Sponsoring Registrar:\s*(.+)/i', $out, $m)) {
            $registrar = trim($m[1]);
        }

        $privacy = (bool) preg_match('/privacy|redacted|whoisguard|data protected/i', $out);

        if (!$created && !$expires && !$registrar) {
            return null;
        }

        return [
            'created' => $created,
            'expires' => $expires,
            'registrar' => $registrar,
            'privacy' => $privacy,
            'source' => 'WHOIS CLI',
        ];
    }

    private function matchWhoisDate(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $ts = strtotime(trim($m[1]));
                if ($ts) {
                    return date('c', $ts);
                }
            }
        }
        return null;
    }

    // -------------------------------------------------------------
    // SSL
    // -------------------------------------------------------------
    private function checkSsl(): void
    {
        $this->data['ssl_valid'] = 0;
        $this->data['ssl_issuer'] = null;
        $this->data['ssl_expires_at'] = null;

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $this->domain,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $this->domain . ':443',
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$client) {
            $this->addSignal('ssl', 'HTTPS / TLS', 'Failed', $errstr ?: 'Could not complete TLS handshake', 'bad');
            return;
        }

        $params = stream_context_get_params($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $sans = [];
        $subject = null;

        if ($cert) {
            $certInfo = openssl_x509_parse($cert);
            if ($certInfo) {
                $this->data['ssl_valid'] = ($certInfo['validTo_time_t'] > time()) ? 1 : 0;
                $this->data['ssl_issuer'] = $certInfo['issuer']['O'] ?? ($certInfo['issuer']['CN'] ?? null);
                $this->data['ssl_expires_at'] = date('Y-m-d', $certInfo['validTo_time_t']);
                $subject = $certInfo['subject']['CN'] ?? null;
                if (!empty($certInfo['extensions']['subjectAltName'])) {
                    foreach (explode(',', $certInfo['extensions']['subjectAltName']) as $san) {
                        $san = trim(str_replace('DNS:', '', $san));
                        if ($san !== '') {
                            $sans[] = $san;
                        }
                    }
                }
            }
        }
        fclose($client);

        $this->addSignal(
            'ssl',
            'Valid SSL',
            $this->data['ssl_valid'] ? 'Yes' : 'No / expired',
            $this->data['ssl_expires_at'] ? ('Expires ' . $this->data['ssl_expires_at']) : '',
            $this->data['ssl_valid'] ? 'good' : 'bad'
        );
        $this->addSignal('ssl', 'Issuer', $this->data['ssl_issuer'] ?? 'Unknown', $subject ? ('CN: ' . $subject) : '', 'neutral');
        if ($sans) {
            $this->addSignal('ssl', 'Certificate SANs', implode(', ', array_slice($sans, 0, 8)), count($sans) > 8 ? ('+' . (count($sans) - 8) . ' more') : '', 'neutral');
        }
    }

    // -------------------------------------------------------------
    // DNS / hosting / CDN / ASN
    // -------------------------------------------------------------
    private function checkDns(): void
    {
        $this->data['ip_address'] = null;
        $this->data['asn'] = null;
        $this->data['asn_org'] = null;
        $this->data['host_country'] = null;
        $this->data['nameservers'] = null;
        $this->data['mx_records'] = null;
        $this->data['has_spf'] = 0;
        $this->data['has_dmarc'] = 0;
        $this->data['uses_cdn'] = 0;
        $this->data['cdn_provider'] = null;

        $aRecords = @dns_get_record($this->domain, DNS_A) ?: [];
        $aaaa = @dns_get_record($this->domain, DNS_AAAA) ?: [];
        $nsRecords = @dns_get_record($this->domain, DNS_NS) ?: [];
        // Turbo throughput mode skips the email-hygiene DNS lookups (MX/TXT/DMARC):
        // they are not scored as negatives and the full report re-runs them anyway.
        $mxRecords = $this->turboFast ? [] : (@dns_get_record($this->domain, DNS_MX) ?: []);
        $txtRecords = $this->turboFast ? [] : (@dns_get_record($this->domain, DNS_TXT) ?: []);

        $ips = [];
        foreach ($aRecords as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        $this->data['ip_address'] = $ips[0] ?? null;

        $names = [];
        foreach ($nsRecords as $r) {
            if (!empty($r['target'])) {
                $names[] = strtolower($r['target']);
            }
        }
        $this->data['nameservers'] = $names ? implode(', ', $names) : null;

        $mx = [];
        foreach ($mxRecords as $r) {
            if (!empty($r['target'])) {
                $mx[] = strtolower($r['target']);
            }
        }
        sort($mx);
        $this->data['mx_records'] = $mx ? implode(', ', $mx) : null;

        $spf = false;
        $dmarc = false;
        $txtJoined = [];
        foreach ($txtRecords as $r) {
            $txt = is_array($r['txt'] ?? null) ? implode('', $r['txt']) : (string) ($r['txt'] ?? '');
            $txtJoined[] = $txt;
            if (stripos($txt, 'v=spf1') === 0 || stripos($txt, 'v=spf1') !== false) {
                $spf = true;
            }
        }
        $dmarcRecs = $this->turboFast ? [] : (@dns_get_record('_dmarc.' . $this->domain, DNS_TXT) ?: []);
        foreach ($dmarcRecs as $r) {
            $txt = is_array($r['txt'] ?? null) ? implode('', $r['txt']) : (string) ($r['txt'] ?? '');
            if (stripos($txt, 'v=dmarc1') !== false) {
                $dmarc = true;
            }
        }
        $this->data['has_spf'] = $spf ? 1 : 0;
        $this->data['has_dmarc'] = $dmarc ? 1 : 0;

        $cdn = $this->detectCdn($names, $ips, $txtJoined);
        if ($cdn) {
            $this->data['uses_cdn'] = 1;
            $this->data['cdn_provider'] = $cdn;
        }

        if ($this->data['ip_address'] && !$this->turboFast) {
            $this->lookupIpMeta($this->data['ip_address']);
        }

        $ipNote = '';
        if ($this->data['uses_cdn']) {
            $ipNote = 'This is a ' . $this->data['cdn_provider'] . ' edge IP (proxy/CDN), not necessarily the origin server. Normal for many legitimate sites.';
        }

        // CDN is informational — most legit sites use Cloudflare/etc. Do NOT mark as warn/bad.
        $this->addSignal('network', 'Resolved IP', $this->data['ip_address'] ?? 'None', $ipNote, 'neutral');
        if (count($ips) > 1) {
            $this->addSignal('network', 'All A/AAAA', implode(', ', array_slice($ips, 0, 6)), '', 'neutral');
        }
        $this->addSignal(
            'network',
            'CDN / proxy',
            $this->data['uses_cdn'] ? ($this->data['cdn_provider'] ?: 'Yes') : 'Not detected',
            $this->data['uses_cdn']
                ? 'Common on legitimate sites (Cloudflare, Fastly, Akamai, etc.). Not a scam signal by itself.'
                : 'No major CDN fingerprint detected.',
            'neutral'
        );
        $this->addSignal('network', 'Nameservers', $this->data['nameservers'] ?? 'Unknown', '', 'neutral');
        $this->addSignal('network', 'Hosting / ASN', $this->data['asn_org'] ?? 'Unknown', $this->data['asn'] ?? '', 'neutral');
        $this->addSignal('network', 'Geo', $this->data['host_country'] ?? 'Unknown', '', 'neutral');
        // Email auth (MX/SPF/DMARC) is hygiene, not a scam signal: plenty of legit
        // sites — especially small ones and landing pages — have none. Reward when
        // present, but stay NEUTRAL (informational) when missing, never a red negative.
        $this->addSignal(
            'email',
            'MX records',
            $this->data['mx_records'] ?: 'None',
            $this->data['mx_records'] ? 'Domain can receive email' : 'No mail exchangers — normal for sites that don’t run email on this domain.',
            $this->data['mx_records'] ? 'good' : 'neutral'
        );
        $this->addSignal('email', 'SPF', $spf ? 'Present' : 'Missing', $spf ? '' : 'Common to omit on small sites; not a scam signal by itself.', $spf ? 'good' : 'neutral');
        $this->addSignal('email', 'DMARC', $dmarc ? 'Present' : 'Missing', $dmarc ? '' : 'Common to omit on small sites; not a scam signal by itself.', $dmarc ? 'good' : 'neutral');
    }

    private function detectCdn(array $nameservers, array $ips, array $txt): ?string
    {
        $nsBlob = implode(' ', $nameservers);
        if (str_contains($nsBlob, 'cloudflare')) {
            return 'Cloudflare';
        }
        if (str_contains($nsBlob, 'akamai') || str_contains($nsBlob, 'edgekey')) {
            return 'Akamai';
        }
        if (str_contains($nsBlob, 'fastly')) {
            return 'Fastly';
        }
        if (preg_match('/awsdns|amazonaws/', $nsBlob)) {
            // NS alone isn't CDN, but common with CloudFront later
        }

        // Cloudflare IP ranges (short sample of common / known via ASN later)
        foreach ($ips as $ip) {
            if ($this->isCloudflareIp($ip)) {
                return 'Cloudflare';
            }
        }

        $txtBlob = strtolower(implode(' ', $txt));
        if (str_contains($txtBlob, 'cloudflare')) {
            return 'Cloudflare';
        }

        return null;
    }

    private function isCloudflareIp(string $ip): bool
    {
        // Conservative common Cloudflare IPv4 blocks (not exhaustive)
        $cidrs = [
            '104.16.0.0/12',
            '104.21.0.0/16',
            '172.64.0.0/13',
            '173.245.48.0/20',
            '188.114.96.0/20',
            '190.93.240.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
        ];
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            [$subnet, $mask] = explode('/', $cidr);
            $mask = (int) $mask;
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - $mask);
            if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                return true;
            }
        }
        return false;
    }

    private function lookupIpMeta(string $ip): void
    {
        // Prefer tokenized ipinfo when configured
        if (IPINFO_API_TOKEN !== '') {
            try {
                $json = $this->httpGet('https://ipinfo.io/' . rawurlencode($ip) . '/json?token=' . IPINFO_API_TOKEN, 5);
                $info = json_decode($json ?? '', true);
                if (is_array($info)) {
                    $org = (string) ($info['org'] ?? '');
                    $this->data['asn'] = explode(' ', $org)[0] ?? null;
                    $this->data['asn_org'] = $org !== '' ? $org : null;
                    $this->data['host_country'] = $info['country'] ?? null;
                    if (!$this->data['cdn_provider'] && stripos($org, 'cloudflare') !== false) {
                        $this->data['uses_cdn'] = 1;
                        $this->data['cdn_provider'] = 'Cloudflare';
                    }
                    return;
                }
            } catch (Throwable $e) {
                // fall through
            }
        }

        // Free fallback: ip-api.com (no key, HTTP)
        try {
            $json = $this->httpGet('http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,isp,org,as,query,proxy,hosting', 5);
            $info = json_decode($json ?? '', true);
            if (is_array($info) && ($info['status'] ?? '') === 'success') {
                $as = (string) ($info['as'] ?? '');
                $org = (string) (($info['org'] ?: $info['isp']) ?? '');
                $this->data['asn'] = explode(' ', $as)[0] ?? null;
                $this->data['asn_org'] = $org !== '' ? $org : ($as ?: null);
                $this->data['host_country'] = $info['countryCode'] ?? null;
                if (!$this->data['cdn_provider'] && (stripos($org, 'cloudflare') !== false || stripos($as, 'cloudflare') !== false)) {
                    $this->data['uses_cdn'] = 1;
                    $this->data['cdn_provider'] = 'Cloudflare';
                }
                return;
            }
        } catch (Throwable $e) {
            // ignore
        }

        // Second free fallback
        try {
            $json = $this->httpGet('https://ipwho.is/' . rawurlencode($ip), 5);
            $info = json_decode($json ?? '', true);
            if (is_array($info) && !empty($info['success'])) {
                $conn = $info['connection'] ?? [];
                $this->data['asn'] = isset($conn['asn']) ? ('AS' . $conn['asn']) : null;
                $this->data['asn_org'] = $conn['org'] ?? ($conn['isp'] ?? null);
                $this->data['host_country'] = $info['country_code'] ?? null;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // -------------------------------------------------------------
    // Content + security headers
    // -------------------------------------------------------------
    private function checkContent(): void
    {
        $this->data['has_contact_info'] = 0;
        $this->data['has_privacy_policy'] = 0;
        $this->data['has_cookie_policy'] = 0;
        $this->data['has_discord'] = 0;
        $this->data['has_phone'] = 0;
        $this->data['free_email_contact'] = 0;
        $this->data['noindex'] = 0;
        $this->data['crypto_only_payment'] = 0;
        $this->data['redirect_count'] = 0;
        $this->data['suspicious_keyword_hits'] = 0;
        $this->data['page_title'] = null;
        $this->data['http_status'] = null;
        $this->data['final_url'] = null;
        $this->data['security_headers'] = null;

        $headersOut = [];
        $liveUrl = 'https://' . $this->domain . '/';
        $html = $this->httpGet($liveUrl, 12, true, $headersOut, true);
        if ($html === null) {
            $liveUrl = 'http://' . $this->domain . '/';
            $html = $this->httpGet($liveUrl, 12, true, $headersOut, true);
        }

        $this->data['http_status'] = $this->lastHttpStatus;
        $this->data['final_url'] = $this->lastFinalUrl;
        $this->data['redirect_count'] = $this->lastRedirectCount ?? 0;
        $this->data['content_source'] = 'live';

        if ($html === null) {
            $why = 'Could not connect to the website over HTTP/HTTPS';
            if ($this->lastCurlError) {
                $why .= ' (' . $this->lastCurlError . ')';
            }
            $this->markSiteUnavailable('unreachable', $why);
            return;
        }

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $this->data['page_title'] = trim(html_entity_decode(strip_tags($m[1])));
        }

        $httpStatus = (int) ($this->data['http_status'] ?? 0);
        // Origin / CDN "site is down" pages are not analyzable business content.
        if ($this->isDownHtml($html, (string) ($this->data['page_title'] ?? ''), $httpStatus)) {
            $this->markSiteUnavailable(
                'down',
                'The website appears to be down or its origin server is not responding'
                . ($httpStatus ? (' (HTTP ' . $httpStatus . ')') : '') . '.'
            );
            return;
        }

        // Bot walls / challenge pages — try low-RAM fallbacks (Wayback, optional API). No Chrome.
        $challenge = $this->isChallengeHtml($html, (string) ($this->data['page_title'] ?? ''), $httpStatus);
        $contentNote = 'Homepage HTML fetched for analysis';
        $contentLabel = 'Readable';
        $contentTone = 'good';

        if ($challenge) {
            $fallback = $this->fetchContentFallback($liveUrl);
            if ($fallback !== null) {
                $html = $fallback['html'];
                $this->data['content_source'] = $fallback['source'];
                $headersOut = $fallback['headers'] ?? [];
                if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m2)) {
                    $this->data['page_title'] = trim(html_entity_decode(strip_tags($m2[1])));
                }
                // Fallback content might still be a down page — treat as unavailable.
                if ($this->isDownHtml($html, (string) ($this->data['page_title'] ?? ''), 200)) {
                    $this->markSiteUnavailable('down', 'Live site looks down; archive/fallback also showed an outage page.');
                    return;
                }
                $challenge = $this->isChallengeHtml(
                    $html,
                    (string) ($this->data['page_title'] ?? ''),
                    200
                );
                if (!$challenge) {
                    $contentLabel = 'Readable via ' . $fallback['source'];
                    $contentNote = $fallback['note'];
                    $contentTone = 'neutral';
                }
            }
        }

        if (!$challenge) {
            $this->data['site_availability'] = 'up';
        } else {
            $this->data['site_availability'] = 'challenge';
        }

        $lower = strtolower($html);

        // Prefer title from Jina markdown when HTML <title> is missing.
        if (($this->data['page_title'] ?? '') === '' || ($this->data['page_title'] ?? null) === null) {
            if (preg_match('/^Title:\s*(.+)$/mi', $html, $tm)) {
                $this->data['page_title'] = trim($tm[1]);
            }
        }

        $legal = $this->detectLegalAndContactSignals($html);
        $this->data['has_contact_info'] = $legal['contact'] ? 1 : 0;
        $this->data['has_privacy_policy'] = $legal['privacy'] ? 1 : 0;
        $this->data['has_cookie_policy'] = $legal['cookies'] ? 1 : 0;
        $this->data['has_discord'] = $legal['discord'] ? 1 : 0;
        $hasTerms = $legal['terms'];

        // If homepage HTML is readable but privacy still missing, probe common legal paths.
        if (!$challenge && !$legal['privacy']) {
            $probed = $this->probeCommonLegalPages();
            if ($probed['privacy']) {
                $this->data['has_privacy_policy'] = 1;
                $legal['privacy'] = true;
            }
            if ($probed['terms']) {
                $hasTerms = true;
            }
            if ($probed['cookies']) {
                $this->data['has_cookie_policy'] = 1;
                $legal['cookies'] = true;
            }
            if ($probed['contact']) {
                $this->data['has_contact_info'] = 1;
                $legal['contact'] = true;
            }
        }

        // Phone / WhatsApp presence (contact verification signal).
        $phoneHit = (bool) (
            preg_match('/tel:\+?[0-9()\s.-]{7,}/i', $html)
            || preg_match('/wa\.me\/\+?[0-9]{7,}/i', $html)
            || preg_match('/\bwhatsapp\b/i', $html)
            || preg_match('/(?:\+|00)?\d{1,3}[\s().-]?\d{2,4}[\s().-]?\d{3,4}[\s().-]?\d{3,4}/', $html)
        );
        $this->data['has_phone'] = $phoneHit ? 1 : 0;

        // Free-mail-only public contacts (gmail/yahoo/etc.) — weak trust signal.
        $freeMail = (bool) preg_match(
            '/mailto:[^"\'\s>]+@(?:gmail|googlemail|yahoo|ymail|hotmail|outlook|live|msn|aol|proton\.?me|protonmail|icloud|mail\.ru|yandex)\./i',
            $html
        );
        $businessMail = (bool) preg_match(
            '/mailto:[^"\'\s>]+@' . preg_quote($this->domain, '/') . '/i',
            $html
        );
        $this->data['free_email_contact'] = ($freeMail && !$businessMail) ? 1 : 0;

        // Hide-from-search / noindex (common on disposable scam landers).
        $robotsMeta = '';
        if (preg_match('/<meta[^>]+name=["\']robots["\'][^>]*>/i', $html, $rm)) {
            $robotsMeta = $rm[0];
        }
        $xRobots = '';
        foreach ($headersOut as $hk => $hv) {
            if (strtolower((string) $hk) === 'x-robots-tag') {
                $xRobots = is_array($hv) ? implode(' ', $hv) : (string) $hv;
            }
        }
        $this->data['noindex'] = (
            preg_match('/\bnoindex\b/i', $robotsMeta)
            || preg_match('/\bnoindex\b/i', $xRobots)
        ) ? 1 : 0;

        $hasLogin = (bool) preg_match('/\b(login|sign in|create account|register)\b/i', $html);
        $hasPayment = (bool) preg_match('/\b(checkout|add to cart|payment|paypal|credit card|billing)\b/i', $html);
        $hasCryptoPay = (bool) preg_match(
            '/\b(bitcoin|btc|ethereum|eth|usdt|usdc|crypto(currency)?|wallet address|send (btc|eth)|coinpayments?|binance pay)\b/i',
            $html
        );
        $hasCardPay = (bool) preg_match(
            '/\b(visa|mastercard|amex|american express|paypal|stripe|apple pay|google pay|credit card|debit card)\b/i',
            $html
        );
        $this->data['crypto_only_payment'] = ($hasCryptoPay && !$hasCardPay) ? 1 : 0;

        $suspiciousPhrases = [
            'verify your account', 'confirm your identity', 'account suspended', 'act now',
            'limited time offer', 'urgent action required', 'wire transfer only',
            'send bitcoin', 'guaranteed profit', 'claim your prize', 'you have won',
            'seed phrase', 'connect your wallet', 'unusual activity detected',
            'nulled', 'cracked script', 'crack download', 'license key generator',
            'warez', 'leaked script', 'null scripts', 'premium nulled',
        ];
        $hits = 0;
        $hitList = [];
        foreach ($suspiciousPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                $hits++;
                $hitList[] = $phrase;
            }
        }
        $this->data['suspicious_keyword_hits'] = $hits;

        // Plain-text excerpt for AI "what is this site about" review (not stored forever in DB columns).
        $this->data['page_excerpt'] = $challenge ? '' : $this->extractPageExcerpt($html);
        $this->data['page_meta_description'] = $challenge ? '' : $this->extractMetaDescription($html);

        $this->data['content_incomplete'] = $challenge ? 1 : 0;
        $challengePageTitle = '';
        if ($challenge) {
            // We never actually saw the real page, so NOTHING read from the challenge
            // HTML is trustworthy — neither positives nor negatives. Zero them all,
            // including suspicious-phrase hits (otherwise the CDN challenge text can
            // trigger a false "scam-style urgency language" flag).
            $this->data['has_contact_info'] = 0;
            $this->data['has_privacy_policy'] = 0;
            $this->data['has_cookie_policy'] = 0;
            $this->data['has_discord'] = 0;
            $this->data['has_phone'] = 0;
            $this->data['free_email_contact'] = 0;
            $this->data['noindex'] = 0;
            $this->data['crypto_only_payment'] = 0;
            $this->data['suspicious_keyword_hits'] = 0;
            $hasTerms = false;
            // Don't store/display the challenge page's own title ("Attention Required!
            // | Cloudflare") as if it were the site's title.
            $challengePageTitle = (string) ($this->data['page_title'] ?? '');
            $this->data['page_title'] = '';
            $this->addSignal(
                'content',
                'Content visibility',
                'Not readable (bot wall)',
                'A CDN/bot challenge hid the page, so content could not be inspected. This is common on legitimate sites and is treated as "unknown", not negative.',
                'neutral'
            );
        } else {
            $this->addSignal('content', 'Content visibility', $contentLabel, $contentNote, $contentTone);
        }

        $sec = $this->scoreSecurityHeaders($headersOut);
        $this->data['security_headers'] = json_encode($sec);

        if ($challenge) {
            // The 403/503 belongs to the CDN bot check, not the origin site — neutral.
            $this->addSignal(
                'content',
                'HTTP status',
                (string) ($this->data['http_status'] ?? 'Unknown'),
                'Status comes from the Cloudflare/CDN bot check, not the site itself — normal for protected sites.',
                'neutral'
            );
            $this->addSignal(
                'content',
                'Page title',
                'Hidden by bot check',
                $challengePageTitle !== '' ? 'Challenge page shows: ' . $challengePageTitle : '',
                'neutral'
            );
        } else {
            $this->addSignal('content', 'HTTP status', (string) ($this->data['http_status'] ?? 'Unknown'), $this->data['final_url'] ?? '', ($this->data['http_status'] ?? 0) >= 400 ? 'bad' : 'good');
            $this->addSignal('content', 'Page title', $this->data['page_title'] ?: 'None', '', 'neutral');
        }
        $this->addSignal('content', 'Redirects', (string) $this->data['redirect_count'], '', $this->data['redirect_count'] > 3 ? 'warn' : 'neutral');
        if (!$challenge) {
            $this->addSignal('content', 'Contact info', $this->data['has_contact_info'] ? 'Found' : 'Not found', '', $this->data['has_contact_info'] ? 'good' : 'warn');
            $this->addSignal(
                'content',
                'Phone / WhatsApp',
                $this->data['has_phone'] ? 'Found' : 'Not found',
                $this->data['has_phone']
                    ? 'Public phone or messaging contact detected on the homepage.'
                    : 'No clear phone / WhatsApp contact on the homepage.',
                $this->data['has_phone'] ? 'good' : 'warn'
            );
            $this->addSignal(
                'content',
                'Contact email type',
                $this->data['free_email_contact'] ? 'Free webmail only' : ($businessMail ? 'Domain / business email' : 'Not detected'),
                $this->data['free_email_contact']
                    ? 'Only free providers (Gmail/Yahoo/Outlook/etc.) — weaker trust signal.'
                    : ($businessMail ? 'Uses an address on this domain.' : ''),
                $this->data['free_email_contact'] ? 'warn' : ($businessMail ? 'good' : 'neutral')
            );
            $this->addSignal(
                'content',
                'Privacy policy',
                $this->data['has_privacy_policy'] ? 'Found' : 'Not found',
                $this->data['has_privacy_policy']
                    ? 'Privacy policy / privacy statement / do-not-sell style link detected.'
                    : 'No clear privacy policy, privacy statement, or privacy-rights link found.',
                $this->data['has_privacy_policy'] ? 'good' : 'warn'
            );
            $this->addSignal(
                'content',
                'Cookie policy',
                !empty($this->data['has_cookie_policy']) ? 'Found' : 'Not found',
                !empty($this->data['has_cookie_policy'])
                    ? 'Cookie policy / cookie preferences link detected.'
                    : 'No clear cookie policy or cookie-preferences link found.',
                !empty($this->data['has_cookie_policy']) ? 'good' : 'neutral'
            );
            $this->addSignal('content', 'Terms page', $hasTerms ? 'Found' : 'Not found', '', $hasTerms ? 'good' : 'neutral');
            $this->addSignal(
                'content',
                'Discord community',
                !empty($this->data['has_discord']) ? 'Link found' : 'Not detected',
                !empty($this->data['has_discord'])
                    ? 'A Discord invite/community link was found. Informational only — not a trust score by itself.'
                    : 'No Discord invite link detected on the inspected page.',
                'neutral'
            );
            $this->addSignal(
                'content',
                'Search indexing',
                $this->data['noindex'] ? 'noindex' : 'Indexable / not blocked',
                $this->data['noindex']
                    ? 'Page asks search engines not to index it — common on disposable landers.'
                    : 'No robots noindex directive detected on the homepage.',
                $this->data['noindex'] ? 'warn' : 'good'
            );
            $this->addSignal('content', 'Login / account UI', $hasLogin ? 'Present' : 'Not detected', '', 'neutral');
            $this->addSignal('content', 'Payment language', $hasPayment ? 'Present' : 'Not detected', $hasPayment ? 'Commerce-style wording detected' : '', $hasPayment ? 'warn' : 'neutral');
            $this->addSignal(
                'content',
                'Crypto-only payments',
                $this->data['crypto_only_payment'] ? 'Likely' : 'Not detected',
                $this->data['crypto_only_payment']
                    ? 'Crypto payment wording without common card/PayPal options.'
                    : ($hasCryptoPay ? 'Crypto mentioned alongside other payment methods.' : ''),
                $this->data['crypto_only_payment'] ? 'bad' : ($hasCryptoPay ? 'warn' : 'neutral')
            );
            $this->addSignal(
                'content',
                'Suspicious phrases',
                $hits === 0 ? 'None' : ($hits . ' hit(s)'),
                $hitList ? implode('; ', array_slice($hitList, 0, 5)) : '',
                $hits === 0 ? 'good' : 'bad'
            );
        }
        $this->addSignal(
            'security',
            'Security headers',
            $sec['score'] . '/' . $sec['max'],
            $challenge
                ? 'Headers may belong to the CDN challenge page, not the origin site'
                : implode(', ', $sec['present'] ?: ['none detected']),
            $challenge ? 'neutral' : ($sec['score'] >= 3 ? 'good' : ($sec['score'] >= 1 ? 'warn' : 'bad'))
        );
    }

    private function scoreRegistrarReputation(): void
    {
        $registrar = strtolower((string) ($this->data['whois_registrar'] ?? ''));
        if ($registrar === '') {
            $this->data['registrar_risk'] = 0;
            return;
        }

        // Registrar is a very weak signal: mainstream budget registrars (Namecheap,
        // Porkbun, etc.) host millions of legitimate sites. Keep only a tiny nudge for
        // the registrars most abused by bulk scam operations, and show it as neutral
        // context — never a red "negative highlight".
        $elevated = [
            'nicenic' => 5,
            'gname' => 5,
            'todaynic' => 5,
            'webnic' => 4,
            'publicdomainregistry' => 4,
            'pdr ltd' => 4,
            'reg.ru' => 3,
        ];
        $risk = 0;
        $matched = null;
        foreach ($elevated as $needle => $pts) {
            if (str_contains($registrar, $needle)) {
                $risk = $pts;
                $matched = $needle;
                break;
            }
        }
        $this->data['registrar_risk'] = $risk;
        if ($risk > 0) {
            $this->addSignal(
                'registration',
                'Registrar',
                $this->data['whois_registrar'] ?? 'Unknown',
                'This registrar sees high volumes of bulk registrations; minor context only, not a scam signal on its own.',
                'neutral'
            );
        } else {
            $this->addSignal(
                'registration',
                'Registrar reputation',
                'No elevated flag',
                'Not on our high-volume abuse registrar list',
                'good'
            );
        }
    }

    private function checkSpamReputation(): void
    {
        $this->data['spam_hit'] = 0;
        // Domain Block List style lookups. Many resolvers block Spamhaus; treat only clear positives.
        $zones = [
            'dbl.spamhaus.org' => 'Spamhaus DBL',
            'multi.surbl.org' => 'SURBL',
        ];
        $hits = [];
        foreach ($zones as $zone => $label) {
            $qhost = $this->domain . '.' . $zone;
            $records = @dns_get_record($qhost, DNS_A);
            if (!$records) {
                continue;
            }
            foreach ($records as $rec) {
                $ip = $rec['ip'] ?? '';
                // Spamhaus/SURBL list responses are 127.0.0.x; 127.255.255.x are errors/policy.
                if (preg_match('/^127\.0\.0\.\d+$/', $ip) && !str_starts_with($ip, '127.255.')) {
                    if ($ip !== '127.0.0.1') { // some APIs use .1 as "not listed" noise
                        $hits[] = $label . " ($ip)";
                    }
                }
            }
        }
        if ($hits) {
            $this->data['spam_hit'] = 1;
            $this->addSignal('threat', 'Spam reputation (DNSBL)', 'Listed', implode(', ', $hits), 'bad');
        } else {
            $this->addSignal(
                'threat',
                'Spam reputation (DNSBL)',
                'No clear hit',
                'Checked Spamhaus DBL / SURBL when reachable.',
                'neutral'
            );
        }

        // Broader public RBL sweep is added in ExternalReputation (abuseBlacklists).
    }

    /**
     * Extra reputation layers: Trustpilot, Sitejabber, public abuse RBLs, URLVoid safety engines.
     */
    private function checkReputationExtras(): void
    {
        $this->data['tranco_rank'] = null;
        $this->data['tranco_bonus'] = 0;
        $this->data['review_penalty'] = (int) ($this->data['review_penalty'] ?? 0);

        // --- Tranco traffic rank (free research API) ---
        try {
            $json = $this->httpGet('https://tranco-list.eu/api/ranks/domain/' . rawurlencode($this->domain), 8);
            $payload = json_decode($json ?: '', true);
            $rank = null;
            if (is_array($payload) && !empty($payload['ranks'][0]['rank'])) {
                $rank = (int) $payload['ranks'][0]['rank'];
            }
            if ($rank && $rank > 0) {
                $this->data['tranco_rank'] = $rank;
                if ($rank <= 10000) {
                    $this->data['tranco_bonus'] = 10;
                    $tone = 'good';
                    $label = 'High traffic (top 10k)';
                } elseif ($rank <= 100000) {
                    $this->data['tranco_bonus'] = 6;
                    $tone = 'good';
                    $label = 'Solid traffic (top 100k)';
                } elseif ($rank <= 500000) {
                    $this->data['tranco_bonus'] = 3;
                    $tone = 'good';
                    $label = 'Some traffic (top 500k)';
                } else {
                    $this->data['tranco_bonus'] = 1;
                    $tone = 'neutral';
                    $label = 'Listed in Tranco top 1M';
                }
                $this->addSignal(
                    'reputation',
                    'Tranco traffic rank',
                    '#' . number_format($rank),
                    $label . ' — research list of the most-visited sites globally.',
                    $tone
                );
            } else {
                $this->addSignal(
                    'reputation',
                    'Tranco traffic rank',
                    'Not in top 1M',
                    'No recent Tranco rank — common for brand-new or low-traffic sites.',
                    'warn'
                );
            }
        } catch (Throwable $e) {
            $this->addSignal('reputation', 'Tranco traffic rank', 'Unavailable', 'Tranco API request failed', 'neutral');
        }

        // --- ScamGuard local reports ---
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT status, COUNT(*) AS n FROM reports WHERE domain_text = ? GROUP BY status");
            $stmt->execute([$this->domain]);
            $rows = $stmt->fetchAll();
            $approved = 0;
            $pending = 0;
            foreach ($rows as $row) {
                if ($row['status'] === 'approved') {
                    $approved = (int) $row['n'];
                }
                if ($row['status'] === 'pending') {
                    $pending = (int) $row['n'];
                }
            }
            if ($approved > 0) {
                $localReview = min(25, 8 + ($approved * 5));
                $this->data['local_review_penalty'] = $localReview;
                $this->addSignal(
                    'reputation',
                    'User reports (ScamGuard)',
                    $approved . ' approved',
                    ($pending ? "$pending pending. " : '') . 'Community reports on ScamGuard.',
                    'bad'
                );
            } elseif ($pending > 0) {
                $this->data['local_review_penalty'] = 4;
                $this->addSignal(
                    'reputation',
                    'User reports (ScamGuard)',
                    $pending . ' pending',
                    'Unreviewed reports exist — soft caution only.',
                    'warn'
                );
            } else {
                $this->data['local_review_penalty'] = 0;
                $this->addSignal(
                    'reputation',
                    'User reports (ScamGuard)',
                    'None yet',
                    'No community reports filed on ScamGuard for this domain.',
                    'neutral'
                );
            }
        } catch (Throwable $e) {
            $this->data['local_review_penalty'] = 0;
            $this->addSignal('reputation', 'User reports (ScamGuard)', 'Unavailable', $e->getMessage(), 'neutral');
        }

        // --- Live external sources (Trustpilot, Sitejabber, RBLs, URLVoid, neighbors, IP BL) ---
        try {
            $cdnProv = (string) ($this->data['cdn_provider'] ?? '');
            $ext = (new ExternalReputation($this->domain, [
                'ip' => $this->data['ip_address'] ?? null,
                'is_cloudflare' => strcasecmp($cdnProv, 'Cloudflare') === 0,
                'uses_cdn' => !empty($this->data['uses_cdn']),
            ]))->collect();
            foreach ($ext['signals'] as $signal) {
                $this->addSignal(
                    (string) $signal['group'],
                    (string) $signal['label'],
                    (string) $signal['value'],
                    (string) ($signal['note'] ?? ''),
                    (string) ($signal['tone'] ?? 'neutral')
                );
            }

            $delta = (int) ($ext['score_delta'] ?? 0);
            $reviewPen = (int) ($ext['review_penalty'] ?? 0);
            // Huge popular sites often have angry Trustpilot threads; dampen if top-ranked.
            if (!empty($this->data['tranco_rank']) && (int) $this->data['tranco_rank'] <= 1000 && $reviewPen > 0) {
                $dampened = (int) floor($reviewPen * 0.25);
                $delta += $reviewPen - $dampened;
                $reviewPen = $dampened;
            }
            $this->data['review_penalty'] = $reviewPen + (int) ($this->data['local_review_penalty'] ?? 0);
            $this->data['review_bonus'] = (int) ($ext['review_bonus'] ?? 0);
            $this->data['review_consensus'] = $ext['review_consensus'] ?? null;
            $this->data['external_score_delta'] = $delta;
            if (!empty($ext['spam_hit'])) {
                $this->data['spam_hit'] = 1;
            }
        } catch (Throwable $e) {
            $this->addSignal('reputation', 'External reputation', 'Error', $e->getMessage(), 'neutral');
            $this->data['external_score_delta'] = 0;
        }
    }

    private function scoreSecurityHeaders(array $headers): array
    {
        $wanted = [
            'strict-transport-security' => 'HSTS',
            'content-security-policy' => 'CSP',
            'x-content-type-options' => 'X-Content-Type-Options',
            'x-frame-options' => 'X-Frame-Options',
            'referrer-policy' => 'Referrer-Policy',
            'permissions-policy' => 'Permissions-Policy',
        ];
        $flat = [];
        foreach ($headers as $k => $v) {
            $flat[strtolower((string) $k)] = $v;
        }
        $present = [];
        foreach ($wanted as $key => $label) {
            if (isset($flat[$key])) {
                $present[] = $label;
            }
        }
        return [
            'score' => count($present),
            'max' => count($wanted),
            'present' => $present,
        ];
    }

    // -------------------------------------------------------------
    // Threat feeds
    // -------------------------------------------------------------
    /**
     * Turbo classification from the discovery source the domain came from.
     * A domain pulled off a live malware/phishing feed is treated as a hit
     * immediately (reliable — it is actively listed), and one from the
     * research-grade Tranco top list gets a verified-safe baseline. This lets
     * automatic discovery move the "safe" and "scam" counters without doing a
     * slow per-domain lookup or full scan.
     */
    private function applyProvenanceClassification(): void
    {
        $this->data['threat_feed_hit'] = 0;
        $this->data['malware_hit'] = 0;
        $this->data['phishing_hit'] = 0;
        $this->data['threat_feed_sources'] = null;
        $this->data['popular_verified'] = 0;

        $hasIp = !empty($this->data['ip_address']);

        switch ($this->sourceClass) {
            case 'malware':
                $this->data['malware_hit'] = 1;
                $this->data['threat_feed_hit'] = 1;
                $this->data['threat_feed_sources'] = 'URLhaus (malware/botnet URLs)';
                $this->addSignal('threat', 'Threat feeds', 'Listed (malware)', 'Domain is on an active malware/botnet URL feed.', 'bad');
                break;
            case 'phishing':
                $this->data['phishing_hit'] = 1;
                $this->data['threat_feed_hit'] = 1;
                $this->data['threat_feed_sources'] = 'Phishing feed (OpenPhish / Phishing.Database)';
                $this->addSignal('threat', 'Threat feeds', 'Listed (phishing)', 'Domain is on an active phishing feed.', 'bad');
                break;
            case 'popular_strong':
                // Tranco is hardened against manipulation; membership + resolving DNS
                // is a strong legitimacy signal for a quick pass.
                if ($hasIp) {
                    $this->data['popular_verified'] = 1;
                }
                $this->addSignal('reputation', 'Traffic ranking', 'Top-ranked site', 'Listed on the Tranco top sites ranking (high global traffic).', $hasIp ? 'good' : 'neutral');
                break;
            case 'popular':
                // Cisco Umbrella / Majestic / DomCop top lists — if it resolves, treat
                // as a presumed-legit baseline so discovery reflects real safe sites.
                // A full scan on open still re-verifies and can downgrade it.
                if ($hasIp) {
                    $this->data['popular_verified'] = 1;
                }
                $this->addSignal('reputation', 'Traffic ranking', 'On a top-domains list', 'Appears on a major top-domains ranking (presumed legitimate; re-verified on full scan).', $hasIp ? 'good' : 'neutral');
                break;
            default:
                $this->addSignal('threat', 'Threat feeds', 'Deferred (turbo)', 'Exact feed confirmation runs during the full report.', 'neutral');
        }
    }

    private function checkThreatFeeds(): void
    {
        $this->data['threat_feed_hit'] = 0;
        $this->data['malware_hit'] = 0;
        $this->data['phishing_hit'] = 0;
        $sources = [];
        $checked = ['Local URLhaus cache', 'Local OpenPhish cache', 'Local Phishing.Database cache'];

        $local = ThreatFeeds::checkDomain($this->domain);
        $sharedHost = $this->isSharedContentHost($this->domain);
        if ($sharedHost && (!empty($local['malware']) || !empty($local['phishing']))) {
            // URLhaus/OpenPhish often list abused paths on GitHub/Drive/etc.
            // That must not brand the whole platform as a scam site.
            $this->addSignal(
                'threat',
                'Shared-host abuse reports',
                'Seen on URL feeds',
                'Malware/phishing URLs were reported on this platform host (' . implode(', ', $local['sources']) . '). The platform itself is not automatically a scam.',
                'warn'
            );
        } else {
            if (!empty($local['malware'])) {
                $this->data['malware_hit'] = 1;
                $sources = array_merge($sources, $local['sources']);
            }
            if (!empty($local['phishing'])) {
                $this->data['phishing_hit'] = 1;
                $sources = array_merge($sources, $local['sources']);
            }
        }

        if (GOOGLE_SAFE_BROWSING_API_KEY !== '') {
            $checked[] = 'Google Safe Browsing';
            try {
                $payload = json_encode([
                    'client' => ['clientId' => 'scamguard', 'clientVersion' => '1.2'],
                    'threatInfo' => [
                        'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => [
                            ['url' => 'http://' . $this->domain . '/'],
                            ['url' => 'https://' . $this->domain . '/'],
                        ],
                    ],
                ]);
                $response = $this->httpPost(
                    'https://safebrowsing.googleapis.com/v4/threatMatches:find?key=' . GOOGLE_SAFE_BROWSING_API_KEY,
                    $payload,
                    6
                );
                $result = json_decode($response ?? '', true);
                if (!empty($result['matches'])) {
                    foreach ($result['matches'] as $match) {
                        $type = $match['threatType'] ?? '';
                        if (in_array($type, ['MALWARE', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'], true)) {
                            $this->data['malware_hit'] = 1;
                            $sources[] = 'Google Safe Browsing (' . $type . ')';
                        }
                        if ($type === 'SOCIAL_ENGINEERING') {
                            $this->data['phishing_hit'] = 1;
                            $sources[] = 'Google Safe Browsing (phishing)';
                        }
                    }
                }
            } catch (Throwable $e) {
                // skip
            }
        }

        if (!$this->data['phishing_hit'] && !$sharedHost) {
            $checked[] = 'OpenPhish live/cache';
            try {
                if ($this->matchCachedFeed(
                    __DIR__ . '/../storage/feeds/openphish.txt',
                    'https://raw.githubusercontent.com/openphish/public/master/feed.txt'
                )) {
                    $this->data['phishing_hit'] = 1;
                    $sources[] = 'OpenPhish';
                }
            } catch (Throwable $e) {
            }
        }

        $sources = array_values(array_unique($sources));
        if ($sources) {
            $this->data['threat_feed_hit'] = 1;
            $this->data['threat_feed_sources'] = implode(', ', $sources);
        } else {
            $this->data['threat_feed_sources'] = null;
        }

        $this->addSignal(
            'threat',
            'Malware lists',
            $this->data['malware_hit'] ? 'HIT' : 'Clean',
            'URLhaus malware URL lists',
            $this->data['malware_hit'] ? 'bad' : 'good'
        );
        $this->addSignal(
            'threat',
            'Phishing lists',
            $this->data['phishing_hit'] ? 'HIT' : 'Clean',
            'OpenPhish / Phishing.Database lists',
            $this->data['phishing_hit'] ? 'bad' : 'good'
        );
        $this->addSignal(
            'threat',
            'Threat intel summary',
            $sources ? implode(', ', $sources) : 'No malware/phishing list match',
            'Checked: ' . implode(', ', $checked),
            $sources ? 'bad' : 'good'
        );
    }

    private function checkHeuristics(): void
    {
        $flags = [];
        $domain = strtolower($this->domain);
        $labels = explode('.', $domain);
        $sld = $labels[0] ?? $domain;

        $brands = [
            'paypal','apple','google','microsoft','amazon','facebook','instagram','whatsapp',
            'netflix','steam','binance','coinbase','metamask','chase','wellsfargo','bankofamerica',
            'dhl','ups','fedex','outlook','office365','icloud','twitter','tiktok','discord',
            'revolut','wise','crypto','wallet','ledger','blockchain'
        ];

        foreach ($brands as $brand) {
            if ($sld === $brand) {
                continue;
            }
            if (str_contains($sld, $brand)) {
                $flags[] = [
                    'code' => 'brand_impersonation',
                    'label' => 'Possible brand impersonation',
                    'detail' => 'Domain label contains "' . $brand . '"',
                    'penalty' => 18,
                ];
                break;
            }
        }

        if (preg_match('/(paypa1|g00gle|micr0soft|app1e|faceb00k)/i', $sld)) {
            $flags[] = [
                'code' => 'homoglyph',
                'label' => 'Lookalike / homoglyph pattern',
                'detail' => 'Digits used to mimic a known brand',
                'penalty' => 22,
            ];
        }

        $riskyTlds = [
            'zip', 'mov', 'country', 'gq', 'tk', 'ml', 'ga', 'cf', 'top', 'buzz', 'xyz', 'click',
            'work', 'rest', 'icu', 'cfd', 'sbs', 'cyou', 'lol', 'quest', 'cam', 'bond', 'hair',
            'mom', 'pics', 'beauty', 'skin',
        ];
        $tld = $labels[count($labels) - 1] ?? '';
        $age = $this->data['domain_age_days'];
        $young = is_int($age) && $age < 30;
        $newish = is_int($age) && $age < 90;
        $onRiskyTld = in_array($tld, $riskyTlds, true);

        if ($onRiskyTld) {
            $flags[] = [
                'code' => 'risky_tld',
                'label' => 'Higher-risk TLD',
                'detail' => '.' . $tld . ' appears often in disposable / phishing campaigns',
                'penalty' => $young ? 16 : ($newish ? 10 : 6),
            ];
        }

        if ($young && $onRiskyTld) {
            // Already covered by stronger risky_tld penalty when young — keep a distinct label for UI.
            $flags[] = [
                'code' => 'young_risky_tld',
                'label' => 'Very new domain on higher-risk TLD',
                'detail' => 'Age ' . $age . ' days on .' . $tld,
                'penalty' => 6,
            ];
        }

        // Only meaningful when we actually read the page. A single generic phrase
        // ("limited time offer") is common on legit marketing sites, so scale by count.
        $hits = (int) ($this->data['suspicious_keyword_hits'] ?? 0);
        $contentRead = empty($this->data['content_incomplete']);
        if ($newish && $hits > 0 && $contentRead) {
            $penaltyForUrgency = $hits >= 3 ? 16 : ($hits === 2 ? 10 : 5);
            $flags[] = [
                'code' => 'young_plus_urgency',
                'label' => 'New domain with scam-style urgency language',
                'detail' => $hits . ' suspicious phrase hit(s) on a domain under 90 days old',
                'penalty' => $penaltyForUrgency,
            ];
        }

        // Only when we actually read the page — bot walls must not look like "missing privacy".
        if ($young && $contentRead
            && !(int) ($this->data['has_privacy_policy'] ?? 0)
            && !(int) ($this->data['has_contact_info'] ?? 0)) {
            $flags[] = [
                'code' => 'young_no_trust_pages',
                'label' => 'New site missing contact/privacy pages',
                'detail' => 'Common pattern for disposable scam landing pages',
                'penalty' => 10,
            ];
        }

        if (!empty($this->data['noindex']) && ($young || $newish)) {
            $flags[] = [
                'code' => 'young_noindex',
                'label' => 'New site hides from search engines',
                'detail' => 'robots noindex on a young domain',
                'penalty' => 10,
            ];
        }

        if (!empty($this->data['free_email_contact']) && ($young || $newish || empty($this->data['has_phone']))) {
            $flags[] = [
                'code' => 'free_email_contact',
                'label' => 'Only free webmail contact',
                'detail' => 'Public contact uses Gmail/Yahoo/Outlook-style addresses',
                'penalty' => 8,
            ];
        }

        if (!empty($this->data['crypto_only_payment'])) {
            $flags[] = [
                'code' => 'crypto_only_payment',
                'label' => 'Crypto-only payment language',
                'detail' => 'Crypto checkout wording without common card/PayPal options',
                'penalty' => 12,
            ];
        }

        if (
            empty($this->data['content_incomplete'])
            && empty($this->data['has_phone'])
            && empty($this->data['has_contact_info'])
            && ($young || $newish)
        ) {
            $flags[] = [
                'code' => 'no_phone_or_contact',
                'label' => 'No phone or clear contact details',
                'detail' => 'Young site with neither phone nor contact cues',
                'penalty' => 8,
            ];
        }

        if (preg_match('/(secure|login|verify|update|account|support)-(paypal|apple|microsoft|amazon|netflix)/i', $sld)) {
            $flags[] = [
                'code' => 'credential_bait_label',
                'label' => 'Credential-harvest naming pattern',
                'detail' => 'Login/verify + brand style label',
                'penalty' => 24,
            ];
        }

        if (substr_count($sld, '-') >= 3) {
            $flags[] = [
                'code' => 'hyphen_stuffing',
                'label' => 'Hyphen-heavy domain label',
                'detail' => 'Often used in phishing kits',
                'penalty' => 8,
            ];
        }

        $this->data['heuristic_flags'] = $flags ? json_encode($flags) : null;
        $penalty = array_sum(array_column($flags, 'penalty'));

        if ($flags) {
            foreach ($flags as $flag) {
                $this->addSignal('heuristics', $flag['label'], 'Flagged', $flag['detail'], 'bad');
            }
            $this->addSignal('heuristics', 'Heuristic risk total', (string) $penalty . ' pts', count($flags) . ' rule(s) matched', 'bad');
        } else {
            $this->addSignal('heuristics', 'Scam heuristics', 'No high-risk patterns', 'Brand lookalike, urgency+age, phishing naming checks', 'good');
        }
    }

    private function buildVerdict(int $score): void
    {
        $reasons = [];
        $availability = (string) ($this->data['site_availability'] ?? '');

        if (in_array($availability, ['down', 'unreachable'], true)) {
            $verdict = 'unavailable';
            $reasons[] = $availability === 'unreachable'
                ? 'Website could not be reached — live trust testing is not possible right now.'
                : 'Website appears down — live trust testing is not possible right now.';
            $reasons[] = 'Try again later when the site is back online.';
            // Still surface hard threat hits if we have them independently.
            if (!empty($this->data['malware_hit']) || !empty($this->data['phishing_hit']) || !empty($this->data['threat_feed_hit'])) {
                $reasons[] = 'Note: threat-list signals may still apply even while the site is down.';
            }
        } elseif (!empty($this->data['malware_hit'])) {
            $verdict = 'malware';
            $reasons[] = 'Domain/host appears on malware or botnet URL intelligence.';
        } elseif (!empty($this->data['phishing_hit'])) {
            $verdict = 'phishing';
            $reasons[] = 'Domain appears on phishing intelligence feeds.';
        } elseif (!empty($this->data['threat_feed_hit'])) {
            $verdict = 'likely_scam';
            $reasons[] = 'Listed on a threat / abuse feed.';
        } else {
            $flags = json_decode((string) ($this->data['heuristic_flags'] ?? '[]'), true) ?: [];
            $penalty = array_sum(array_map(static fn($f) => (int) ($f['penalty'] ?? 0), $flags));
            $incomplete = !empty($this->data['content_incomplete']);
            $aiDelta = (int) ($this->data['ai_score_delta'] ?? 0);

            // Without a malware/phishing/feed hit we have NO hard proof of fraud.
            // Only call it a scam when soft evidence is overwhelming AND corroborated —
            // otherwise the strongest we say is "suspicious / risky". This keeps
            // niche-but-not-fraudulent sites (small shops, streaming, hobby sites) from
            // being branded outright scams.
            $overwhelmingSoft = $penalty >= 45 || ($penalty >= 26 && $aiDelta <= -12);

            if ($overwhelmingSoft) {
                $verdict = 'likely_scam';
                $reasons[] = 'Multiple strong scam heuristics point to fraud (no feed hit, but high-risk pattern).';
            } elseif ($penalty >= 14 || $score < 45 || $aiDelta <= -10 || !empty($this->data['spam_hit'])) {
                $verdict = 'suspicious';
                $reasons[] = !empty($this->data['spam_hit'])
                    ? 'Spam / blacklist reputation signals were found — treat with caution.'
                    : 'Elevated risk signals; not confirmed fraud, but be careful.';
            } elseif ($incomplete) {
                $verdict = 'caution';
                $reasons[] = 'This site uses Cloudflare/CDN bot protection, so page content could not be inspected. That is a normal setup on legitimate sites — not a scam signal on its own.';
            } elseif ($score >= 78) {
                $verdict = 'likely_safe';
                $reasons[] = 'No malware/phishing/feed hits and strong positive signals.';
            } else {
                $verdict = 'caution';
                $reasons[] = 'No direct malware/phishing hit, but not strongly verified either.';
            }
            foreach (array_slice($flags, 0, 3) as $flag) {
                $reasons[] = $flag['label'] . ': ' . $flag['detail'];
            }
            if (!empty($this->data['registrar_risk'])) {
                $reasons[] = 'Registrar is commonly used by high volumes of low-trust sites (soft signal).';
            }
            if (($this->data['domain_age_scope'] ?? '') === 'parent') {
                $reasons[] = 'Subdomain checked — parent domain age is informational only and was not used as “old site” trust.';
            } elseif (($this->data['domain_age_scope'] ?? '') === 'platform') {
                $reasons[] = 'Shared platform host — platform/company age was not used as this site’s age.';
            }
        }

        if (!empty($this->data['threat_feed_sources'])) {
            $reasons[] = 'Feeds: ' . $this->data['threat_feed_sources'];
        }
        if (!empty($this->data['analyst_summary']) && ($verdict ?? '') !== 'unavailable') {
            array_unshift($reasons, 'Analyst: ' . $this->data['analyst_summary']);
        }
        if (($verdict ?? '') !== 'unavailable') {
            $reasons[] = 'Note: Review coverage includes Trustpilot + Sitejabber when available, plus ScamGuard community reports. Google Reviews, BBB, and Reddit are not queried.';
        }

        $this->data['verdict'] = $verdict;
        $this->data['verdict_reasons'] = json_encode($reasons);
        $tone = match ($verdict) {
            'malware', 'phishing', 'likely_scam' => 'bad',
            'likely_safe' => 'good',
            'unavailable' => 'neutral',
            default => 'warn',
        };
        $this->addSignal(
            'verdict',
            'Engine verdict',
            strtoupper(str_replace('_', ' ', $verdict)),
            implode(' | ', $reasons),
            $tone
        );
    }

    private function mapVerdictToStatus(string $verdict, int $score): string
    {
        return match ($verdict) {
            'malware', 'phishing', 'likely_scam' => 'scam',
            'suspicious' => 'risky',
            'likely_safe' => 'safe',
            'caution' => 'caution',
            'unavailable' => 'unavailable',
            default => score_to_status($score),
        };
    }

    /**
     * Rule-based analyst brief (always) + optional LLM site investigation (AI key).
     */
    private function applyAnalystOpinion(): void
    {
        $brief = AiAnalyst::ruleBrief($this->domain, $this->data, $this->signals);
        $this->data['analyst_lean'] = $brief['lean'];
        $this->data['analyst_summary'] = $brief['summary'];
        $this->data['ai_score_delta'] = (int) ($brief['score_hint'] ?? 0);
        $this->data['ai_site_about'] = null;

        $this->addSignal(
            'analysis',
            'Analyst lean',
            $brief['label'],
            $brief['summary'],
            $brief['tone']
        );

        $ai = AiAnalyst::llmOpinion($this->domain, $this->data, $this->signals, $brief);
        if ($ai === null) {
            return;
        }

        // AI site judgment nudges the score; rule hint only fills in when AI is mixed/low confidence.
        $aiDelta = (int) ($ai['score_delta'] ?? 0);
        $conf = (int) ($ai['confidence'] ?? 50);
        if (($ai['lean'] ?? '') === 'mixed' || $conf < 45) {
            $this->data['ai_score_delta'] = max(-18, min(16, $aiDelta + (int) round(($brief['score_hint'] ?? 0) * 0.35)));
        } else {
            $this->data['ai_score_delta'] = max(-18, min(16, $aiDelta));
        }

        if (!empty($ai['site_about'])) {
            $this->data['ai_site_about'] = $ai['site_about'];
            $this->addSignal(
                'ai',
                'What this site appears to be',
                mb_substr((string) $ai['site_about'], 0, 160),
                'AI read page text + scan facts to describe the site’s purpose.',
                'neutral'
            );
        }

        if (!empty($ai['summary'])) {
            $this->data['analyst_summary'] = $ai['summary'];
        }
        if (!empty($ai['lean'])) {
            $this->data['analyst_lean'] = $ai['lean'];
        }

        $factorNote = !empty($ai['factors']) ? implode('; ', $ai['factors']) : '';
        $scoreNote = 'Score impact: ' . (($aiDelta >= 0) ? '+' : '') . $aiDelta . ' (confidence ' . $conf . '%)';
        $this->addSignal(
            'ai',
            'AI risk judgment',
            $ai['label'],
            trim($ai['summary'] . ' ' . $scoreNote . ($factorNote !== '' ? ' — ' . $factorNote : '')),
            $ai['tone']
        );
    }

    /** Meta description / OG description from HTML. */
    private function extractMetaDescription(string $html): string
    {
        $patterns = [
            '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i',
            '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/i',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $html, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return '';
    }

    /** Visible-ish homepage text for AI (scripts/styles stripped). */
    private function extractPageExcerpt(string $html): string
    {
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $text) ?? $text;
        $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, 2800);
        }
        return substr($text, 0, 2800);
    }

    private function matchCachedFeed(string $localPath, string $remoteUrl): bool
    {
        $feed = null;
        if (is_file($localPath) && (time() - filemtime($localPath)) < 86400) {
            $feed = @file_get_contents($localPath);
        }
        if (!$feed) {
            $feed = $this->httpGet($remoteUrl, 6);
            if ($feed && is_dir(dirname($localPath))) {
                @file_put_contents($localPath, $feed);
            }
        }
        if (!$feed) {
            return false;
        }
        // Exact host match only (avoid "google.com" substring hits inside drive.google.com URLs).
        foreach (preg_split("/\r\n|\n|\r/", $feed) as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($this->extractFeedHost($line) === $this->domain) {
                return true;
            }
        }
        return false;
    }

    private function matchLineFeed(string $path, string $domain): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $fh = fopen($path, 'r');
        if (!$fh) {
            return false;
        }
        $needle = strtolower($domain);
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($this->extractFeedHost($line) === $needle) {
                fclose($fh);
                return true;
            }
        }
        fclose($fh);
        return false;
    }

    private function extractFeedHost(string $value): string
    {
        $value = strtolower(trim($value));
        if (str_contains($value, '://')) {
            return strtolower((string) (parse_url($value, PHP_URL_HOST) ?: ''));
        }
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        return $value;
    }

    /** Major platforms where URL-feed hits mean abused paths, not the site itself. */
    private function isSharedContentHost(string $domain): bool
    {
        static $exact = [
            'github.com', 'githubusercontent.com', 'raw.githubusercontent.com', 'gist.github.com',
            'google.com', 'www.google.com', 'drive.google.com', 'docs.google.com', 'sites.google.com',
            'storage.googleapis.com', 'storage.cloud.google.com', 'appengine.google.com',
            'dropbox.com', 'www.dropbox.com', 'dl.dropboxusercontent.com',
            'blogspot.com', 'wordpress.com', 'medium.com', 'substack.com',
            'pastebin.com', 'discord.com', 'discord.gg', 'telegram.org', 't.me',
            'bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly',
            'youtube.com', 'youtu.be', 'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
            'linkedin.com', 'reddit.com', 'microsoft.com', 'office.com', 'live.com', 'outlook.com',
            'apple.com', 'icloud.com', 'amazon.com', 'aws.amazon.com',
        ];
        if (in_array($domain, $exact, true)) {
            return true;
        }
        // Only well-known platform suffixes — NOT free scam-hosting TLDs like *.pages.dev.
        $suffixes = [
            '.github.io', '.googleusercontent.com', '.appspot.com', '.cloudfunctions.net',
            '.blogspot.com', '.wordpress.com',
        ];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------
    // Scoring
    // -------------------------------------------------------------
    private function calculateScore(): int
    {
        // Live site down / unreachable — do not invent a trust score.
        $availability = (string) ($this->data['site_availability'] ?? '');
        if (in_array($availability, ['down', 'unreachable'], true)) {
            return 0;
        }

        $score = 50;

        $wAge = get_score_config('weight_domain_age', 20);
        $wReg = get_score_config('weight_registration_length', 10);
        $wSsl = get_score_config('weight_ssl', 15);
        $wHost = get_score_config('weight_hosting', 10);
        $wContent = get_score_config('weight_content', 15);
        $wThreat = get_score_config('weight_threat_feed', 30);
        $newDomainThreshold = get_score_config('new_domain_threshold_days', 180);
        $ageScope = (string) ($this->data['domain_age_scope'] ?? 'unknown');

        // Only exact registrable-domain age earns / loses age trust.
        // Subdomains & platform hosts must not inherit "old domain" credit.
        if ($ageScope === 'exact' && $this->data['domain_age_days'] !== null) {
            if ($this->data['domain_age_days'] < 30) {
                $score -= $wAge;
            } elseif ($this->data['domain_age_days'] < $newDomainThreshold) {
                $score -= $wAge * 0.5;
            } else {
                $score += $wAge * 0.5;
            }
        } elseif ($ageScope === 'parent' || $ageScope === 'platform') {
            // Informational parent/platform age only — no bonus, mild unknown trim.
            $score -= 2;
        } else {
            $score -= 4; // missing registration data is mildly suspicious / incomplete
        }

        // Registration length from parent WHOIS is also not the subdomain's term.
        if ($ageScope === 'exact' && $this->data['registration_length_days'] !== null) {
            if ($this->data['registration_length_days'] <= 370) {
                $score -= $wReg * 0.5;
            } else {
                $score += $wReg * 0.5;
            }
        }

        if ($this->data['ssl_valid'] === null) {
            // Unknown in turbo discovery; do not reward or punish.
        } elseif ($this->data['ssl_valid']) {
            $score += $wSsl;
        } else {
            $score -= $wSsl;
        }

        if (!empty($this->data['asn_org'])) {
            $score += $wHost * 0.25;
            if ($this->data['uses_cdn']) {
                // CDN is normal for legit sites; slight plus for established infra
                $score += 2;
            }
        }

        if ($this->data['has_spf']) {
            $score += 3;
        }
        if ($this->data['has_dmarc']) {
            $score += 3;
        }
        if ($this->data['mx_records']) {
            $score += 2;
        }

        $incomplete = !empty($this->data['content_incomplete']);

        if (!$incomplete) {
            if ($this->data['has_contact_info']) {
                $score += $wContent * 0.35;
            }
            if ($this->data['has_privacy_policy']) {
                $score += $wContent * 0.25;
            }
            if (!empty($this->data['has_cookie_policy'])) {
                $score += 1;
            }
            if (!empty($this->data['has_phone'])) {
                $score += 3;
            }
            // free_email / noindex / crypto_only are scored via heuristic flags (avoid double-counting)
            if ($this->data['suspicious_keyword_hits'] > 0) {
                $score -= min($this->data['suspicious_keyword_hits'] * 8, $wContent + 10);
            }

            $sec = json_decode((string) ($this->data['security_headers'] ?? ''), true);
            if (is_array($sec) && isset($sec['score'])) {
                $score += min(6, (int) $sec['score']);
            }
        } else {
            // Challenge pages are not proof of safety — but a Cloudflare/CDN bot wall
            // is extremely common on legitimate sites, so it should only mildly reduce
            // confidence, not act like a scam signal.
            $cdnChallenge = !empty($this->data['uses_cdn'])
                || strcasecmp((string) ($this->data['cdn_provider'] ?? ''), 'Cloudflare') === 0;
            $httpStatus = (int) ($this->data['http_status'] ?? 0);
            if ($cdnChallenge && ($httpStatus === 0 || $httpStatus < 400 || $httpStatus === 403)) {
                // Recognised CDN challenge — small confidence trim only.
                $score -= 4;
            } else {
                // Genuinely unreadable / broken origin is more concerning.
                $score -= 10;
                if ($httpStatus >= 400) {
                    $score -= 4;
                }
            }
        }

        if (!empty($this->data['malware_hit'])) {
            $score -= ($wThreat + 25);
        } elseif (!empty($this->data['phishing_hit'])) {
            $score -= ($wThreat + 15);
        } elseif (!empty($this->data['threat_feed_hit'])) {
            $score -= $wThreat;
        }

        if (!empty($this->data['spam_hit'])) {
            // Graduated RBL penalty already applied via external_score_delta;
            // this is the extra weight for a confirmed multi-list spam hit.
            $score -= 12;
        }

        if (!empty($this->data['registrar_risk'])) {
            $score -= (int) $this->data['registrar_risk'];
        }

        if (!empty($this->data['tranco_bonus'])) {
            $score += (int) $this->data['tranco_bonus'];
        }

        if (!empty($this->data['external_score_delta'])) {
            $score += (int) $this->data['external_score_delta'];
        }

        if (!empty($this->data['local_review_penalty'])) {
            $score -= (int) $this->data['local_review_penalty'];
        }

        if (!empty($this->data['ai_score_delta'])) {
            // AI investigated site purpose + visitor-harm risk — meaningful but bounded nudge.
            $score += max(-18, min(16, (int) $this->data['ai_score_delta']));
        }

        $flags = json_decode((string) ($this->data['heuristic_flags'] ?? '[]'), true) ?: [];
        foreach ($flags as $flag) {
            $score -= (int) ($flag['penalty'] ?? 0);
        }

        $score = (int) round($score);

        // Without a malware/phishing/threat-feed hit we have NO hard proof of fraud.
        // Soft signals alone (new-ish domain, risky TLD, one RBL, CDN wall, gray content)
        // should land a site in "risky / caution" territory — not crush it into the
        // single digits reserved for confirmed scams. Establish a reasonable floor.
        $hardEvidence = !empty($this->data['malware_hit'])
            || !empty($this->data['phishing_hit'])
            || !empty($this->data['threat_feed_hit']);

        // Research-grade top-list membership (Tranco) that resolves is a reliable
        // fast-pass to "likely safe" during automatic discovery.
        if (!$hardEvidence && !empty($this->data['popular_verified'])) {
            $score = max($score, 82);
        }

        if (!$hardEvidence) {
            $floor = 25;
            if (!empty($this->data['ssl_valid'])) {
                $floor += 6;
            }
            $ageDays = $this->data['domain_age_days'];
            $ageScopeExact = ((string) ($this->data['domain_age_scope'] ?? '')) === 'exact';
            if ($ageScopeExact && is_int($ageDays) && $ageDays >= $newDomainThreshold) {
                $floor += 6; // established registration age (exact host only)
            }
            $score = max($score, $floor);
        }

        // Never call an unread/challenge-blocked site "fully safe".
        if ($incomplete) {
            $score = min($score, 68);
        }

        // Strong negative Trustpilot + incomplete content should not stay mid-caution forever.
        if ($incomplete && !empty($this->data['review_penalty']) && (int) $this->data['review_penalty'] >= 12) {
            $score = min($score, 45);
        }

        return max(1, min(100, $score));
    }

    // -------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------

    /**
     * Mark live site as down/unreachable — stop content analysis and force N/A scoring.
     */
    private function markSiteUnavailable(string $kind, string $detail): void
    {
        $kind = in_array($kind, ['down', 'unreachable'], true) ? $kind : 'down';
        $this->data['site_availability'] = $kind;
        $this->data['content_incomplete'] = 1;
        $this->data['has_contact_info'] = 0;
        $this->data['has_privacy_policy'] = 0;
        $this->data['has_cookie_policy'] = 0;
        $this->data['has_discord'] = 0;
        $this->data['has_phone'] = 0;
        $this->data['free_email_contact'] = 0;
        $this->data['noindex'] = 0;
        $this->data['crypto_only_payment'] = 0;
        $this->data['suspicious_keyword_hits'] = 0;
        $this->data['page_excerpt'] = '';
        $this->data['page_meta_description'] = '';

        $label = $kind === 'unreachable' ? 'Unreachable' : 'Website down';
        $this->addSignal(
            'content',
            'Site availability',
            $label,
            $detail . ' We can’t complete a live trust test right now — try again later.',
            'bad'
        );
        $this->addSignal(
            'content',
            'Homepage fetch',
            'Failed',
            $detail,
            'bad'
        );
        $this->addSignal(
            'content',
            'Content visibility',
            'Not readable (site down)',
            'The live website did not return a usable page, so content signals are unavailable.',
            'neutral'
        );
        $status = (int) ($this->data['http_status'] ?? 0);
        if ($status > 0) {
            $this->addSignal(
                'content',
                'HTTP status',
                (string) $status,
                'Returned while the site appeared down/unreachable.',
                'bad'
            );
        }
    }

    /**
     * Detect Cloudflare / host “origin down” pages (521–524 etc.), not bot challenges.
     */
    private function isDownHtml(string $html, string $title = '', int $httpStatus = 0): bool
    {
        $lower = strtolower($html);
        $title = strtolower($title);
        if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = strtolower(trim(html_entity_decode(strip_tags($m[1]))));
        }

        if (in_array($httpStatus, [521, 522, 523, 524, 525, 526], true)) {
            return true;
        }

        $downByText = (
            str_contains($title, 'web server is down')
            || str_contains($title, 'origin is unreachable')
            || str_contains($title, 'connection timed out')
            || str_contains($title, 'host error')
            || str_contains($title, '502 bad gateway')
            || str_contains($title, '503 service')
            || str_contains($title, '504 gateway')
            || str_contains($title, 'this site can’t be reached')
            || str_contains($title, 'this site can\'t be reached')
            || str_contains($title, 'website is offline')
            || str_contains($lower, 'error code 521')
            || str_contains($lower, 'error code 522')
            || str_contains($lower, 'error code 523')
            || str_contains($lower, 'error code 524')
            || str_contains($lower, 'cloudflare is currently unable to resolve')
            || (str_contains($lower, 'web server is down') && str_contains($lower, 'cloudflare'))
            || (bool) preg_match('/\b(error\s*code\s*52[1-6]|origin dns error|origin connection time-?out)\b/i', $lower)
        );
        if ($downByText) {
            return true;
        }

        // Generic 5xx: treat as down unless it clearly looks like a bot challenge.
        if ($httpStatus >= 500 && $httpStatus <= 599) {
            return !$this->hasChallengeMarkers($lower, $title);
        }

        return false;
    }

    /** Marker-only challenge detection (no recursion with isDownHtml). */
    private function hasChallengeMarkers(string $lowerHtml, string $titleLower): bool
    {
        return (
            str_contains($titleLower, 'just a moment')
            || str_contains($titleLower, 'attention required')
            || str_contains($titleLower, 'checking your browser')
            || str_contains($titleLower, 'access denied')
            || str_contains($titleLower, 'request unsuccessful')
            || str_contains($lowerHtml, 'cf-browser-verification')
            || str_contains($lowerHtml, 'challenge-platform')
            || str_contains($lowerHtml, '_cf_chl')
            || str_contains($lowerHtml, 'cdn-cgi/challenge')
            || str_contains($lowerHtml, 'performing security verification')
            || str_contains($lowerHtml, 'incapsula')
            || str_contains($lowerHtml, '_incapsula_resource')
            || str_contains($lowerHtml, 'imperva')
            || str_contains($lowerHtml, 'distil_referrer')
            || str_contains($lowerHtml, 'pardon our interruption')
        );
    }

    /**
     * Detect Cloudflare / Incapsula / bot-challenge interstitial pages.
     */
    private function isChallengeHtml(string $html, string $title = '', int $httpStatus = 0): bool
    {
        // Down pages are handled separately — don't classify them as bot walls.
        if ($this->isDownHtml($html, $title, $httpStatus)) {
            return false;
        }

        $lower = strtolower($html);
        $title = strtolower($title);
        if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = strtolower(trim(html_entity_decode(strip_tags($m[1]))));
        }

        $len = strlen($html);

        return (
            $this->hasChallengeMarkers($lower, $title)
            || ($httpStatus === 403 && $len < 8000)
            // Tiny 200 responses with no real document body are almost always interstitials.
            || ($httpStatus === 200 && $len > 0 && $len < 450
                && !preg_match('/<(?:main|article|nav|footer)\b/i', $html))
        );
    }

    /**
     * Detect privacy / terms / cookies / contact / Discord from HTML or markdown text.
     *
     * @return array{privacy:bool,terms:bool,cookies:bool,contact:bool,discord:bool}
     */
    private function detectLegalAndContactSignals(string $text): array
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $lower = strtolower($decoded);

        $privacy = (bool) preg_match(
            '/\b('
            . 'privacy[ \-_]?(policy|statement|notice|center)?'
            . '|online privacy'
            . '|data protection( policy| notice)?'
            . '|do not sell( or share)?( my (personal )?(information|info|data))?'
            . '|your privacy choices'
            . '|privacy rights'
            . '|ccpa'
            . '|gdpr'
            . ')\b/i',
            $decoded
        );
        $privacy = $privacy || (bool) preg_match(
            '/(?:href|]\()(["\']?)[^"\')\s]*(?:privacy|do-not-sell|donotsell|data-protection|privacy-policy|privacy_policy)[^"\')\s]*\1/i',
            $decoded
        );

        $terms = (bool) preg_match(
            '/\b(terms([ \-_]?(of)?[ \-_]?(service|use))?|terms & conditions|user agreement|legal terms)\b/i',
            $decoded
        );
        $terms = $terms || (bool) preg_match(
            '/(?:href|]\()(["\']?)[^"\')\s]*(?:terms(?:-of-(?:use|service))?|tos|user-agreement)[^"\')\s]*\1/i',
            $decoded
        );

        $cookies = (bool) preg_match(
            '/\b(cookie[ \-_]?(policy|notice|preferences|settings|banner)|manage your cookie|cookie settings|cookie list)\b/i',
            $decoded
        );
        $cookies = $cookies || (bool) preg_match(
            '/(?:href|]\()(["\']?)[^"\')\s]*(?:cookie(?:-policy|-preferences|-settings)?|cookies)[^"\')\s]*\1/i',
            $decoded
        );

        $contact = (bool) preg_match(
            '/\b(contact( us)?|support@|mailto:|help center|customer service|get in touch|reach us)\b/i',
            $decoded
        );
        $contact = $contact || (bool) preg_match(
            '/(?:href|]\()(["\']?)[^"\')\s]*(?:contact(?:-us)?|support|help)[^"\')\s]*\1/i',
            $decoded
        );

        $discord = (bool) preg_match(
            '/(?:discord\.gg\/|discord\.com\/invite\/|discordapp\.com\/invite\/)/i',
            $decoded
        );

        // Avoid false "privacy" from challenge/vendor noise alone.
        if ($privacy && (str_contains($lower, 'incapsula') || str_contains($lower, 'cf-browser-verification'))) {
            if (!preg_match('/privacy[ \-_]?(policy|statement|notice)|online privacy|do not sell/i', $decoded)) {
                $privacy = false;
            }
        }

        return [
            'privacy' => $privacy,
            'terms' => $terms,
            'cookies' => $cookies,
            'contact' => $contact,
            'discord' => $discord,
        ];
    }

    /**
     * Probe a small allowlist of common legal/contact paths when homepage missed them.
     *
     * @return array{privacy:bool,terms:bool,cookies:bool,contact:bool}
     */
    private function probeCommonLegalPages(): array
    {
        $out = ['privacy' => false, 'terms' => false, 'cookies' => false, 'contact' => false];
        // Keep this short — only a few common paths, short timeout.
        $paths = [
            '/privacy-policy',
            '/privacy',
            '/legal/privacy',
            '/do-not-sell-my-info',
            '/cookie-policy',
            '/terms-of-use',
            '/contact-us',
        ];

        foreach ($paths as $path) {
            $url = 'https://' . $this->domain . $path;
            $headers = [];
            // Do not track redirects here — avoid overwriting homepage http_status/final_url.
            $body = $this->httpGet($url, 4, false, $headers, true);
            if ($body === null || strlen($body) < 250) {
                continue;
            }
            if ($this->isChallengeHtml($body, '', 200)) {
                continue;
            }

            // Soft 404 / empty marketing shells: require relevant wording near the path purpose.
            $legal = $this->detectLegalAndContactSignals($body);
            $pathLower = strtolower($path);
            if (!$out['privacy'] && $legal['privacy']
                && (str_contains($pathLower, 'privacy') || str_contains($pathLower, 'do-not-sell') || str_contains($pathLower, 'legal'))) {
                $out['privacy'] = true;
            }
            if (!$out['cookies'] && $legal['cookies'] && str_contains($pathLower, 'cookie')) {
                $out['cookies'] = true;
            }
            if (!$out['terms'] && $legal['terms'] && str_contains($pathLower, 'term')) {
                $out['terms'] = true;
            }
            if (!$out['contact'] && $legal['contact'] && str_contains($pathLower, 'contact')) {
                $out['contact'] = true;
            }

            // Early exit once we have privacy (main trust page).
            if ($out['privacy'] && ($out['terms'] || $out['cookies'] || $out['contact'])) {
                break;
            }
        }

        return $out;
    }

    /**
     * Low-RAM content fallbacks when live fetch hits a bot wall.
     * Order: optional remote fetch API → Jina reader → Internet Archive Wayback.
     * Never uses local Chrome/Playwright.
     *
     * @return array{html:string,source:string,note:string,headers?:array}|null
     */
    private function fetchContentFallback(string $liveUrl): ?array
    {
        $api = $this->fetchViaUnblockApi($liveUrl);
        if ($api !== null) {
            return $api;
        }

        $jina = $this->fetchViaJinaHtml($liveUrl);
        if ($jina !== null) {
            return $jina;
        }

        return $this->fetchWaybackHtml($liveUrl);
    }

    /**
     * Free reader proxy used elsewhere for Trustpilot — often bypasses Incapsula/CF walls.
     * Returns markdown/text that our keyword detectors can still analyze.
     *
     * @return array{html:string,source:string,note:string,headers?:array}|null
     */
    private function fetchViaJinaHtml(string $liveUrl): ?array
    {
        $headers = [];
        $md = $this->httpGet('https://r.jina.ai/' . $liveUrl, 28, false, $headers, false);
        if ($md === null || strlen($md) < 400) {
            return null;
        }
        if ($this->isChallengeHtml($md, '', 200)) {
            return null;
        }
        // Require some real page substance, not an error shell.
        if (!preg_match('/\b(privacy|terms|contact|cookie|about|login|home|product|service)\b/i', $md)
            && strlen($md) < 1200) {
            return null;
        }

        return [
            'html' => $md,
            'source' => 'Jina',
            'note' => 'Live page was bot-blocked; content recovered via Jina reader (not a local browser).',
            'headers' => $headers,
        ];
    }

    /**
     * Optional paid/remote unblocker. Set CONTENT_FETCH_API_URL in config with `{url}` placeholder.
     * Example (ScrapingBee): https://app.scrapingbee.com/api/v1/?api_key=KEY&url={url}&render_js=false
     * Runs on their servers — zero Chrome RAM on the VPS.
     *
     * @return array{html:string,source:string,note:string,headers?:array}|null
     */
    private function fetchViaUnblockApi(string $liveUrl): ?array
    {
        $tpl = defined('CONTENT_FETCH_API_URL') ? (string) CONTENT_FETCH_API_URL : '';
        if ($tpl === '' || !str_contains($tpl, '{url}')) {
            return null;
        }

        $apiUrl = str_replace('{url}', rawurlencode($liveUrl), $tpl);
        $headers = [];
        $html = $this->httpGet($apiUrl, 25, false, $headers, true);
        if ($html === null || strlen($html) < 400) {
            return null;
        }
        if ($this->isChallengeHtml($html, '', 200)) {
            return null;
        }

        return [
            'html' => $html,
            'source' => 'fetch API',
            'note' => 'Live page was bot-blocked; HTML retrieved via configured CONTENT_FETCH_API_URL (not local browser).',
            'headers' => $headers,
        ];
    }

    /**
     * Internet Archive Wayback Machine — free, curl-only, works when CF blocks live.
     *
     * @return array{html:string,source:string,note:string,headers?:array}|null
     */
    private function fetchWaybackHtml(string $liveUrl): ?array
    {
        $discardHeaders = [];
        $availJson = $this->httpGet(
            'https://archive.org/wayback/available?url=' . rawurlencode($liveUrl),
            12,
            false,
            $discardHeaders,
            true
        );
        if ($availJson === null) {
            return null;
        }

        $avail = json_decode($availJson, true);
        $snap = $avail['archived_snapshots']['closest'] ?? null;
        if (!is_array($snap) || empty($snap['available']) || empty($snap['url'])) {
            return null;
        }

        $archiveUrl = (string) $snap['url'];
        // Prefer raw original bytes (id_) so we don't analyze Wayback chrome/toolbar HTML.
        if (!str_contains($archiveUrl, 'id_/')) {
            $archiveUrl = preg_replace('#(/web/\d+)(/https?://)#', '$1id_$2', $archiveUrl, 1) ?: $archiveUrl;
        }

        $headers = [];
        $html = $this->httpGet($archiveUrl, 22, false, $headers, true);
        if ($html === null || strlen($html) < 500) {
            return null;
        }
        if ($this->isChallengeHtml($html, '', 200)) {
            return null;
        }

        $ts = (string) ($snap['timestamp'] ?? '');
        $when = $ts !== '' && strlen($ts) >= 8
            ? substr($ts, 0, 4) . '-' . substr($ts, 4, 2) . '-' . substr($ts, 6, 2)
            : 'unknown date';

        return [
            'html' => $html,
            'source' => 'Wayback',
            'note' => 'Live page blocked by bot protection; analyzed Wayback snapshot from ' . $when . ' (may be outdated).',
            'headers' => $headers,
        ];
    }

    private function httpGet(string $url, int $timeout = 6, bool $trackRedirects = false, ?array &$responseHeaders = null, bool $browserLike = false): ?string
    {
        $headers = [];
        $ua = $browserLike
            ? self::BROWSER_UA
            : ('ScamGuardBot/1.1 (+' . (defined('SITE_URL') ? SITE_URL : 'https://www.chillflix.lol/scamguard') . ')');
        $reqHeaders = $browserLike
            ? [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
            ]
            : [
                'Accept: text/html,application/json,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => '', // accept gzip/deflate (Wayback often serves gzip)
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $reqHeaders,
            CURLOPT_HEADERFUNCTION => static function ($ch, $headerLine) use (&$headers) {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $headers[trim($parts[0])] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        if ($trackRedirects) {
            $this->lastRedirectCount = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
            $this->lastFinalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $this->lastHttpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $this->lastCurlErrno = $errno ?: null;
            $this->lastCurlError = $errno ? (string) $error : null;
        }
        curl_close($ch);

        if ($responseHeaders !== null) {
            $responseHeaders = $headers;
        }

        return $errno ? null : ($result === false ? null : $result);
    }

    private function httpPost(string $url, string $body, int $timeout = 6): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ScamGuardBot/1.1'],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $result = curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);
        return $error ? null : $result;
    }

    private function httpPostForm(string $url, array $fields, int $timeout = 6): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ScamGuardBot/1.1'],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $result = curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);
        return $error ? null : $result;
    }
}
