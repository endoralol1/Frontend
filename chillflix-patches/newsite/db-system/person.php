<?php
/** @var array $person */
/** @var list<array<string,mixed>> $knownFor */
/** @var string $name */
/** @var string $profile */
/** @var string $backdrop */
/** @var string $department */
/** @var string $birthday */
/** @var string $deathday */
/** @var string $birthPlace */
/** @var string $biography */
/** @var int|null $age */
/** @var string $imdbUrl */
/** @var string $homepage */
/** @var int $creditCount */

$headerClass = $headerClass ?? 'relative';
$hasMoreBio = $biography !== '' && mb_strlen($biography) > 380;
$creditCount = (int) ($creditCount ?? count($knownFor));
$deptLabel = $department !== '' ? $department : 'Entertainment';
?>
<main class="person-page">
    <section class="person-stage">
        <div class="person-stage-bg" aria-hidden="true">
            <div class="person-stage-img" style="background-image:url('<?= e($backdrop) ?>');"></div>
            <div class="person-stage-veil"></div>
            <div class="person-stage-glow"></div>
        </div>

        <div class="container person-stage-inner">
            <a class="person-back" href="javascript:history.back()" aria-label="Go back">
                <i class="uil uil-arrow-left" aria-hidden="true"></i>
                <span>Back</span>
            </a>

            <div class="person-hero-grid">
                <div class="person-portrait">
                    <div class="person-portrait-ring">
                        <img src="<?= e($profile) ?>" alt="<?= e($name) ?>" width="360" height="540" loading="eager" decoding="async" fetchpriority="high">
                    </div>
                </div>

                <div class="person-hero-copy">
                    <p class="person-eyebrow">
                        <span class="person-eyebrow-dot" aria-hidden="true"></span>
                        Cast &amp; Crew
                    </p>
                    <h1 class="person-name"><?= e($name) ?></h1>

                    <div class="person-chip-row" role="list">
                        <span class="person-chip is-accent" role="listitem"><?= e($deptLabel) ?></span>
                        <?php if ($creditCount > 0): ?>
                        <span class="person-chip" role="listitem"><?= (int) $creditCount ?> titles</span>
                        <?php endif; ?>
                        <?php if ($age !== null && $deathday === ''): ?>
                        <span class="person-chip" role="listitem"><?= (int) $age ?> years old</span>
                        <?php endif; ?>
                        <?php if ($birthday !== ''): ?>
                        <span class="person-chip is-soft" role="listitem"><i class="uil uil-calender" aria-hidden="true"></i> <?= e($birthday) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($birthPlace !== '' || $deathday !== ''): ?>
                    <div class="person-facts">
                        <?php if ($birthPlace !== ''): ?>
                        <div class="person-fact">
                            <span class="person-fact-label">Born in</span>
                            <strong><?= e($birthPlace) ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if ($deathday !== ''): ?>
                        <div class="person-fact">
                            <span class="person-fact-label">Passed</span>
                            <strong><?= e($deathday) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($imdbUrl !== '' || $homepage !== ''): ?>
                    <div class="person-actions">
                        <?php if ($imdbUrl !== ''): ?>
                        <a class="person-action is-primary" href="<?= e($imdbUrl) ?>" target="_blank" rel="noopener noreferrer">
                            <i class="uil uil-external-link-alt" aria-hidden="true"></i> View on IMDb
                        </a>
                        <?php endif; ?>
                        <?php if ($homepage !== ''): ?>
                        <a class="person-action" href="<?= e($homepage) ?>" target="_blank" rel="noopener noreferrer">
                            <i class="uil uil-globe" aria-hidden="true"></i> Website
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="container person-body">
        <section class="person-bio-panel">
            <div class="person-bio-head">
                <h2>Biography</h2>
                <?php if ($department !== ''): ?>
                <span class="person-bio-tag"><?= e($department) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($biography !== ''): ?>
            <div class="person-bio<?= $hasMoreBio ? ' is-clamp' : '' ?>" id="person-bio">
                <?= nl2br(e($biography)) ?>
            </div>
            <?php if ($hasMoreBio): ?>
            <button type="button" class="person-bio-more" id="person-bio-more" aria-expanded="false" aria-controls="person-bio">
                Read full biography
            </button>
            <?php endif; ?>
            <?php else: ?>
            <p class="person-bio-empty">No biography available for this person yet.</p>
            <?php endif; ?>
        </section>

        <?php if ($knownFor): ?>
        <section class="section person-known-for">
            <div class="head person-known-head">
                <div class="start">
                    <h2 class="title gardiently">Known for</h2>
                    <p class="person-known-sub">Top titles from their filmography</p>
                </div>
                <span class="person-known-count"><?= count($knownFor) ?></span>
            </div>
            <div class="body">
                <div class="scaff movies items person-known-grid">
                    <?php foreach ($knownFor as $i => $credit) {
                        $item = $credit;
                        $type = (($credit['media_type'] ?? '') === 'tv') ? 'tv' : 'movie';
                        $rank = null;
                        echo '<div class="person-credit-wrap" style="--cf-i:' . (int) $i . '">';
                        require __DIR__ . '/../partials/movie-card.php';
                        $role = (string) ($credit['character'] ?? $credit['job'] ?? '');
                        if ($role !== '') {
                            echo '<div class="person-credit-role">' . e($role) . '</div>';
                        }
                        echo '</div>';
                    } ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>
</main>
<script>
(function () {
  var btn = document.getElementById('person-bio-more');
  var bio = document.getElementById('person-bio');
  if (!btn || !bio) return;
  btn.addEventListener('click', function () {
    var open = bio.classList.toggle('is-open');
    bio.classList.toggle('is-clamp', !open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.textContent = open ? 'Show less' : 'Read full biography';
  });
})();
</script>
