#!/usr/bin/env python3
"""Fix RD key save perms + surface missing-key error instead of generic sources failed."""
from pathlib import Path

ROOT = Path("/var/www/chillflix-newsite")

# --- RealDebridSources: safer writable settings file ---
rd = ROOT / "app/Services/RealDebridSources.php"
t = rd.read_text(encoding="utf-8")
old = """    public static function saveApiKey(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        $payload = [
            'apiKey' => $apiKey,
            'updatedAt' => time(),
        ];
        $path = self::settingsPath();
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Could not write settings'];
        }
        @rename($tmp, $path);
        @chmod($path, 0600);
        return ['ok' => true, 'configured' => $apiKey !== ''];
    }
"""
new = """    public static function saveApiKey(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        $payload = [
            'apiKey' => $apiKey,
            'updatedAt' => time(),
        ];
        $path = self::settingsPath();
        $tmp = $path . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Could not write settings (storage not writable)'];
        }
        // Replace atomically; if rename fails (e.g. odd perms), fall back to overwrite.
        if (!@rename($tmp, $path)) {
            if (@file_put_contents($path, $json, LOCK_EX) === false) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'Could not save settings file'];
            }
            @unlink($tmp);
        }
        @chmod($path, 0660);
        // Keep readable/writable by the web user (www-data), not root-only.
        if (function_exists('posix_getpwnam')) {
            $u = @posix_getpwnam('www-data');
            $g = @posix_getgrnam('www-data');
            if (is_array($u) && is_array($g)) {
                @chown($path, (int) $u['uid']);
                @chgrp($path, (int) $g['gid']);
            }
        }
        return ['ok' => true, 'configured' => $apiKey !== ''];
    }
"""
if old not in t:
    raise SystemExit("saveApiKey block missing/changed")
rd.write_text(t.replace(old, new, 1), encoding="utf-8")
print("patched RealDebridSources::saveApiKey")

# --- PlayerSources: prefer RD error over generic empty message ---
ps = ROOT / "app/Services/PlayerSources.php"
pt = ps.read_text(encoding="utf-8")
old2 = """        $sources = array_values($merged);
        if (!$sources) {
            return [
                'ok' => false,
                'error' => 'No playable sources right now.',
                'diagnostics' => array_values($diagnostics),
                'sources' => [],
                'subtitles' => [],
            ];
        }
"""
new2 = """        $sources = array_values($merged);
        if (!$sources) {
            $err = 'No playable sources right now.';
            foreach (array_reverse($diagnostics) as $d) {
                if (!is_array($d)) {
                    continue;
                }
                $code = (string) ($d['code'] ?? '');
                $msg = trim((string) ($d['message'] ?? ''));
                if ($code === 'RD_KEY_MISSING') {
                    $err = 'RealDebrid API key missing. Open Admin → Sources, paste your key from real-debrid.com/apitoken, click Save key, then try again.';
                    break;
                }
                if ($code === 'RD_KEY_INVALID') {
                    $err = 'RealDebrid API key is invalid or expired. Update it in Admin → Sources.';
                    break;
                }
                if ($code === 'RD_HOST_BLOCKED') {
                    $err = 'RealDebrid is only available on vuflix.co.';
                    break;
                }
                if ($code === 'RD_EMPTY' && $msg !== '') {
                    $err = $msg;
                    break;
                }
            }
            return [
                'ok' => false,
                'error' => $err,
                'diagnostics' => array_values($diagnostics),
                'sources' => [],
                'subtitles' => [],
            ];
        }
"""
if old2 not in pt:
    raise SystemExit("PlayerSources empty block missing")
ps.write_text(pt.replace(old2, new2, 1), encoding="utf-8")
print("patched PlayerSources empty error")

# --- Admin sources copy: make Save key step unmistakable ---
admin = ROOT / "app/Views/pages/admin/sources.php"
at = admin.read_text(encoding="utf-8")
old3 = """      <p style="margin:0 0 .75rem;color:rgba(255,255,255,.55);font-size:.86rem;line-height:1.4">
        Learning provider: Torrentio finds torrents, RealDebrid unlocks HTTP links, your player plays them.
        Cached titles start fast; uncached ones wait on RD’s side (not this server).
        Get a key at <a href="https://real-debrid.com/apitoken" target="_blank" rel="noopener" style="color:#ffb3bb">real-debrid.com/apitoken</a>.
      </p>
"""
new3 = """      <p style="margin:0 0 .75rem;color:rgba(255,255,255,.55);font-size:.86rem;line-height:1.4">
        <strong style="color:#fbbf24">Save your API key first</strong> — enabling RealDebrid without a saved key makes the player say sources failed.
        Learning flow: Torrentio finds torrents → RealDebrid unlocks HTTP links → your player plays them.
        Cached titles start fast; uncached ones wait on RD’s side (not this server).
        Get a key at <a href="https://real-debrid.com/apitoken" target="_blank" rel="noopener" style="color:#ffb3bb">real-debrid.com/apitoken</a>.
      </p>
"""
if old3 not in at:
    raise SystemExit("admin rd copy missing")
at = at.replace(old3, new3, 1)

old4 = """      return '<div class="cf-admin-source'+(s.enabled?'':' is-off')+'" draggable="true" data-id="'+s.id+'">'+
        '<div class="meta"><strong>'+s.name+' <span style="color:rgba(255,255,255,.4)">('+s.id+')</span></strong>'+
        '<em>Public: '+s.publicLabel+(s.enabled?' · enabled':' · disabled')+'</em></div>'+
"""
new4 = """      var needsKey = (s.id === 'realdebrid' && window.__rdConfigured === false);
      return '<div class="cf-admin-source'+(s.enabled?'':' is-off')+'" draggable="true" data-id="'+s.id+'">'+
        '<div class="meta"><strong>'+s.name+' <span style="color:rgba(255,255,255,.4)">('+s.id+')</span></strong>'+
        (needsKey ? '<em style="color:#fbbf24">API key missing — Save key above before testing</em>' : '<em>Public: '+s.publicLabel+(s.enabled?' · enabled':' · disabled')+'</em>')+'</div>'+
"""
if old4 not in at:
    raise SystemExit("admin render row missing")
at = at.replace(old4, new4, 1)

old5 = """      var rd = d.realdebrid;
      var panel = document.getElementById('rd-panel');
      var status = document.getElementById('rd-status');
      if (panel && rd && rd.hostAllowed) {
        panel.hidden = false;
        status.textContent = rd.configured
          ? ('Key saved: ' + (rd.maskedKey || '••••') + ' — enable RealDebrid below and Test with a TMDB id')
          : 'No API key yet — paste one and Save, then enable RealDebrid in the list';
      } else if (panel) {
        panel.hidden = true;
      }
"""
new5 = """      var rd = d.realdebrid;
      window.__rdConfigured = !!(rd && rd.configured);
      var panel = document.getElementById('rd-panel');
      var status = document.getElementById('rd-status');
      if (panel && rd && rd.hostAllowed) {
        panel.hidden = false;
        status.textContent = rd.configured
          ? ('Key saved: ' + (rd.maskedKey || '••••') + ' — enable RealDebrid below and Test with a TMDB id')
          : 'No API key yet — paste one and Save, then enable RealDebrid in the list';
        // re-render so the missing-key badge updates after load/save
        render(d.sources||[]);
      } else if (panel) {
        panel.hidden = true;
      }
"""
if old5 not in at:
    raise SystemExit("admin load rd block missing")
at = at.replace(old5, new5, 1)

# Avoid double-render: load() currently calls render then rd block which now re-renders.
# That's fine. But first render happens before __rdConfigured is set — second fixes it.

admin.write_text(at, encoding="utf-8")
print("patched admin sources UX")

# Ensure settings file ownership
import os
import pwd
import grp
path = ROOT / "storage/realdebrid.json"
if path.exists():
    try:
        u = pwd.getpwnam("www-data")
        g = grp.getgrnam("www-data")
        os.chown(path, u.pw_uid, g.gr_gid)
        os.chmod(path, 0o660)
        print("fixed storage/realdebrid.json ownership")
    except Exception as e:
        print("ownership fix skipped:", e)

print("OK")
