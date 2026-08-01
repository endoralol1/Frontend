                                <div>Unable to load trailer. Please try again.</div>
                            </div>
                            <button type="button" class="btn-play" id="btn-play" aria-label="Watch now" title="Watch now"><i></i></button>
                            <?php if ($trailer): ?>
                            <button type="button" class="player-trailer-label js-play-trailer">Trailer</button>
                            <?php endif; ?>
                            <div id="player-frame" class="d-none"></div>
                        </div>
                    </div>
                    <div id="movie-managers">
                        <div class="movie-managers-wrap">
                            <?php if (!empty($trailer)): ?>
                            <button type="button" class="movie-manager trailer no-tooltip" id="btn-trailer">
                                <i class="uil uil-youtube" aria-hidden="true"></i><span class="ml-1">Trailer</span>
                            </button>
                            <?php endif; ?>
                            <button type="button" class="movie-manager watch-now-btn" id="btn-watch-now">
                                <i class="uil uil-play"></i><span class="ml-1">Watch Now</span>
                            </button>
                            <button type="button" class="movie-manager auto-play no-tooltip no-progress" id="btn-autoplay" aria-pressed="false">
                                <i class="uil uil-circle" aria-hidden="true"></i><span class="ml-1"><?= $type === 'tv' ? 'Auto Next' : 'Auto Play' ?></span>
                            </button>
                            <div class="bookmark movie-manager user-bookmark-toggle"
                                 data-id="<?= (int) $item['id'] ?>" data-media-type="<?= e($type) ?>"
                                 data-title="<?= e($title) ?>" data-poster="<?= e(img_url($item['poster_path'] ?? null, 'w185')) ?>"
                                 data-year="<?= e($year) ?>">
                                <i class="uil uil-plus-circle"></i><span class="ml-1">Favorite</span>
                            </div>
                            <button type="button" class="movie-manager share-btn no-tooltip" id="btn-share">
                                <i class="uil uil-share-alt" aria-hidden="true"></i><span class="ml-1">Share</span>
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($type === 'tv' && $currentEp): ?>
                <div class="horizontal-episode-card-container section">
                    <div class="horizontal-episode-card" data-episode-number="<?= (int) $episode ?>" data-available="true">
                        <div class="horizontal-episode-backdrop">
                            <div class="horizontal-episode-backdrop-inner" style="background-image:url('<?= e($epBackdrop) ?>')">
                                <button type="button" class="horizontal-episode-play-btn" id="btn-ep-play" data-episode-number="<?= (int) $episode ?>">
                                    <i></i>
                                </button>
