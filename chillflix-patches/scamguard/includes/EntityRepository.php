<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/PhoneChecker.php';
require_once __DIR__ . '/CryptoChecker.php';
require_once __DIR__ . '/IbanChecker.php';

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
        $normalized = match ($type) {
            'phone' => PhoneChecker::normalize($raw),
            'crypto' => CryptoChecker::normalize($raw),
            'iban' => IbanChecker::normalize($raw),
            default => null,
        };
        if ($normalized === null || $normalized === '') {
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
        if (IbanChecker::normalize($input)) {
            return 'iban';
        }
        if (CryptoChecker::normalize($input) && (
            preg_match('/^0x[a-fA-F0-9]{40}$/', trim($input))
            || preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/', trim($input))
            || preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', trim($input))
        )) {
            return 'crypto';
        }
        // Phone-like: mostly digits with optional + / spaces
        $digits = preg_replace('/\D+/', '', $input);
        if ($digits && strlen($digits) >= 8 && strlen($digits) <= 15 && preg_match('/^\+?[\d\s().-]{8,20}$/', $input)) {
            if (PhoneChecker::normalize($input)) {
                return 'phone';
            }
        }
        return 'website';
    }
}
