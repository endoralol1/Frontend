</main>
<footer class="footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> <?= h(get_setting('site_name', 'ScamGuard')) ?> — <?= h(get_setting('site_tagline', 'Know before you click.')) ?></p>
        <p class="footer-note">Scores combine malware/phishing intel, registration data, and scam heuristics. Not a legal verdict — use judgment.</p>
    </div>
</footer>

<?php if (empty($hideSupportChat) && get_setting('support_chat_enabled', '1') === '1'): ?>
<div id="sg-support-chat"
     data-api="<?= h(base_path('/api/chat.php')) ?>"
     data-base="<?= h(rtrim(BASE_PATH, '/')) ?>"></div>
<script src="<?= BASE_PATH ?>/assets/js/support-chat.js?v=<?= h($assetVer ?? '1') ?>" defer></script>
<?php endif; ?>
</body>
</html>
