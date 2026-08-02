<?php $headerClass = 'relative'; $site = (string) config('site_name'); ?>
<main class="cf-form-page page-pad-top">
    <div class="container">
        <section class="cf-form-shell" aria-labelledby="request-title">
            <header class="cf-form-head">
                <p class="cf-form-kicker">Missing something?</p>
                <h1 id="request-title" class="cf-form-title">Request a title</h1>
                <p class="cf-form-lead">Tell us the movie or show you want on <?= e($site) ?>. We review requests regularly.</p>
            </header>

            <?php if (!empty($sent)): ?>
                <div class="cf-form-alert cf-form-alert--ok" role="status">
                    <i class="uil uil-check-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Request received</strong>
                        <span>Thanks — we’ll look into adding it soon.</span>
                    </div>
                </div>
            <?php endif; ?>

            <form class="cf-form" method="post" action="<?= e(url('/request')) ?>" autocomplete="on">
                <fieldset class="cf-form-type">
                    <legend>Type</legend>
                    <div class="cf-form-type-row" role="radiogroup" aria-label="Request type">
                        <label class="cf-form-type-opt">
                            <input type="radio" name="type" value="movie" checked>
                            <span><i class="uil uil-clapper-board" aria-hidden="true"></i> Movie</span>
                        </label>
                        <label class="cf-form-type-opt">
                            <input type="radio" name="type" value="tv">
                            <span><i class="uil uil-tv-retro" aria-hidden="true"></i> TV Show</span>
                        </label>
                    </div>
                </fieldset>

                <label class="cf-field">
                    <span class="cf-field-label">Title</span>
                    <input class="cf-field-input" type="text" name="title" required maxlength="160" placeholder="e.g. Dune: Part Two" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Year <em>(optional)</em></span>
                    <input class="cf-field-input" type="text" name="year" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="2024" enterkeyhint="next">
                </label>

                <label class="cf-field">
                    <span class="cf-field-label">Notes <em>(optional)</em></span>
                    <textarea class="cf-field-input cf-field-input--area" name="notes" rows="4" maxlength="800" placeholder="Season, language, or anything that helps us find it"></textarea>
                </label>

                <button type="submit" class="cf-form-submit">
                    <i class="uil uil-message" aria-hidden="true"></i>
                    Submit request
                </button>
            </form>
        </section>
    </div>
</main>
