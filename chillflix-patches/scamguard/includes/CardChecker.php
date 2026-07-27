<?php
/**
 * Payment card (PAN) checks — Luhn, brand, BIN hints, community reports.
 * Never stores or displays the full PAN in logs beyond masked form.
 */
class CardChecker
{
    private string $raw;
    private array $signals = [];

    public function __construct(string $input)
    {
        $this->raw = trim($input);
    }

    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($input)) ?? '';
        if (strlen($digits) < 13 || strlen($digits) > 19) {
            return null;
        }
        // Reject if original had letters (not a PAN)
        if (preg_match('/[A-Za-z]/', $input)) {
            return null;
        }
        return $digits;
    }

    public static function looksLike(string $input): bool
    {
        $compact = preg_replace('/[\s\-]+/', '', trim($input)) ?? '';
        if ($compact === '' || preg_match('/[A-Za-z]/', $compact)) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $compact) ?? '';
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        // Card-spaced input (4-4-4-4) or continuous digits in PAN range
        if (preg_match('/^[\d\s\-]{13,23}$/', $input) && self::luhn($digits)) {
            return true;
        }
        // Even without Luhn yet (user still typing) — 13–19 pure digits, not phone-shaped
        if ($len >= 13 && $len <= 19 && preg_match('/^[\d\s\-]+$/', $input)) {
            // Prefer card over phone when length is card-range and no leading +
            if (!str_starts_with(trim($input), '+') && !str_starts_with(trim($input), '00')) {
                return true;
            }
        }
        return false;
    }

    public function run(): array
    {
        $pan = self::normalize($this->raw);
        if ($pan === null) {
            return $this->invalid('Not a valid card number format');
        }

        $luhnOk = self::luhn($pan);
        $brand = $this->brand($pan);
        $masked = $this->mask($pan);
        $bin = substr($pan, 0, 6);

        $abuse = $this->communityAbuse($pan);

        $this->addSignal('facts', 'Card brand', $brand, 'Detected from leading digits (IIN/BIN)', $brand !== 'Unknown' ? 'good' : 'warn');
        $this->addSignal('facts', 'Number', $masked, 'Only a masked form is stored — full PAN is not kept', 'neutral');
        $this->addSignal('facts', 'Luhn checksum', $luhnOk ? 'Valid' : 'Invalid', 'Industry-standard card digit check', $luhnOk ? 'good' : 'bad');
        $this->addSignal('facts', 'BIN / IIN', $bin, 'First 6 digits identify the issuing range', 'neutral');
        $this->addSignal('facts', 'Community reports', (string) $abuse['count'], $abuse['approved'] . ' approved', $abuse['count'] ? 'bad' : 'good');
        $this->addSignal('facts', 'Recent abuse', $abuse['recent'] ? 'Yes' : 'No', '', $abuse['recent'] ? 'bad' : 'good');

        $score = $luhnOk ? 70 : 20;
        $score -= min(45, $abuse['approved'] * 20 + max(0, $abuse['count'] - $abuse['approved']) * 8);
        $score = max(1, min(100, $score));

        $status = !$luhnOk ? 'unknown' : score_to_status($score);
        if ($abuse['spam_like']) {
            $status = 'scam';
            $score = min($score, 15);
        } elseif ($abuse['recent'] && $luhnOk) {
            $status = 'risky';
        }

        $verdict = match (true) {
            !$luhnOk => 'invalid',
            $abuse['spam_like'] => 'likely_scam',
            $abuse['recent'] => 'suspicious',
            default => 'likely_safe',
        };

        // Store only SHA-256 of PAN as entity_value (never full card in DB plaintext path)
        $hash = hash('sha256', $pan);

        $facts = [
            'brand' => $brand,
            'masked' => $masked,
            'luhn' => $luhnOk ? 'Valid' : 'Invalid',
            'bin' => $bin,
            'recent_abuse' => $abuse['recent'] ? 'Yes' : 'No',
            'reports' => $abuse['count'],
        ];

        return [
            'entity_type' => 'card',
            'entity_value' => 'card:' . $hash,
            'display_value' => $masked . ($brand !== 'Unknown' ? " ($brand)" : ''),
            'trust_score' => $score,
            'status' => $status,
            'verdict' => $verdict,
            'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE),
            'signals_json' => json_encode($this->signals, JSON_UNESCAPED_SLASHES),
        ];
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return $sum % 10 === 0;
    }

    private function brand(string $pan): string
    {
        if (preg_match('/^4/', $pan)) {
            return 'Visa';
        }
        if (preg_match('/^5[1-5]/', $pan) || preg_match('/^2(2[2-9]|[3-6]|7[01]|720)/', $pan)) {
            return 'Mastercard';
        }
        if (preg_match('/^3[47]/', $pan)) {
            return 'American Express';
        }
        if (preg_match('/^6(?:011|5)/', $pan)) {
            return 'Discover';
        }
        if (preg_match('/^3(?:0[0-5]|[68])/', $pan)) {
            return 'Diners Club';
        }
        if (preg_match('/^(?:2131|1800|35)/', $pan)) {
            return 'JCB';
        }
        if (preg_match('/^62/', $pan)) {
            return 'UnionPay';
        }
        return 'Unknown';
    }

    private function mask(string $pan): string
    {
        $len = strlen($pan);
        if ($len <= 10) {
            return str_repeat('•', max(0, $len - 4)) . substr($pan, -4);
        }
        return substr($pan, 0, 6) . str_repeat('•', $len - 10) . substr($pan, -4);
    }

    private function invalid(string $reason): array
    {
        $this->addSignal('facts', 'Format', 'Invalid', $reason, 'bad');
        return [
            'entity_type' => 'card',
            'entity_value' => '',
            'display_value' => 'Invalid card',
            'trust_score' => 10,
            'status' => 'unknown',
            'verdict' => 'invalid',
            'facts_json' => json_encode(['error' => $reason]),
            'signals_json' => json_encode($this->signals),
        ];
    }

    private function addSignal(string $group, string $label, $value, string $note = '', string $tone = 'neutral'): void
    {
        $this->signals[] = compact('group', 'label', 'value', 'note', 'tone');
    }

    /** @return array{count:int,approved:int,recent:bool,spam_like:bool} */
    private function communityAbuse(string $pan): array
    {
        $hash = 'card:' . hash('sha256', $pan);
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT status, created_at FROM entity_reports
                 WHERE entity_type = 'card' AND entity_value = ?
                 ORDER BY created_at DESC LIMIT 50"
            );
            $stmt->execute([$hash]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        $count = count($rows);
        $approved = 0;
        $recent = false;
        $cutoff = time() - 180 * 86400;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'approved') {
                $approved++;
            }
            $ts = strtotime((string) ($row['created_at'] ?? ''));
            if ($ts && $ts >= $cutoff && in_array($row['status'] ?? '', ['pending', 'approved'], true)) {
                $recent = true;
            }
        }
        return [
            'count' => $count,
            'approved' => $approved,
            'recent' => $recent || $approved > 0,
            'spam_like' => $approved >= 1 || $count >= 3,
        ];
    }
}
