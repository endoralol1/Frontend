<?php
/**
 * ScamBehaviorAnalyzer — high-signal scam pattern detection from live HTML.
 *
 * Design rules:
 * - Prefer corroborated patterns (2+ independent cues) over single keywords.
 * - Never treat CDN/Cloudflare/bot walls as fraud evidence.
 * - Soft marketing language alone is not enough; require money/login risk cues.
 * - Output machine-checkable evidence quotes for AI / UI.
 */
class ScamBehaviorAnalyzer
{
    /** Well-known brands used for content↔domain mismatch checks. */
    private const BRANDS = [
        'paypal', 'apple', 'google', 'microsoft', 'amazon', 'facebook', 'instagram',
        'whatsapp', 'netflix', 'steam', 'binance', 'coinbase', 'metamask', 'chase',
        'wellsfargo', 'bankofamerica', 'dhl', 'ups', 'fedex', 'outlook', 'office365',
        'icloud', 'revolut', 'wise', 'ledger', 'blockchain', 'ebay', 'walmart',
        'costco', 'target', 'americanexpress', 'amex', 'visa', 'mastercard',
        'hsbc', 'barclays', 'citibank', 'capitalone', 'discord', 'telegram',
    ];

    /**
     * @return array{
     *   flags: array<int,array{code:string,label:string,detail:string,penalty:int}>,
     *   signals: array<int,array{group:string,label:string,value:string,note:string,tone:string}>,
     *   evidence: array<string,mixed>
     * }
     */
    public static function analyze(string $domain, string $html, string $pageTitle = '', string $excerpt = ''): array
    {
        $domain = strtolower(trim($domain));
        $apex = function_exists('registrable_domain') ? (registrable_domain($domain) ?: $domain) : $domain;
        $text = self::plainText($html);
        if ($excerpt !== '') {
            $text .= ' ' . $excerpt;
        }
        $lower = strtolower($text . ' ' . $pageTitle);
        $titleLower = strtolower($pageTitle);

        $flags = [];
        $signals = [];
        $evidence = [
            'domain' => $domain,
            'apex' => $apex,
            'brands_mentioned' => [],
            'sensitive_forms' => [],
            'offsite_form_actions' => [],
            'investment_cues' => [],
            'fake_shop_cues' => [],
            'identity' => [
                'emails' => [],
                'phones' => [],
                'org_names' => [],
            ],
            'quotes' => [],
        ];

        // --- 1) Sensitive forms posting off-site ---------------------------------
        $formFindings = self::analyzeForms($html, $apex);
        $evidence['sensitive_forms'] = $formFindings['sensitive'];
        $evidence['offsite_form_actions'] = $formFindings['offsite'];
        if ($formFindings['credential_offsite'] > 0) {
            $flags[] = [
                'code' => 'credential_form_offsite',
                'label' => 'Login/password form posts off-site',
                'detail' => $formFindings['credential_offsite'] . ' sensitive form(s) submit to another domain — classic phishing pattern.',
                'penalty' => 28,
            ];
            $signals[] = self::sig(
                'heuristics',
                'Form destinations',
                'Off-site credential form',
                'A password/login form sends data away from ' . $apex . '.',
                'bad'
            );
            $evidence['quotes'][] = 'Off-site credential form action detected';
        } elseif ($formFindings['payment_offsite'] > 0) {
            $flags[] = [
                'code' => 'payment_form_offsite',
                'label' => 'Payment form posts off-site',
                'detail' => 'Card/payment fields submit to a different registrable domain.',
                'penalty' => 22,
            ];
            $signals[] = self::sig('heuristics', 'Form destinations', 'Off-site payment form', 'Payment data leaves this domain.', 'bad');
        } elseif ($formFindings['sensitive_total'] > 0 && $formFindings['offsite_total'] === 0) {
            $signals[] = self::sig(
                'heuristics',
                'Form destinations',
                'Sensitive forms on-domain',
                'Login/payment fields found; actions stay on this site (not automatically bad).',
                'neutral'
            );
        }

        // --- 2) Brand content vs domain mismatch ---------------------------------
        $brandsHit = [];
        foreach (self::BRANDS as $brand) {
            if (preg_match('/\b' . preg_quote($brand, '/') . '\b/i', $titleLower . ' ' . self::clip($lower, 1200))) {
                $brandsHit[] = $brand;
            }
        }
        $brandsHit = array_values(array_unique($brandsHit));
        $evidence['brands_mentioned'] = $brandsHit;

        $domainCompact = preg_replace('/[^a-z0-9]/', '', $apex) ?? $apex;
        $mismatch = [];
        foreach ($brandsHit as $brand) {
            // Skip if brand is clearly part of the domain (paypal.com, appleid.apple.com parent, etc.)
            if (str_contains($domainCompact, $brand)) {
                continue;
            }
            // Title/heading heavily brand-led while domain is unrelated.
            if (preg_match('/\b' . preg_quote($brand, '/') . '\b/i', $titleLower)
                || preg_match('/(?:sign in|log in|verify|secure).{0,40}\b' . preg_quote($brand, '/') . '\b/i', $lower)) {
                $mismatch[] = $brand;
            }
        }
        if ($mismatch) {
            $flags[] = [
                'code' => 'brand_content_mismatch',
                'label' => 'Brand content does not match domain',
                'detail' => 'Page references ' . implode(', ', array_slice($mismatch, 0, 3))
                    . ' but domain is ' . $apex . ' — common clone/phishing pattern.',
                'penalty' => 26,
            ];
            $signals[] = self::sig(
                'heuristics',
                'Brand match',
                'Mismatch',
                'Mentions ' . implode(', ', array_slice($mismatch, 0, 3)) . ' while hosted on ' . $apex . '.',
                'bad'
            );
            $evidence['quotes'][] = 'Brand mismatch: ' . implode(', ', array_slice($mismatch, 0, 3));
        } elseif ($brandsHit && str_contains($domainCompact, $brandsHit[0])) {
            $signals[] = self::sig('heuristics', 'Brand match', 'Aligned', 'Brand mentions match the domain family.', 'good');
        }

        // --- 3) Investment / crypto ROI scam language ----------------------------
        $investCues = [];
        $investPatterns = [
            'guaranteed profit' => '/guaranteed\s+(daily\s+)?(profit|returns?|income)/i',
            'daily roi' => '/\b\d{1,3}\s*%\s*(daily|per day|a day)\b/i',
            'fixed returns' => '/fixed\s+(daily\s+)?returns?/i',
            'double your money' => '/double\s+your\s+(money|investment|crypto)/i',
            'withdrawal fee demand' => '/(pay|send).{0,30}(withdrawal|unlock)\s+fee/i',
            'deposit to start' => '/(minimum|min\.?)\s+deposit.{0,20}(\$|€|£|usdt|btc)/i',
            'referral commission tiers' => '/referral\s+(commission|bonus|income).{0,40}(level|tier)/i',
            'cloud mining profit' => '/cloud\s+mining.{0,40}(profit|guaranteed|daily)/i',
        ];
        foreach ($investPatterns as $label => $re) {
            if (preg_match($re, $lower, $m)) {
                $investCues[] = $label;
                $evidence['quotes'][] = self::clip($m[0]);
            }
        }
        $evidence['investment_cues'] = $investCues;
        $walletCue = (bool) preg_match('/\b(send\s+(btc|eth|usdt)|wallet\s+address|connect\s+your\s+wallet|seed\s+phrase|private\s+key)\b/i', $lower);
        $chatOnly = (bool) preg_match('/\b(contact\s+us\s+on\s+)?(telegram|whatsapp)\b/i', $lower)
            && !(bool) preg_match('/\b(support@|help@|contact@)\b/i', $lower);

        if (count($investCues) >= 2 || (count($investCues) >= 1 && ($walletCue || $chatOnly))) {
            $pen = min(30, 14 + (count($investCues) * 4) + ($walletCue ? 4 : 0));
            $flags[] = [
                'code' => 'investment_scam_language',
                'label' => 'Investment / crypto scam language',
                'detail' => 'Matched: ' . implode(', ', array_slice($investCues, 0, 4))
                    . ($walletCue ? '; wallet/seed cues' : '')
                    . ($chatOnly ? '; chat-app contact emphasis' : ''),
                'penalty' => $pen,
            ];
            $signals[] = self::sig(
                'heuristics',
                'Investment risk language',
                count($investCues) . ' cue(s)',
                'Guaranteed/high-ROI style claims are a common fraud pattern.',
                'bad'
            );
        } elseif ($investCues) {
            $signals[] = self::sig(
                'heuristics',
                'Investment risk language',
                'Weak cue',
                'One investment-style phrase found — not enough alone to call it a scam.',
                'warn'
            );
        }

        // --- 4) Fake-shop pack ----------------------------------------------------
        $shopCues = [];
        if (preg_match('/\b(add to cart|buy now|checkout|order now|shop now)\b/i', $lower)) {
            $shopCues[] = 'commerce_ui';
        }
        if (preg_match('/\b(90%\s*off|80%\s*off|70%\s*off|huge\s+clearance|flash\s+sale|today\s+only)\b/i', $lower, $m)) {
            $shopCues[] = 'extreme_discount';
            $evidence['quotes'][] = self::clip($m[0]);
        }
        if (preg_match('/\b(lorem ipsum|your company name|sample address|insert address|placeholder)\b/i', $lower, $m)) {
            $shopCues[] = 'policy_placeholder';
            $evidence['quotes'][] = self::clip($m[0]);
        }
        if (preg_match('/\b(telegram|whatsapp)\b/i', $lower) && preg_match('/\b(payment|pay|order|invoice)\b/i', $lower)
            && !preg_match('/\b(visa|mastercard|paypal|stripe|apple pay|google pay)\b/i', $lower)) {
            $shopCues[] = 'chat_payment_only';
        }
        if (preg_match('/\b(no refunds?|all sales final|non[- ]refundable)\b/i', $lower)
            && preg_match('/\b(limited stock|hurry|act now|only\s+\d+\s+left)\b/i', $lower)) {
            $shopCues[] = 'pressure_no_refund';
        }
        $evidence['fake_shop_cues'] = $shopCues;

        $shopScore = 0;
        foreach ($shopCues as $c) {
            $shopScore += match ($c) {
                'commerce_ui' => 1,
                'extreme_discount' => 2,
                'policy_placeholder' => 3,
                'chat_payment_only' => 3,
                'pressure_no_refund' => 2,
                default => 1,
            };
        }
        if ($shopScore >= 5) {
            $flags[] = [
                'code' => 'fake_shop_pattern',
                'label' => 'Fake-shop risk pattern',
                'detail' => 'Combined cues: ' . implode(', ', $shopCues),
                'penalty' => min(24, 10 + $shopScore * 2),
            ];
            $signals[] = self::sig('heuristics', 'Shop risk pattern', 'Elevated', implode(', ', $shopCues), 'bad');
        } elseif ($shopScore >= 3) {
            $signals[] = self::sig('heuristics', 'Shop risk pattern', 'Watch', implode(', ', $shopCues), 'warn');
            // Mild flag only when discount + another strong cue exist.
            if (in_array('extreme_discount', $shopCues, true) && count($shopCues) >= 2) {
                $flags[] = [
                    'code' => 'fake_shop_pattern_soft',
                    'label' => 'Suspicious shop marketing mix',
                    'detail' => 'Extreme discounts plus other weak shop-risk cues.',
                    'penalty' => 8,
                ];
            }
        }

        // --- 5) Identity consistency ---------------------------------------------
        $emails = [];
        if (preg_match_all('/[a-z0-9._%+\-]+@([a-z0-9.\-]+\.[a-z]{2,})/i', $text, $em)) {
            foreach ($em[0] as $i => $full) {
                $host = strtolower($em[1][$i]);
                if (preg_match('/(example\.com|sentry\.|wixpress|schema\.org)/i', $host)) {
                    continue;
                }
                $emails[] = strtolower($full);
            }
        }
        $emails = array_values(array_unique(array_slice($emails, 0, 8)));
        $phones = [];
        if (preg_match_all('/(?:\+|00)?\d{1,3}[\s().-]?\d{2,4}[\s().-]?\d{3,4}[\s().-]?\d{3,4}/', $text, $ph)) {
            foreach ($ph[0] as $p) {
                $digits = preg_replace('/\D+/', '', $p) ?? '';
                if (strlen($digits) >= 10 && strlen($digits) <= 15) {
                    $phones[] = trim($p);
                }
            }
        }
        $phones = array_values(array_unique(array_slice($phones, 0, 6)));

        $orgs = [];
        if (preg_match_all('/\b([A-Z][A-Za-z0-9&\'.\-]+(?:\s+[A-Z][A-Za-z0-9&\'.\-]+){0,4}\s+(?:Ltd|LLC|Inc|GmbH|Limited|Corp|Corporation|BV|SAS|PLC))\b/', $text, $om)) {
            foreach ($om[1] as $org) {
                $orgs[] = trim($org);
            }
        }
        $orgs = array_values(array_unique(array_slice($orgs, 0, 6)));
        $evidence['identity'] = ['emails' => $emails, 'phones' => $phones, 'org_names' => $orgs];

        $freeMailHosts = ['gmail.com','yahoo.com','hotmail.com','outlook.com','proton.me','protonmail.com','icloud.com','mail.ru','yandex.'];
        $bizMail = 0;
        $freeMail = 0;
        foreach ($emails as $e) {
            $h = substr(strrchr($e, '@') ?: '', 1);
            $isFree = false;
            foreach ($freeMailHosts as $fh) {
                if (str_starts_with($h, rtrim($fh, '.')) || $h === rtrim($fh, '.')) {
                    $isFree = true;
                    break;
                }
            }
            if ($isFree) {
                $freeMail++;
            } elseif ($h === $apex || str_ends_with($h, '.' . $apex)) {
                $bizMail++;
            }
        }

        if (count($orgs) >= 2) {
            // Different legal names on one homepage is a soft inconsistency.
            $flags[] = [
                'code' => 'identity_org_conflict',
                'label' => 'Conflicting company names on page',
                'detail' => 'Found multiple legal-style names: ' . implode(' / ', array_slice($orgs, 0, 3)),
                'penalty' => 10,
            ];
            $signals[] = self::sig('heuristics', 'Identity consistency', 'Conflicting names', implode(' · ', array_slice($orgs, 0, 3)), 'warn');
        } elseif ($freeMail > 0 && $bizMail === 0 && preg_match('/\b(checkout|payment|invest|wallet|login)\b/i', $lower)) {
            $signals[] = self::sig(
                'heuristics',
                'Identity consistency',
                'Free-mail contact on money page',
                'Only free webmail contacts found on a page talking about payments/logins.',
                'warn'
            );
        } elseif ($bizMail > 0) {
            $signals[] = self::sig('heuristics', 'Identity consistency', 'Business email present', 'Contact email matches the site domain family.', 'good');
        } else {
            $signals[] = self::sig('heuristics', 'Identity consistency', 'Limited identity cues', 'No strong conflicting identity signals detected.', 'neutral');
        }

        // Deduplicate flags by code (keep highest penalty).
        $byCode = [];
        foreach ($flags as $f) {
            $code = $f['code'];
            if (!isset($byCode[$code]) || $f['penalty'] > $byCode[$code]['penalty']) {
                $byCode[$code] = $f;
            }
        }
        $flags = array_values($byCode);

        return [
            'flags' => $flags,
            'signals' => $signals,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array{sensitive:array,offsite:array,credential_offsite:int,payment_offsite:int,sensitive_total:int,offsite_total:int}
     */
    private static function analyzeForms(string $html, string $apex): array
    {
        $sensitive = [];
        $offsite = [];
        $credentialOffsite = 0;
        $paymentOffsite = 0;

        if (!preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER)) {
            return [
                'sensitive' => [],
                'offsite' => [],
                'credential_offsite' => 0,
                'payment_offsite' => 0,
                'sensitive_total' => 0,
                'offsite_total' => 0,
            ];
        }

        foreach (array_slice($forms, 0, 25) as $form) {
            $attrs = $form[1];
            $body = $form[2];
            $action = '';
            if (preg_match('/\baction\s*=\s*([\'"])(.*?)\1/i', $attrs, $am)) {
                $action = trim($am[2]);
            }
            $hasPassword = (bool) preg_match('/type\s*=\s*[\'"]password[\'"]/i', $body);
            $hasSeed = (bool) preg_match('/name\s*=\s*[\'"][^\'"]*(seed|mnemonic|private[_\-]?key|recovery)[^\'"]*[\'"]/i', $body)
                || (bool) preg_match('/\b(seed phrase|recovery phrase|private key)\b/i', $body);
            $hasCard = (bool) preg_match('/name\s*=\s*[\'"][^\'"]*(card|cc-|ccnum|cvc|cvv|expir)[^\'"]*[\'"]/i', $body)
                || (bool) preg_match('/autocomplete\s*=\s*[\'"]cc-/i', $body);
            $sensitiveType = $hasSeed ? 'seed' : ($hasPassword ? 'password' : ($hasCard ? 'payment' : null));
            if ($sensitiveType === null) {
                continue;
            }

            $actionHost = self::actionHost($action, $apex);
            $isOffsite = $actionHost !== null && $actionHost !== $apex && !str_ends_with($actionHost, '.' . $apex);
            // Ignore known payment processors as automatic phishing (Stripe/PayPal checkout).
            $processor = (bool) preg_match('/(^|\.)(paypal\.com|stripe\.com|checkout\.stripe\.com|pay\.google\.com|apple\.com)$/i', (string) $actionHost);

            $row = [
                'type' => $sensitiveType,
                'action' => self::clip($action !== '' ? $action : '(same page)', 160),
                'action_host' => $actionHost,
                'offsite' => $isOffsite && !$processor,
            ];
            $sensitive[] = $row;
            if ($row['offsite']) {
                $offsite[] = $row;
                if ($sensitiveType === 'password' || $sensitiveType === 'seed') {
                    $credentialOffsite++;
                } elseif ($sensitiveType === 'payment') {
                    $paymentOffsite++;
                }
            }
        }

        return [
            'sensitive' => $sensitive,
            'offsite' => $offsite,
            'credential_offsite' => $credentialOffsite,
            'payment_offsite' => $paymentOffsite,
            'sensitive_total' => count($sensitive),
            'offsite_total' => count($offsite),
        ];
    }

    private static function actionHost(string $action, string $fallbackApex): ?string
    {
        $action = trim($action);
        if ($action === '' || $action === '#' || str_starts_with($action, 'javascript:')) {
            return $fallbackApex;
        }
        if (str_starts_with($action, '/') || str_starts_with($action, '?') || str_starts_with($action, '.')) {
            return $fallbackApex;
        }
        if (!preg_match('#^https?://#i', $action)) {
            return $fallbackApex;
        }
        $host = parse_url($action, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        return function_exists('registrable_domain') ? (registrable_domain($host) ?: $host) : $host;
    }

    private static function plainText(string $html): string
    {
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private static function clip(string $s, int $max = 90): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
        }
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }

    /** @return array{group:string,label:string,value:string,note:string,tone:string} */
    private static function sig(string $group, string $label, string $value, string $note, string $tone): array
    {
        return compact('group', 'label', 'value', 'note', 'tone');
    }
}
