<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/DomainRepository.php';

$repo = new DomainRepository();
$stats = $repo->stats();
$recent = $repo->recentlyCheckedMixed(12);

$prefill = trim($_GET['q'] ?? '');
$error = isset($_GET['error']);

// Dial countries for the phone picker (flag + ISO + dial code)
$dialCountries = [
    ['iso' => 'DE', 'name' => 'Germany', 'dial' => '49', 'flag' => '🇩🇪'],
    ['iso' => 'US', 'name' => 'United States', 'dial' => '1', 'flag' => '🇺🇸'],
    ['iso' => 'GB', 'name' => 'United Kingdom', 'dial' => '44', 'flag' => '🇬🇧'],
    ['iso' => 'FR', 'name' => 'France', 'dial' => '33', 'flag' => '🇫🇷'],
    ['iso' => 'NL', 'name' => 'Netherlands', 'dial' => '31', 'flag' => '🇳🇱'],
    ['iso' => 'BE', 'name' => 'Belgium', 'dial' => '32', 'flag' => '🇧🇪'],
    ['iso' => 'AT', 'name' => 'Austria', 'dial' => '43', 'flag' => '🇦🇹'],
    ['iso' => 'CH', 'name' => 'Switzerland', 'dial' => '41', 'flag' => '🇨🇭'],
    ['iso' => 'IT', 'name' => 'Italy', 'dial' => '39', 'flag' => '🇮🇹'],
    ['iso' => 'ES', 'name' => 'Spain', 'dial' => '34', 'flag' => '🇪🇸'],
    ['iso' => 'PT', 'name' => 'Portugal', 'dial' => '351', 'flag' => '🇵🇹'],
    ['iso' => 'PL', 'name' => 'Poland', 'dial' => '48', 'flag' => '🇵🇱'],
    ['iso' => 'CZ', 'name' => 'Czechia', 'dial' => '420', 'flag' => '🇨🇿'],
    ['iso' => 'SE', 'name' => 'Sweden', 'dial' => '46', 'flag' => '🇸🇪'],
    ['iso' => 'NO', 'name' => 'Norway', 'dial' => '47', 'flag' => '🇳🇴'],
    ['iso' => 'DK', 'name' => 'Denmark', 'dial' => '45', 'flag' => '🇩🇰'],
    ['iso' => 'FI', 'name' => 'Finland', 'dial' => '358', 'flag' => '🇫🇮'],
    ['iso' => 'IE', 'name' => 'Ireland', 'dial' => '353', 'flag' => '🇮🇪'],
    ['iso' => 'RO', 'name' => 'Romania', 'dial' => '40', 'flag' => '🇷🇴'],
    ['iso' => 'HU', 'name' => 'Hungary', 'dial' => '36', 'flag' => '🇭🇺'],
    ['iso' => 'GR', 'name' => 'Greece', 'dial' => '30', 'flag' => '🇬🇷'],
    ['iso' => 'TR', 'name' => 'Turkey', 'dial' => '90', 'flag' => '🇹🇷'],
    ['iso' => 'UA', 'name' => 'Ukraine', 'dial' => '380', 'flag' => '🇺🇦'],
    ['iso' => 'RU', 'name' => 'Russia', 'dial' => '7', 'flag' => '🇷🇺'],
    ['iso' => 'IN', 'name' => 'India', 'dial' => '91', 'flag' => '🇮🇳'],
    ['iso' => 'PK', 'name' => 'Pakistan', 'dial' => '92', 'flag' => '🇵🇰'],
    ['iso' => 'BD', 'name' => 'Bangladesh', 'dial' => '880', 'flag' => '🇧🇩'],
    ['iso' => 'CN', 'name' => 'China', 'dial' => '86', 'flag' => '🇨🇳'],
    ['iso' => 'JP', 'name' => 'Japan', 'dial' => '81', 'flag' => '🇯🇵'],
    ['iso' => 'KR', 'name' => 'South Korea', 'dial' => '82', 'flag' => '🇰🇷'],
    ['iso' => 'AU', 'name' => 'Australia', 'dial' => '61', 'flag' => '🇦🇺'],
    ['iso' => 'NZ', 'name' => 'New Zealand', 'dial' => '64', 'flag' => '🇳🇿'],
    ['iso' => 'CA', 'name' => 'Canada', 'dial' => '1', 'flag' => '🇨🇦'],
    ['iso' => 'MX', 'name' => 'Mexico', 'dial' => '52', 'flag' => '🇲🇽'],
    ['iso' => 'BR', 'name' => 'Brazil', 'dial' => '55', 'flag' => '🇧🇷'],
    ['iso' => 'AR', 'name' => 'Argentina', 'dial' => '54', 'flag' => '🇦🇷'],
    ['iso' => 'ZA', 'name' => 'South Africa', 'dial' => '27', 'flag' => '🇿🇦'],
    ['iso' => 'NG', 'name' => 'Nigeria', 'dial' => '234', 'flag' => '🇳🇬'],
    ['iso' => 'KE', 'name' => 'Kenya', 'dial' => '254', 'flag' => '🇰🇪'],
    ['iso' => 'EG', 'name' => 'Egypt', 'dial' => '20', 'flag' => '🇪🇬'],
    ['iso' => 'AE', 'name' => 'United Arab Emirates', 'dial' => '971', 'flag' => '🇦🇪'],
    ['iso' => 'SA', 'name' => 'Saudi Arabia', 'dial' => '966', 'flag' => '🇸🇦'],
    ['iso' => 'IL', 'name' => 'Israel', 'dial' => '972', 'flag' => '🇮🇱'],
    ['iso' => 'PH', 'name' => 'Philippines', 'dial' => '63', 'flag' => '🇵🇭'],
    ['iso' => 'ID', 'name' => 'Indonesia', 'dial' => '62', 'flag' => '🇮🇩'],
    ['iso' => 'MY', 'name' => 'Malaysia', 'dial' => '60', 'flag' => '🇲🇾'],
    ['iso' => 'SG', 'name' => 'Singapore', 'dial' => '65', 'flag' => '🇸🇬'],
    ['iso' => 'TH', 'name' => 'Thailand', 'dial' => '66', 'flag' => '🇹🇭'],
    ['iso' => 'VN', 'name' => 'Vietnam', 'dial' => '84', 'flag' => '🇻🇳'],
];

$pageTitle = get_setting('site_name', 'ScamGuard') . ' — Check websites, phones, crypto & IBAN';
$pageDescription = 'Free scam checker for websites, phone numbers, crypto addresses, and IBANs. Paste anything — ScamGuard detects the type as you type.';
$canonicalUrl = absolute_url('/');
$jsonLd = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => get_setting('site_name', 'ScamGuard'),
    'url' => absolute_url('/'),
    'description' => $pageDescription,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="container">
        <h1>Quick check for scams</h1>
        <p>Paste a website, phone, card, crypto address, or IBAN — we detect it while you type.</p>

        <?php if ($error): ?>
            <div class="alert alert-error" style="max-width:640px;margin:0 auto 14px;">That input didn’t look valid. Try again.</div>
        <?php endif; ?>

        <form class="multi-search" action="<?= BASE_PATH ?>/check-entity.php" method="get" id="scam-search">
            <input type="hidden" name="type" id="search-type" value="auto">

            <div class="search-box search-box-phone" id="search-box">
                <div class="dial-wrap" id="dial-wrap" hidden>
                    <button type="button" class="dial-btn" id="dial-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Country calling code">
                        <span class="dial-flag" id="dial-flag">🇩🇪</span>
                        <span class="dial-code" id="dial-code">+49</span>
                        <span class="dial-caret" aria-hidden="true">▾</span>
                    </button>
                    <div class="dial-menu" id="dial-menu" role="listbox" hidden>
                        <input type="search" class="dial-filter" id="dial-filter" placeholder="Search country…" autocomplete="off">
                        <div class="dial-list" id="dial-list"></div>
                    </div>
                </div>
                <input type="text" name="q" id="search-q"
                       placeholder="Enter website, phone, card, crypto, or IBAN…"
                       value="<?= h($prefill) ?>"
                       autofocus required
                       autocomplete="off"
                       inputmode="text">
                <button type="submit">Check scam</button>
            </div>

            <div class="type-row" aria-live="polite">
                <span class="type-label">Detected:</span>
                <?php
                $types = [
                    'website' => 'Website',
                    'phone' => 'Phone',
                    'card' => 'Card',
                    'crypto' => 'Crypto',
                    'iban' => 'IBAN',
                ];
                foreach ($types as $key => $label):
                ?>
                    <span class="type-chip" data-type="<?= h($key) ?>" id="chip-<?= h($key) ?>"><?= h($label) ?></span>
                <?php endforeach; ?>
            </div>
        </form>

        <div class="report-cta-card">
            <div>
                <strong>Report scams to help others</strong>
                <p>Share your experience and protect the community.</p>
            </div>
            <a class="btn btn-danger" href="<?= BASE_PATH ?>/report.php">Report</a>
        </div>

        <div class="stats-row">
            <div class="stat">
                <div class="num"><?= number_format($stats['total_domains']) ?></div>
                <div class="label">Domains tracked</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['likely_safe']) ?></div>
                <div class="label">Likely safe</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['flagged_scams']) ?></div>
                <div class="label">Flagged as scams</div>
            </div>
            <div class="stat">
                <div class="num"><?= number_format($stats['checked_today']) ?></div>
                <div class="label">Checked today</div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:end; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
            <h2 class="section-title" style="margin:0;">Recently checked websites</h2>
            <a class="btn btn-sm" href="<?= BASE_PATH ?>/browse.php">Browse all checks</a>
        </div>
        <p style="color:var(--text-faint); margin:0 0 14px; font-size:14px;">
            Shows a mix of safe and risky results — not only threat-feed hits.
        </p>
        <div class="card" style="padding:0;">
            <div class="table-wrap"><table>
                <thead>
                    <tr><th>Domain</th><th>Score</th><th>Status</th><th>Last checked</th></tr>
                </thead>
                <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="4" style="color:var(--text-faint);">No domains checked yet — be the first to search one above.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $r): $badge = status_badge($r['status']); ?>
                    <tr>
                        <td><a href="<?= h(domain_page_path($r['domain'])) ?>"><?= h($r['domain']) ?></a></td>
                        <td><?= (int) $r['trust_score'] ?>/100</td>
                        <td><span class="badge <?= $badge['class'] ?>"><?= $badge['label'] ?></span></td>
                        <td style="color:var(--text-faint);"><?= h($r['last_checked'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</section>

<script>
(() => {
  const countries = <?= json_encode($dialCountries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const form = document.getElementById('scam-search');
  const typeInput = document.getElementById('search-type');
  const q = document.getElementById('search-q');
  const box = document.getElementById('search-box');
  const dialWrap = document.getElementById('dial-wrap');
  const dialBtn = document.getElementById('dial-btn');
  const dialMenu = document.getElementById('dial-menu');
  const dialList = document.getElementById('dial-list');
  const dialFilter = document.getElementById('dial-filter');
  const dialFlag = document.getElementById('dial-flag');
  const dialCode = document.getElementById('dial-code');
  const chips = document.querySelectorAll('.type-chip');

  let selected = countries.find((c) => c.iso === 'DE') || countries[0];
  let menuOpen = false;

  function detectType(raw) {
    const v = (raw || '').trim();
    if (!v) return 'website';

    const compact = v.replace(/[\s\-]+/g, '');
    const digits = v.replace(/\D+/g, '');
    const hasLetters = /[A-Za-z]/.test(v);

    // Full IBAN
    if (/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/i.test(compact) && compact.length >= 15) {
      return 'iban';
    }
    // Incomplete IBAN while typing (e.g. DE66051092) — never call this a phone
    if (/^[A-Z]{2}\d{2}[A-Z0-9]{2,}$/i.test(compact) && hasLetters) {
      return 'iban';
    }

    // Crypto
    if (/^0x[a-fA-F0-9]{40}$/.test(compact)) return 'crypto';
    if (/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/.test(compact)) return 'crypto';
    if (/^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(compact)) return 'crypto';
    if (/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$/.test(compact) && compact.length >= 26) return 'crypto';

    // Bank card: 13–19 digits, no letters, not starting with + / 00
    if (!hasLetters && digits.length >= 13 && digits.length <= 19 && /^[\d\s\-]+$/.test(v)
        && !v.startsWith('+') && !v.startsWith('00')) {
      return 'card';
    }

    // Phone: digits / + / spaces only — NO letters
    if (!hasLetters) {
      const phoneLike = /^[\s()+.\-]*\d[\d\s()+.\-]*$/.test(v) && digits.length >= 6 && digits.length <= 15;
      if (phoneLike) return 'phone';
    }

    // Website / domain
    if (/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}([\/?#].*)?$/i.test(v)) return 'website';
    if (/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(v)) return 'website';

    return 'website';
  }

  function matchDialFromInput(v) {
    const digits = v.replace(/\D+/g, '');
    if (!digits) return null;
    // Longest dial-code prefix match
    const sorted = [...countries].sort((a, b) => b.dial.length - a.dial.length);
    for (const c of sorted) {
      if (digits.startsWith(c.dial) && digits.length > c.dial.length) return c;
      if (v.trim().startsWith('+' + c.dial) || v.trim().startsWith('00' + c.dial)) return c;
    }
    return null;
  }

  function setDial(country) {
    selected = country;
    dialFlag.textContent = country.flag;
    dialCode.textContent = '+' + country.dial;
    dialBtn.setAttribute('aria-label', country.name + ' +' + country.dial);
  }

  function setTypeUI(t) {
    typeInput.value = t;
    const isPhone = t === 'phone';
    dialWrap.hidden = !isPhone;
    box.classList.toggle('has-phone-cc', isPhone);
    q.inputMode = isPhone ? 'tel' : 'text';
    q.placeholder = isPhone
      ? 'Phone number'
      : t === 'crypto'
        ? 'Crypto address'
        : t === 'iban'
          ? 'IBAN'
          : t === 'card'
            ? 'Card number'
            : 'Website, phone, card, crypto, or IBAN';
    chips.forEach((c) => c.classList.toggle('is-active', c.dataset.type === t));
  }

  function refreshDetection() {
    const t = detectType(q.value);
    setTypeUI(t);
    if (t === 'phone') {
      const matched = matchDialFromInput(q.value);
      if (matched) setDial(matched);
    }
  }

  function renderDialList(filter) {
    const f = (filter || '').trim().toLowerCase();
    dialList.innerHTML = '';
    countries
      .filter((c) => !f || c.name.toLowerCase().includes(f) || c.iso.toLowerCase().includes(f) || ('+' + c.dial).includes(f) || c.dial.includes(f))
      .forEach((c) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dial-option' + (c.iso === selected.iso && c.dial === selected.dial ? ' is-active' : '');
        btn.setAttribute('role', 'option');
        btn.innerHTML = '<span class="dial-option-flag">' + c.flag + '</span><span class="dial-option-name">' + c.name + '</span><span class="dial-option-code">+' + c.dial + '</span>';
        btn.addEventListener('click', () => {
          setDial(c);
          closeMenu();
          // If local number without country, keep as-is; dial is prepended on submit
          q.focus();
        });
        dialList.appendChild(btn);
      });
  }

  function openMenu() {
    menuOpen = true;
    dialMenu.hidden = false;
    dialBtn.setAttribute('aria-expanded', 'true');
    renderDialList('');
    dialFilter.value = '';
    dialFilter.focus();
  }

  function closeMenu() {
    menuOpen = false;
    dialMenu.hidden = true;
    dialBtn.setAttribute('aria-expanded', 'false');
  }

  dialBtn.addEventListener('click', (e) => {
    e.preventDefault();
    menuOpen ? closeMenu() : openMenu();
  });
  dialFilter.addEventListener('input', () => renderDialList(dialFilter.value));
  document.addEventListener('click', (e) => {
    if (!dialWrap.contains(e.target)) closeMenu();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });

  q.addEventListener('input', refreshDetection);
  q.addEventListener('paste', () => setTimeout(refreshDetection, 0));

  form.addEventListener('submit', (e) => {
    const t = detectType(q.value);
    typeInput.value = t;
    if (t !== 'phone') return;

    let v = q.value.trim();
    // Already international?
    if (v.startsWith('+') || v.startsWith('00')) return;

    // Strip leading trunk 0 for many countries (DE/FR/UK local style)
    let national = v.replace(/[^\d]/g, '');
    if (national.startsWith('0')) national = national.replace(/^0+/, '');

    // Avoid double-prefix if user already typed country digits
    if (national.startsWith(selected.dial) && national.length > selected.dial.length + 4) {
      q.value = '+' + national;
      return;
    }

    q.value = '+' + selected.dial + national;
  });

  // Guess default dial country from browser locale
  try {
    const lang = (navigator.language || 'de').toUpperCase();
    const iso = lang.split('-')[1] || lang.split('-')[0];
    const guess = countries.find((c) => c.iso === iso);
    if (guess) setDial(guess);
    else setDial(selected);
  } catch (_) {
    setDial(selected);
  }

  refreshDetection();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
