#!/usr/bin/env node
/**
 * Resolve Cineplay/Vidking Yoru (4K) sources via api.speedracelight.com.
 * Same enc=2 + seed decrypt as vidking.net VideoPlayer.
 *
 * Usage:
 *   node cineplay-yoru-resolve.mjs --type movie --tmdb 157336 [--title "Interstellar"] [--year 2014]
 *   node cineplay-yoru-resolve.mjs --type tv --tmdb 1396 --season 1 --episode 1
 */
let ProxyAgent = null;
let undiciFetch = globalThis.fetch;
try {
  const undici = await import("undici");
  ProxyAgent = undici.ProxyAgent;
  undiciFetch = undici.fetch;
} catch {
  // native fetch only (no proxy support)
}

const API = "https://api.speedracelight.com";
const META = "https://db.speedracelight.com/3";

const Hl = [
  1116352408, 1899447441, 3049323471, 3921009573, 961987163, 1508970993,
  2453635748, 2870763221, 3624381080, 310598401, 607225278, 1426881987,
  1925078388, 2162078206, 2614888103, 3248222580,
];
const _f = [1732584193, 4023233417, 2562383102, 271733878];
const Js = 61;
const Sf = 8;
const ms = 2654435769;
const Ys = [109, 118, 109, 49];
const bf = (l) => (l * (l + 1) & 1) === 0;
const If = (l) => (l * (l + 1) & 1) === 1;

function ci(l) {
  l >>>= 0;
  l ^= l >>> 16;
  l = Math.imul(l, 2246822507) >>> 0;
  l ^= l >>> 13;
  l = Math.imul(l, 3266489909) >>> 0;
  l ^= l >>> 16;
  return l >>> 0;
}
function ps(l, o) {
  l >>>= 0;
  o &= 31;
  return o === 0 ? l >>> 0 : ((l << o) | (l >>> (32 - o))) >>> 0;
}
function Af(l) {
  let o = _f[0] >>> 0;
  for (let e = 0; e < l.length; e++) {
    o = ps((o ^ Math.imul(l.charCodeAt(e), Hl[e & 15])) >>> 0, 5);
  }
  return ci(o);
}
function wf(l) {
  const o = new Array(256);
  for (let i = 0; i < 256; i++) o[i] = i;
  let e = 0;
  for (let i = 0; i < 256; i++) {
    e = (e + o[i] + l.charCodeAt(i % l.length)) & 255;
    const r = o[i];
    o[i] = o[e];
    o[e] = r;
  }
  return o;
}
function vf(l) {
  let o = 2166136261;
  for (let e = 0; e < l.length; e++) {
    o = Math.imul(o ^ l.charCodeAt(e), 16777619) >>> 0;
  }
  return ci(o);
}
function Nf(l, o, e) {
  return (((l ^ o) >>> 0) | ((l & o & e) >>> 0)) >>> 0;
}
function Rf(l, o) {
  if (If(l.length)) return { S: wf(l), acc: Af(l) };
  const e = new Array(Js);
  let i = ci(vf(l) ^ ci((o >>> 0) ^ ms)) >>> 0;
  for (let r = 0; r < Sf; r++) {
    if (bf(r)) {
      const n = i % Js;
      i = ps((i + ms) >>> 0, 7 + (r & 7));
      e[n] = (i ^ ci(i)) >>> 0;
      i = ci((i + n) >>> 0);
    } else e[r] = Hl[r & 15];
  }
  return { S: e, acc: ci(i ^ 2779096485) >>> 0 };
}
function Cf(l, o) {
  const e = l.S;
  let i = l.acc;
  const r = i % Js;
  const n = 0 - +(r in e);
  const u = e[r] >>> 0;
  const d = Math.imul(ms, o + 1) >>> 0;
  let g = Nf(i, (u ^ d) >>> 0, n);
  g = (ps((g + i) >>> 0, r & 31) ^ ps(i, Math.imul(r, 7) & 31)) >>> 0;
  i = ci((g + ms) >>> 0);
  e[r] = i >>> 0;
  l.acc = i;
  return i >>> 0;
}
function xf(l, o, e) {
  const i = Rf(l, o);
  const r = new Uint8Array(e);
  let n = 0;
  for (let u = 0; u < e; ) {
    const d = Cf(i, n++);
    r[u++] = d & 255;
    if (u < e) r[u++] = (d >>> 8) & 255;
    if (u < e) r[u++] = (d >>> 16) & 255;
    if (u < e) r[u++] = (d >>> 24) & 255;
  }
  return r;
}
function Df(l) {
  const o = l.replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(l.length / 4) * 4, "=");
  const e = Buffer.from(o, "base64");
  return new Uint8Array(e);
}
function decrypt(blob, seed, mediaId) {
  const i = Df(blob);
  const r = xf(seed, parseInt(String(mediaId), 10), i.length);
  for (let n = 0; n < i.length; n++) i[n] ^= r[n];
  for (let n = 0; n < Ys.length; n++) {
    if (i[n] !== Ys[n]) throw new Error("decrypt failed: bad seed or tampered payload");
  }
  return Buffer.from(i.subarray(Ys.length)).toString("utf8");
}

function arg(name, def = "") {
  const i = process.argv.indexOf(`--${name}`);
  if (i < 0) return def;
  return process.argv[i + 1] || def;
}

function resolveProxy() {
  for (const k of ["PROVIDER_FETCH_PROXY", "OUTBOUND_HTTP_PROXY", "HTTPS_PROXY", "HTTP_PROXY", "VIXSRC_PROXY"]) {
    const v = (process.env[k] || "").trim();
    if (v) return v;
  }
  return "";
}

async function httpGet(url, headers, proxyUrl) {
  const init = { headers, redirect: "follow" };
  if (proxyUrl && ProxyAgent) {
    init.dispatcher = new ProxyAgent(proxyUrl);
  }
  const res = await undiciFetch(url, init);
  const text = await res.text();
  return { ok: res.ok, status: res.status, text };
}

async function main() {
  const type = arg("type", "movie") === "tv" ? "tv" : "movie";
  const tmdbId = arg("tmdb", "");
  const season = arg("season", "1");
  const episode = arg("episode", "1");
  let title = arg("title", "");
  let year = arg("year", "");
  let imdbId = arg("imdb", "");
  if (!tmdbId) {
    console.log(JSON.stringify({ ok: false, error: "tmdb required" }));
    process.exit(1);
  }

  const headers = {
    "User-Agent":
      "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36",
    Accept: "application/json, */*;q=0.01",
    Referer: "https://www.vidking.net/",
    Origin: "https://www.vidking.net",
  };

  const proxy = resolveProxy();
  const attempts = proxy ? [proxy, ""] : [""];

  try {
    if (!title) {
      let meta = null;
      for (const p of attempts) {
        const m = await httpGet(
          `${META}/${type}/${tmdbId}?append_to_response=external_ids`,
          headers,
          p || undefined
        );
        if (m.ok) {
          meta = JSON.parse(m.text);
          break;
        }
      }
      if (meta) {
        title = type === "movie" ? meta.title : meta.name;
        const d = type === "movie" ? meta.release_date : meta.first_air_date;
        year = d ? String(new Date(d).getFullYear()) : "";
        imdbId = meta.external_ids?.imdb_id || "";
      }
    }

    let seed = "";
    let lastErr = "";
    for (const p of attempts) {
      try {
        const s = await httpGet(
          `${API}/seed?mediaId=${encodeURIComponent(tmdbId)}`,
          headers,
          p || undefined
        );
        if (!s.ok) {
          lastErr = `seed ${s.status}`;
          continue;
        }
        const j = JSON.parse(s.text);
        if (j.seed) {
          seed = j.seed;
          break;
        }
        lastErr = "seed missing";
      } catch (e) {
        lastErr = String(e?.message || e);
      }
    }
    if (!seed) {
      console.log(JSON.stringify({ ok: false, error: lastErr || "seed failed", cfBlocked: true }));
      process.exit(2);
    }

    const qs = new URLSearchParams({
      title: encodeURIComponent(title || ""),
      mediaType: type,
      year: String(year || ""),
      episodeId: String(episode || "1"),
      seasonId: String(season || "1"),
      tmdbId,
      imdbId: imdbId || "",
      enc: "2",
      seed,
      _t: String(Date.now()),
    });

    let blob = "";
    for (const p of attempts) {
      try {
        const r = await httpGet(`${API}/cdn/sources-with-title?${qs}`, headers, p || undefined);
        if (!r.ok) {
          lastErr = `sources ${r.status}`;
          continue;
        }
        blob = r.text;
        break;
      } catch (e) {
        lastErr = String(e?.message || e);
      }
    }
    if (!blob) {
      console.log(JSON.stringify({ ok: false, error: lastErr || "sources failed", cfBlocked: true }));
      process.exit(2);
    }

    const parsed = JSON.parse(decrypt(blob, seed, tmdbId));
    const sources = (parsed.sources || []).filter((s) => s && s.url);
    const subtitles = parsed.subtitles || [];
    console.log(
      JSON.stringify({
        ok: sources.length > 0,
        server: "Yoru",
        sources,
        subtitles,
        title,
        year,
        imdbId,
      })
    );
  } catch (e) {
    console.log(JSON.stringify({ ok: false, error: String(e?.message || e) }));
    process.exit(1);
  }
}

main();
