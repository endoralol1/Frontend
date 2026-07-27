<?php
/**
 * Phone number scam check — format, country, line type, VoIP heuristics,
 * and community abuse reports (ScamAdviser-style key facts / network info).
 */
class PhoneChecker
{
    private string $raw;
    private string $e164;
    private array $data = [];
    private array $signals = [];

    public function __construct(string $input)
    {
        $this->raw = trim($input);
        $this->e164 = self::normalize($input) ?? '';
    }

    public static function normalize(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        // Keep leading +, strip other junk
        $hasPlus = str_starts_with($input, '+');
        $digits = preg_replace('/\D+/', '', $input);
        if ($digits === null || strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }
        // Common local formats: 00-prefix → international
        if (str_starts_with($digits, '00') && strlen($digits) > 9) {
            $digits = substr($digits, 2);
        }
        return '+' . $digits;
    }

    public function run(): array
    {
        if ($this->e164 === '') {
            return [
                'entity_type' => 'phone',
                'entity_value' => '',
                'display_value' => $this->raw,
                'trust_score' => 1,
                'status' => 'unknown',
                'verdict' => 'invalid',
                'facts_json' => json_encode(['error' => 'Invalid phone number']),
                'signals_json' => '[]',
            ];
        }

        $parsed = $this->parse($this->e164);
        $abuse = $this->communityAbuse($this->e164);

        $this->data = array_merge($parsed, [
            'entity_type' => 'phone',
            'entity_value' => $this->e164,
            'display_value' => $this->e164,
            'status_label' => $parsed['valid'] ? 'Active format' : 'Invalid',
            'recent_abuse' => $abuse['recent'] ? 'Yes' : 'No',
            'spammer' => $abuse['spam_like'] ? 'Yes' : 'No',
            'report_count' => $abuse['count'],
            'approved_reports' => $abuse['approved'],
        ]);

        $this->addSignal('facts', 'Status', $this->data['status_label'], $parsed['valid'] ? 'Number matches a known country dialing pattern' : 'Could not validate format', $parsed['valid'] ? 'good' : 'bad');
        $this->addSignal('facts', 'Recent abuse', $this->data['recent_abuse'], $abuse['count'] ? ($abuse['count'] . ' report(s) in ScamGuard') : 'No community reports yet', $abuse['recent'] ? 'bad' : 'good');
        $this->addSignal('facts', 'Spammer', $this->data['spammer'], $abuse['spam_like'] ? 'Multiple / serious reports on this number' : 'Not flagged as a spammer in our reports', $abuse['spam_like'] ? 'bad' : 'good');

        $this->addSignal('network', 'Carrier', $parsed['carrier'] ?: 'Unknown', $parsed['carrier_note'] ?? 'Best-effort from public prefix maps; number portability may differ', $parsed['carrier'] ? 'neutral' : 'warn');
        $this->addSignal('network', 'Country', ($parsed['country_name'] ?? 'Unknown') . ($parsed['country'] ? ' (' . $parsed['country'] . ')' : ''), '', $parsed['country'] ? 'good' : 'warn');
        $this->addSignal('network', 'Region', $parsed['region'] ?: 'Unknown', '', $parsed['region'] ? 'neutral' : 'neutral');
        $this->addSignal('network', 'Dialing code', (string) ($parsed['dialing_code'] ?? '—'), '', 'neutral');
        $this->addSignal('network', 'Line type', $parsed['line_type'] ?: 'Unknown', '', $parsed['line_type'] === 'VoIP' ? 'warn' : 'neutral');
        $this->addSignal('network', 'VOIP', !empty($parsed['is_voip']) ? 'Yes' : 'No', !empty($parsed['is_voip']) ? 'VoIP numbers are often used in scam call centers' : 'Not detected as a typical VoIP prefix', !empty($parsed['is_voip']) ? 'warn' : 'good');

        $score = $this->score($parsed, $abuse);
        $status = score_to_status($score);
        if (!$parsed['valid']) {
            $status = 'unknown';
            $score = min($score, 20);
        }
        if ($abuse['spam_like']) {
            $status = 'scam';
        } elseif ($abuse['recent']) {
            $status = in_array($status, ['safe', 'caution'], true) ? 'risky' : $status;
        }

        $verdict = match (true) {
            !$parsed['valid'] => 'invalid',
            $abuse['spam_like'] => 'likely_scam',
            $abuse['recent'] => 'suspicious',
            !empty($parsed['is_voip']) => 'caution',
            default => 'likely_safe',
        };

        $this->data['trust_score'] = $score;
        $this->data['status'] = $status;
        $this->data['verdict'] = $verdict;
        $this->data['facts_json'] = json_encode([
            'status' => $this->data['status_label'],
            'recent_abuse' => $this->data['recent_abuse'],
            'spammer' => $this->data['spammer'],
            'carrier' => $parsed['carrier'] ?: 'Unknown',
            'country' => $parsed['country'] ?? null,
            'country_name' => $parsed['country_name'] ?? null,
            'region' => $parsed['region'] ?: null,
            'dialing_code' => $parsed['dialing_code'] ?? null,
            'line_type' => $parsed['line_type'] ?: 'Unknown',
            'voip' => !empty($parsed['is_voip']) ? 'Yes' : 'No',
            'report_count' => $abuse['count'],
        ], JSON_UNESCAPED_UNICODE);
        $this->data['signals_json'] = json_encode($this->signals, JSON_UNESCAPED_SLASHES);

        return $this->data;
    }

    private function addSignal(string $group, string $label, $value, string $note = '', string $tone = 'neutral'): void
    {
        $this->signals[] = compact('group', 'label', 'value', 'note', 'tone');
    }

    /** @return array{count:int,approved:int,recent:bool,spam_like:bool} */
    private function communityAbuse(string $e164): array
    {
        try {
            $db = Database::getConnection();
            $digits = ltrim($e164, '+');
            $stmt = $db->prepare(
                "SELECT status, category, created_at FROM entity_reports
                 WHERE entity_type = 'phone'
                   AND (entity_value = ? OR entity_value = ? OR entity_value LIKE ?)
                 ORDER BY created_at DESC LIMIT 50"
            );
            $stmt->execute([$e164, $digits, '%' . $digits]);
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
        $spamLike = $approved >= 2 || ($approved >= 1 && $count >= 3);

        return [
            'count' => $count,
            'approved' => $approved,
            'recent' => $recent || $approved > 0,
            'spam_like' => $spamLike,
        ];
    }

    private function score(array $parsed, array $abuse): int
    {
        $score = 72;
        if (!$parsed['valid']) {
            return 15;
        }
        if (!empty($parsed['is_voip'])) {
            $score -= 12;
        }
        if (($parsed['line_type'] ?? '') === 'Unknown') {
            $score -= 4;
        }
        $score -= min(40, $abuse['approved'] * 18 + max(0, $abuse['count'] - $abuse['approved']) * 6);
        if ($abuse['spam_like']) {
            $score -= 25;
        }
        return max(1, min(100, $score));
    }

    /** @return array<string,mixed> */
    private function parse(string $e164): array
    {
        $digits = ltrim($e164, '+');
        $cc = $this->matchCountry($digits);
        if (!$cc) {
            return [
                'valid' => false,
                'country' => null,
                'country_name' => null,
                'dialing_code' => null,
                'national' => $digits,
                'line_type' => 'Unknown',
                'is_voip' => false,
                'carrier' => null,
                'region' => null,
            ];
        }

        $national = substr($digits, strlen($cc['code']));
        $lenOk = strlen($national) >= $cc['min'] && strlen($national) <= $cc['max'];
        $line = $this->guessLineType($cc['iso'], $national);
        $voip = ($line['type'] === 'VoIP');
        $carrier = $this->guessCarrier($cc['iso'], $national);
        $region = $this->guessRegion($cc['iso'], $national);

        return [
            'valid' => $lenOk,
            'country' => $cc['iso'],
            'country_name' => $cc['name'],
            'dialing_code' => $cc['code'],
            'national' => $national,
            'line_type' => $line['type'],
            'is_voip' => $voip,
            'carrier' => $carrier['name'],
            'carrier_note' => $carrier['note'],
            'region' => $region,
        ];
    }

    /** Longest-prefix country match. */
    private function matchCountry(string $digits): ?array
    {
        $list = $this->countries();
        usort($list, static fn($a, $b) => strlen($b['code']) <=> strlen($a['code']));
        foreach ($list as $c) {
            if (str_starts_with($digits, $c['code'])) {
                return $c;
            }
        }
        return null;
    }

    /** @return list<array{code:string,iso:string,name:string,min:int,max:int}> */
    private function countries(): array
    {
        // Common calling codes (not exhaustive). Lengths are typical national significant number lengths.
        return [
            ['code' => '1', 'iso' => 'US', 'name' => 'United States / Canada', 'min' => 10, 'max' => 10],
            ['code' => '7', 'iso' => 'RU', 'name' => 'Russia / Kazakhstan', 'min' => 10, 'max' => 10],
            ['code' => '20', 'iso' => 'EG', 'name' => 'Egypt', 'min' => 8, 'max' => 10],
            ['code' => '27', 'iso' => 'ZA', 'name' => 'South Africa', 'min' => 9, 'max' => 9],
            ['code' => '30', 'iso' => 'GR', 'name' => 'Greece', 'min' => 10, 'max' => 10],
            ['code' => '31', 'iso' => 'NL', 'name' => 'Netherlands', 'min' => 9, 'max' => 9],
            ['code' => '32', 'iso' => 'BE', 'name' => 'Belgium', 'min' => 8, 'max' => 9],
            ['code' => '33', 'iso' => 'FR', 'name' => 'France', 'min' => 9, 'max' => 9],
            ['code' => '34', 'iso' => 'ES', 'name' => 'Spain', 'min' => 9, 'max' => 9],
            ['code' => '36', 'iso' => 'HU', 'name' => 'Hungary', 'min' => 8, 'max' => 9],
            ['code' => '39', 'iso' => 'IT', 'name' => 'Italy', 'min' => 9, 'max' => 10],
            ['code' => '40', 'iso' => 'RO', 'name' => 'Romania', 'min' => 9, 'max' => 9],
            ['code' => '41', 'iso' => 'CH', 'name' => 'Switzerland', 'min' => 9, 'max' => 9],
            ['code' => '43', 'iso' => 'AT', 'name' => 'Austria', 'min' => 7, 'max' => 13],
            ['code' => '44', 'iso' => 'GB', 'name' => 'United Kingdom', 'min' => 9, 'max' => 10],
            ['code' => '45', 'iso' => 'DK', 'name' => 'Denmark', 'min' => 8, 'max' => 8],
            ['code' => '46', 'iso' => 'SE', 'name' => 'Sweden', 'min' => 7, 'max' => 9],
            ['code' => '47', 'iso' => 'NO', 'name' => 'Norway', 'min' => 8, 'max' => 8],
            ['code' => '48', 'iso' => 'PL', 'name' => 'Poland', 'min' => 9, 'max' => 9],
            ['code' => '49', 'iso' => 'DE', 'name' => 'Germany', 'min' => 6, 'max' => 12],
            ['code' => '51', 'iso' => 'PE', 'name' => 'Peru', 'min' => 8, 'max' => 9],
            ['code' => '52', 'iso' => 'MX', 'name' => 'Mexico', 'min' => 10, 'max' => 10],
            ['code' => '54', 'iso' => 'AR', 'name' => 'Argentina', 'min' => 10, 'max' => 10],
            ['code' => '55', 'iso' => 'BR', 'name' => 'Brazil', 'min' => 10, 'max' => 11],
            ['code' => '56', 'iso' => 'CL', 'name' => 'Chile', 'min' => 8, 'max' => 9],
            ['code' => '57', 'iso' => 'CO', 'name' => 'Colombia', 'min' => 10, 'max' => 10],
            ['code' => '58', 'iso' => 'VE', 'name' => 'Venezuela', 'min' => 10, 'max' => 10],
            ['code' => '60', 'iso' => 'MY', 'name' => 'Malaysia', 'min' => 8, 'max' => 10],
            ['code' => '61', 'iso' => 'AU', 'name' => 'Australia', 'min' => 9, 'max' => 9],
            ['code' => '62', 'iso' => 'ID', 'name' => 'Indonesia', 'min' => 9, 'max' => 11],
            ['code' => '63', 'iso' => 'PH', 'name' => 'Philippines', 'min' => 10, 'max' => 10],
            ['code' => '64', 'iso' => 'NZ', 'name' => 'New Zealand', 'min' => 8, 'max' => 10],
            ['code' => '65', 'iso' => 'SG', 'name' => 'Singapore', 'min' => 8, 'max' => 8],
            ['code' => '66', 'iso' => 'TH', 'name' => 'Thailand', 'min' => 8, 'max' => 9],
            ['code' => '81', 'iso' => 'JP', 'name' => 'Japan', 'min' => 9, 'max' => 10],
            ['code' => '82', 'iso' => 'KR', 'name' => 'South Korea', 'min' => 8, 'max' => 10],
            ['code' => '84', 'iso' => 'VN', 'name' => 'Vietnam', 'min' => 9, 'max' => 10],
            ['code' => '86', 'iso' => 'CN', 'name' => 'China', 'min' => 11, 'max' => 11],
            ['code' => '90', 'iso' => 'TR', 'name' => 'Turkey', 'min' => 10, 'max' => 10],
            ['code' => '91', 'iso' => 'IN', 'name' => 'India', 'min' => 10, 'max' => 10],
            ['code' => '92', 'iso' => 'PK', 'name' => 'Pakistan', 'min' => 10, 'max' => 10],
            ['code' => '93', 'iso' => 'AF', 'name' => 'Afghanistan', 'min' => 9, 'max' => 9],
            ['code' => '94', 'iso' => 'LK', 'name' => 'Sri Lanka', 'min' => 9, 'max' => 9],
            ['code' => '95', 'iso' => 'MM', 'name' => 'Myanmar', 'min' => 8, 'max' => 10],
            ['code' => '98', 'iso' => 'IR', 'name' => 'Iran', 'min' => 10, 'max' => 10],
            ['code' => '212', 'iso' => 'MA', 'name' => 'Morocco', 'min' => 9, 'max' => 9],
            ['code' => '213', 'iso' => 'DZ', 'name' => 'Algeria', 'min' => 9, 'max' => 9],
            ['code' => '216', 'iso' => 'TN', 'name' => 'Tunisia', 'min' => 8, 'max' => 8],
            ['code' => '218', 'iso' => 'LY', 'name' => 'Libya', 'min' => 9, 'max' => 9],
            ['code' => '220', 'iso' => 'GM', 'name' => 'Gambia', 'min' => 7, 'max' => 7],
            ['code' => '234', 'iso' => 'NG', 'name' => 'Nigeria', 'min' => 10, 'max' => 10],
            ['code' => '254', 'iso' => 'KE', 'name' => 'Kenya', 'min' => 9, 'max' => 9],
            ['code' => '255', 'iso' => 'TZ', 'name' => 'Tanzania', 'min' => 9, 'max' => 9],
            ['code' => '256', 'iso' => 'UG', 'name' => 'Uganda', 'min' => 9, 'max' => 9],
            ['code' => '351', 'iso' => 'PT', 'name' => 'Portugal', 'min' => 9, 'max' => 9],
            ['code' => '352', 'iso' => 'LU', 'name' => 'Luxembourg', 'min' => 8, 'max' => 9],
            ['code' => '353', 'iso' => 'IE', 'name' => 'Ireland', 'min' => 7, 'max' => 9],
            ['code' => '354', 'iso' => 'IS', 'name' => 'Iceland', 'min' => 7, 'max' => 7],
            ['code' => '358', 'iso' => 'FI', 'name' => 'Finland', 'min' => 5, 'max' => 10],
            ['code' => '359', 'iso' => 'BG', 'name' => 'Bulgaria', 'min' => 8, 'max' => 9],
            ['code' => '370', 'iso' => 'LT', 'name' => 'Lithuania', 'min' => 8, 'max' => 8],
            ['code' => '371', 'iso' => 'LV', 'name' => 'Latvia', 'min' => 8, 'max' => 8],
            ['code' => '372', 'iso' => 'EE', 'name' => 'Estonia', 'min' => 7, 'max' => 8],
            ['code' => '380', 'iso' => 'UA', 'name' => 'Ukraine', 'min' => 9, 'max' => 9],
            ['code' => '381', 'iso' => 'RS', 'name' => 'Serbia', 'min' => 8, 'max' => 9],
            ['code' => '385', 'iso' => 'HR', 'name' => 'Croatia', 'min' => 8, 'max' => 9],
            ['code' => '386', 'iso' => 'SI', 'name' => 'Slovenia', 'min' => 8, 'max' => 8],
            ['code' => '420', 'iso' => 'CZ', 'name' => 'Czechia', 'min' => 9, 'max' => 9],
            ['code' => '421', 'iso' => 'SK', 'name' => 'Slovakia', 'min' => 9, 'max' => 9],
            ['code' => '852', 'iso' => 'HK', 'name' => 'Hong Kong', 'min' => 8, 'max' => 8],
            ['code' => '853', 'iso' => 'MO', 'name' => 'Macau', 'min' => 8, 'max' => 8],
            ['code' => '855', 'iso' => 'KH', 'name' => 'Cambodia', 'min' => 8, 'max' => 9],
            ['code' => '856', 'iso' => 'LA', 'name' => 'Laos', 'min' => 8, 'max' => 10],
            ['code' => '880', 'iso' => 'BD', 'name' => 'Bangladesh', 'min' => 10, 'max' => 10],
            ['code' => '886', 'iso' => 'TW', 'name' => 'Taiwan', 'min' => 9, 'max' => 9],
            ['code' => '960', 'iso' => 'MV', 'name' => 'Maldives', 'min' => 7, 'max' => 7],
            ['code' => '961', 'iso' => 'LB', 'name' => 'Lebanon', 'min' => 7, 'max' => 8],
            ['code' => '962', 'iso' => 'JO', 'name' => 'Jordan', 'min' => 8, 'max' => 9],
            ['code' => '963', 'iso' => 'SY', 'name' => 'Syria', 'min' => 8, 'max' => 9],
            ['code' => '964', 'iso' => 'IQ', 'name' => 'Iraq', 'min' => 10, 'max' => 10],
            ['code' => '965', 'iso' => 'KW', 'name' => 'Kuwait', 'min' => 8, 'max' => 8],
            ['code' => '966', 'iso' => 'SA', 'name' => 'Saudi Arabia', 'min' => 9, 'max' => 9],
            ['code' => '971', 'iso' => 'AE', 'name' => 'United Arab Emirates', 'min' => 9, 'max' => 9],
            ['code' => '972', 'iso' => 'IL', 'name' => 'Israel', 'min' => 8, 'max' => 9],
            ['code' => '973', 'iso' => 'BH', 'name' => 'Bahrain', 'min' => 8, 'max' => 8],
            ['code' => '974', 'iso' => 'QA', 'name' => 'Qatar', 'min' => 8, 'max' => 8],
            ['code' => '977', 'iso' => 'NP', 'name' => 'Nepal', 'min' => 10, 'max' => 10],
            ['code' => '994', 'iso' => 'AZ', 'name' => 'Azerbaijan', 'min' => 9, 'max' => 9],
            ['code' => '995', 'iso' => 'GE', 'name' => 'Georgia', 'min' => 9, 'max' => 9],
            ['code' => '998', 'iso' => 'UZ', 'name' => 'Uzbekistan', 'min' => 9, 'max' => 9],
        ];
    }

    /** @return array{type:string} */
    private function guessLineType(string $iso, string $national): array
    {
        $n = $national;
        if ($iso === 'DE') {
            // German mobiles typically start with 15/16/17
            if (preg_match('/^1[5-7]/', $n)) {
                return ['type' => 'Wireless'];
            }
            if (preg_match('/^(32|700)/', $n)) {
                return ['type' => 'VoIP'];
            }
            return ['type' => 'Landline'];
        }
        if ($iso === 'US') {
            // NANP: no reliable mobile vs landline without LNP DB; treat as unknown wireless-capable
            if (preg_match('/^(800|888|877|866|855|844|833)/', $n)) {
                return ['type' => 'Toll-free'];
            }
            return ['type' => 'Unknown'];
        }
        if ($iso === 'GB') {
            if (preg_match('/^7/', $n)) {
                return ['type' => 'Wireless'];
            }
            if (preg_match('/^(55|56)/', $n)) {
                return ['type' => 'VoIP'];
            }
            return ['type' => 'Landline'];
        }
        if ($iso === 'FR') {
            if (preg_match('/^[67]/', $n)) {
                return ['type' => 'Wireless'];
            }
            if (preg_match('/^9/', $n)) {
                return ['type' => 'VoIP'];
            }
            return ['type' => 'Landline'];
        }
        if ($iso === 'NL') {
            if (preg_match('/^6/', $n)) {
                return ['type' => 'Wireless'];
            }
            return ['type' => 'Landline'];
        }
        if ($iso === 'IN') {
            return ['type' => 'Wireless'];
        }
        if ($iso === 'AU') {
            if (preg_match('/^4/', $n)) {
                return ['type' => 'Wireless'];
            }
            return ['type' => 'Landline'];
        }
        return ['type' => 'Unknown'];
    }

    /** @return array{name:?string,note:string} */
    private function guessCarrier(string $iso, string $national): array
    {
        $note = 'Prefix-based estimate only — number portability means the live carrier can differ.';
        if ($iso === 'DE') {
            // Historical German mobile prefixes (pre-portability)
            $map = [
                '151' => 'Telekom',
                '152' => 'Vodafone',
                '155' => 'Telekom',
                '157' => 'E-Plus / O2',
                '159' => 'O2',
                '160' => 'Telekom',
                '162' => 'Vodafone',
                '163' => 'E-Plus / O2',
                '170' => 'Telekom',
                '171' => 'Telekom',
                '172' => 'Vodafone',
                '173' => 'Vodafone',
                '174' => 'Vodafone',
                '175' => 'Telekom',
                '176' => 'O2',
                '177' => 'E-Plus / O2',
                '178' => 'E-Plus / O2',
                '179' => 'O2',
            ];
            foreach ($map as $prefix => $name) {
                if (str_starts_with($national, $prefix)) {
                    return ['name' => $name, 'note' => $note];
                }
            }
        }
        if ($iso === 'GB' && preg_match('/^7/', $national)) {
            return ['name' => 'UK mobile (carrier unknown)', 'note' => $note];
        }
        return ['name' => null, 'note' => 'Carrier lookup needs a paid number-intelligence feed; showing format signals instead.'];
    }

    private function guessRegion(string $iso, string $national): ?string
    {
        if ($iso === 'DE') {
            // Very rough: some geographic landline area codes
            $areas = [
                '30' => 'Berlin',
                '40' => 'Hamburg',
                '69' => 'Frankfurt',
                '89' => 'Munich',
                '221' => 'Cologne',
                '211' => 'Düsseldorf',
                '491' => 'Leer / Ostfriesland',
            ];
            // Mobile: region often unknown; ScamAdviser sometimes still shows an area from HLR
            if (preg_match('/^1[5-7]/', $national)) {
                return 'Mobile (region unknown)';
            }
            foreach ($areas as $code => $name) {
                if (str_starts_with($national, $code)) {
                    return $name;
                }
            }
        }
        if ($iso === 'US' && strlen($national) === 10) {
            return 'Area code ' . substr($national, 0, 3);
        }
        return null;
    }
}
