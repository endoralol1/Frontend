<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/PhoneChecker.php';
require_once __DIR__ . '/CryptoChecker.php';
require_once __DIR__ . '/IbanChecker.php';
require_once __DIR__ . '/CardChecker.php';

class EntityRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function find(string $type, string $value): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM entity_checks WHERE entity_type = ? AND entity_value = ?');
        $stmt->execute([$type, $value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrCheck(string $type, string $raw, bool $force = false): array
    {
        // Card pretty-URL loads by stored hash key
        if ($type === 'card' && str_starts_with($raw, 'card:')) {
            $existing = $this->find('card', $raw);
            if ($existing) {
                $this->bumpSearch((int) $existing['id']);
                return $existing;
            }
            return [
                'entity_type' => 'card',
                'entity_value' => $raw,
                'display_value' => 'Unknown card',
                'trust_score' => 1,
                'status' => 'unknown',
                'verdict' => 'invalid',
                'facts_json' => json_encode(['error' => 'Card check not found']),
                'signals_json' => '[]',
                '_invalid' => true,
            ];
        }

        $normalized = match ($type) {
            'phone' => PhoneChecker::normalize($raw),
            'crypto' => CryptoChecker::normalize($raw),
            'iban' => IbanChecker::normalize($raw),
            'card' => CardChecker::normalize($raw),
            default => null,
        };
        if ($type === 'card' && $normalized !== null) {
            // Store lookups by hash key, but checker needs raw digits once.
            $result = (new CardChecker($normalized))->run();
            $existing = $this->find('card', (string) $result['entity_value']);
            if ($existing && !$force && !$this->isStale($existing)) {
                $this->bumpSearch((int) $existing['id']);
                return $existing;
            }
            $id = $this->upsert($result, $existing['id'] ?? null);
            return $this->findById($id) ?? $result;
        }
        if ($normalized === null || $normalized === '') {
            // Incomplete IBAN still gets a helpful check page
            if ($type === 'iban' && preg_match('/^[A-Za-z]{2}\d{2}[A-Za-z0-9]*$/', preg_replace('/\s+/', '', $raw) ?? '')) {
                $result = (new IbanChecker($raw))->run();
                return $result + ['_invalid' => ($result['verdict'] ?? '') === 'invalid'];
            }
            return [
                'entity_type' => $type,
                'entity_value' => '',
                'display_value' => $raw,
                'trust_score' => 1,
                'status' => 'unknown',
                'verdict' => 'invalid',
                'facts_json' => json_encode(['error' => 'Invalid input']),
                'signals_json' => '[]',
                '_invalid' => true,
            ];
        }

        $existing = $this->find($type, $normalized);
        if ($existing && !$force && !$this->isStale($existing)) {
            $this->bumpSearch((int) $existing['id']);
            return $existing;
        }

        $result = match ($type) {
            'phone' => (new PhoneChecker($normalized))->run(),
            'crypto' => (new CryptoChecker($normalized))->run(),
            'iban' => (new IbanChecker($normalized))->run(),
            'card' => (new CardChecker($normalized))->run(),
            default => throw new InvalidArgumentException('Unknown type'),
        };

        $id = $this->upsert($result, $existing['id'] ?? null);
        return $this->findById($id) ?? $result;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM entity_checks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isStale(array $row): bool
    {
        if (empty($row['last_checked'])) {
            return true;
        }
        $hours = (int) get_score_config('recheck_interval_hours', 72);
        return (time() - strtotime($row['last_checked'])) > ($hours * 3600);
    }

    private function bumpSearch(int $id): void
    {
        $this->db->prepare('UPDATE entity_checks SET search_count = search_count + 1 WHERE id = ?')->execute([$id]);
    }

    private function upsert(array $data, ?int $existingId = null): int
    {
        if ($existingId) {
            $stmt = $this->db->prepare(
                'UPDATE entity_checks SET
                    display_value = ?, trust_score = ?, status = ?, verdict = ?,
                    facts_json = ?, signals_json = ?,
                    check_count = check_count + 1, search_count = search_count + 1,
                    last_checked = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $data['display_value'] ?? $data['entity_value'],
                (int) $data['trust_score'],
                $data['status'] ?? 'unknown',
                $data['verdict'] ?? 'unknown',
                $data['facts_json'] ?? null,
                $data['signals_json'] ?? null,
                $existingId,
            ]);
            return $existingId;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO entity_checks
                (entity_type, entity_value, display_value, trust_score, status, verdict, facts_json, signals_json, check_count, search_count, last_checked)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())'
        );
        $stmt->execute([
            $data['entity_type'],
            $data['entity_value'],
            $data['display_value'] ?? $data['entity_value'],
            (int) $data['trust_score'],
            $data['status'] ?? 'unknown',
            $data['verdict'] ?? 'unknown',
            $data['facts_json'] ?? null,
            $data['signals_json'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Detect input type from free-text (homepage auto mode). */
    public static function detectType(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return 'website';
        }

        $compact = preg_replace('/\s+/', '', $input) ?? $input;

        // Full IBAN
        if (IbanChecker::normalize($input)) {
            return 'iban';
        }
        // Incomplete / in-progress IBAN (e.g. DE66051092) — never treat as phone
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{2,}$/i', $compact) && preg_match('/[A-Za-z]/', $compact)) {
            return 'iban';
        }

        if (
            preg_match('/^0x[a-fA-F0-9]{40}$/', $compact)
            || preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/', $compact)
            || preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $compact)
            || (preg_match('/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$/', $compact) && strlen($compact) >= 26)
        ) {
            return 'crypto';
        }

        // Bank card BEFORE phone (13–19 digits)
        if (CardChecker::looksLike($input)) {
            return 'card';
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        // Phone only if the raw input has no letters
        if (
            !preg_match('/[A-Za-z]/', $input)
            && preg_match('/^[\s()+.\-]*\d[\d\s()+.\-]*$/', $input)
            && strlen($digits) >= 6
            && strlen($digits) <= 15
            && PhoneChecker::normalize($input)
        ) {
            return 'phone';
        }

        if (normalize_domain($input) || preg_match('#^https?://#i', $input)) {
            return 'website';
        }

        return 'website';
    }
}
