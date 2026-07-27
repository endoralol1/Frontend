<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ThreatFeeds.php';
require_once __DIR__ . '/ExternalReputation.php';

/**
 * DomainChecker — multi-source trust signals (no headless browser).
 */
class DomainChecker
{
    private string $domain;
    private array $data = [];
    private array $signals = [];
    private ?int $lastRedirectCount = null;
    private ?string $lastFinalUrl = null;
    private ?int $lastHttpStatus = null;

    public function __construct(string $domain)
    {
        $this->domain = $domain;
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
        $this->data['registrar_risk'] = 0;
        $this->data['spam_hit'] = 0;
        $this->data['tranco_rank'] = null;
        $this->data['tranco_bonus'] = 0;
        $this->data['review_penalty'] = 0;

        $this->checkWhois();
        $this->checkSsl();
        $this->checkDns();
        $this->checkContent();
        $this->checkSpamReputation();
        $this->checkThreatFeeds();
        $this->checkHeuristics();
        $this->scoreRegistrarReputation();
        $this->checkReputationExtras();

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

        $parsed = $this->fetchRdap($this->domain);
        if (!$parsed) {
            $parsed = $this->fetchWhoisCli($this->domain);
        }

        if (!$parsed) {
            $this->addSignal('registration', 'WHOIS / RDAP', 'Unavailable', 'Registry lookup timed out or returned no data', 'warn');
            return;
        }

        $this->data['whois_registrar'] = $parsed['registrar'] ?? null;
        $this->data['whois_privacy_protected'] = !empty($parsed['privacy']) ? 1 : 0;

        if (!empty($parsed['created'])) {
            $createdTs = strtotime($parsed['created']);
            if ($createdTs) {
                $this->data['whois_created_at'] = date('Y-m-d', $createdTs);
                $this->data['domain_age_days'] = (int) floor((time() - $createdTs) / 86400);
            }
        }

        if (!empty($parsed['expires'])) {
            $expiresTs = strtotime($parsed['expires']);
            if ($expiresTs) {
                $this->data['whois_expires_at'] = date('Y-m-d', $expiresTs);
                if (!empty($parsed['created'])) {
                    $createdTs = strtotime($parsed['created']);
                    if ($createdTs) {
                        $this->data['registration_length_days'] = (int) floor(($expiresTs - $createdTs) / 86400);
                    }
                }
            }
        }

        $age = $this->data['domain_age_days'];
        $ageTone = 'neutral';
        if ($age !== null) {
            $ageTone = $age < 30 ? 'bad' : ($age < 180 ? 'warn' : 'good');
        }

        $this->addSignal(
            'registration',
            'Registrar',
            $this->data['whois_registrar'] ?? 'Unknown',
            $parsed['source'] ?? '',
            'neutral'
        );
        $this->addSignal(
            'registration',
            'Domain age',
            $age !== null ? number_format($age) . ' days' : 'Unknown',
            $this->data['whois_created_at'] ? ('Registered ' . $this->data['whois_created_at']) : '',
            $ageTone
        );
        $this->addSignal(
            'registration',
            'Expires',
            $this->data['whois_expires_at'] ?? 'Unknown',
            $this->data['registration_length_days']
                ? ('Term ≈ ' . number_format($this->data['registration_length_days']) . ' days')
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
        $mxRecords = @dns_get_record($this->domain, DNS_MX) ?: [];
        $txtRecords = @dns_get_record($this->domain, DNS_TXT) ?: [];

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
        $dmarcRecs = @dns_get_record('_dmarc.' . $this->domain, DNS_TXT) ?: [];
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

        if ($this->data['ip_address']) {
            $this->lookupIpMeta($this->data['ip_address']);
        }

        $ipNote = '';
        if ($this->data['uses_cdn']) {
            $ipNote = 'This is a ' . $this->data['cdn_provider'] . ' edge IP (proxy/CDN), not necessarily the origin server.';
        }

        $this->addSignal('network', 'Resolved IP', $this->data['ip_address'] ?? 'None', $ipNote, $this->data['uses_cdn'] ? 'warn' : 'neutral');
        if (count($ips) > 1) {
            $this->addSignal('network', 'All A/AAAA', implode(', ', array_slice($ips, 0, 6)), '', 'neutral');
        }
        $this->addSignal('network', 'CDN / proxy', $this->data['uses_cdn'] ? ($this->data['cdn_provider'] ?: 'Yes') : 'Not detected', '', $this->data['uses_cdn'] ? 'warn' : 'good');
        $this->addSignal('network', 'Nameservers', $this->data['nameservers'] ?? 'Unknown', '', 'neutral');
        $this->addSignal('network', 'Hosting / ASN', $this->data['asn_org'] ?? 'Unknown', $this->data['asn'] ?? '', 'neutral');
        $this->addSignal('network', 'Geo', $this->data['host_country'] ?? 'Unknown', '', 'neutral');
        $this->addSignal('email', 'MX records', $this->data['mx_records'] ?: 'None', $this->data['mx_records'] ? 'Mail infrastructure present' : 'No mail exchangers', $this->data['mx_records'] ? 'good' : 'warn');
        $this->addSignal('email', 'SPF', $spf ? 'Present' : 'Missing', '', $spf ? 'good' : 'warn');
        $this->addSignal('email', 'DMARC', $dmarc ? 'Present' : 'Missing', '', $dmarc ? 'good' : 'warn');
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
        $html = $this->httpGet('https://' . $this->domain . '/', 12, true, $headersOut);
        if ($html === null) {
            $html = $this->httpGet('http://' . $this->domain . '/', 12, true, $headersOut);
        }

        $this->data['http_status'] = $this->lastHttpStatus;
        $this->data['final_url'] = $this->lastFinalUrl;
        $this->data['redirect_count'] = $this->lastRedirectCount ?? 0;

        if ($html === null) {
            $this->addSignal('content', 'Homepage fetch', 'Failed', 'Could not download HTML over HTTP/HTTPS', 'bad');
            return;
        }

        $lower = strtolower($html);
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $this->data['page_title'] = trim(html_entity_decode(strip_tags($m[1])));
        }

        $this->data['has_contact_info'] = (
            preg_match('/\b(contact|support@|mailto:|help center|customer service)\b/i', $html)
        ) ? 1 : 0;

        $this->data['has_privacy_policy'] = (
            preg_match('/privacy([ -_]?policy)?/i', $html)
        ) ? 1 : 0;

        // Phone / WhatsApp presence (ScamAdviser-style contact verification signal).
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

        $hasTerms = (bool) preg_match('/terms([ -_]?(of)?[ -_]?(service|use))?/i', $html);
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

        // Bot walls / challenge pages mean we did not actually see the site.
        $title = strtolower((string) ($this->data['page_title'] ?? ''));
        $challenge = (
            str_contains($title, 'just a moment')
            || str_contains($title, 'attention required')
            || str_contains($title, 'checking your browser')
            || str_contains($lower, 'cf-browser-verification')
            || str_contains($lower, 'challenge-platform')
            || str_contains($lower, '_cf_chl')
            || str_contains($lower, 'cdn-cgi/challenge')
            || ((int) ($this->data['http_status'] ?? 0) === 403 && strlen($html) < 8000)
        );
        $this->data['content_incomplete'] = $challenge ? 1 : 0;
        if ($challenge) {
            // Do not treat challenge-page headers / empty legal pages as positive proof.
            $this->data['has_contact_info'] = 0;
            $this->data['has_privacy_policy'] = 0;
            $this->data['has_phone'] = 0;
            $this->data['free_email_contact'] = 0;
            $this->data['noindex'] = 0;
            $this->data['crypto_only_payment'] = 0;
            $this->addSignal(
                'content',
                'Content visibility',
                'Blocked / challenge page',
                'Bot protection hid the real page, so content signals are incomplete — score is capped.',
                'warn'
            );
        } else {
            $this->addSignal('content', 'Content visibility', 'Readable', 'Homepage HTML fetched for analysis', 'good');
        }

        $sec = $this->scoreSecurityHeaders($headersOut);
        $this->data['security_headers'] = json_encode($sec);

        $this->addSignal('content', 'HTTP status', (string) ($this->data['http_status'] ?? 'Unknown'), $this->data['final_url'] ?? '', ($this->data['http_status'] ?? 0) >= 400 ? 'bad' : 'good');
        $this->addSignal('content', 'Page title', $this->data['page_title'] ?: 'None', '', 'neutral');
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
            $this->addSignal('content', 'Privacy policy', $this->data['has_privacy_policy'] ? 'Found' : 'Not found', '', $this->data['has_privacy_policy'] ? 'good' : 'warn');
            $this->addSignal('content', 'Terms page', $hasTerms ? 'Found' : 'Not found', '', $hasTerms ? 'good' : 'neutral');
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

        // Soft risk only — cheap/high-volume registrars are common for both legit sites and scams.
        $elevated = [
            'namecheap' => 8,
            'namesilo' => 7,
            'porkbun' => 6,
            'dynadot' => 6,
            'nicenic' => 10,
            'alibaba' => 8,
            'alibaba cloud' => 8,
            'hostinger' => 7,
            'publicdomainregistry' => 9,
            'pdr ltd' => 9,
            'webnic' => 8,
            'gname' => 10,
            'todaynic' => 10,
            'reg.ru' => 7,
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
                'Registrar reputation',
                'Elevated risk volume',
                'Registrar "' . ($this->data['whois_registrar'] ?? '') . '" is commonly used by both normal and scam sites (' . $matched . '). Soft penalty only.',
                'warn'
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

        // Paid/partner spam products (e.g. ScamAdviser iQ Abuse Scan) are not available to us.
        // A fuller public RBL sweep is added in ExternalReputation (abuseBlacklists).
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
                    $label . ' — same data family ScamAdviser cites for traffic.',
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
            'URLhaus local cache (+ Google Safe Browsing if configured)',
            $this->data['malware_hit'] ? 'bad' : 'good'
        );
        $this->addSignal(
            'threat',
            'Phishing lists',
            $this->data['phishing_hit'] ? 'HIT' : 'Clean',
            'OpenPhish / Phishing.Database (+ GSB if configured)',
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

        $hits = (int) ($this->data['suspicious_keyword_hits'] ?? 0);
        if ($newish && $hits > 0) {
            $flags[] = [
                'code' => 'young_plus_urgency',
                'label' => 'New domain with scam-style urgency language',
                'detail' => $hits . ' suspicious phrase hit(s) on a domain under 90 days old',
                'penalty' => 16,
            ];
        }

        if ($young && !(int) ($this->data['has_privacy_policy'] ?? 0) && !(int) ($this->data['has_contact_info'] ?? 0)) {
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

        if (!empty($this->data['malware_hit'])) {
            $verdict = 'malware';
            $reasons[] = 'Domain/host appears on malware or botnet URL intelligence.';
        } elseif (!empty($this->data['phishing_hit'])) {
            $verdict = 'phishing';
            $reasons[] = 'Domain appears on phishing intelligence feeds.';
        } else {
            $flags = json_decode((string) ($this->data['heuristic_flags'] ?? '[]'), true) ?: [];
            $penalty = array_sum(array_map(static fn($f) => (int) ($f['penalty'] ?? 0), $flags));
            $incomplete = !empty($this->data['content_incomplete']);

            if ($penalty >= 30 || $score <= 25) {
                $verdict = 'likely_scam';
                $reasons[] = 'Multiple scam heuristics and/or very low trust score.';
            } elseif ($penalty >= 14 || $score < 50 || !empty($this->data['spam_hit'])) {
                $verdict = 'suspicious';
                $reasons[] = !empty($this->data['spam_hit'])
                    ? 'Spam reputation signals were found.'
                    : 'Elevated risk signals; treat with caution.';
            } elseif ($incomplete) {
                $verdict = 'caution';
                $reasons[] = 'Real page content was blocked (bot challenge/CDN wall), so this is not a full safety verification.';
            } elseif ($score >= 80) {
                $verdict = 'likely_safe';
                $reasons[] = 'No malware/phishing list hits and strong positive signals.';
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
        }

        if (!empty($this->data['threat_feed_sources'])) {
            $reasons[] = 'Feeds: ' . $this->data['threat_feed_sources'];
        }
        $reasons[] = 'Note: ScamGuard does not yet ingest paid review networks (Trustpilot/ScamAdviser user reports).';

        $this->data['verdict'] = $verdict;
        $this->data['verdict_reasons'] = json_encode($reasons);
        $this->addSignal(
            'verdict',
            'Engine verdict',
            strtoupper(str_replace('_', ' ', $verdict)),
            implode(' | ', $reasons),
            in_array($verdict, ['malware', 'phishing', 'likely_scam'], true) ? 'bad' : ($verdict === 'likely_safe' ? 'good' : 'warn')
        );
    }

    private function mapVerdictToStatus(string $verdict, int $score): string
    {
        return match ($verdict) {
            'malware', 'phishing', 'likely_scam' => 'scam',
            'suspicious' => 'risky',
            'likely_safe' => 'safe',
            'caution' => 'caution',
            default => score_to_status($score),
        };
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
        $score = 50;

        $wAge = get_score_config('weight_domain_age', 20);
        $wReg = get_score_config('weight_registration_length', 10);
        $wSsl = get_score_config('weight_ssl', 15);
        $wHost = get_score_config('weight_hosting', 10);
        $wContent = get_score_config('weight_content', 15);
        $wThreat = get_score_config('weight_threat_feed', 30);
        $newDomainThreshold = get_score_config('new_domain_threshold_days', 180);

        if ($this->data['domain_age_days'] !== null) {
            if ($this->data['domain_age_days'] < 30) {
                $score -= $wAge;
            } elseif ($this->data['domain_age_days'] < $newDomainThreshold) {
                $score -= $wAge * 0.5;
            } else {
                $score += $wAge * 0.5;
            }
        } else {
            $score -= 4; // missing registration data is mildly suspicious / incomplete
        }

        if ($this->data['registration_length_days'] !== null) {
            if ($this->data['registration_length_days'] <= 370) {
                $score -= $wReg * 0.5;
            } else {
                $score += $wReg * 0.5;
            }
        }

        if ($this->data['ssl_valid']) {
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
            // Challenge pages are not proof of safety.
            $score -= 12;
            if (((int) ($this->data['http_status'] ?? 0)) >= 400) {
                $score -= 6;
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
            $score -= 18;
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

        $flags = json_decode((string) ($this->data['heuristic_flags'] ?? '[]'), true) ?: [];
        foreach ($flags as $flag) {
            $score -= (int) ($flag['penalty'] ?? 0);
        }

        $score = (int) round($score);
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
    private function httpGet(string $url, int $timeout = 6, bool $trackRedirects = false, ?array &$responseHeaders = null): ?string
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'ScamGuardBot/1.1 (+' . SITE_URL . ')',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/json,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
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
        if ($trackRedirects) {
            $this->lastRedirectCount = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
            $this->lastFinalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $this->lastHttpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
