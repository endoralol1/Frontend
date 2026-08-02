<?php
/** @var string $type */
/** @var array $item */
/** @var string $title */
/** @var string $backUrl */
/** @var string $backdrop */
/** @var int $season */
/** @var int $episode */
/** @var array|null $nextEpisode */
?>
<div class="np-shell" id="np-shell">
    <div class="np-stage">
        <video id="np-video" class="np-video" playsinline webkit-playsinline></video>
        <div class="np-poster" id="np-poster" style="background-image:url('<?= e($backdrop) ?>')"></div>
        <div class="np-subs" id="np-subs" aria-live="polite"></div>

        <div class="np-top">
            <a class="np-back" href="<?= e($backUrl) ?>" aria-label="Back">
                <span>←</span><span>Details</span>
            </a>
            <div class="np-titleblock">
                <h1 class="np-title"><?= e($title) ?></h1>
                <?php if ($type === 'tv'): ?>
                <p class="np-meta">S<?= (int) $season ?> · E<?= (int) $episode ?></p>
                <?php elseif (!empty($year)): ?>
                <p class="np-meta"><?= e((string) $year) ?></p>
                <?php endif; ?>
            </div>
            <div class="np-top-actions">
                <button type="button" class="np-chip" id="np-settings-btn" aria-haspopup="dialog">Settings</button>
            </div>
        </div>

        <div class="np-center" id="np-center">
            <button type="button" class="np-playbig" id="np-playbig" aria-label="Play">▶</button>
            <p class="np-status" id="np-status">Loading sources…</p>
        </div>

        <div class="np-bottom" id="np-controls">
            <div class="np-progress-wrap">
                <input type="range" id="np-progress" class="np-progress" min="0" max="1000" value="0" step="1" aria-label="Seek">
            </div>
            <div class="np-bar">
                <div class="np-bar-left">
                    <button type="button" class="np-btn" id="np-play" aria-label="Play/Pause">▶</button>
                    <button type="button" class="np-btn np-mute-btn" id="np-mute" aria-label="Mute"></button>
                    <span class="np-time" id="np-time">0:00 / 0:00</span>
                </div>
                <div class="np-bar-right">
                    <?php if ($type === 'tv' && !empty($nextEpisode['url'])): ?>
                    <a class="np-btn" id="np-next" href="<?= e($nextEpisode['url']) ?>" title="Next episode">Next</a>
                    <?php endif; ?>
                    <button type="button" class="np-btn" id="np-source-btn" title="Source">Src</button>
                    <button type="button" class="np-btn" id="np-fs" aria-label="Fullscreen">⛶</button>
                </div>
            </div>
        </div>
    </div>

    <aside class="np-panel" id="np-panel" hidden>
        <div class="np-panel-head">
            <h2>Settings</h2>
            <button type="button" class="np-btn" id="np-panel-close" aria-label="Close">✕</button>
        </div>
        <div class="np-panel-tabs" id="np-panel-tabs">
            <button type="button" data-tab="source" class="is-active">Source</button>
            <button type="button" data-tab="quality">Quality</button>
            <button type="button" data-tab="audio">Audio</button>
            <button type="button" data-tab="subs">Subs</button>
        </div>
        <div class="np-panel-body">
            <section data-panel="source" class="is-active"><div class="np-list" id="np-source-list"></div></section>
            <section data-panel="quality"><div class="np-list" id="np-quality-list"></div></section>
            <section data-panel="audio"><div class="np-list" id="np-audio-list"></div></section>
            <section data-panel="subs">
                <div class="np-list" id="np-sub-list"></div>
                <div class="np-sliders">
                    <label><span>Delay</span><input type="range" id="np-delay" min="-10" max="10" step="0.1" value="0"><span class="np-slider-val" id="np-delay-val">0.0s</span></label>
                    <label><span>Size</span><input type="range" id="np-size" min="0.75" max="1.75" step="0.05" value="1"><span class="np-slider-val" id="np-size-val">100%</span></label>
                    <label><span>BG</span><input type="range" id="np-bg" min="0" max="0.9" step="0.05" value="0.7"><span class="np-slider-val" id="np-bg-val">70%</span></label>
                </div>
            </section>
        </div>
    </aside>
</div>
