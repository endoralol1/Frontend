<?php
/**
 * Crypto address checks — format validation + local/community abuse lists.
 */
class CryptoChecker
{
    private string $raw;
    private array $signals = [];

    public function __construct(string $input)
    {
        $this->raw = trim($input);
    }

    public static function normalize(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        // Strip common URI prefixes
        $input = preg_replace('#^(bitcoin|ethereum|eth|btc|litecoin|ltc|tron|trx):#i', '', $input) ?? $input;
        $input = trim($input);
        if (strlen($input) < 26 || strlen($input) > 128) {
            return null;
        }
        return $input;
    }

    public function run(): array
    {
        $addr = self::normalize($this->raw);
        if ($addr === null) {
            return $this->invalid('Not a recognizable crypto address');
        }

        $kind = $this->detect($addr);
        if ($kind['type'] === 'unknown' || empty($kind['valid'])) {
            return $this->invalid('Address format failed checksum / pattern checks', $addr);
        }

        $abuse = $this->communityAbuse($addr);
        $blacklist = $this->localBlacklist($addr);

        $this->addSignal('facts', 'Asset type', $kind['label'], $kind['note'], 'good');
        $this->addSignal('facts', 'Format', 'Valid', 'Checksum / pattern validation passed', 'good');
        $this->addSignal('facts', 'Blacklist hit', $blacklist ? 'Yes' : 'No', $blacklist ?: 'Not on ScamGuard curated scam-wallet list', $blacklist ? 'bad' : 'good');
        $this->addSignal('facts', 'Community reports', (string) $abuse['count'], $abuse['approved'] . ' approved', $abuse['count'] ? 'bad' : 'good');
        $this->addSignal('facts', 'Recent abuse', $abuse['recent'] ? 'Yes' : 'No', '', $abuse['recent'] ? 'bad' : 'good');

        $score = 78;
        if ($blacklist) {
            $score -= 55;
        }
        $score -= min(40, $abuse['approved'] * 20 + max(0, $abuse['count'] - $abuse['approved']) * 8);
        $score = max(1, min(100, $score));

        $status = score_to_status($score);
        if ($blacklist || $abuse['spam_like']) {
            $status = 'scam';
            $score = min($score, 15);
        } elseif ($abuse['recent']) {
            $status = 'risky';
        }

        $verdict = match (true) {
            (bool) $blacklist, $abuse['spam_like'] => 'likely_scam',
            $abuse['recent'] => 'suspicious',
            default => 'likely_safe',
        };

        $facts = [
            'asset' => $kind['label'],
            'format' => 'Valid',
            'blacklist' => $blacklist ? 'Yes' : 'No',
            'recent_abuse' => $abuse['recent'] ? 'Yes' : 'No',
            'reports' => $abuse['count'],
        ];

        return [
            'entity_type' => 'crypto',
            'entity_value' => $addr,
            'display_value' => $addr,
            'trust_score' => $score,
            'status' => $status,
            'verdict' => $verdict,
            'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE),
            'signals_json' => json_encode($this->signals, JSON_UNESCAPED_SLASHES),
            'asset_type' => $kind['type'],
        ];
    }

    private function invalid(string $reason, string $addr = ''): array
    {
        $this->addSignal('facts', 'Format', 'Invalid', $reason, 'bad');
        return [
            'entity_type' => 'crypto',
            'entity_value' => $addr,
            'display_value' => $this->raw,
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

    /** @return array{type:string,label:string,valid:bool,note:string} */
    private function detect(string $addr): array
    {
        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $addr)) {
            return [
                'type' => 'eth',
                'label' => 'Ethereum / EVM (ETH, USDT-ERC20, …)',
                'valid' => true,
                'note' => 'Matches 20-byte hex address used across EVM chains',
            ];
        }
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $addr)) {
            return [
                'type' => 'trx',
                'label' => 'Tron (TRX / USDT-TRC20)',
                'valid' => $this->base58LooksOk($addr),
                'note' => 'Tron base58 address pattern',
            ];
        }
        if (preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/', $addr)) {
            $ok = str_starts_with(strtolower($addr), 'bc1') ? strlen($addr) >= 14 : $this->base58LooksOk($addr);
            return [
                'type' => 'btc',
                'label' => 'Bitcoin',
                'valid' => $ok,
                'note' => 'Bitcoin address pattern (legacy / bech32)',
            ];
        }
        if (preg_match('/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$/', $addr)) {
            return [
                'type' => 'ltc',
                'label' => 'Litecoin',
                'valid' => $this->base58LooksOk($addr),
                'note' => 'Litecoin-style base58 address',
            ];
        }
        return ['type' => 'unknown', 'label' => 'Unknown', 'valid' => false, 'note' => ''];
    }

    private function base58LooksOk(string $addr): bool
    {
        // Lightweight: alphabet + length; full checksum needs sha256 which we can do for BTC-like
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]+$/', $addr)) {
            return false;
        }
        return $this->base58Check($addr);
    }

    private function base58Check(string $addr): bool
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        // Decode base58 to binary without requiring GMP.
        $bytes = [0];
        for ($i = 0, $n = strlen($addr); $i < $n; $i++) {
            $pos = strpos($alphabet, $addr[$i]);
            if ($pos === false) {
                return false;
            }
            $carry = $pos;
            for ($j = 0, $m = count($bytes); $j < $m; $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }
        $pad = 0;
        for ($i = 0; $i < strlen($addr) && $addr[$i] === '1'; $i++) {
            $pad++;
        }
        $bin = str_repeat("\x00", $pad);
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            $bin .= chr($bytes[$i]);
        }
        if (strlen($bin) < 5) {
            return false;
        }
        $payload = substr($bin, 0, -4);
        $checksum = substr($bin, -4);
        $hash = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        return hash_equals($checksum, $hash);
    }

    private function localBlacklist(string $addr): ?string
    {
        // Small curated starters — expand via admin later
        static $bad = [
            // empty intentionally for now; community reports + future feed populate risk
        ];
        $key = strtolower($addr);
        return $bad[$key] ?? null;
    }

    /** @return array{count:int,approved:int,recent:bool,spam_like:bool} */
    private function communityAbuse(string $addr): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT status, created_at FROM entity_reports
                 WHERE entity_type = 'crypto' AND entity_value = ?
                 ORDER BY created_at DESC LIMIT 50"
            );
            $stmt->execute([$addr]);
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
