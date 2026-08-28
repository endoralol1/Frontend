<?php
/** @var list<array<string,mixed>> $articles */
/** @var string $countryCode */

if (!function_exists('n24_img_src')) {
    function n24_img_src(array $article): string
    {
        $image = (string) ($article['image'] ?? '');
        if ($image === '') {
            return NewsFeed::placeholder((string) $article['id']);
        }
        if (str_starts_with($image, 'data:')) {
            return $image;
        }
        return url('/api/news/image?u=' . rawurlencode($image));
    }
}

if (!function_exists('s24_badge')) {
    function s24_badge(array $article): string
    {
        $isLive = !empty($article['isLive']) || str_contains(strtolower((string) ($article['url'] ?? '')), '/live/');
        if ($isLive) {
            return 'LIVE';
        }
        $cat = strtoupper(trim((string) ($article['category'] ?? '')));
        $map = [
            'WORLD' => 'NEWS', 'EUROPE' => 'NEWS', 'POLITICS' => 'NEWS',
            'SPORT' => 'SPORT', 'SPORTS' => 'SPORT',
            'SCI/TECH' => 'SCI/TECH', 'TECH' => 'SCI/TECH', 'TECHNOLOGY' => 'SCI/TECH', 'SCIENCE' => 'SCI/TECH',
            'BUSINESS' => 'EXPRESS',
            'SHOW' => 'SHOW', 'ENTERTAINMENT' => 'SHOW',
            'LIFE' => 'LIFE&STYLE', 'HEALTH' => 'LIFE&STYLE',
            'VIRAL' => 'VIRAL', 'LIVE' => 'LIVE',
        ];
        if ($cat === '' || $cat === 'NEWS' || $cat === 'TOP') {
            $cat = class_exists('NewsArticleReader')
                ? NewsArticleReader::inferSectionLabel((string) ($article['url'] ?? ''), 'WORLD')
                : 'WORLD';
        }
        return $map[$cat] ?? $cat;
    }
}

if (!function_exists('s24_badge_color')) {
    function s24_badge_color(string $badge): string
    {
        return match ($badge) {
            'LIVE', 'NEWS' => '#d7060c',
            'SHOW' => '#f5a528',
            'SPORT' => '#40b14d',
            'LIFE&STYLE' => '#efc10d',
            'SCI/TECH' => '#47c0ff',
            'VIDEO' => '#9757f6',
            'EXPRESS' => '#e53935',
            'VIRAL' => '#297af6',
            default => '#d7060c',
        };
    }
}

if (!function_exists('s24_headline')) {
    function s24_headline(string $title): array
    {
        $title = trim($title);
        if (preg_match('/^([A-ZČĆŽŠĐ]{3,16})\s+(.+)$/u', $title, $m)) {
            return [$m[1], $m[2]];
        }
        return ['', $title];
    }
}

$lead = $articles[0] ?? null;
$rest = array_slice($articles, 1);
$mosaic = array_slice($rest, 0, 3);
$more = array_slice($rest, 3);
?>
<main class="s24-main">
    <?php if (!$lead): ?>
        <div class="s24-empty">No stories right now. Try another country or refresh in a minute.</div>
    <?php else: ?>
        <?php
        $leadBadge = s24_badge($lead);
        [$shout, $restTitle] = s24_headline((string) $lead['title']);
        ?>
        <article class="s24-card s24-card--lead">
            <a class="s24-card-link" href="<?= e(NewsFeed::articleUrl($lead, $countryCode)) ?>">
                <div class="s24-card-media">
                    <img src="<?= e(n24_img_src($lead)) ?>" alt="" loading="eager">
                    <span class="s24-card-label" style="background:<?= e(s24_badge_color($leadBadge)) ?>"><?= e($leadBadge) ?></span>
                </div>
                <div class="s24-card-body">
                    <h1 class="s24-card-title">
                        <?php if ($shout !== ''): ?><span class="s24-shout"><?= e($shout) ?></span> <?php endif; ?>
                        <?= e($restTitle) ?>
                    </h1>
                    <?php if (!empty($lead['summary'])): ?>
                        <p class="s24-card-lead"><?= e((string) $lead['summary']) ?></p>
                    <?php endif; ?>
                </div>
            </a>
            <div class="s24-engage" aria-hidden="true">
                <div class="s24-engage-likes">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#2f80ed" d="M2 21h4V9H2v12zm20-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L13.17 1 6.59 7.59C6.22 7.95 6 8.45 6 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
                    <span class="s24-emos">
                        <svg viewBox="0 0 24 24" width="16" height="16"><circle fill="#f2c94c" cx="12" cy="12" r="10"/><path fill="#333" d="M8.5 14.5c.8 1.2 2 2 3.5 2s2.7-.8 3.5-2"/><circle fill="#333" cx="8.5" cy="10" r="1.2"/><circle fill="#333" cx="15.5" cy="10" r="1.2"/></svg>
                        <svg viewBox="0 0 24 24" width="16" height="16"><path fill="#eb5757" d="M12 21s-7-4.4-9.5-8.2C.5 9.5 2.2 6 5.6 6c1.9 0 3.4 1.1 4.2 2.3C10.6 7.1 12.1 6 14 6c3.4 0 5.1 3.5 3.1 6.8C19 16.6 12 21 12 21z"/></svg>
                    </span>
                    <span>3</span>
                </div>
                <div class="s24-engage-mid">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2"><path d="M4 6h16v10H8l-4 3V6z"/></svg>
                    <span>6</span>
                </div>
                <div class="s24-engage-share">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2"><path d="M14 7l6 5-6 5V7zM4 12h14"/></svg>
                </div>
            </div>
        </article>

        <?php if (count($mosaic) >= 3): ?>
            <?php $m0 = $mosaic[0]; $mBadge = s24_badge($m0); ?>
            <section class="s24-mosaic">
                <a class="s24-mosaic-main" href="<?= e(NewsFeed::articleUrl($m0, $countryCode)) ?>">
                    <img src="<?= e(n24_img_src($m0)) ?>" alt="" loading="lazy">
                    <span class="s24-card-label" style="background:<?= e(s24_badge_color($mBadge)) ?>"><?= e($mBadge) ?></span>
                </a>
                <div class="s24-mosaic-side">
                    <?php foreach (array_slice($mosaic, 1, 2) as $side): ?>
                        <a href="<?= e(NewsFeed::articleUrl($side, $countryCode)) ?>">
                            <img src="<?= e(n24_img_src($side)) ?>" alt="" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <a class="s24-mosaic-title" href="<?= e(NewsFeed::articleUrl($m0, $countryCode)) ?>">
                <h2 class="s24-card-title"><?= e((string) $m0['title']) ?></h2>
            </a>
        <?php endif; ?>

        <?php foreach ($more as $i => $article):
            $badge = s24_badge($article);
            [$shout, $restTitle] = s24_headline((string) $article['title']);
        ?>
            <article class="s24-card">
                <a class="s24-card-link" href="<?= e(NewsFeed::articleUrl($article, $countryCode)) ?>">
                    <div class="s24-card-media s24-card-media--round">
                        <img src="<?= e(n24_img_src($article)) ?>" alt="" loading="lazy">
                        <span class="s24-card-label" style="background:<?= e(s24_badge_color($badge)) ?>"><?= e($badge) ?></span>
                    </div>
                    <div class="s24-card-body">
                        <h2 class="s24-card-title">
                            <?php if ($shout !== ''): ?><span class="s24-shout"><?= e($shout) ?></span> <?php endif; ?>
                            <?= e($restTitle) ?>
                        </h2>
                    </div>
                </a>
                <div class="s24-engage" aria-hidden="true">
                    <div class="s24-engage-likes">
                        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#2f80ed" d="M2 21h4V9H2v12zm20-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L13.17 1 6.59 7.59C6.22 7.95 6 8.45 6 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>
                        <span><?= 1 + (($i * 7) % 20) ?></span>
                    </div>
                    <div class="s24-engage-mid">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2"><path d="M4 6h16v10H8l-4 3V6z"/></svg>
                        <span><?= ($i * 3) % 12 ?></span>
                    </div>
                    <div class="s24-engage-share">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#888" stroke-width="2"><path d="M14 7l6 5-6 5V7zM4 12h14"/></svg>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
