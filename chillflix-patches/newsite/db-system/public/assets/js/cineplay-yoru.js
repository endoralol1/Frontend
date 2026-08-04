/**
 * Cineplay / Vidking Yoru resolver (browser-side).
 * Same crypto + API path as vidking.net VideoPlayer (enc=2 + seed).
 * Runs in the viewer's browser so Cloudflare doesn't see our VPS IP.
 */
(function (global) {
  "use strict";

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
  const Ys = [109, 118, 109, 49]; // mvm1
  const bf = (l) => (l * (l + 1) & 1) === 0;
  const If = (l) => (l * (l + 1) & 1) === 1;

  function ci(l) {
    l >>>= 0;
    l ^= l >>> 16;
    l = (Math.imul(l, 2246822507) >>> 0);
    l ^= l >>> 13;
    l = (Math.imul(l, 3266489909) >>> 0);
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
      } else {
        e[r] = Hl[r & 15];
      }
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
    const o = l
      .replace(/-/g, "+")
      .replace(/_/g, "/")
      .padEnd(Math.ceil(l.length / 4) * 4, "=");
    const e = atob(o);
    const i = new Uint8Array(e.length);
    for (let r = 0; r < e.length; r++) i[r] = e.charCodeAt(r);
    return i;
  }
  function decrypt(blob, seed, mediaId) {
    const i = Df(blob);
    const r = xf(seed, parseInt(String(mediaId), 10), i.length);
    for (let n = 0; n < i.length; n++) i[n] ^= r[n];
    for (let n = 0; n < Ys.length; n++) {
      if (i[n] !== Ys[n]) throw new Error("decrypt failed: bad seed or tampered payload");
    }
    return new TextDecoder("utf-8").decode(i.subarray(Ys.length));
  }

  const seedCache = new Map();

  async function fetchSeed(mediaId) {
    const key = String(mediaId);
    const hit = seedCache.get(key);
    if (hit && hit.exp > Date.now()) return hit.seed;
    const res = await fetch(`${API}/seed?mediaId=${encodeURIComponent(key)}`, {
      headers: {
        Accept: "application/json, */*;q=0.01",
      },
      credentials: "omit",
      mode: "cors",
    });
    if (!res.ok) throw new Error(`seed failed: ${res.status}`);
    const json = await res.json();
    if (!json || typeof json.seed !== "string") throw new Error("seed missing");
    seedCache.set(key, {
      seed: json.seed,
      exp: Date.now() + Math.min(Number(json.ttlMs || 25000), 25000),
    });
    return json.seed;
  }

  async function fetchMeta(mediaType, tmdbId) {
    const kind = mediaType === "tv" ? "tv" : "movie";
    const res = await fetch(
      `${META}/${kind}/${tmdbId}?append_to_response=external_ids`,
      { credentials: "omit", mode: "cors" }
    );
    if (!res.ok) throw new Error(`meta failed: ${res.status}`);
    const r = await res.json();
    return {
      title: kind === "movie" ? r.title : r.name,
      year:
        kind === "movie"
          ? r.release_date
            ? new Date(r.release_date).getFullYear()
            : ""
          : r.first_air_date
            ? new Date(r.first_air_date).getFullYear()
            : "",
      imdbId: (r.external_ids && r.external_ids.imdb_id) || "",
    };
  }

  /**
   * Resolve Yoru (4K) sources for a title.
   * @returns {Promise<{sources: Array<{url:string,quality?:string,type?:string}>, subtitles: Array}>}
   */
  async function resolveYoru(opts) {
    const mediaType = opts.mediaType === "tv" ? "tv" : "movie";
    const tmdbId = String(opts.tmdbId || "");
    if (!tmdbId) throw new Error("tmdbId required");

    let title = opts.title || "";
    let year = opts.year || "";
    let imdbId = opts.imdbId || "";
    if (!title) {
      const meta = await fetchMeta(mediaType, tmdbId);
      title = meta.title || "";
      year = meta.year || "";
      imdbId = meta.imdbId || imdbId;
    }

    const seed = await fetchSeed(tmdbId);
    const url = new URL(`${API}/cdn/sources-with-title`);
    url.searchParams.set("title", encodeURIComponent(title));
    url.searchParams.set("mediaType", mediaType);
    url.searchParams.set("year", String(year || ""));
    url.searchParams.set("episodeId", String(opts.episode || 1));
    url.searchParams.set("seasonId", String(opts.season || 1));
    url.searchParams.set("tmdbId", tmdbId);
    url.searchParams.set("imdbId", imdbId || "");
    url.searchParams.set("enc", "2");
    url.searchParams.set("seed", seed);
    url.searchParams.set("_t", String(Date.now()));

    const res = await fetch(url.toString(), {
      headers: {
        Accept: "*/*",
        "Cache-Control": "no-cache",
      },
      credentials: "omit",
      mode: "cors",
    });
    if (!res.ok) throw new Error(`yoru sources failed: ${res.status}`);
    const blob = await res.text();
    const parsed = JSON.parse(decrypt(blob, seed, tmdbId));
    const sources = Array.isArray(parsed.sources) ? parsed.sources.filter((s) => s && s.url) : [];
    const subtitles = Array.isArray(parsed.subtitles) ? parsed.subtitles : [];
    return { sources, subtitles, server: "Yoru" };
  }

  /**
   * Same-origin proxy path (optional). Used when browser CORS blocks direct API.
   * Server must forward with a non-banned egress IP to succeed.
   */
  async function resolveYoruViaProxy(opts) {
    const base = (global.APP && global.APP.baseUrl) || "";
    const q = new URLSearchParams({
      type: opts.mediaType === "tv" ? "tv" : "movie",
      tmdbId: String(opts.tmdbId || ""),
      season: String(opts.season || 1),
      episode: String(opts.episode || 1),
    });
    if (opts.title) q.set("title", opts.title);
    const res = await fetch(`${base}/api/cineplay/yoru?${q}`, {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
    });
    const json = await res.json();
    if (!json || !json.ok) throw new Error((json && json.error) || "proxy yoru failed");
    return {
      sources: json.sources || [],
      subtitles: json.subtitles || [],
      server: "Yoru",
    };
  }

  async function resolve(opts) {
    try {
      return await resolveYoru(opts);
    } catch (err) {
      // Fall back to same-origin proxy (needs working residential egress on VPS)
      try {
        return await resolveYoruViaProxy(opts);
      } catch (_) {
        throw err;
      }
    }
  }

  global.CineplayYoru = {
    resolve,
    resolveYoru,
    resolveYoruViaProxy,
    decrypt,
  };
})(typeof window !== "undefined" ? window : globalThis);
