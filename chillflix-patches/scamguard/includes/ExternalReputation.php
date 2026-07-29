<?php
/**
 * External reputation lookups:
 * Trustpilot / Sitejabber / Scamadviser / Yelp (best-effort) reviews,
 * multi-RBL abuse/spam, multi-engine web safety (URLVoid),
 * reverse-IP neighbors, and origin IP DNSBL checks.
 */
class ExternalReputation
{
    private string $domain;
    private string $cacheDir;
    /** @var array{ip?:?string,is_cloudflare?:bool,uses_cdn?:bool} */
    private array $ctx;

    public function __construct(string $domain, array $ctx = [])
    {
        $this->domain = strtolower(trim($domain));
        $this->ctx = $ctx;
        $this->cacheDir = __DIR__ . '/../storage/cache/reputation';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }
    }

    /**
     * @return array{
     *   signals:array<int,array>,
     *   score_delta:int,
     *   spam_hit:int,
     *   review_penalty:int,
     *   review_bonus:int,
     *   review_consensus:?string
     * }
     */
    public function collect(): array
    {
        $signals = [];
        $delta = 0;
        $spamHit = 0;

        $profiles = [];
        foreach ([$this->trustpilot(), $this->sitejabber(), $this->scamadviser(), $this->yelp()] as $src) {
            if (!empty($src['signal'])) {
                $signals[] = $src['signal'];
            }
            if (!empty($src['usable'])) {
                $profiles[] = $src;
            }
        }

        $consensus = $this->reconcileReviewProfiles($profiles);
        if ($consensus['signal'] !== null) {
            // Put consensus first among reputation signals for UI highlights.
            array_unshift($signals, $consensus['signal']);
        }
        $reviewPenalty = (int) $consensus['penalty'];
        $reviewBonus = (int) $consensus['bonus'];
        $delta -= $reviewPenalty;
        $delta += $reviewBonus;

        // Soft-adjust per-source display tones after consensus (tiny samples vs strong positives).
        $signals = $this->retoneReviewSignals($signals, $profiles, $consensus);

        $abuse = $this->abuseBlacklists();
        $signals[] = $abuse['signal'];
        if (!empty($abuse['hit'])) {
            $spamHit = 1;
            $delta -= (int) $abuse['penalty'];
        } else {
            $delta += (int) ($abuse['bonus'] ?? 0);
        }

        $safety = $this->urlvoidSafety();
        $signals[] = $safety['signal'];
        $delta += (int) $safety['delta'];

        $neighbors = $this->reverseIpNeighbors();
        $signals[] = $neighbors['signal'];
        $delta += (int) ($neighbors['delta'] ?? 0);

        $ipBl = $this->originIpBlacklists();
        $signals[] = $ipBl['signal'];
        $delta += (int) ($ipBl['delta'] ?? 0);
        if (!empty($ipBl['hit'])) {
            $spamHit = 1;
        }

        return [
            'signals' => $signals,
            'score_delta' => $delta,
            'spam_hit' => $spamHit,
            'review_penalty' => $reviewPenalty,
            'review_bonus' => $reviewBonus,
            'review_consensus' => $consensus['summary'],
        ];
    }

    /**
     * @return array{
     *   usable:bool,
     *   source:string,
     *   stars:?float,
     *   count:int,
     *   neg:?int,
     *   pos:?int,
     *   scamish:bool,
     *   signal:array,
     *   weight:float
     * }
     */
    private function trustpilot(): array
    {
        $empty = [
            'usable' => false,
            'source' => 'Trustpilot',
            'stars' => null,
            'count' => 0,
            'neg' => null,
            'pos' => null,
            'scamish' => false,
            'weight' => 1.0,
            'signal' => $this->sig('reputation', 'Trustpilot reviews', 'Unavailable', 'Could not fetch Trustpilot profile right now.', 'neutral'),
        ];

        $cached = $this->cacheGet('trustpilot');
        if ($cached === null) {
            $md = $this->httpGet('https://r.jina.ai/https://www.trustpilot.com/review/' . rawurlencode($this->domain), 25);
            $cached = [
                'ok' => is_string($md) && $md !== '' && !str_contains($md, 'Just a moment'),
                'raw' => is_string($md) ? substr($md, 0, 120000) : '',
            ];
            $this->cacheSet('trustpilot', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return $empty;
        }

        $raw = (string) $cached['raw'];
        $score = null;
        $count = null;

        // Prefer the page title / TrustScore — ignore "suggested companies" noise.
        if (preg_match('/^Title:\s*.*rated\s+"([^"]+)"\s+with\s+([0-9.]+)\s*\/\s*5/mi', $raw, $m)) {
            $score = (float) $m[2];
        } elseif (preg_match('/rated\s+"([^"]+)"\s+with\s+([0-9.]+)\s*\/\s*5/i', $raw, $m)) {
            $score = (float) $m[2];
        } elseif (preg_match('/TrustScore\s+([0-9.]+)\s+out of\s+5/i', $raw, $m)) {
            $score = (float) $m[1];
        } elseif (preg_match('/TrustScore\s+([0-9.]+)/i', $raw, $m)) {
            $score = (float) $m[1];
        }

        if (preg_match('/See all\s+([0-9][0-9,]*)\s+reviews/i', $raw, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        } elseif (preg_match('/Considering\s+([0-9][0-9,]*)\s+reviews/i', $raw, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        } elseif (preg_match('/\*\*([0-9][0-9,]*)\s+reviews\*\*/i', $raw, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        } elseif (preg_match('/Reviews\s*\n\s*\n?\s*([0-9][0-9,]*)/i', $raw, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
        }

        if ($score === null && $count === null) {
            $empty['signal'] = $this->sig(
                'reputation',
                'Trustpilot reviews',
                'No profile / unreadable',
                'Trustpilot page fetched but score could not be parsed.',
                'neutral'
            );
            return $empty;
        }

        $scamish = (bool) preg_match('/\b(scam|fraud|stolen|nulled|malware|fake|ripoff|rip-off)\b/i', $raw);
        $negPct = $this->parseTrustpilotNegativeShare($raw);
        $n = (int) ($count ?? 0);
        $neg = null;
        $pos = null;
        if ($n > 0 && $negPct !== null) {
            $neg = (int) round($n * ($negPct / 100));
            $pos = max(0, $n - $neg);
        } elseif ($n > 0 && $score !== null) {
            [$neg, $pos] = $this->estimatePosNegFromStars($score, $n);
        }

        $scored = $this->scoreReviewMix([
            'source' => 'Trustpilot',
            'stars' => $score,
            'count' => $n,
            'neg' => $neg,
            'pos' => $pos,
            'scamish' => $scamish,
            'weight' => 1.15, // Trustpilot usually has the best volume signal
        ]);

        $label = ($score !== null ? number_format($score, 1) . '/5' : 'n/a')
            . ($n > 0 ? ' · ' . number_format($n) . ' reviews' : '');
        if ($neg !== null && $pos !== null && $n >= 5) {
            $label .= ' · ~' . $neg . ' neg / ~' . $pos . ' pos';
        }

        return [
            'usable' => $score !== null || $n > 0,
            'source' => 'Trustpilot',
            'stars' => $score,
            'count' => $n,
            'neg' => $neg,
            'pos' => $pos,
            'scamish' => $scamish,
            'weight' => 1.15,
            'penalty' => $scored['penalty'],
            'bonus' => $scored['bonus'],
            'signal' => $this->sig('reputation', 'Trustpilot reviews', $label, $scored['note'], $scored['tone']),
        ];
    }

    /**
     * @return array{
     *   usable:bool,
     *   source:string,
     *   stars:?float,
     *   count:int,
     *   neg:?int,
     *   pos:?int,
     *   scamish:bool,
     *   signal:array,
     *   weight:float,
     *   penalty?:int,
     *   bonus?:int
     * }
     */
    private function sitejabber(): array
    {
        $empty = [
            'usable' => false,
            'source' => 'Sitejabber',
            'stars' => null,
            'count' => 0,
            'neg' => null,
            'pos' => null,
            'scamish' => false,
            'weight' => 0.85,
            'signal' => $this->sig('reputation', 'Sitejabber / SmartCustomer', 'Unavailable', 'Could not load Sitejabber business profile.', 'neutral'),
        ];

        $cached = $this->cacheGet('sitejabber');
        if ($cached === null) {
            $json = $this->httpGet('https://api.smartcustomer.com/v2/businesses/' . rawurlencode($this->domain), 12);
            $payload = json_decode($json ?: '', true);
            $cached = is_array($payload) ? $payload : ['error' => true];
            $this->cacheSet('sitejabber', $cached, 21600);
        }

        if (!empty($cached['error']) || empty($cached['display_address'])) {
            return $empty;
        }

        $reviews = (int) ($cached['review_numbers']['reviews'] ?? 0);
        $sj = isset($cached['sj_score']) ? (float) $cached['sj_score'] : null;
        $avg = isset($cached['average_ratings']['rating']) ? (float) $cached['average_ratings']['rating'] : null;
        $negRaw = $cached['reviews_distribution']['negative'] ?? 0;
        $posRaw = $cached['reviews_distribution']['positive'] ?? null;
        $pos = (int) ($cached['positive_reviews_number'] ?? 0);
        if ($pos <= 0 && is_numeric($posRaw)) {
            $pos = (int) $posRaw;
        }
        $neg = is_numeric($negRaw) ? (float) $negRaw : 0.0;

        // Prefer star average when present; sj_score can be a 0-100 index.
        $sjStars = $avg;
        if ($sjStars === null && $sj !== null) {
            $sjStars = $sj > 5 ? max(0.0, min(5.0, $sj / 20.0)) : $sj;
        }

        $negCount = null;
        if ($reviews > 0) {
            if ($neg > 0 && $neg <= 100 && $neg > $reviews) {
                $negCount = (int) round(($neg / 100) * $reviews);
            } elseif ($neg > 0) {
                $negCount = (int) round($neg);
            } elseif ($sjStars !== null && $sjStars <= 2.5 && $pos === 0) {
                // Tiny all-negative profiles often leave distribution empty.
                $negCount = $reviews;
                $pos = 0;
            }
        }
        if ($reviews > 0 && $pos === 0 && $negCount !== null) {
            $pos = max(0, $reviews - $negCount);
        } elseif ($reviews > 0 && $negCount === null && $sjStars !== null) {
            [$negCount, $pos] = $this->estimatePosNegFromStars($sjStars, $reviews);
        }

        $scored = $this->scoreReviewMix([
            'source' => 'Sitejabber',
            'stars' => $sjStars,
            'count' => $reviews,
            'neg' => $negCount,
            'pos' => $pos,
            'scamish' => false,
            'weight' => 0.85,
        ]);

        $value = ($sjStars !== null ? number_format($sjStars, 1) . '/5' : 'Profile found')
            . ' · ' . $reviews . ' reviews';
        if ($negCount !== null && $pos !== null && $reviews > 0) {
            $value .= ' · ' . $negCount . ' neg / ' . $pos . ' pos';
        }

        return [
            'usable' => $reviews > 0 && $sjStars !== null,
            'source' => 'Sitejabber',
            'stars' => $sjStars,
            'count' => $reviews,
            'neg' => $negCount,
            'pos' => $pos,
            'scamish' => false,
            'weight' => 0.85,
            'penalty' => $scored['penalty'],
            'bonus' => $scored['bonus'],
            'signal' => $this->sig('reputation', 'Sitejabber / SmartCustomer', $value, $scored['note'], $scored['tone']),
        ];
    }

    /**
     * Scamadviser trust score + review lean (Jina). Complements Trustpilot/Sitejabber.
     *
     * @return array{usable:bool,source:string,stars:?float,count:int,neg:?int,pos:?int,scamish:bool,signal:array,weight:float,penalty?:int,bonus?:int}
     */
    private function scamadviser(): array
    {
        $empty = [
            'usable' => false,
            'source' => 'Scamadviser',
            'stars' => null,
            'count' => 0,
            'neg' => null,
            'pos' => null,
            'scamish' => false,
            'weight' => 0.55,
            'signal' => $this->sig('reputation', 'Scamadviser', 'Unavailable', 'Could not load Scamadviser summary.', 'neutral'),
        ];

        $cached = $this->cacheGet('scamadviser');
        if ($cached === null) {
            $md = $this->httpGet('https://r.jina.ai/https://www.scamadviser.com/check-website/' . rawurlencode($this->domain), 22);
            $cached = [
                'ok' => is_string($md) && $md !== '' && !str_contains(strtolower($md), 'just a moment'),
                'raw' => is_string($md) ? substr($md, 0, 80000) : '',
            ];
            $this->cacheSet('scamadviser', $cached, 21600);
        }
        if (empty($cached['ok'])) {
            return $empty;
        }

        $raw = (string) $cached['raw'];
        $trust = null;
        if (preg_match('/Trust\s*Score\s*([0-9]{1,3})\b/i', $raw, $m)) {
            $trust = max(0, min(100, (int) $m[1]));
        }
        if ($trust === null) {
            return $empty;
        }

        // Map 0-100 trust → rough 1-5 stars for mix scoring.
        $stars = max(1.0, min(5.0, $trust / 20.0));
        $posLean = (bool) preg_match('/people are giving this website positive reviews|positive public reviews|overwhelmingly positive/i', $raw);
        $negLean = (bool) preg_match('/\b(bad reviews|mostly negative reviews|negative review profile|reviews are (?:either )?missing|few or no reviews|little to no reviews)\b/i', $raw)
            || (bool) preg_match('/in summary[^\n]{0,120}\b(scam|suspicious|not safe|high risk)\b/i', $raw);

        // Synthetic small sample so Scamadviser can't dominate — it's a meta score.
        $count = $posLean || $negLean ? 12 : 8;
        $neg = $negLean ? 7 : ($posLean ? 2 : 4);
        $pos = max(0, $count - $neg);
        if ($trust >= 80) {
            $neg = 1;
            $pos = 11;
            $count = 12;
        } elseif ($trust <= 35) {
            $neg = 10;
            $pos = 2;
            $count = 12;
        }

        $scored = $this->scoreReviewMix([
            'source' => 'Scamadviser',
            'stars' => $stars,
            'count' => $count,
            'neg' => $neg,
            'pos' => $pos,
            'scamish' => $trust <= 30,
            'weight' => 0.55,
        ]);

        $tone = $trust >= 75 ? 'good' : ($trust <= 40 ? 'bad' : ($trust <= 60 ? 'warn' : 'neutral'));
        $note = 'Scamadviser trust score ' . $trust . '/100'
            . ($posLean ? ' — notes positive public reviews.' : '')
            . ($negLean ? ' — notes weak/negative review picture.' : '');

        return [
            'usable' => true,
            'source' => 'Scamadviser',
            'stars' => $stars,
            'count' => $count,
            'neg' => $neg,
            'pos' => $pos,
            'scamish' => $trust <= 30,
            'weight' => 0.55,
            'penalty' => $scored['penalty'],
            'bonus' => $scored['bonus'],
            'signal' => $this->sig('reputation', 'Scamadviser', $trust . '/100 trust', $note, $tone),
        ];
    }

    /**
     * Yelp is often bot-walled; best-effort only.
     *
     * @return array{usable:bool,source:string,stars:?float,count:int,neg:?int,pos:?int,scamish:bool,signal:array,weight:float,penalty?:int,bonus?:int}
     */
    private function yelp(): array
    {
        $empty = [
            'usable' => false,
            'source' => 'Yelp',
            'stars' => null,
            'count' => 0,
            'neg' => null,
            'pos' => null,
            'scamish' => false,
            'weight' => 0.7,
            'signal' => $this->sig(
                'reputation',
                'Yelp reviews',
                'Unavailable',
                'Yelp often blocks automated checks — skipped when unreadable.',
                'neutral'
            ),
        ];

        $cached = $this->cacheGet('yelp');
        if ($cached === null) {
            $q = rawurlencode(preg_replace('/^www\./', '', $this->domain) ?: $this->domain);
            $md = $this->httpGet('https://r.jina.ai/https://www.yelp.com/search?find_desc=' . $q, 18);
            $blocked = !is_string($md)
                || $md === ''
                || str_contains(strtolower($md), 'captcha')
                || str_contains(strtolower($md), 'access to this page has been denied')
                || strlen($md) < 400;
            $cached = [
                'ok' => !$blocked,
                'raw' => is_string($md) ? substr($md, 0, 80000) : '',
            ];
            $this->cacheSet('yelp', $cached, 21600);
        }
        if (empty($cached['ok'])) {
            return $empty;
        }

        $raw = (string) $cached['raw'];
        $score = null;
        $count = null;
        if (preg_match('/\b([1-5]\.[0-9])\s*(?:star|stars)?\b[^0-9]{0,40}\b([0-9][0-9,]*)\s+reviews?\b/i', $raw, $m)) {
            $score = (float) $m[1];
            $count = (int) str_replace(',', '', $m[2]);
        } elseif (preg_match('/\b([0-9][0-9,]*)\s+reviews?\b[^0-9]{0,40}\b([1-5]\.[0-9])\b/i', $raw, $m)) {
            $count = (int) str_replace(',', '', $m[1]);
            $score = (float) $m[2];
        }

        if ($score === null || $count === null || $count <= 0) {
            $empty['signal'] = $this->sig(
                'reputation',
                'Yelp reviews',
                'No clear match',
                'Yelp page loaded but no reliable rating/count matched this domain.',
                'neutral'
            );
            return $empty;
        }

        [$neg, $pos] = $this->estimatePosNegFromStars($score, $count);
        $scored = $this->scoreReviewMix([
            'source' => 'Yelp',
            'stars' => $score,
            'count' => $count,
            'neg' => $neg,
            'pos' => $pos,
            'scamish' => false,
            'weight' => 0.7,
        ]);

        $label = number_format($score, 1) . '/5 · ' . number_format($count) . ' reviews';
        return [
            'usable' => true,
            'source' => 'Yelp',
            'stars' => $score,
            'count' => $count,
            'neg' => $neg,
            'pos' => $pos,
            'scamish' => false,
            'weight' => 0.7,
            'penalty' => $scored['penalty'],
            'bonus' => $scored['bonus'],
            'signal' => $this->sig('reputation', 'Yelp reviews', $label, $scored['note'], $scored['tone']),
        ];
    }

    /**
     * Core mix scoring:
     * - 5 neg + 5 pos ≈ mild (-2/-3)
     * - 50 neg + few pos ≈ harsh (~-20)
     * - tiny samples still count, but capped
     * - strong positive volume can earn bonus
     *
     * @param array{source:string,stars:?float,count:int,neg:?int,pos:?int,scamish?:bool,weight?:float} $p
     * @return array{penalty:int,bonus:int,tone:string,note:string}
     */
    private function scoreReviewMix(array $p): array
    {
        $source = (string) $p['source'];
        $stars = $p['stars'];
        $count = max(0, (int) $p['count']);
        $neg = $p['neg'];
        $pos = $p['pos'];
        $scamish = !empty($p['scamish']);
        $weight = isset($p['weight']) ? (float) $p['weight'] : 1.0;

        if ($stars === null && $count <= 0) {
            return ['penalty' => 0, 'bonus' => 0, 'tone' => 'neutral', 'note' => $source . ' reviews unavailable.'];
        }

        if (($neg === null || $pos === null) && $count > 0 && $stars !== null) {
            [$neg, $pos] = $this->estimatePosNegFromStars((float) $stars, $count);
        }
        $neg = (int) ($neg ?? 0);
        $pos = (int) ($pos ?? 0);
        if ($count <= 0) {
            $count = max(1, $neg + $pos);
        }

        $cap = $this->reviewSampleCap($count);
        $negShare = ($neg + $pos) > 0 ? ($neg / ($neg + $pos)) : 0.0;

        // Ratio-first penalty (what users feel when reading review pages).
        $ratioPenalty = 0;
        if ($neg + $pos >= 2) {
            // Balanced 5/5 → mild; 50/5 → harsh.
            $imbalance = $neg - $pos; // positive when negatives dominate
            if ($imbalance <= 0 && $negShare <= 0.45) {
                $ratioPenalty = $negShare >= 0.35 ? 2 : 0;
            } elseif ($neg <= 5 && $pos >= 3 && $negShare <= 0.55) {
                $ratioPenalty = 3; // e.g. 5 neg / 5 pos
            } elseif ($neg >= 40 && $pos <= 8) {
                $ratioPenalty = 22; // e.g. 50 neg / few pos
            } elseif ($neg >= 20 && $negShare >= 0.7) {
                $ratioPenalty = 16;
            } elseif ($neg >= 10 && $negShare >= 0.6) {
                $ratioPenalty = 11;
            } elseif ($negShare >= 0.55) {
                $ratioPenalty = 7;
            } elseif ($negShare >= 0.45) {
                $ratioPenalty = 4;
            }
        }

        // Star floor — 2.x is already worrying even with mixed counts.
        $starPenalty = 0;
        $tone = 'neutral';
        $note = $source . ' consumer reviews.';
        if ($stars !== null) {
            if ($stars <= 1.9) {
                $starPenalty = $count <= 4 ? 3 : 12;
                $tone = $count <= 4 ? 'warn' : 'bad';
                $note = $count <= 4
                    ? $source . ' score ≤1.9 but only ' . $count . ' review(s) — small sample, limited weight.'
                    : $source . ' score ≤1.9 — critical low reputation.';
            } elseif ($stars <= 2.5) {
                $starPenalty = $count <= 4 ? 2 : 9;
                $tone = $count <= 4 ? 'warn' : 'bad';
                $note = $count <= 4
                    ? $source . ' score ≤2.5 on a tiny sample — note it, but don’t over-weight.'
                    : $source . ' score ≤2.5 — low-reputation pattern.';
            } elseif ($stars < 3.0) {
                $starPenalty = $count <= 4 ? 2 : 6;
                $tone = 'warn';
                $note = $source . ' under 3.0 — already a serious reputation concern'
                    . ($count < 10 ? ' (moderate sample).' : '.');
            } elseif ($stars < 3.5) {
                $starPenalty = 3;
                $tone = 'warn';
                $note = $source . ' mediocre score — mixed feedback.';
            }
        }

        $penalty = (int) round(max($ratioPenalty, $starPenalty) * $weight);
        if ($scamish && $penalty > 0) {
            $penalty += $count >= 8 ? 3 : 1;
            $note .= ' Scam/fraud language appears in review text.';
        }
        if ($neg >= 5 && $pos > 0) {
            $note .= ' Mix ~' . $neg . ' negative / ' . $pos . ' positive.';
        }
        $penalty = min($cap, $penalty);

        $bonus = 0;
        if ($stars !== null && $penalty === 0) {
            if ($stars >= 4.5 && $count >= 25 && $negShare <= 0.25) {
                $bonus = (int) round(min(12, 6 + (int) floor($count / 50)) * $weight);
                $tone = 'good';
                $note = $source . ' excellent score with strong volume.';
            } elseif ($stars >= 4.0 && $count >= 10 && $negShare <= 0.35) {
                $bonus = (int) round(min(9, 4 + (int) floor($count / 40)) * $weight);
                $tone = 'good';
                $note = $source . ' strong positive review profile.';
            } elseif ($stars >= 4.0 && $count >= 5) {
                $bonus = (int) round(3 * $weight);
                $tone = 'good';
                $note = $source . ' positive score (smaller sample).';
            }
        }

        return [
            'penalty' => max(0, $penalty),
            'bonus' => max(0, $bonus),
            'tone' => $tone,
            'note' => $note,
        ];
    }

    /**
     * Combine sources so one tiny angry profile can’t drown a large positive Trustpilot.
     *
     * @param array<int,array> $profiles
     * @return array{penalty:int,bonus:int,summary:?string,signal:?array}
     */
    private function reconcileReviewProfiles(array $profiles): array
    {
        if (!$profiles) {
            return ['penalty' => 0, 'bonus' => 0, 'summary' => null, 'signal' => null];
        }

        $totalNeg = 0;
        $totalPos = 0;
        $weightedStars = 0.0;
        $starWeight = 0.0;
        $rawPenalty = 0;
        $rawBonus = 0;
        $parts = [];

        foreach ($profiles as $p) {
            $n = (int) ($p['count'] ?? 0);
            $w = (float) ($p['weight'] ?? 1.0);
            $volW = $w * log(1 + max(1, $n)); // volume-aware
            if ($p['stars'] !== null) {
                $weightedStars += ((float) $p['stars']) * $volW;
                $starWeight += $volW;
            }
            $totalNeg += (int) ($p['neg'] ?? 0);
            $totalPos += (int) ($p['pos'] ?? 0);
            $rawPenalty += (int) ($p['penalty'] ?? 0);
            $rawBonus += (int) ($p['bonus'] ?? 0);
            $parts[] = $p['source']
                . ($p['stars'] !== null ? ' ' . number_format((float) $p['stars'], 1) . '/5' : '')
                . ($n > 0 ? ' (' . $n . ')' : '');
        }

        $avgStars = $starWeight > 0 ? ($weightedStars / $starWeight) : null;

        // Strong high-volume positive source dampens tiny negative outliers.
        $strongPos = null;
        foreach ($profiles as $p) {
            if (($p['stars'] ?? 0) >= 4.0 && ($p['count'] ?? 0) >= 50) {
                if ($strongPos === null || $p['count'] > $strongPos['count']) {
                    $strongPos = $p;
                }
            }
        }
        if ($strongPos) {
            $adjustedPenalty = 0;
            $adjustedBonus = (int) ($strongPos['bonus'] ?? 0);
            foreach ($profiles as $p) {
                if ($p['source'] === $strongPos['source']) {
                    continue;
                }
                $n = (int) ($p['count'] ?? 0);
                $pen = (int) ($p['penalty'] ?? 0);
                if ($n > 0 && $n < 8 && $pen > 0) {
                    // Tiny contrary samples: keep a whisper, not a scare.
                    $adjustedPenalty += min(2, $pen);
                } elseif ($pen > 0) {
                    $adjustedPenalty += (int) floor($pen * 0.45);
                }
                $adjustedBonus += (int) floor(((int) ($p['bonus'] ?? 0)) * 0.5);
            }
            $penalty = min(28, $adjustedPenalty);
            $bonus = min(14, $adjustedBonus);
            $summary = 'Review consensus leans positive — '
                . $strongPos['source'] . ' is strong ('
                . number_format((float) $strongPos['stars'], 1) . '/5, '
                . number_format((int) $strongPos['count']) . ' reviews)'
                . '; smaller contrary samples are down-weighted.';
            $tone = $penalty >= 8 ? 'warn' : 'good';
            return [
                'penalty' => $penalty,
                'bonus' => $bonus,
                'summary' => $summary,
                'signal' => $this->sig(
                    'reputation',
                    'Review consensus',
                    ($avgStars !== null ? number_format($avgStars, 1) . '/5 weighted · ' : '')
                    . implode(' · ', $parts),
                    $summary,
                    $tone
                ),
            ];
        }

        // No single strong positive — use combined mix, harsh when negatives dominate at volume.
        $mix = $this->scoreReviewMix([
            'source' => 'Combined',
            'stars' => $avgStars,
            'count' => $totalNeg + $totalPos,
            'neg' => $totalNeg,
            'pos' => $totalPos,
            'scamish' => false,
            'weight' => 1.0,
        ]);

        // Blend source penalties with combined mix (favor the harsher when volume is meaningful).
        $combinedCount = $totalNeg + $totalPos;
        $penalty = $mix['penalty'];
        if ($combinedCount >= 15) {
            $penalty = max($penalty, (int) floor($rawPenalty * 0.8));
        } else {
            $penalty = max($penalty, (int) floor($rawPenalty * 0.55));
        }
        $bonus = max($mix['bonus'], (int) floor($rawBonus * 0.7));
        $penalty = min(32, $penalty);
        $bonus = min(14, $bonus);

        $summary = 'Review consensus from ' . implode(', ', $parts) . '.';
        if ($totalNeg + $totalPos > 0) {
            $summary .= ' Approx ' . $totalNeg . ' negative vs ' . $totalPos . ' positive across sources.';
        }

        $tone = $mix['tone'];
        if ($penalty >= 12) {
            $tone = 'bad';
        } elseif ($penalty >= 4) {
            $tone = 'warn';
        } elseif ($bonus >= 4) {
            $tone = 'good';
        }

        return [
            'penalty' => $penalty,
            'bonus' => $bonus,
            'summary' => $summary,
            'signal' => $this->sig(
                'reputation',
                'Review consensus',
                ($avgStars !== null ? number_format($avgStars, 1) . '/5 weighted · ' : '')
                . implode(' · ', $parts),
                $summary,
                $tone
            ),
        ];
    }

    /**
     * After consensus, demote scary tones on tiny samples when a strong positive source exists.
     *
     * @param array<int,array> $signals
     * @param array<int,array> $profiles
     * @param array{penalty:int,bonus:int,summary:?string} $consensus
     * @return array<int,array>
     */
    private function retoneReviewSignals(array $signals, array $profiles, array $consensus): array
    {
        $hasStrongPos = false;
        foreach ($profiles as $p) {
            if (($p['stars'] ?? 0) >= 4.0 && ($p['count'] ?? 0) >= 50) {
                $hasStrongPos = true;
                break;
            }
        }
        if (!$hasStrongPos) {
            return $signals;
        }

        foreach ($signals as &$s) {
            $label = (string) ($s['label'] ?? '');
            if (!preg_match('/Sitejabber|Yelp|Scamadviser/i', $label)) {
                continue;
            }
            // Find matching profile count
            foreach ($profiles as $p) {
                if (stripos($label, $p['source']) === false) {
                    continue;
                }
                if ((int) ($p['count'] ?? 0) < 8 && in_array(($s['tone'] ?? ''), ['bad', 'warn'], true)) {
                    $s['tone'] = 'neutral';
                    $note = (string) ($s['note'] ?? '');
                    $s['note'] = trim($note . ' Down-weighted: tiny sample vs a much larger positive review profile elsewhere.');
                }
            }
        }
        unset($s);
        return $signals;
    }

    /** @return array{0:int,1:int} [neg, pos] */
    private function estimatePosNegFromStars(float $stars, int $count): array
    {
        $stars = max(1.0, min(5.0, $stars));
        // Map stars → expected negative share (approx).
        // 1.0 → ~90% neg, 2.5 → ~65%, 3.0 → ~50%, 4.0 → ~20%, 4.5 → ~10%, 5.0 → ~5%
        $negShare = max(0.05, min(0.95, (5.2 - $stars) / 4.2));
        if ($stars >= 4.5) {
            $negShare = 0.10;
        } elseif ($stars >= 4.0) {
            $negShare = 0.20;
        } elseif ($stars >= 3.5) {
            $negShare = 0.35;
        }
        $neg = (int) round($count * $negShare);
        $pos = max(0, $count - $neg);
        return [$neg, $pos];
    }

    /**
     * Cap how hard reviews can swing the score based on sample size.
     * Tiny samples still count, but cannot dominate.
     */
    private function reviewSampleCap(int $count): int
    {
        if ($count <= 0) {
            return 3;
        }
        if ($count <= 2) {
            return 3; // e.g. Transamerica Sitejabber 2 reviews
        }
        if ($count <= 4) {
            return 5;
        }
        if ($count <= 9) {
            return 12; // ~5 reviews can matter, not nuke the score
        }
        if ($count <= 24) {
            return 20;
        }
        if ($count <= 49) {
            return 26;
        }
        return 32;
    }

    private function parseTrustpilotNegativeShare(string $raw): ?int
    {
        if (preg_match('/\b([0-9]{1,3})\s*%\s*(?:of reviews\s*)?(?:are\s*)?negative\b/i', $raw, $m)) {
            $n = (int) $m[1];
            return ($n >= 0 && $n <= 100) ? $n : null;
        }
        if (preg_match('/1\s*[-\s]?star[^0-9]{0,40}([0-9]{1,3})\s*%/i', $raw, $m1)
            && preg_match('/2\s*[-\s]?star[^0-9]{0,40}([0-9]{1,3})\s*%/i', $raw, $m2)) {
            $sum = (int) $m1[1] + (int) $m2[1];
            return ($sum >= 0 && $sum <= 100) ? $sum : null;
        }
        return null;
    }

    /** Public RBL sweep via MXToolbox. */
    private function abuseBlacklists(): array
    {
        $cached = $this->cacheGet('mxtoolbox');
        if ($cached === null) {
            $url = 'https://r.jina.ai/https://mxtoolbox.com/SuperTool.aspx?action=blacklist%3a'
                . rawurlencode($this->domain) . '&run=toolpage';
            $md = $this->httpGet($url, 35);
            $cached = [
                'ok' => is_string($md) && str_contains($md, 'Blacklist'),
                'raw' => is_string($md) ? substr($md, 0, 80000) : '',
            ];
            $this->cacheSet('mxtoolbox', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Abuse / spam blacklists',
                    'Unavailable',
                    'Public RBL sweep failed or timed out.',
                    'neutral'
                ),
                'hit' => 0,
                'penalty' => 0,
                'bonus' => 0,
            ];
        }

        $raw = (string) $cached['raw'];

        // Guard against bad Jina/MXToolbox captures that probe loopback test IPs
        // (e.g. "Checking 127.0.0.2 against 60 known blacklists") — those are not
        // this domain and produce huge false-positive "listed" counts.
        $checkedLoopback = (bool) preg_match(
            '/Checking\s+\*{0,2}127\.\d+\.\d+\.\d+\*{0,2}\s+against/i',
            $raw
        );
        $mentionsDomain = (bool) preg_match(
            '/(?:blacklist:|Checking\s+\*{0,2})' . preg_quote($this->domain, '/') . '/i',
            $raw
        );
        if ($checkedLoopback || !$mentionsDomain) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Abuse / spam blacklists',
                    'Unavailable',
                    $checkedLoopback
                        ? 'Public RBL sweep returned a loopback test probe — ignored as unreliable.'
                        : 'Public RBL sweep did not clearly target this domain — ignored.',
                    'neutral'
                ),
                'hit' => 0,
                'penalty' => 0,
                'bonus' => 0,
            ];
        }

        $listed = 0;
        if (preg_match('/Listed\s+\*\*?(\d+)\*\*?\s+times/i', $raw, $m)) {
            $listed = (int) $m[1];
        } elseif (preg_match('/Listed\s+(\d+)\s+times/i', $raw, $m)) {
            $listed = (int) $m[1];
        }

        if ($listed > 0) {
            // Public RBLs are noisy: a single listing is common for streaming, proxy,
            // and high-traffic sites and is NOT proof of fraud. Only treat multiple
            // listings as a real spam "hit"; a lone listing is a soft caution.
            $strong = $listed >= 3;
            $moderate = $listed === 2;
            $penalty = $strong ? min(20, 6 + $listed * 3) : ($moderate ? 8 : 4);
            $note = $strong
                ? 'Listed on several public RBLs — a stronger abuse signal.'
                : ($moderate
                    ? 'Listed on a couple of public RBLs.'
                    : 'Listed on a single public RBL — often noisy for streaming / high-traffic sites, so treated as a soft caution.');
            return [
                'signal' => $this->sig(
                    'threat',
                    'Abuse / spam blacklists',
                    'Listed on ' . $listed . ' list(s)',
                    $note,
                    $strong ? 'bad' : 'warn'
                ),
                'hit' => $strong ? 1 : 0,
                'penalty' => $penalty,
                'bonus' => 0,
            ];
        }

        return [
            'signal' => $this->sig(
                'threat',
                'Abuse / spam blacklists',
                'Clean on public RBLs',
                'Swept ~68 public blacklists via MXToolbox.',
                'good'
            ),
            'hit' => 0,
            'penalty' => 0,
            'bonus' => 3,
        ];
    }

    /**
     * Same-server neighbor domains via reverse IP (HackerTarget).
     * Skipped on Cloudflare / shared CDN anycast IPs — too noisy.
     */
    private function reverseIpNeighbors(): array
    {
        $ip = trim((string) ($this->ctx['ip'] ?? ''));
        $skip = !empty($this->ctx['is_cloudflare']) || !empty($this->ctx['uses_cdn']);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'No origin IPv4',
                    'Need a public A record to reverse-look up co-hosted domains.',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }
        if ($skip) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'Skipped (CDN / Cloudflare)',
                    'Reverse-IP neighbor checks are noisy on shared CDN anycast addresses.',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }

        $cached = $this->cacheGet('revip');
        if ($cached === null) {
            $body = $this->httpGet('https://api.hackertarget.com/reverseiplookup/?q=' . rawurlencode($ip), 12);
            $lines = [];
            $error = false;
            if (is_string($body) && $body !== '') {
                if (preg_match('/error|api count|limit|no records/i', $body) && !str_contains($body, "\n")) {
                    $error = true;
                } else {
                    foreach (preg_split('/\r?\n/', trim($body)) as $line) {
                        $line = strtolower(trim($line));
                        if ($line === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $line)) {
                            continue;
                        }
                        if ($line === $this->domain || str_ends_with($line, '.' . $this->domain)) {
                            continue;
                        }
                        $lines[] = $line;
                    }
                    $lines = array_values(array_unique($lines));
                }
            } else {
                $error = true;
            }
            $cached = [
                'ok' => !$error,
                'count' => count($lines),
                'sample' => array_slice($lines, 0, 8),
            ];
            $this->cacheSet('revip', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'Unavailable',
                    'Reverse IP lookup failed or rate-limited.',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }

        $count = (int) ($cached['count'] ?? 0);
        $sample = is_array($cached['sample'] ?? null) ? $cached['sample'] : [];
        $sampleNote = $sample ? (' e.g. ' . implode(', ', array_slice($sample, 0, 4))) : '';

        if ($count === 0) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    'None found',
                    'No other hostnames reported on this origin IP.',
                    'good'
                ),
                'delta' => 2,
            ];
        }

        // Dense shared hosts are weakly correlated with disposable scam kits.
        if ($count >= 80) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    $count . ' co-hosted domains',
                    'Very crowded origin IP — common on cheap shared hosting used by disposable sites.' . $sampleNote,
                    'warn'
                ),
                'delta' => -8,
            ];
        }
        if ($count >= 25) {
            return [
                'signal' => $this->sig(
                    'hosting',
                    'Same-server neighbors',
                    $count . ' co-hosted domains',
                    'Busy shared host; soft caution only.' . $sampleNote,
                    'warn'
                ),
                'delta' => -4,
            ];
        }

        return [
            'signal' => $this->sig(
                'hosting',
                'Same-server neighbors',
                $count . ' co-hosted domain(s)',
                'Other hostnames share this origin IP.' . $sampleNote,
                'neutral'
            ),
            'delta' => 0,
        ];
    }

    /**
     * Origin IP DNSBL checks (skipped on Cloudflare/CDN).
     * Complements domain RBL sweep with host-level abuse lists.
     */
    private function originIpBlacklists(): array
    {
        $ip = trim((string) ($this->ctx['ip'] ?? ''));
        $skip = !empty($this->ctx['is_cloudflare']) || !empty($this->ctx['uses_cdn']);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Origin IP blacklists',
                    'No origin IPv4',
                    'Cannot DNSBL-check without a public A record.',
                    'neutral'
                ),
                'delta' => 0,
                'hit' => 0,
            ];
        }
        if ($skip) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Origin IP blacklists',
                    'Skipped (CDN / Cloudflare)',
                    'CDN edge IPs are shared; listing them would false-positive many sites.',
                    'neutral'
                ),
                'delta' => 0,
                'hit' => 0,
            ];
        }

        $cached = $this->cacheGet('ipdnsbl');
        if ($cached === null) {
            $lists = [
                'zen.spamhaus.org',
                'bl.spamcop.net',
                'b.barracudacentral.org',
                'dnsbl.sorbs.net',
                'cbl.abuseat.org',
            ];
            $hits = [];
            $parts = explode('.', $ip);
            $rev = $parts[3] . '.' . $parts[2] . '.' . $parts[1] . '.' . $parts[0];
            foreach ($lists as $zone) {
                $q = $rev . '.' . $zone;
                $answers = @dns_get_record($q, DNS_A);
                if (!is_array($answers) || !$answers) {
                    continue;
                }
                foreach ($answers as $ans) {
                    $a = (string) ($ans['ip'] ?? '');
                    // Real listings are typically 127.0.0.2–127.0.0.255; skip policy/error codes.
                    if (preg_match('/^127\.0\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/', $a)) {
                        $hits[] = $zone;
                        break;
                    }
                }
            }
            $cached = [
                'ok' => true,
                'hits' => array_values(array_unique($hits)),
            ];
            $this->cacheSet('ipdnsbl', $cached, 21600);
        }

        $hits = is_array($cached['hits'] ?? null) ? $cached['hits'] : [];
        $n = count($hits);
        if ($n === 0) {
            return [
                'signal' => $this->sig(
                    'threat',
                    'Origin IP blacklists',
                    'Clean on checked DNSBLs',
                    'Origin IP not listed on Spamhaus/SpamCop/Barracuda/SORBS/CBL.',
                    'good'
                ),
                'delta' => 3,
                'hit' => 0,
            ];
        }

        return [
            'signal' => $this->sig(
                'threat',
                'Origin IP blacklists',
                'Listed on ' . $n . ' DNSBL(s)',
                'Hit: ' . implode(', ', $hits),
                'bad'
            ),
            'delta' => -min(22, 8 + $n * 4),
            'hit' => 1,
        ];
    }

    /** Multi-engine web filter reputation (URLVoid) — stand-in for DNSFilter labels. */
    private function urlvoidSafety(): array
    {
        $cached = $this->cacheGet('urlvoid');
        if ($cached === null) {
            $html = $this->httpGet('https://www.urlvoid.com/scan/' . rawurlencode($this->domain) . '/', 20);
            $dets = null;
            $total = null;
            if (is_string($html) && preg_match('/Detections Counts.*?(\d+)\s*\/\s*(\d+)/is', $html, $m)) {
                $dets = (int) $m[1];
                $total = (int) $m[2];
            } elseif (is_string($html) && preg_match('/label-[a-z]+[^>]*>\s*(\d+)\s*\/\s*(\d+)/i', $html, $m)) {
                $dets = (int) $m[1];
                $total = (int) $m[2];
            }
            $cached = [
                'ok' => $dets !== null,
                'detections' => $dets,
                'total' => $total,
            ];
            $this->cacheSet('urlvoid', $cached, 21600);
        }

        if (empty($cached['ok'])) {
            return [
                'signal' => $this->sig(
                    'reputation',
                    'Web safety engines',
                    'Unavailable',
                    'URLVoid multi-engine lookup failed. (DNSFilter itself needs a paid API key.)',
                    'neutral'
                ),
                'delta' => 0,
            ];
        }

        $d = (int) $cached['detections'];
        $t = (int) $cached['total'];
        $value = $d . '/' . $t . ' detections';

        if ($d === 0) {
            return [
                'signal' => $this->sig(
                    'reputation',
                    'Web safety engines',
                    $value,
                    'URLVoid engines report clean — free stand-in for DNSFilter-style safety labeling.',
                    'good'
                ),
                'delta' => 5,
            ];
        }

        return [
            'signal' => $this->sig(
                'reputation',
                'Web safety engines',
                $value,
                'One or more URLVoid safety/blacklist engines flagged this host.',
                'bad'
            ),
            'delta' => -min(20, 6 + $d * 4),
        ];
    }

    private function sig(string $group, string $label, string $value, string $note, string $tone): array
    {
        return compact('group', 'label', 'value', 'note', 'tone');
    }

    private function cacheGet(string $key): ?array
    {
        $file = $this->cacheDir . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', $this->domain . '__' . $key) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || empty($payload['expires']) || $payload['expires'] < time()) {
            return null;
        }
        return is_array($payload['data'] ?? null) ? $payload['data'] : null;
    }

    private function cacheSet(string $key, array $data, int $ttl): void
    {
        $file = $this->cacheDir . '/' . preg_replace('/[^a-z0-9._-]+/i', '_', $this->domain . '__' . $key) . '.json';
        @file_put_contents($file, json_encode([
            'expires' => time() + $ttl,
            'data' => $data,
        ]));
    }

    private function httpGet(string $url, int $timeout = 12): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ScamGuardBot/1.3; +' . (defined('SITE_URL') ? SITE_URL : 'https://www.chillflix.lol/scamguard') . ')',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/json,text/plain,*/*',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            return null;
        }
        return (string) $body;
    }
}
