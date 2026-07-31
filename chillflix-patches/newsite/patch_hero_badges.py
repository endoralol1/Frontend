#!/usr/bin/env python3
"""Add TMDB title logos + #Today / HOT / Newly launched badges to newsite hero."""
from __future__ import annotations

from pathlib import Path

TMDB = Path("/var/www/chillflix-newsite/app/Services/Tmdb.php")
ROUTES = Path("/var/www/chillflix-newsite/app/routes.php")
HOME = Path("/var/www/chillflix-newsite/app/Views/pages/home.php")
APP_CSS = Path("/var/www/chillflix-newsite/public/assets/css/app.css")
LAYOUT = Path("/var/www/chillflix-newsite/app/Views/layouts/main.php")


def patch_tmdb() -> None:
    text = TMDB.read_text()
    marker = "public function trailerKey(?array $details): ?string"
    if "function pickPreferredLogo" in text:
        print("Tmdb already has pickPreferredLogo")
        return
    insert = r'''
    /**
     * Prefer English / language-neutral title logos (matches main Chillflix hero).
     */
    public function pickPreferredLogo(?array $logos): ?string
    {
        if (!$logos) {
            return null;
        }
        $score = static function (array $logo): float {
            return ((float) ($logo['vote_average'] ?? 0)) * 1000 + ((float) ($logo['vote_count'] ?? 0));
        };
        $pick = static function (array $list) use ($score): ?array {
            if (!$list) {
                return null;
            }
            usort($list, static fn ($a, $b) => $score($b) <=> $score($a));
            return $list[0] ?? null;
        };
        $en = [];
        $neutral = [];
        foreach ($logos as $logo) {
            $lang = $logo['iso_639_1'] ?? null;
            if ($lang === 'en') {
                $en[] = $logo;
            } elseif ($lang === null || $lang === '') {
                $neutral[] = $logo;
            }
        }
        $best = $pick($en) ?? $pick($neutral) ?? $pick($logos);
        $path = $best['file_path'] ?? null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Enrich trending slides with logo, runtime, and status badges for the home hero.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function enrichHeroSlides(array $items, int $limit = 5): array
    {
        $out = [];
        $rank = 0;
        foreach (array_slice($items, 0, $limit) as $item) {
            $rank++;
            $id = (int) ($item['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $details = $this->get("movie/{$id}", [
                'append_to_response' => 'images',
                'include_image_language' => 'en,null',
            ]) ?? [];
            $release = (string) ($details['release_date'] ?? $item['release_date'] ?? '');
            $isNew = false;
            if ($release !== '') {
                $ts = strtotime($release . ' UTC');
                if ($ts !== false) {
                    $diffDays = (time() - $ts) / 86400;
                    $isNew = $diffDays >= -21 && $diffDays <= 45;
                }
            }
            $item['logo_path'] = $this->pickPreferredLogo($details['images']['logos'] ?? null);
            $item['runtime'] = (int) ($details['runtime'] ?? 0);
            $item['trending_rank'] = $rank;
            $item['is_hot'] = $rank <= 5 || ((float) ($item['popularity'] ?? 0)) >= 120;
            $item['is_newly_launched'] = $isNew;
            if (!empty($details['overview'])) {
                $item['overview'] = $details['overview'];
            }
            if (!empty($details['backdrop_path'])) {
                $item['backdrop_path'] = $details['backdrop_path'];
            }
            $out[] = $item;
        }
        return $out;
    }

    ''' + marker
    if marker not in text:
        raise SystemExit("trailerKey marker missing")
    TMDB.write_text(text.replace(marker, insert, 1))
    print("patched Tmdb.php")


def patch_routes() -> None:
    text = ROUTES.read_text()
    old = """    $trendingMoviesWeek = $tmdb->trending('movie', 'week');
    $featured = array_slice($trendingMoviesWeek, 0, 5);
    $top10Movies = array_slice($trendingMoviesWeek, 0, 10);"""
    new = """    $trendingMoviesDay = $tmdb->trending('movie', 'day');
    $trendingMoviesWeek = $tmdb->trending('movie', 'week');
    // Hero badges (#N Today / HOT / Newly launched) use daily trending + TMDB logos.
    $featured = $tmdb->enrichHeroSlides($trendingMoviesDay ?: $trendingMoviesWeek, 5);
    $top10Movies = array_slice($trendingMoviesWeek, 0, 10);"""
    if old not in text:
        if "enrichHeroSlides" in text:
            print("routes already patched")
            return
        raise SystemExit("home featured block not found")
    ROUTES.write_text(text.replace(old, new, 1))
    print("patched routes.php")


def patch_home() -> None:
    text = HOME.read_text()
    old = """            <?php foreach ($featured as $i => $slide):
                $t = title_of($slide);
                $y = year_of(date_of($slide));
                $href = media_url('movie', (int) $slide['id'], $t);
                /* w1280 looks sharp full-bleed; original (2K–4K) was a major decode cost */
                $bg = img_url($slide['backdrop_path'] ?? $slide['poster_path'] ?? null, 'w1280');
                $rating = isset($slide['vote_average']) ? round((float) $slide['vote_average'], 1) : null;
                $desc = truncate((string) ($slide['overview'] ?? ''), 160);
                $heroStyle = 'background-color:#0a0c12;';
                if ((int) $i === 0) {
                    $heroStyle .= 'background-image:url(' . e($bg) . ');';
                }
            ?>
            <div class="swiper-slide<?= (int) $i === 0 ? ' lazyloaded' : ' lazyload' ?>"<?php if ((int) $i !== 0): ?> data-bgset="<?= e($bg) ?>"<?php endif; ?> style="<?= $heroStyle ?>">
                <div class="wrapper">
                    <div class="info">
                        <div class="hero-kicker">Now Featured</div>
                        <div class="name"><?= e($t) ?></div>
                        <div class="meta">
                            <span class="rating status-icon">Movie</span>
                            <?php if ($rating): ?><span class="hero-chip"><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
                            <?php if ($y): ?><span class="hero-chip"><i class="uil uil-calender"></i> <?= e($y) ?></span><?php endif; ?>
                        </div>
                        <div class="desc"><?= e($desc) ?></div>
                        <div class="action">
                            <div>
                                <a href="<?= e($href) ?>" class="btn btn-primary btn-lg hero-watch-btn">
                                    <i class="uil uil-play"></i><span>Watch Now</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>"""

    new = """            <?php foreach ($featured as $i => $slide):
                $t = title_of($slide);
                $y = year_of(date_of($slide));
                $href = media_url('movie', (int) $slide['id'], $t);
                /* w1280 looks sharp full-bleed; original (2K–4K) was a major decode cost */
                $bg = img_url($slide['backdrop_path'] ?? $slide['poster_path'] ?? null, 'w1280');
                $rating = isset($slide['vote_average']) ? round((float) $slide['vote_average'], 1) : null;
                $desc = truncate((string) ($slide['overview'] ?? ''), 160);
                $logoPath = $slide['logo_path'] ?? null;
                $logo = $logoPath ? img_url($logoPath, 'w500') : null;
                $runtimeMins = (int) ($slide['runtime'] ?? 0);
                $runtimeLabel = '';
                if ($runtimeMins > 0) {
                    $rh = intdiv($runtimeMins, 60);
                    $rm = $runtimeMins % 60;
                    $runtimeLabel = $rh > 0 ? ($rh . 'h ' . str_pad((string) $rm, 2, '0', STR_PAD_LEFT) . 'm') : ($rm . 'm');
                }
                $rank = (int) ($slide['trending_rank'] ?? ((int) $i + 1));
                $isHot = !empty($slide['is_hot']);
                $isNew = !empty($slide['is_newly_launched']);
                $heroStyle = 'background-color:#0a0c12;';
                if ((int) $i === 0) {
                    $heroStyle .= 'background-image:url(' . e($bg) . ');';
                }
            ?>
            <div class="swiper-slide<?= (int) $i === 0 ? ' lazyloaded' : ' lazyload' ?>"<?php if ((int) $i !== 0): ?> data-bgset="<?= e($bg) ?>"<?php endif; ?> style="<?= $heroStyle ?>">
                <div class="wrapper">
                    <div class="info">
                        <?php if ($logo): ?>
                        <div class="hero-logo">
                            <img src="<?= e($logo) ?>" alt="<?= e($t) ?>" width="420" height="130" decoding="<?= (int) $i === 0 ? 'sync' : 'async' ?>"<?= (int) $i === 0 ? ' fetchpriority="high"' : '' ?>>
                        </div>
                        <?php else: ?>
                        <div class="name"><?= e($t) ?></div>
                        <?php endif; ?>
                        <div class="meta">
                            <?php if ($rating): ?><span class="hero-chip"><i class="uil uil-star"></i> <?= e((string) $rating) ?></span><?php endif; ?>
                            <?php if ($y): ?><span class="hero-chip"><i class="uil uil-calender"></i> <?= e($y) ?></span><?php endif; ?>
                            <?php if ($runtimeLabel): ?><span class="hero-chip"><i class="uil uil-clock"></i> <?= e($runtimeLabel) ?></span><?php endif; ?>
                        </div>
                        <?php if (($rank > 0 && $rank <= 10) || $isHot || $isNew): ?>
                        <div class="hero-badges">
                            <?php if ($rank > 0 && $rank <= 10): ?>
                            <span class="hero-badge hero-badge-today"><span class="hero-badge-rank">#<?= (int) $rank ?></span><span>Today</span></span>
                            <?php endif; ?>
                            <?php if ($isHot): ?>
                            <span class="hero-badge hero-badge-hot"><i class="uil uil-fire"></i><span>HOT</span></span>
                            <?php endif; ?>
                            <?php if ($isNew): ?>
                            <span class="hero-badge hero-badge-new"><span class="hero-badge-new-label">NEW</span><span>Newly launched</span></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="desc"><?= e($desc) ?></div>
                        <div class="action">
                            <div>
                                <a href="<?= e($href) ?>" class="btn btn-primary btn-lg hero-watch-btn">
                                    <i class="uil uil-play"></i><span>Watch Now</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>"""

    if old not in text:
        if "hero-badges" in text:
            print("home already patched")
            return
        raise SystemExit("home featured loop not found")
    HOME.write_text(text.replace(old, new, 1))
    print("patched home.php")


def patch_css() -> None:
    css = APP_CSS.read_text()
    marker = "/* hero status badges */"
    if marker in css:
        print("css already has hero badges")
        return
    css += """

/* hero status badges */
#featured .info .hero-logo {
  margin-bottom: 0.85rem;
  max-width: min(78vw, 26rem);
}
#featured .info .hero-logo img {
  display: block;
  width: auto;
  max-width: 100%;
  max-height: 5.5rem;
  height: auto;
  object-fit: contain;
  object-position: left center;
  filter: drop-shadow(0 4px 18px rgba(0, 0, 0, 0.75));
}
@media (min-width: 768px) {
  #featured .info .hero-logo img {
    max-height: 7.25rem;
  }
}
#featured .info .hero-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0.75rem 0 0.35rem;
}
#featured .info .hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: #fff;
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1;
  backdrop-filter: none;
}
#featured .info .hero-badge-rank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.15rem;
  height: 1.15rem;
  border-radius: 999px;
  background: #fff;
  color: #000;
  font-size: 0.62rem;
  font-weight: 800;
}
#featured .info .hero-badge-hot i {
  color: #ff4d2e;
  font-size: 0.95rem;
}
#featured .info .hero-badge-hot span {
  letter-spacing: 0.04em;
  font-weight: 800;
}
#featured .info .hero-badge-new-label {
  font-style: italic;
  font-weight: 800;
}
"""
    APP_CSS.write_text(css)
    print("patched app.css")


def bump_assets() -> None:
    text = LAYOUT.read_text()
    for old, new in [
        ("?v=20260731-watchpolish1", "?v=20260731-herobadges1"),
        ("?v=20260731-sidedupe1", "?v=20260731-herobadges1"),
    ]:
        if old in text:
            LAYOUT.write_text(text.replace(old, new))
            print(f"bumped {old} -> {new}")
            return
    if "herobadges1" in text:
        print("asset version already bumped")
        return
    print("WARN: asset version string not found")


if __name__ == "__main__":
    patch_tmdb()
    patch_routes()
    patch_home()
    patch_css()
    bump_assets()
    print("done")
