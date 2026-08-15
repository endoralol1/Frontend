                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label" data-i18n="browse.language"><?= e(t('browse.language')) ?></h4>
                        <?php $uiLang = class_exists('Locale') ? Locale::current() : 'en'; ?>
                        <div class="browse-settings-langs" id="browse-settings-langs" role="listbox" aria-label="<?= e(t('browse.language')) ?>">
                            <?php foreach ((class_exists('Locale') ? Locale::LABELS : ['en' => 'English']) as $code => $label): ?>
                            <button type="button" class="browse-settings-lang<?= $uiLang === $code ? ' is-active' : '' ?>" data-lang="<?= e($code) ?>" role="option" aria-selected="<?= $uiLang === $code ? 'true' : 'false' ?>"><?= e($label) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label">Performance</h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong>Performance mode</strong>
                                <em>Fewer animations and effects for smoother browsing on slower devices.</em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-performance" role="switch" aria-checked="false" aria-label="Performance mode"></button>
                        </div>
                    </section>

<section class="browse-settings-block">
                        <h4 class="browse-settings-label"><?= e(t('browse.playback')) ?></h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong data-i18n="browse.autoplay_short"><?= e(t('browse.autoplay_short')) ?></strong>
                                <em data-i18n="browse.autoplay_help"><?= e(t('browse.autoplay_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autoplay" role="switch" aria-checked="false" aria-label="<?= e(t('browse.autoplay_short')) ?>"></button>
                        </div>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong><?= e(t('browse.autonext_short')) ?></strong>
                                <em><?= e(t('browse.autonext_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-autonext" role="switch" aria-checked="false" aria-label="<?= e(t('browse.autonext_short')) ?>"></button>
                        </div>
                    </section>

                    <section class="browse-settings-block">
                        <h4 class="browse-settings-label"><?= e(t('browse.library')) ?></h4>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong data-i18n="browse.watchlist"><?= e(t('browse.watchlist')) ?></strong>
                                <em data-i18n="browse.watchlist_help"><?= e(t('browse.watchlist_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-watchlist" role="switch" aria-checked="true" aria-label="<?= e(t('browse.watchlist')) ?>"></button>
                        </div>
                        <div class="browse-settings-toggle-row">
                            <div class="browse-settings-toggle-copy">
                                <strong data-i18n="browse.continue_watching"><?= e(t('browse.continue_watching')) ?></strong>
                                <em data-i18n="browse.continue_help"><?= e(t('browse.continue_help')) ?></em>
                            </div>
                            <button type="button" class="browse-settings-switch" id="browse-settings-continue" role="switch" aria-checked="true" aria-label="<?= e(t('browse.continue_watching')) ?>"></button>
                        </div>
                    </section>
</div>
