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

$headerClass = $headerClass ?? 'relative';
$hasMoreBio = $biography !== '' && mb_strlen($biography) > 420;
?>
<main class="page-pad-top person-page">
    <div class="person-hero" aria-hidden="true">
        <div class="person-hero-bg" style="background-image:url('<?= e($backdrop) ?>');"></div>
        <div class="person-hero-shade"></div>
    </div>

    <div class="container person-wrap">
        <section class="person-panel">
            <div class="person-profile">
                <div class="person-photo">
                    <img src="<?= e($profile) ?>" alt="<?= e($name) ?>" width="300" height="450" loading="eager" decoding="async">
                </div>
                <div class="person-side-meta">
                    <?php if ($department !== ''): ?>
                    <div class="person-side-row">
                        <span class="person-side-label">Known for</span>
                        <span><?= e($department) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($birthday !== ''): ?>
                    <div class="person-side-row">
                        <span class="person-side-label">Born</span>
                        <span>
                            <?= e($birthday) ?>
                            <?php if ($age !== null && $deathday === ''): ?>
                            <em class="person-age">(<?= (int) $age ?>)</em>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($deathday !== ''): ?>
                    <div class="person-side-row">
                        <span class="person-side-label">Died</span>
                        <span><?= e($deathday) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($birthPlace !== ''): ?>
                    <div class="person-side-row">
                        <span class="person-side-label">Place of birth</span>
                        <span><?= e($birthPlace) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($imdbUrl !== '' || $homepage !== ''): ?>
                    <div class="person-ext-links">
                        <?php if ($imdbUrl !== ''): ?>
                        <a class="person-ext-link" href="<?= e($imdbUrl) ?>" target="_blank" rel="noopener noreferrer">
                            <i class="uil uil-external-link-alt" aria-hidden="true"></i> IMDb
                        </a>
                        <?php endif; ?>
                        <?php if ($homepage !== ''): ?>
                        <a class="person-ext-link" href="<?= e($homepage) ?>" target="_blank" rel="noopener noreferrer">
                            <i class="uil uil-globe" aria-hidden="true"></i> Website
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="person-main">
                <p class="person-kicker">Cast &amp; Crew</p>
                <h1 class="person-name"><?= e($name) ?></h1>

                <?php if ($biography !== ''): ?>
                <div class="person-bio-block">
                    <h2 class="person-section-title">Biography</h2>
                    <div class="person-bio<?= $hasMoreBio ? ' is-clamp' : '' ?>" id="person-bio">
                        <?= nl2br(e($biography)) ?>
                    </div>
                    <?php if ($hasMoreBio): ?>
                    <button type="button" class="person-bio-more" id="person-bio-more" aria-expanded="false" aria-controls="person-bio">
                        Read more
                    </button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="person-bio-block">
                    <h2 class="person-section-title">Biography</h2>
                    <p class="person-bio-empty text-muted">No biography available for this person yet.</p>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($knownFor): ?>
        <section class="section person-known-for">
            <div class="head">
                <div class="start">
                    <h2 class="title gardiently">Known for</h2>
                </div>
            </div>
            <div class="body">
                <div class="scaff movies items person-known-grid">
                    <?php foreach ($knownFor as $credit) {
                        $item = $credit;
                        $type = (($credit['media_type'] ?? '') === 'tv') ? 'tv' : 'movie';
                        $rank = null;
                        require __DIR__ . '/../partials/movie-card.php';
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
    btn.textContent = open ? 'Show less' : 'Read more';
  });
})();
</script>
