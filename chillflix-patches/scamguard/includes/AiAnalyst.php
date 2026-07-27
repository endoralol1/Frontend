<?php
/**
 * Site risk analyst — rule-based brief always; optional LLM when AI_API_KEY is set.
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

        foreach ($signals as $s) {
            $tone = (string) ($s['tone'] ?? 'neutral');
            $label = (string) ($s['label'] ?? '');
            $value = (string) ($s['value'] ?? '');
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

        $age = $data['domain_age_days'] ?? null;
        $scoreHint = 0;
        if ($age !== null) {
            if ((int) $age < 30) {
                $scoreHint -= 6;
                $badBits[] = 'Very new domain (' . (int) $age . ' days)';
            } elseif ((int) $age > 730) {
                $scoreHint += 3;
                $goodBits[] = 'Established domain age';
            }
        }
        if (!empty($data['ssl_valid'])) {
            $scoreHint += 2;
        }
        if (!empty($data['review_penalty'])) {
            $scoreHint -= min(10, (int) $data['review_penalty']);
            $badBits[] = 'Weak review reputation';
        }
        if (!empty($data['content_incomplete'])) {
            $scoreHint -= 4;
            $badBits[] = 'Content scan incomplete (bot wall)';
        } elseif (($data['content_source'] ?? '') === 'Wayback' || ($data['content_source'] ?? '') === 'fetch API') {
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
     * Optional LLM second opinion. Returns null if disabled / failed.
     *
     * @return array{lean:string,label:string,summary:string,tone:string,confidence:int,score_delta:int,factors:array}|null
     */
    public static function llmOpinion(string $domain, array $data, array $signals, array $ruleBrief): ?array
    {
        $key = defined('AI_API_KEY') ? trim((string) AI_API_KEY) : '';
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
            ];
        }

        $url = defined('AI_API_URL') && AI_API_URL !== ''
            ? (string) AI_API_URL
            : 'https://api.openai.com/v1/chat/completions';
        $model = defined('AI_MODEL') && AI_MODEL !== ''
            ? (string) AI_MODEL
            : 'gpt-4o-mini';

        $compactSignals = [];
        foreach (array_slice($signals, 0, 40) as $s) {
            $compactSignals[] = [
                'g' => $s['group'] ?? '',
                'l' => $s['label'] ?? '',
                'v' => mb_substr((string) ($s['value'] ?? ''), 0, 80),
                't' => $s['tone'] ?? 'neutral',
            ];
        }

        $payloadFacts = [
            'domain' => $domain,
            'page_title' => $data['page_title'] ?? null,
            'http_status' => $data['http_status'] ?? null,
            'content_source' => $data['content_source'] ?? 'live',
            'domain_age_days' => $data['domain_age_days'] ?? null,
            'ssl_valid' => !empty($data['ssl_valid']),
            'cdn' => $data['cdn_provider'] ?? null,
            'malware_hit' => !empty($data['malware_hit']),
            'phishing_hit' => !empty($data['phishing_hit']),
            'spam_hit' => !empty($data['spam_hit']),
            'review_penalty' => (int) ($data['review_penalty'] ?? 0),
            'rule_brief_lean' => $ruleBrief['lean'] ?? 'mixed',
            'signals' => $compactSignals,
        ];

        $system = 'You are a cautious website trust analyst for ScamGuard. '
            . 'Given structured scan facts, judge whether the site leans positive (likely legit), negative (likely scam/risky), or mixed. '
            . 'Be skeptical of new domains, crypto-only payments, urgency language, missing contact, and weak reviews. '
            . 'CDN/Cloudflare is NOT a negative. Do not invent facts not in the input. '
            . 'Respond with ONLY compact JSON: '
            . '{"lean":"positive|negative|mixed","confidence":0-100,"summary":"1-2 sentences","factors":["..."],"score_delta":-8..8} '
            . 'score_delta is a soft adjustment only.';

        $body = json_encode([
            'model' => $model,
            'temperature' => 0.2,
            'max_tokens' => 280,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($payloadFacts, JSON_UNESCAPED_SLASHES)],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 14,
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

        // Strip markdown fences if the model adds them.
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/u', '', trim($text)));
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            // Try to extract first JSON object.
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $parsed = json_decode($m[0], true);
            }
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
        $summary = mb_substr($summary, 0, 420);
        $factors = $parsed['factors'] ?? [];
        if (!is_array($factors)) {
            $factors = [];
        }
        $factors = array_values(array_filter(array_map(
            static fn($f) => mb_substr(trim((string) $f), 0, 120),
            $factors
        )));
        $delta = max(-8, min(8, (int) ($parsed['score_delta'] ?? 0)));

        $tone = $lean === 'positive' ? 'good' : ($lean === 'negative' ? 'bad' : 'warn');
        $label = match ($lean) {
            'positive' => 'AI: positive lean',
            'negative' => 'AI: negative lean',
            default => 'AI: mixed',
        };

        return [
            'lean' => $lean,
            'label' => $label . ' (' . $confidence . '% conf.)',
            'summary' => $summary,
            'tone' => $tone,
            'confidence' => $confidence,
            'score_delta' => $delta,
            'factors' => array_slice($factors, 0, 6),
        ];
    }
}
