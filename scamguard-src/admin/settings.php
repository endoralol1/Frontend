<?php
require_once __DIR__ . '/../includes/Auth.php';
Auth::requireLogin();
$pageTitle = 'Site Settings';
$db = Database::getConnection();
$flash = null;

require_once __DIR__ . '/../includes/functions.php';

$editable = ['site_name', 'site_tagline', 'announcement_banner', 'announcement_enabled'];
$aiFields = ['ai_api_key', 'ai_api_url', 'ai_model'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    foreach ($editable as $key) {
        $value = $key === 'announcement_enabled' ? (isset($_POST[$key]) ? '1' : '0') : trim($_POST[$key] ?? '');
        set_setting($key, $value);
    }

    // AI second opinion (OpenAI-compatible). Leave key blank to keep existing value.
    $newKey = trim($_POST['ai_api_key'] ?? '');
    if ($newKey !== '') {
        set_setting('ai_api_key', $newKey);
    } elseif (!empty($_POST['ai_api_key_clear'])) {
        set_setting('ai_api_key', '');
    }
    set_setting('ai_api_url', trim($_POST['ai_api_url'] ?? '') ?: 'https://api.openai.com/v1/chat/completions');
    set_setting('ai_model', trim($_POST['ai_model'] ?? '') ?: 'gpt-4o-mini');

    log_admin_activity(Auth::id(), 'update_site_settings');
    $flash = 'Settings saved.';
}

require __DIR__ . '/includes/layout_top.php';

$aiKeySet = trim(get_setting('ai_api_key', '')) !== '';
$aiUrl = get_setting('ai_api_url', 'https://api.openai.com/v1/chat/completions');
$aiModel = get_setting('ai_model', 'gpt-4o-mini');
?>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<div class="card" style="max-width:560px;">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken()) ?>">
        <div class="field">
            <label>Site name</label>
            <input type="text" name="site_name" value="<?= h(get_setting('site_name')) ?>">
        </div>
        <div class="field">
            <label>Tagline</label>
            <input type="text" name="site_tagline" value="<?= h(get_setting('site_tagline')) ?>">
        </div>
        <div class="field">
            <label><input type="checkbox" name="announcement_enabled" style="width:auto; display:inline;" <?= get_setting('announcement_enabled') === '1' ? 'checked' : '' ?>> Show announcement banner</label>
        </div>
        <div class="field">
            <label>Announcement text</label>
            <textarea name="announcement_banner" rows="2"><?= h(get_setting('announcement_banner')) ?></textarea>
        </div>

        <hr style="border:0; border-top:1px solid var(--line, #333); margin:22px 0;">
        <h3 style="margin:0 0 8px; font-size:1rem;">AI second opinion (optional)</h3>
        <p style="margin:0 0 14px; color:var(--text-faint, #889); font-size:.9rem;">
            Rule-based positive/negative lean always runs. Paste an OpenAI-compatible API key to add a model opinion.
            Works with OpenAI, Groq, OpenRouter, etc.
        </p>
        <div class="field">
            <label>AI API key <?= $aiKeySet ? '<span style="color:var(--safe,#2fbf71);">(saved)</span>' : '<span style="color:var(--text-faint);">(not set — analyst still works)</span>' ?></label>
            <input type="password" name="ai_api_key" autocomplete="new-password" placeholder="<?= $aiKeySet ? '••••••••  (leave blank to keep)' : 'sk-… or Groq/OpenRouter key' ?>">
        </div>
        <div class="field">
            <label><input type="checkbox" name="ai_api_key_clear" value="1" style="width:auto; display:inline;"> Clear saved AI key</label>
        </div>
        <div class="field">
            <label>AI API URL</label>
            <input type="text" name="ai_api_url" value="<?= h($aiUrl) ?>" placeholder="https://api.openai.com/v1/chat/completions">
        </div>
        <div class="field">
            <label>AI model</label>
            <input type="text" name="ai_model" value="<?= h($aiModel) ?>" placeholder="gpt-4o-mini">
        </div>

        <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
</div>

<div class="alert alert-info" style="margin-top:20px; max-width:560px;">
    Other optional keys (Google Safe Browsing, IPinfo, <code>CONTENT_FETCH_API_URL</code>) can still be set in <code>config/config.php</code> on the server.
    Groq free-tier example URL: <code>https://api.groq.com/openai/v1/chat/completions</code> — model <code>llama-3.1-8b-instant</code>.
</div>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
