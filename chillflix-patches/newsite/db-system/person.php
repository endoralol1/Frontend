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

$personName = (string) ($name ?? ($person['name'] ?? 'Unknown'));
$creditCount = (int) ($creditCount ?? count($knownFor));
$deptLabel = $department !== '' ? $department : 'Entertainment';
$metaBits = [];
if ($deptLabel !== '') {
    $metaBits[] = $deptLabel;
}
if ($creditCount > 0) {
    $metaBits[] = $creditCount . ' titles';
}
if ($age !== null && $deathday === '') {
    $metaBits[] = $age . ' yrs';
}
if ($birthday !== '') {
    $metaBits[] = $birthday;
}

// Clean TMDB bio: drop wiki license footers, normalize paragraphs
$bioClean = trim((string) $biography);
$bioClean = preg_replace('/\n*Description above from the Wikipedia article[\s\S]*$/iu', '', $bioClean) ?? $bioClean;
$bioClean = preg_replace('/\n*From Wikipedia[\s\S]*$/iu', '', $bioClean) ?? $bioClean;
$bioClean = trim(preg_replace("/[ \t]+/u", ' ', $bioClean) ?? $bioClean);
$bioParagraphs = array_values(array_filter(array_map(
    static fn ($p) => trim(preg_replace("/\s*\n\s*/u", ' ', $p) ?? $p),
    preg_split("/\n{2,}/u", $bioClean) ?: []
), static fn ($p) => $p !== ''));
if (!$bioParagraphs && $bioClean !== '') {
    $bioParagraphs = [$bioClean];
}
$bioPlainLen = mb_strlen(implode(' ', $bioParagraphs));
$hasMoreBio = $bioPlainLen > 320;
?>
<main class="person-page">
    <section class="person-stage">
        <div class="person-stage-bg" aria-hidden="true">
            <div class="person-stage-img" style="background-image:url('<?= e($backdrop) ?>');"></div>
            <div class="person-stage-veil"></div>
        </div>

        <div class="container person-stage-inner">
            <div class="person-hero">
                <div class="person-portrait">
                    <img src="<?= e($profile) ?>" alt="<?= e($personName) ?>" width="160" height="240" loading="eager" decoding="async" fetchpriority="high">
                </div>
                <div class="person-hero-copy">
                    <p class="person-eyebrow">Cast &amp; Crew</p>
                    <h1 class="person-name"><?= e($personName) ?></h1>
                    <?php if ($metaBits): ?>
                    <p class="person-meta-line"><?= e(implode(' · ', $metaBits)) ?></p>
                    <?php endif; ?>
                    <?php if ($birthPlace !== ''): ?>
                    <p class="person-place"><i class="uil uil-map-marker" aria-hidden="true"></i> <?= e($birthPlace) ?></p>
                    <?php endif; ?>
                    <?php if ($imdbUrl !== '' || $homepage !== ''): ?>
                    <div class="person-actions">
                        <?php if ($imdbUrl !== ''): ?>
                        <a class="person-action is-primary" href="<?= e($imdbUrl) ?>" target="_blank" rel="noopener noreferrer">
                            <i class="uil uil-external-link-alt" aria-hidden="true"></i> IMDb
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
        <section class="section person-bio-section">
            <div class="head">
                <div class="start">
                    <h2 class="title gardiently">Biography</h2>
                </div>
            </div>
            <div class="body">
                <?php if ($bioParagraphs): ?>
                <div class="person-bio<?= $hasMoreBio ? ' is-clamp' : '' ?>" id="person-bio">
                    <?php foreach ($bioParagraphs as $para): ?>
                    <p><?= e($para) ?></p>
                    <?php endforeach; ?>
                </div>
                <?php if ($hasMoreBio): ?>
                <button type="button" class="person-bio-more" id="person-bio-more" aria-expanded="false" aria-controls="person-bio">
                    Read full biography
                </button>
                <?php endif; ?>
                <?php else: ?>
                <p class="person-bio-empty">No biography available yet.</p>
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
    btn.textContent = open ? 'Show less' : 'Read full biography';
  });
})();
</script>
