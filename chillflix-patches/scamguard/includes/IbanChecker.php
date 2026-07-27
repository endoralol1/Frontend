<?php
/**
 * IBAN validation (ISO 13616) + community abuse reports.
 */
class IbanChecker
{
    private string $raw;
    private array $signals = [];

    public function __construct(string $input)
    {
        $this->raw = trim($input);
    }

    public static function normalize(string $input): ?string
    {
        $input = strtoupper(preg_replace('/\s+/', '', trim($input)) ?? '');
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $input)) {
            return null;
        }
        return $input;
    }

    public function run(): array
    {
        $iban = self::normalize($this->raw);
        if ($iban === null) {
            return $this->invalid('IBAN format looks wrong');
        }

        $country = substr($iban, 0, 2);
        $check = substr($iban, 2, 2);
        $bban = substr($iban, 4);
        $lenOk = $this->expectedLength($country) === null || $this->expectedLength($country) === strlen($iban);
        $checksumOk = $this->mod97($iban);

        $this->addSignal('facts', 'Country', $this->countryName($country) . " ($country)", '', $lenOk ? 'good' : 'warn');
        $this->addSignal('facts', 'Check digits', $check, '', 'neutral');
        $this->addSignal('facts', 'Checksum', $checksumOk ? 'Valid' : 'Invalid', 'ISO 13616 mod-97', $checksumOk ? 'good' : 'bad');
        $this->addSignal('facts', 'Length', (string) strlen($iban), $lenOk ? 'Matches expected length for country' : 'Unexpected length for country', $lenOk ? 'good' : 'warn');
        $this->addSignal('network', 'BBAN', $bban, 'Domestic bank account part', 'neutral');

        $abuse = $this->communityAbuse($iban);
        $this->addSignal('facts', 'Community reports', (string) $abuse['count'], $abuse['approved'] . ' approved', $abuse['count'] ? 'bad' : 'good');
        $this->addSignal('facts', 'Recent abuse', $abuse['recent'] ? 'Yes' : 'No', '', $abuse['recent'] ? 'bad' : 'good');

        $valid = $checksumOk && $lenOk;
        $score = $valid ? 80 : 12;
        $score -= min(45, $abuse['approved'] * 20 + max(0, $abuse['count'] - $abuse['approved']) * 8);
        $score = max(1, min(100, $score));

        $status = !$valid ? 'unknown' : score_to_status($score);
        if ($abuse['spam_like']) {
            $status = 'scam';
            $score = min($score, 15);
        } elseif ($abuse['recent'] && $valid) {
            $status = 'risky';
        }

        $verdict = match (true) {
            !$valid => 'invalid',
            $abuse['spam_like'] => 'likely_scam',
            $abuse['recent'] => 'suspicious',
            default => 'likely_safe',
        };

        $facts = [
            'country' => $country,
            'country_name' => $this->countryName($country),
            'checksum' => $checksumOk ? 'Valid' : 'Invalid',
            'length' => strlen($iban),
            'recent_abuse' => $abuse['recent'] ? 'Yes' : 'No',
            'reports' => $abuse['count'],
        ];

        return [
            'entity_type' => 'iban',
            'entity_value' => $iban,
            'display_value' => $this->pretty($iban),
            'trust_score' => $score,
            'status' => $status,
            'verdict' => $verdict,
            'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE),
            'signals_json' => json_encode($this->signals, JSON_UNESCAPED_SLASHES),
        ];
    }

    private function pretty(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }

    private function invalid(string $reason): array
    {
        $this->addSignal('facts', 'Checksum', 'Invalid', $reason, 'bad');
        return [
            'entity_type' => 'iban',
            'entity_value' => '',
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

    private function mod97(string $iban): bool
    {
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $expanded = '';
        $len = strlen($rearranged);
        for ($i = 0; $i < $len; $i++) {
            $ch = $rearranged[$i];
            if ($ch >= 'A' && $ch <= 'Z') {
                $expanded .= (string) (ord($ch) - 55);
            } else {
                $expanded .= $ch;
            }
        }
        // Chunked mod to avoid bigints if gmp missing
        if (function_exists('gmp_mod')) {
            return gmp_cmp(gmp_mod(gmp_init($expanded, 10), 97), 1) === 0;
        }
        $checksum = 0;
        foreach (str_split($expanded, 7) as $block) {
            $checksum = (int) ($checksum . $block) % 97;
        }
        return $checksum === 1;
    }

    private function expectedLength(string $country): ?int
    {
        static $lens = [
            'AL' => 28, 'AD' => 24, 'AT' => 20, 'AZ' => 28, 'BE' => 16, 'BH' => 22, 'BA' => 20,
            'BR' => 29, 'BG' => 22, 'CR' => 22, 'HR' => 21, 'CY' => 28, 'CZ' => 24, 'DK' => 18,
            'DO' => 28, 'EE' => 20, 'FO' => 18, 'FI' => 18, 'FR' => 27, 'GE' => 22, 'DE' => 22,
            'GI' => 23, 'GR' => 27, 'GL' => 18, 'GT' => 28, 'HU' => 28, 'IS' => 26, 'IE' => 22,
            'IL' => 23, 'IT' => 27, 'JO' => 30, 'KZ' => 20, 'XK' => 20, 'KW' => 30, 'LV' => 21,
            'LB' => 28, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'MK' => 19, 'MT' => 31, 'MR' => 27,
            'MU' => 30, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'NL' => 18, 'NO' => 15, 'PK' => 24,
            'PS' => 29, 'PL' => 28, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'SM' => 27, 'SA' => 24,
            'RS' => 22, 'SK' => 24, 'SI' => 19, 'ES' => 24, 'SE' => 24, 'CH' => 21, 'TN' => 24,
            'TR' => 26, 'AE' => 23, 'GB' => 22, 'VG' => 24,
        ];
        return $lens[$country] ?? null;
    }

    private function countryName(string $iso): string
    {
        static $names = [
            'DE' => 'Germany', 'GB' => 'United Kingdom', 'FR' => 'France', 'NL' => 'Netherlands',
            'BE' => 'Belgium', 'AT' => 'Austria', 'CH' => 'Switzerland', 'IT' => 'Italy',
            'ES' => 'Spain', 'PT' => 'Portugal', 'IE' => 'Ireland', 'PL' => 'Poland',
            'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark', 'FI' => 'Finland',
            'US' => 'United States',
        ];
        return $names[$iso] ?? $iso;
    }

    /** @return array{count:int,approved:int,recent:bool,spam_like:bool} */
    private function communityAbuse(string $iban): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT status, created_at FROM entity_reports
                 WHERE entity_type = 'iban' AND entity_value = ?
                 ORDER BY created_at DESC LIMIT 50"
            );
            $stmt->execute([$iban]);
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
