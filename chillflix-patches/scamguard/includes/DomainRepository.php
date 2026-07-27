<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/DomainChecker.php';

class DomainRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function find(string $domain): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM domains WHERE domain = ?');
        $stmt->execute([$domain]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM domains WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function isStale(array $record): bool
    {
        if (empty($record['last_checked'])) {
            return true;
        }

        // Incomplete first-pass results should be refreshed ASAP.
        if ($this->isIncomplete($record)) {
            return true;
        }

        $intervalHours = (int) get_score_config('recheck_interval_hours', 72);
        $lastChecked = strtotime($record['last_checked']);
        return (time() - $lastChecked) > ($intervalHours * 3600);
    }

    public function isIncomplete(array $record): bool
    {
        // Missing both WHOIS age and ASN usually means checks timed out / failed.
        $noWhois = empty($record['whois_registrar']) && $record['domain_age_days'] === null;
        $noHost = empty($record['asn_org']) && empty($record['host_country']);
        $noSignals = empty($record['signals_json']);
        return $noWhois || ($noHost && $noSignals);
    }

    public function getOrCheck(string $domain, string $discoveredVia = 'search', bool $force = false): array
    {
        $existing = $this->find($domain);

        if ($existing && $existing['manual_override'] && !$force) {
            $this->incrementSearchCount($existing['id']);
            return $existing;
        }

        if ($existing && !$force && !$this->isStale($existing)) {
            $this->incrementSearchCount($existing['id']);
            return $existing;
        }

        $checker = new DomainChecker($domain);
        $result = $checker->run();

        $id = $this->upsert($result, $existing['id'] ?? null, $discoveredVia);
        return $this->findById($id);
    }

    public function upsert(array $data, ?int $existingId = null, string $discoveredVia = 'search'): int
    {
        $fields = [
            $data['trust_score'], $data['status'],
            $data['whois_registrar'] ?? null,
            $data['whois_created_at'] ?? null,
            $data['whois_expires_at'] ?? null,
            $data['whois_privacy_protected'] ?? null,
            $data['domain_age_days'] ?? null,
            $data['registration_length_days'] ?? null,
            $data['ssl_valid'] ?? null,
            $data['ssl_issuer'] ?? null,
            $data['ssl_expires_at'] ?? null,
            $data['ip_address'] ?? null,
            $data['asn'] ?? null,
            $data['asn_org'] ?? null,
            $data['host_country'] ?? null,
            $data['nameservers'] ?? null,
            $data['has_contact_info'] ?? null,
            $data['has_privacy_policy'] ?? null,
            $data['redirect_count'] ?? null,
            $data['suspicious_keyword_hits'] ?? null,
            $data['threat_feed_hit'] ?? 0,
            $data['threat_feed_sources'] ?? null,
            $data['signals_json'] ?? null,
            $data['mx_records'] ?? null,
            $data['has_spf'] ?? null,
            $data['has_dmarc'] ?? null,
            $data['uses_cdn'] ?? null,
            $data['cdn_provider'] ?? null,
            $data['page_title'] ?? null,
            $data['http_status'] ?? null,
            $data['final_url'] ?? null,
            $data['security_headers'] ?? null,
            $data['malware_hit'] ?? 0,
            $data['phishing_hit'] ?? 0,
            $data['heuristic_flags'] ?? null,
            $data['verdict'] ?? null,
            $data['verdict_reasons'] ?? null,
        ];

        if ($existingId) {
            $sql = 'UPDATE domains SET
                trust_score = ?, status = ?, last_checked = NOW(), check_count = check_count + 1,
                whois_registrar = ?, whois_created_at = ?, whois_expires_at = ?, whois_privacy_protected = ?,
                domain_age_days = ?, registration_length_days = ?,
                ssl_valid = ?, ssl_issuer = ?, ssl_expires_at = ?,
                ip_address = ?, asn = ?, asn_org = ?, host_country = ?, nameservers = ?,
                has_contact_info = ?, has_privacy_policy = ?, redirect_count = ?, suspicious_keyword_hits = ?,
                threat_feed_hit = ?, threat_feed_sources = ?,
                signals_json = ?, mx_records = ?, has_spf = ?, has_dmarc = ?, uses_cdn = ?, cdn_provider = ?,
                page_title = ?, http_status = ?, final_url = ?, security_headers = ?,
                malware_hit = ?, phishing_hit = ?, heuristic_flags = ?, verdict = ?, verdict_reasons = ?
                WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([...$fields, $existingId]);
            $id = $existingId;
        } else {
            $sql = 'INSERT INTO domains
                (domain, trust_score, status, last_checked, check_count,
                 whois_registrar, whois_created_at, whois_expires_at, whois_privacy_protected,
                 domain_age_days, registration_length_days,
                 ssl_valid, ssl_issuer, ssl_expires_at,
                 ip_address, asn, asn_org, host_country, nameservers,
                 has_contact_info, has_privacy_policy, redirect_count, suspicious_keyword_hits,
                 threat_feed_hit, threat_feed_sources,
                 signals_json, mx_records, has_spf, has_dmarc, uses_cdn, cdn_provider,
                 page_title, http_status, final_url, security_headers,
                 malware_hit, phishing_hit, heuristic_flags, verdict, verdict_reasons, discovered_via)
                VALUES (?, ?, ?, NOW(), 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['domain'],
                ...$fields,
                $discoveredVia,
            ]);
            $id = (int) $this->db->lastInsertId();
        }

        $stmt = $this->db->prepare('INSERT INTO domain_history (domain_id, trust_score, status) VALUES (?, ?, ?)');
        $stmt->execute([$id, $data['trust_score'], $data['status']]);

        return $id;
    }

    private function incrementSearchCount(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE domains SET search_count = search_count + 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function getHistory(int $domainId, int $limit = 30): array
    {
        $stmt = $this->db->prepare('SELECT trust_score, status, checked_at FROM domain_history WHERE domain_id = ? ORDER BY checked_at DESC LIMIT ?');
        $stmt->bindValue(1, $domainId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll());
    }

    public function recentlyChecked(int $limit = 12): array
    {
        $stmt = $this->db->prepare('SELECT domain, trust_score, status, last_checked FROM domains ORDER BY last_checked DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Mix recent non-scam + scam rows so the homepage isn't only threat-feed junk.
     */
    public function recentlyCheckedMixed(int $limit = 12): array
    {
        $half = max(1, (int) floor($limit / 2));
        $safe = $this->db->prepare(
            "SELECT domain, trust_score, status, last_checked FROM domains
             WHERE status IN ('safe','caution','whitelisted','risky','unknown')
             ORDER BY last_checked DESC LIMIT ?"
        );
        $safe->bindValue(1, $half, PDO::PARAM_INT);
        $safe->execute();
        $safeRows = $safe->fetchAll();

        $scam = $this->db->prepare(
            "SELECT domain, trust_score, status, last_checked FROM domains
             WHERE status IN ('scam','blacklisted')
             ORDER BY last_checked DESC LIMIT ?"
        );
        $scam->bindValue(1, $limit - count($safeRows), PDO::PARAM_INT);
        $scam->execute();
        $scamRows = $scam->fetchAll();

        $merged = array_merge($safeRows, $scamRows);
        usort($merged, static function (array $a, array $b): int {
            return strcmp((string) ($b['last_checked'] ?? ''), (string) ($a['last_checked'] ?? ''));
        });

        if (count($merged) < $limit) {
            $extra = $this->recentlyChecked($limit);
            $seen = [];
            foreach ($merged as $row) {
                $seen[$row['domain']] = true;
            }
            foreach ($extra as $row) {
                if (isset($seen[$row['domain']])) {
                    continue;
                }
                $merged[] = $row;
                if (count($merged) >= $limit) {
                    break;
                }
            }
        }

        return array_slice($merged, 0, $limit);
    }

    public function browse(int $page = 1, int $perPage = 40, string $status = '', string $q = ''): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where[] = 'domain LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM domains $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT domain, trust_score, status, last_checked, discovered_via, verdict
             FROM domains $whereSql
             ORDER BY last_checked DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function sitemapEntries(int $limit = 50000): array
    {
        $stmt = $this->db->prepare(
            'SELECT domain, last_checked, updated_at FROM domains
             WHERE last_checked IS NOT NULL
             ORDER BY last_checked DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function stats(): array
    {
        $total = $this->db->query('SELECT COUNT(*) FROM domains')->fetchColumn();
        $scams = $this->db->query("SELECT COUNT(*) FROM domains WHERE status IN ('scam','blacklisted')")->fetchColumn();
        $safe = $this->db->query("SELECT COUNT(*) FROM domains WHERE status IN ('safe','whitelisted')")->fetchColumn();
        $checkedToday = $this->db->query("SELECT COUNT(*) FROM domains WHERE DATE(last_checked) = CURDATE()")->fetchColumn();
        return [
            'total_domains' => (int) $total,
            'flagged_scams' => (int) $scams,
            'likely_safe' => (int) $safe,
            'checked_today' => (int) $checkedToday,
        ];
    }
}
