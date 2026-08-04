#!/usr/bin/env node
/**
 * Resolve Byse (gn1r5n.org / byse.sx) embed → HLS.
 * HiMovies "UpCloud" and many "Vidmoly" buttons redirect here.
 *
 * Usage:
 *   node byse-resolve.mjs --code 5r9302w1nidc [--referer URL]
 *   node byse-resolve.mjs --wrapper https://0123movie.space/mv/tt…/969681/ [--referer URL]
 *   node byse-resolve.mjs --imdb tt22084616 --tmdb 969681 [--kind mv|vmf]
 */
import crypto from "crypto";

function arg(name, fallback = null) {
  const i = process.argv.indexOf(name);
  if (i >= 0 && process.argv[i + 1]) return process.argv[i + 1];
  return fallback;
}

const CODE_ARG = arg("--code");
const WRAPPER = arg("--wrapper");
const IMDB = arg("--imdb");
const TMDB = arg("--tmdb");
const KIND = arg("--kind", "mv"); // mv=UpCloud wrapper, vmf=labeled Vidmoly
const REFERER = arg(
  "--referer",
  "https://himovies.watch/"
);
const BASE_FALLBACK = "https://gn1r5n.org";
const UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36";

// ---- PoW (from Byse pow-*.js Er export) ----
const be = 512,
  lt = be - 1,
  dr = 2,
  lr = 2654435761,
  hr = 2246822519;
const rot = (t, e) => ((t << e) | (t >>> (32 - e))) >>> 0;
const imul = (t, e) => Math.imul(t, e) >>> 0;
function ye(t) {
  t[0] = (t[0] + t[1]) >>> 0;
  t[3] = rot(t[3] ^ t[0], 16);
  t[2] = (t[2] + t[3]) >>> 0;
  t[1] = rot(t[1] ^ t[2], 12);
  t[0] = (t[0] + t[1]) >>> 0;
  t[3] = rot(t[3] ^ t[0], 8);
  t[2] = (t[2] + t[3]) >>> 0;
  t[1] = rot(t[1] ^ t[2], 7);
}
function gr(t) {
  const e = new Uint32Array([1779033703, 3144134277, 1013904242, 2773480762]);
  for (let i = 0; i < t.length; i++) {
    e[0] = (e[0] + t[i]) >>> 0;
    e[0] = rot(e[0], 7);
    ye(e);
  }
  for (let i = 0; i < 8; i++) ye(e);
  const r = new Uint32Array(be);
  for (let i = 0; i < be; i++) {
    ye(e);
    r[i] = (e[0] ^ e[2]) >>> 0;
  }
  for (let i = 0; i < dr; i++)
    for (let s = 0; s < be; s++) {
      const a = r[s] & lt;
      let c = (r[s] + r[a]) >>> 0;
      c = rot(c, 13);
      c = (c ^ imul(r[(s + 1) & lt], lr)) >>> 0;
      r[s] = c;
      e[0] = (e[0] ^ c) >>> 0;
      ye(e);
    }
  const n = new Uint32Array(8),
    o = be / 8;
  for (let i = 0; i < 8; i++) {
    ye(e);
    let s = e[0];
    const a = i * o;
    for (let c = 0; c < o; c++) {
      const d = r[a + c];
      s = (s + d) >>> 0;
      s = rot(s, 5);
      s = (s ^ imul(d, hr)) >>> 0;
    }
    n[i] = (s ^ e[2]) >>> 0;
  }
  return n;
}
function leadingZeroBits(t) {
  let e = 0;
  for (let r = 0; r < t.length; r++) {
    const n = t[r];
    if (n === 0) {
      e += 32;
      continue;
    }
    return e + Math.clz32(n);
  }
  return e;
}
function toBytes(t) {
  const e = new Uint8Array(t.length);
  for (let r = 0; r < t.length; r++) e[r] = t.charCodeAt(r) & 255;
  return e;
}
async function solvePow(nonce, difficulty, timeoutMs = 20000) {
  if (difficulty <= 0) return "0";
  const prefix = nonce + ":";
  const started = Date.now();
  let s = 0;
  for (;;) {
    for (let c = 0; c < 2048; c++) {
      if (leadingZeroBits(gr(toBytes(prefix + s))) >= difficulty) return String(s);
      s++;
    }
    if (Date.now() - started > timeoutMs) return null;
    await new Promise((r) => setImmediate(r));
  }
}

const jar = new Map();
function storeCookies(res) {
  for (const c of res.headers.getSetCookie?.() || []) {
    const [nv] = c.split(";");
    const i = nv.indexOf("=");
    if (i > 0) jar.set(nv.slice(0, i), nv.slice(i + 1));
  }
}

async function http(method, url, { headers = {}, body, redirect = "follow" } = {}) {
  const h = { "User-Agent": UA, ...headers };
  const cookie = [...jar].map(([k, v]) => `${k}=${v}`).join("; ");
  if (cookie) h.Cookie = cookie;
  let payload;
  if (body !== undefined) {
    h["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }
  const res = await fetch(url, { method, headers: h, body: payload, redirect });
  storeCookies(res);
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {}
  return { status: res.status, url: res.url, headers: res.headers, text, json };
}

function b64urlToBuf(s) {
  const t = String(s).replace(/-/g, "+").replace(/_/g, "/");
  return Buffer.from(t + "=".repeat((4 - (t.length % 4)) % 4), "base64");
}
function Xt(s) {
  return new Uint8Array(b64urlToBuf(s));
}
function Ea(version, len) {
  const n = Number(String(version || "").trim());
  if (!Number.isFinite(n) || n < 1 || n > 20) return [];
  const a = n ^ 0;
  const i = (31 - n) ^ 0;
  return a >= 1 && i >= 1 && a <= len && i <= len ? [a, i] : [];
}
function ws(e) {
  const t = Array.isArray(e.key_parts) ? e.key_parts : [];
  const r = Ea(e.version, t.length);
  if (!r.length) return t;
  const n = r.map((o) => t[o - 1]).filter((o) => typeof o === "string" && o.length);
  return n.length ? n : t;
}
function ks(parts) {
  const t = parts.filter((a) => typeof a === "string" && a.length).map(Xt);
  const n = new Uint8Array(t.reduce((a, i) => a + i.length, 0));
  let o = 0;
  for (const a of t) {
    n.set(a, o);
    o += a.length;
  }
  return n;
}
function decryptPlayback(enc) {
  const key = Buffer.from(ks(ws(enc)));
  const iv = Buffer.from(Xt(enc.iv));
  const data = Buffer.from(Xt(enc.payload));
  const tag = data.subarray(data.length - 16);
  const ct = data.subarray(0, data.length - 16);
  const algo = key.length === 16 ? "aes-128-gcm" : "aes-256-gcm";
  const d = crypto.createDecipheriv(algo, key, iv);
  d.setAuthTag(tag);
  return JSON.parse(Buffer.concat([d.update(ct), d.final()]).toString("utf8"));
}

async function resolveCode(code, base, referer) {
  const originHost = new URL(referer).hostname.replace(/^www\./, "");
  const api = (method, path, body, extra = {}) =>
    http(method, base + path, {
      body,
      headers: {
        Accept: "application/json, text/plain, */*",
        Origin: base,
        Referer: `${base}/e/${code}`,
        "X-Embed-Origin": originHost,
        "X-Embed-Referer": referer,
        "X-Embed-Parent": new URL(referer).origin + "/",
        ...extra,
      },
    });

  const details = await api("GET", `/api/videos/${code}/embed/details`);
  if (details.status !== 200) {
    return { ok: false, error: `details HTTP ${details.status}`, body: details.text.slice(0, 200) };
  }

  const captcha = await api("POST", `/api/videos/${code}/embed/captcha`, {});
  if (!captcha.json?.pow_nonce) {
    return { ok: false, error: "captcha challenge missing", body: captcha.text.slice(0, 200) };
  }
  const solution = await solvePow(
    captcha.json.pow_nonce,
    captcha.json.pow_difficulty,
    20000
  );
  if (solution == null) return { ok: false, error: "PoW timeout" };

  const verify = await api("POST", `/api/videos/${code}/embed/captcha/verify`, {
    pow_token: captcha.json.pow_token,
    solution,
  });
  if (verify.json?.status !== "ok" || !verify.json?.token) {
    return { ok: false, error: "captcha verify failed", body: verify.text.slice(0, 200) };
  }

  const playback = await api(
    "POST",
    `/api/videos/${code}/embed/playback`,
    { fingerprint: {} },
    { "X-Captcha-Token": verify.json.token }
  );
  if (playback.status !== 200 || !playback.json?.playback) {
    return { ok: false, error: `playback HTTP ${playback.status}`, body: playback.text.slice(0, 200) };
  }

  let dec;
  try {
    dec = decryptPlayback(playback.json.playback);
  } catch (e) {
    return { ok: false, error: "decrypt failed: " + e.message };
  }
  const sources = Array.isArray(dec.sources) ? dec.sources : [];
  if (!sources.length) return { ok: false, error: "no sources in playback" };

  return {
    ok: true,
    host: "byse",
    code,
    base,
    title: details.json?.title || null,
    sources: sources.map((s) => ({
      url: s.url,
      quality: s.label || s.quality || "Auto",
      mimeType: s.mime_type || "application/vnd.apple.mpegurl",
      height: s.height || null,
      bitrateKbps: s.bitrate_kbps || null,
    })),
    tracks: Array.isArray(dec.tracks) ? dec.tracks : [],
    poster: dec.poster_url || details.json?.poster_url || null,
    expiresAt: dec.expires_at || null,
  };
}

async function followWrapper(wrapper, referer) {
  // manual redirect to capture final byse URL
  let url = wrapper;
  for (let i = 0; i < 8; i++) {
    const res = await http("GET", url, {
      redirect: "manual",
      headers: {
        Accept: "text/html",
        Referer: referer,
        "Sec-Fetch-Dest": "iframe",
        "Sec-Fetch-Mode": "navigate",
        "Sec-Fetch-Site": "cross-site",
      },
    });
    if ([301, 302, 303, 307, 308].includes(res.status)) {
      const loc = res.headers.get("location");
      if (!loc) break;
      url = loc.startsWith("http") ? loc : new URL(loc, url).toString();
      continue;
    }
    // final
    return { final: res.url || url, status: res.status };
  }
  return { final: url, status: 0 };
}

function parseEmbed(finalUrl) {
  const u = new URL(finalUrl);
  const m = u.pathname.match(/\/e\/([a-zA-Z0-9]+)/);
  if (!m) return null;
  return { base: u.origin, code: m[1] };
}

async function main() {
  try {
    let code = CODE_ARG;
    let base = BASE_FALLBACK;
    let wrapper = WRAPPER;

    if (!code && !wrapper && IMDB && TMDB) {
      wrapper = `https://0123movie.space/${KIND}/${IMDB}/${TMDB}/`;
    }
    if (!code && wrapper) {
      const followed = await followWrapper(wrapper, REFERER);
      const parsed = parseEmbed(followed.final);
      if (!parsed) {
        console.log(
          JSON.stringify({
            ok: false,
            error: "wrapper did not land on Byse embed",
            final: followed.final,
          })
        );
        process.exit(2);
      }
      code = parsed.code;
      base = parsed.base;
    }
    if (!code) {
      console.log(JSON.stringify({ ok: false, error: "missing --code or --wrapper/--imdb/--tmdb" }));
      process.exit(2);
    }

    const result = await resolveCode(code, base, REFERER);
    if (wrapper) result.wrapper = wrapper;
    console.log(JSON.stringify(result));
    process.exit(result.ok ? 0 : 3);
  } catch (e) {
    console.log(JSON.stringify({ ok: false, error: String(e?.message || e) }));
    process.exit(1);
  }
}

main();
