<?php
/**
 * Site risk analyst — rule-based brief always; optional LLM when AI key is set.
 * OpenAI-compatible chat completions (OpenAI, Groq, OpenRouter, etc.).
 * Never runs a local model (RAM). Never overrides malware/phishing list hits.
 */
class AiAnalyst
{
    /**
     * Plain-language lean from hard signals (no API, free, instant).
     *
     * @return array{lean:string,label:string,summary:string,tone:string,score_hint:int}
     */
    public static function ruleBrief(string $domain, array $data, array $signals): array
    {
        $good = 0;
        $bad = 0;
        $warn = 0;
        $goodBits = [];
        $badBits = [];

        $expectsContact = !array_key_exists('expects_business_contact', $data) || !empty($data['expects_business_contact']);
        $expectsPay = !empty($data['expects_payment']);
        // Soft hygiene labels that should not drive Negative lean on free-media / hobby sites.
        $softHygiene = ['Contact info', 'Phone / WhatsApp', 'Privacy policy', 'Contact email type', 'Cookie policy', 'Terms page'];

        foreach ($signals as $s) {
            $tone = (string) ($s['tone'] ?? 'neutral');
            $label = (string) ($s['label'] ?? '');
            $value = (string) ($s['value'] ?? '');

            // Ignore optional hygiene warns when this site type does not require them.
            if (!$expectsContact && in_array($label, $softHygiene, true) && in_array($tone, ['warn', 'bad'], true)) {
                continue;
            }
            if (!$expectsPay && $label === 'Payment language' && in_array($tone, ['warn', 'bad'], true)
                && stripos($value, 'not detected') !== false) {
                continue;
            }

            if ($tone === 'good') {
                $good++;
                if (count($goodBits) < 5) {
                    $goodBits[] = $label . ($value !== '' ? ': ' . $value : '');
                }
            } elseif ($tone === 'bad') {
                $bad++;
                if (count($badBits) < 5) {
                    $badBits[] = $label . ($value !== '' ? ': ' . $value : '');
                }
            } elseif ($tone === 'warn') {
                $warn++;
                if (count($badBits) < 5) {
                    $badBits[] = $label . ($value !== '' ? ': ' . $value : '');
                }
            }
        }

        if (!empty($data['malware_hit']) || !empty($data['phishing_hit'])) {
            return [
                'lean' => 'negative',
                'label' => 'Negative — threat list hit',
                'summary' => $domain . ' appears on malware/phishing intelligence. Treat as unsafe regardless of other positives.',
                'tone' => 'bad',
                'score_hint' => -20,
            ];
        }

        if (($data['scan_depth'] ?? '') === 'provisional') {
            return [
                'lean' => 'mixed',
                'label' => 'Provisional — full check needed',
                'summary' => $domain . ' only has a discovery shortcut so far (DNS + local lists). Do not treat this as a finished trust verdict until a full live check runs.',
                'tone' => 'warn',
                'score_hint' => 0,
            ];
        }

        $age = $data['domain_age_days'] ?? null;
        $ageScope = (string) ($data['domain_age_scope'] ?? 'exact');
        $scoreHint = 0;
        // Only exact host registration age counts. Parent/platform age is informational.
        if ($ageScope === 'exact' && $age !== null) {
            if ((int) $age < 30) {
                $scoreHint -= 6;
                $badBits[] = 'Very new domain (' . (int) $age . ' days)';
            } elseif ((int) $age > 730) {
                $scoreHint += 3;
                $goodBits[] = 'Established domain age';
            }
        } elseif ($ageScope === 'parent') {
            $badBits[] = 'Subdomain — parent domain age is not this site’s age';
        } elseif ($ageScope === 'platform') {
            $badBits[] = 'Shared platform host — platform age is not this site’s age';
        }
        if (!empty($data['ssl_valid'])) {
            $scoreHint += 2;
        }
        // Reviews already affect the numeric score via external_score_delta —
        // use consensus text for the lean, and only lightly nudge the hint.
        $reviewConsensus = trim((string) ($data['review_consensus'] ?? ''));
        if (!empty($data['review_penalty'])) {
            $scoreHint -= min(4, (int) floor(((int) $data['review_penalty']) / 4));
            $badBits[] = $reviewConsensus !== ''
                ? 'Reviews: ' . mb_strimwidth($reviewConsensus, 0, 140, '…')
                : 'Weak review reputation';
        } elseif (!empty($data['review_bonus']) || ($reviewConsensus !== '' && stripos($reviewConsensus, 'positive') !== false)) {
            $scoreHint += min(3, 1 + (int) floor(((int) ($data['review_bonus'] ?? 0)) / 4));
            $goodBits[] = $reviewConsensus !== ''
                ? 'Reviews: ' . mb_strimwidth($reviewConsensus, 0, 140, '…')
                : 'Positive review reputation';
        } elseif ($reviewConsensus !== '') {
            $goodBits[] = 'Reviews: ' . mb_strimwidth($reviewConsensus, 0, 140, '…');
        }
        if (!empty($data['content_incomplete'])) {
            $scoreHint -= 4;
            $badBits[] = 'Content scan incomplete (bot wall)';
        } elseif (in_array((string) ($data['content_source'] ?? ''), ['Wayback', 'fetch API', 'Jina'], true)) {
            $goodBits[] = 'Content recovered via ' . $data['content_source'];
        }

        $net = $good - $bad - (int) floor($warn / 2) + (int) round($scoreHint / 3);

        if ($net >= 4 && $bad <= 1) {
            $lean = 'positive';
            $label = 'Positive lean';
            $tone = 'good';
            $summary = $domain . ' looks more trustworthy than risky based on current signals'
                . ($goodBits ? ' — strengths: ' . implode('; ', array_slice($goodBits, 0, 3)) : '.')
                . ($badBits ? ' Watch: ' . implode('; ', array_slice($badBits, 0, 2)) . '.' : '');
        } elseif ($net <= -3 || $bad >= 3) {
            $lean = 'negative';
            $label = 'Negative lean';
            $tone = 'bad';
            $summary = $domain . ' leans negative / higher risk'
                . ($badBits ? ' — concerns: ' . implode('; ', array_slice($badBits, 0, 4)) : '.')
                . ($goodBits ? ' Mitigating: ' . implode('; ', array_slice($goodBits, 0, 2)) . '.' : '');
        } else {
            $lean = 'mixed';
            $label = 'Mixed / unclear';
            $tone = 'warn';
            $summary = $domain . ' has mixed signals (' . $good . ' positive, ' . $bad . ' negative, ' . $warn . ' caution).'
                . ($badBits ? ' Main cautions: ' . implode('; ', array_slice($badBits, 0, 3)) . '.' : '')
                . ' Verify independently before trusting money or logins.';
        }

        return [
            'lean' => $lean,
            'label' => $label,
            'summary' => $summary,
            'tone' => $tone,
            'score_hint' => max(-12, min(8, $scoreHint)),
        ];
    }

    /**
     * LLM investigates what the site is about + risk judgment that affects score.
     *
     * @return array{lean:string,label:string,summary:string,tone:string,confidence:int,score_delta:int,factors:array,site_about:string}|null
     */
    public static function llmOpinion(string $domain, array $data, array $signals, array $ruleBrief): ?array
    {
        $key = self::resolveApiKey();
        if ($key === '') {
            return null;
        }

        // Hard evidence wins — do not spend tokens arguing against list hits.
        if (!empty($data['malware_hit']) || !empty($data['phishing_hit'])) {
            return [
                'lean' => 'negative',
                'label' => 'AI skipped (list hit)',
                'summary' => 'AI not consulted: malware/phishing list evidence already decides this as unsafe.',
                'tone' => 'bad',
                'confidence' => 100,
                'score_delta' => 0,
                'factors' => ['Threat intelligence list hit'],
                'site_about' => 'Listed on threat intelligence — treat as malicious.',
            ];
        }

        $url = self::resolveApiUrl();
        $model = self::resolveModel();

        $compactSignals = [];
        foreach (array_slice($signals, 0, 35) as $s) {
            $compactSignals[] = [
                'g' => $s['group'] ?? '',
                'l' => $s['label'] ?? '',
                'v' => mb_substr((string) ($s['value'] ?? ''), 0, 80),
                't' => $s['tone'] ?? 'neutral',
            ];
        }

        $excerpt = (string) ($data['page_excerpt'] ?? '');
        $meta = (string) ($data['page_meta_description'] ?? '');
        $incomplete = !empty($data['content_incomplete']);
        $evidence = is_array($data['ai_evidence'] ?? null) ? $data['ai_evidence'] : null;
        $behaviorFlags = [];
        foreach ($data['behavior_flags'] ?? [] as $bf) {
            if (!is_array($bf)) {
                continue;
            }
            $behaviorFlags[] = [
                'code' => (string) ($bf['code'] ?? ''),
                'label' => (string) ($bf['label'] ?? ''),
                'detail' => mb_substr((string) ($bf['detail'] ?? ''), 0, 160),
                'penalty' => (int) ($bf['penalty'] ?? 0),
            ];
        }

        $evidencePack = null;
        if (is_array($evidence)) {
            $evidencePack = [
                'brands_mentioned' => array_slice((array) ($evidence['brands_mentioned'] ?? []), 0, 8),
                'sensitive_forms' => array_slice((array) ($evidence['sensitive_forms'] ?? []), 0, 6),
                'offsite_form_actions' => array_slice((array) ($evidence['offsite_form_actions'] ?? []), 0, 6),
                'investment_cues' => array_slice((array) ($evidence['investment_cues'] ?? []), 0, 8),
                'fake_shop_cues' => array_slice((array) ($evidence['fake_shop_cues'] ?? []), 0, 8),
                'identity' => [
                    'emails' => array_slice((array) (($evidence['identity']['emails'] ?? [])), 0, 6),
                    'phones' => array_slice((array) (($evidence['identity']['phones'] ?? [])), 0, 4),
                    'org_names' => array_slice((array) (($evidence['identity']['org_names'] ?? [])), 0, 4),
                ],
                'quotes' => array_slice((array) ($evidence['quotes'] ?? []), 0, 8),
            ];
        }

        $hasLogin = !empty($data['has_login']);
        $hasPayment = !empty($data['has_payment']);
        $expectsPay = !empty($data['expects_payment']);
        $expectsContact = !array_key_exists('expects_business_contact', $data) || !empty($data['expects_business_contact']);
        $siteCategory = (string) ($data['site_category'] ?? 'general');

        $payloadFacts = [
            'domain' => $domain,
            'page_title' => $data['page_title'] ?? null,
            'meta_description' => $meta !== '' ? mb_substr($meta, 0, 400) : null,
            'page_text_excerpt' => $excerpt !== '' ? mb_substr($excerpt, 0, 2400) : null,
            'content_incomplete' => $incomplete,
            'content_source' => $data['content_source'] ?? 'live',
            'http_status' => $data['http_status'] ?? null,
            'domain_age_days' => $data['domain_age_days'] ?? null,
            'domain_age_scope' => $data['domain_age_scope'] ?? null,
            'ssl_valid' => !empty($data['ssl_valid']),
            'cdn' => $data['cdn_provider'] ?? null,
            'spam_hit' => !empty($data['spam_hit']),
            'review_penalty' => (int) ($data['review_penalty'] ?? 0),
            'suspicious_keyword_hits' => (int) ($data['suspicious_keyword_hits'] ?? 0),
            'crypto_only_payment' => !empty($data['crypto_only_payment']),
            'site_category' => $siteCategory,
            'site_category_label' => $data['site_category_label'] ?? null,
            'has_login' => $hasLogin,
            'has_payment' => $hasPayment,
            'expects_payment' => $expectsPay,
            'expects_business_contact' => $expectsContact,
            'behavior_flags' => $behaviorFlags,
            'evidence_pack' => $evidencePack,
            'rule_brief_lean' => $ruleBrief['lean'] ?? 'mixed',
            'signals' => $compactSignals,
            'task' => 'Investigate what this website/domain is about from title, meta, page text, AND evidence_pack. '
                . 'Only judge from provided evidence — do not invent forms, brands, logins, payments, or quotes. '
                . 'Respect has_login / has_payment / expects_* flags from the scanner. '
                . 'Decide if that purpose is trustworthy or risky/scammy for average users (money, logins, downloads). '
                . 'Then set lean + score_delta that should move the trust score.',
        ];

        $system = 'You are ScamGuard’s website investigator. Your job is to judge whether a site will HARM a normal visitor, not to police content legality. '
            . 'First figure out WHAT the site is (shop, forum, streaming, SaaS, blog, bank-phishing page, nulled-software downloads, casino, etc.) from page_title, meta_description, page_text_excerpt, evidence_pack, and site_category. '
            . 'Separate two different things: (A) VISITOR HARM = scam/fraud, phishing/credential theft, fake shop that takes money and never delivers, malware or virus pop-ups, forced downloads, wallet-drainers. (B) GRAY / legality = piracy, unofficial movie/TV streaming, ROMs, or similar. '
            . 'Scoring rules: real VISITOR HARM → negative, strong penalty. GRAY-but-not-harmful sites (e.g. an unofficial streaming site with ads but no fraud/malware signs) are only MILDLY risky, NOT scams → lean mixed with a small penalty (about -3 to -8); do not treat piracy alone as fraud. Clearly legitimate sites → positive. '
            . 'CRITICAL expectation rules: '
            . '(1) If has_login=true, NEVER claim the site lacks login/account systems. '
            . '(2) If expects_payment=false (typical for free/unofficial streaming, forums, blogs), missing payment/checkout is NORMAL and MUST NOT be used as a risk factor or mentioned as a concern. '
            . '(3) If expects_business_contact=false, missing phone/contact-page/privacy-policy is NOT a scam signal — do not list them as concerns and do not let them push lean negative. '
            . '(4) Only treat missing payments as relevant when expects_payment=true (shops, paid hosting, finance, paid SaaS). '
            . '(5) Only treat missing phone/contact/privacy as relevant when expects_business_contact=true. '
            . 'CDN/Cloudflare, a bot challenge page, or missing SPF/DMARC/MX are NOT scam signals — ignore them as fraud evidence. '
            . 'Incomplete security headers alone are a mild technical note, not proof of fraud — especially on free media sites. '
            . 'Treat evidence_pack as machine-checked facts: off-site credential/payment forms, brand↔domain mismatch, investment ROI cues, fake-shop cue packs, and identity conflicts are high-signal when present. Prefer corroborated multi-cue patterns over a single keyword. '
            . 'Do not invent page facts — if content_incomplete is true or the excerpt is empty, say you could not fully read the site and stay mixed with a small delta unless there is hard evidence (feed/blacklist) of harm. '
            . 'score_delta guidance: confirmed fraud/phishing/malware → -14 to -22; clearly risky commercial behaviour → -8 to -13; gray/piracy or minor concerns → -3 to -8; unclear/mixed → -3 to +3; solid legit → +5 to +16. Scale magnitude by your confidence. '
            . 'Respond with ONLY JSON: '
            . '{"site_about":"short what the site is","lean":"positive|negative|mixed","confidence":0-100,'
            . '"harm":"none|low|medium|high","summary":"2 sentences: what it is + whether it can actually harm a visitor","factors":["..."],"score_delta":-22..16}';

        $body = json_encode([
            'model' => $model,
            'temperature' => 0.15,
            'max_tokens' => 420,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($payloadFacts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $raw === false || $code >= 400) {
            return null;
        }

        $resp = json_decode($raw, true);
        $text = (string) ($resp['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            return null;
        }

        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($text)));
        $parsed = json_decode($text, true);
        if (!is_array($parsed) && preg_match('/\{.*\}/s', $text, $m)) {
            $parsed = json_decode($m[0], true);
        }
        if (!is_array($parsed)) {
            return null;
        }

        $lean = strtolower((string) ($parsed['lean'] ?? 'mixed'));
        if (!in_array($lean, ['positive', 'negative', 'mixed'], true)) {
            $lean = 'mixed';
        }
        $confidence = max(0, min(100, (int) ($parsed['confidence'] ?? 50)));
        $summary = trim((string) ($parsed['summary'] ?? ''));
        if ($summary === '') {
            $summary = 'AI returned no summary.';
        }
        $summary = mb_substr($summary, 0, 520);
        $siteAbout = trim((string) ($parsed['site_about'] ?? ''));
        $siteAbout = mb_substr($siteAbout !== '' ? $siteAbout : 'Purpose unclear from available text.', 0, 220);

        $factors = $parsed['factors'] ?? [];
        if (!is_array($factors)) {
            $factors = [];
        }
        $factors = array_values(array_filter(array_map(
            static fn($f) => mb_substr(trim((string) $f), 0, 120),
            $factors
        )));

        // Strip invented / site-type-inappropriate concerns the model sometimes still emits.
        $factors = array_values(array_filter($factors, static function ($f) use ($hasLogin, $expectsPay, $expectsContact) {
            $low = strtolower((string) $f);
            if ($hasLogin && preg_match('/\b(no |lack(?:s|ing)? |missing |without ).{0,40}\b(login|sign[\s-]?in|account system)\b/i', $low)) {
                return false;
            }
            if (!$expectsPay && preg_match('/\b(no |lack(?:s|ing)? |missing |without ).{0,40}\b(payment|checkout|billing|pay system)\b/i', $low)) {
                return false;
            }
            if (!$expectsContact && preg_match('/\b(no |lack(?:s|ing)? |missing |without ).{0,40}\b(contact|phone|whatsapp|privacy policy|privacy page)\b/i', $low)) {
                return false;
            }
            return true;
        }));

        // Clean summary sentences that wrongly cite missing login/payment/contact.
        if ($hasLogin) {
            $summary = preg_replace('/\b(?:it )?lacks? a clear (?:payment or )?login system[,.]?\s*/i', '', $summary) ?? $summary;
            $summary = preg_replace('/\bmissing (?:a )?login(?: system)?[,.]?\s*/i', '', $summary) ?? $summary;
        }
        if (!$expectsPay) {
            $summary = preg_replace('/\b(?:and )?its? (?:lack of|missing) (?:a )?payment(?: system)?[,.]?\s*/i', '', $summary) ?? $summary;
            $summary = preg_replace('/\b(?:no|without|lacks?) (?:a )?payment(?: system|\/checkout)?[,.]?\s*/i', '', $summary) ?? $summary;
        }
        if (!$expectsContact) {
            $summary = preg_replace('/\b(?:missing|no|lacks?) (?:clear )?(?:contact(?: info(?:rmation)?)?|phone|privacy policy)[,.]?\s*/i', '', $summary) ?? $summary;
        }
        $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);

        $harm = strtolower((string) ($parsed['harm'] ?? ''));
        $delta = (int) ($parsed['score_delta'] ?? 0);
        $delta = max(-22, min(16, $delta));

        // Scale negative penalties by confidence so a 60%-sure hunch can't crush a site.
        if ($delta < 0) {
            $delta = (int) round($delta * max(35, min(100, $confidence)) / 100);
        }

        // Cap penalties when there is no evidence of real visitor harm. Gray/piracy
        // content alone should be a small nudge, never a scam-level drop.
        if ($harm === 'none' || $harm === 'low' || $harm === '') {
            $delta = max($delta, -8);
        } elseif ($harm === 'medium') {
            $delta = max($delta, -13);
        }

        // Free-media / no-payment-expected sites: don't let hygiene-style AI deltas go too negative.
        if (!$expectsPay && !$expectsContact && $delta < -6 && in_array($harm, ['none', 'low', ''], true)) {
            $delta = max($delta, -6);
            if ($lean === 'negative') {
                $lean = 'mixed';
            }
        }

        if ($lean === 'positive' && $confidence >= 65 && $delta < 4) {
            $delta = max($delta, 5);
        }
        if ($incomplete) {
            // Couldn't read live content — don't let AI strongly swing the score either way.
            $delta = max(-8, min(4, $delta));
            if ($lean === 'positive') {
                $lean = 'mixed';
            }
        }

        $tone = $lean === 'positive' ? 'good' : ($lean === 'negative' ? 'bad' : 'warn');
        $label = match ($lean) {
            'positive' => 'AI: positive / safer purpose',
            'negative' => 'AI: negative / risky purpose',
            default => 'AI: mixed / unclear purpose',
        };

        return [
            'lean' => $lean,
            'label' => $label . ' (' . $confidence . '% conf.)',
            'summary' => $summary,
            'tone' => $tone,
            'confidence' => $confidence,
            'score_delta' => $delta,
            'factors' => array_slice($factors, 0, 6),
            'site_about' => $siteAbout,
        ];
    }

    /** Admin site_settings override, then config.php constant. */
    private static function resolveApiKey(): string
    {
        if (function_exists('get_setting')) {
            $fromDb = trim(get_setting('ai_api_key', ''));
            if ($fromDb !== '') {
                return $fromDb;
            }
        }
        return defined('AI_API_KEY') ? trim((string) AI_API_KEY) : '';
    }

    private static function resolveApiUrl(): string
    {
        if (function_exists('get_setting')) {
            $fromDb = trim(get_setting('ai_api_url', ''));
            if ($fromDb !== '') {
                return $fromDb;
            }
        }
        if (defined('AI_API_URL') && trim((string) AI_API_URL) !== '') {
            return (string) AI_API_URL;
        }
        return 'https://api.openai.com/v1/chat/completions';
    }

    private static function resolveModel(): string
    {
        if (function_exists('get_setting')) {
            $fromDb = trim(get_setting('ai_model', ''));
            if ($fromDb !== '') {
                return $fromDb;
            }
        }
        if (defined('AI_MODEL') && trim((string) AI_MODEL) !== '') {
            return (string) AI_MODEL;
        }
        return 'gpt-4o-mini';
    }
}
