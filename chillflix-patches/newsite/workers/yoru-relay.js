/**
 * Cloudflare Worker: Yoru + VixSrc resolve relay.
 *
 * Why: VPS datacenter IPs are Cloudflare-banned on:
 *   - api.speedracelight.com (Yoru / Cineplay / Videasy)
 *   - vixsrc.to (VixSrc)
 * This Worker fetches from CF edge (clean IP) and returns plain HLS URLs.
 *
 * Routes:
 *   GET /health
 *   GET /resolve?type=&tmdb=&season=&episode=&key=   → Yoru
 *   GET /vixsrc?type=&tmdb=&season=&episode=&key=    → VixSrc
 *
 * Deploy (Cloudflare dashboard → Workers → edit divine-frost-3156 → paste):
 *   YORU_RELAY_URL=https://divine-frost-3156.vuflix.workers.dev
 *   YORU_RELAY_SECRET=<same secret>
 *
 * Bind secret: wrangler secret put YORU_RELAY_SECRET
 */
const API = "https://api.speedracelight.com";
const META = "https://db.speedracelight.com/3";
const ORIGIN = "https://www.vidking.net";
const VIXSRC = "https://vixsrc.to";
const VIXSRC_UA =
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150 Safari/537.36";

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
  const bin = atob(o);
  const e = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) e[i] = bin.charCodeAt(i);
  return e;
}
function decrypt(blob, seed, mediaId) {
  const i = Df(blob);
  const r = xf(seed, parseInt(String(mediaId), 10), i.length);
  for (let n = 0; n < i.length; n++) i[n] ^= r[n];
  for (let n = 0; n < Ys.length; n++) {
    if (i[n] !== Ys[n]) throw new Error("decrypt failed");
  }
  return new TextDecoder().decode(i.subarray(Ys.length));
}

const headers = {
  "User-Agent":
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36",
  Accept: "application/json, */*;q=0.01",
  Referer: ORIGIN + "/",
  Origin: ORIGIN,
};

function json(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "access-control-allow-origin": "*",
      "cache-control": "no-store",
    },
  });
}

async function resolve(type, tmdbId, season, episode, title, year, imdbId) {
  if (!title) {
    const metaRes = await fetch(
      `${META}/${type}/${tmdbId}?append_to_response=external_ids`,
      { headers }
    );
    if (metaRes.ok) {
      const meta = await metaRes.json();
      title = type === "movie" ? meta.title : meta.name;
      const d = type === "movie" ? meta.release_date : meta.first_air_date;
      year = d ? String(new Date(d).getFullYear()) : year;
      imdbId = meta.external_ids?.imdb_id || imdbId;
    }
  }

  const seedRes = await fetch(`${API}/seed?mediaId=${encodeURIComponent(tmdbId)}`, {
    headers,
  });
  if (!seedRes.ok) {
    return { ok: false, error: `seed ${seedRes.status}`, cfBlocked: seedRes.status === 429 || seedRes.status === 403 };
  }
  const seedJson = await seedRes.json();
  const seed = seedJson.seed;
  if (!seed) return { ok: false, error: "seed missing" };

  const qs = new URLSearchParams({
    title: encodeURIComponent(title || ""),
    mediaType: type,
    year: String(year || ""),
    episodeId: String(episode || "1"),
    seasonId: String(season || "1"),
    tmdbId: String(tmdbId),
    imdbId: imdbId || "",
    enc: "2",
    seed,
    _t: String(Date.now()),
  });
  const srcRes = await fetch(`${API}/cdn/sources-with-title?${qs}`, { headers });
  if (!srcRes.ok) {
    return { ok: false, error: `sources ${srcRes.status}`, cfBlocked: srcRes.status === 429 || srcRes.status === 403 };
  }
  const blob = await srcRes.text();
  const parsed = JSON.parse(decrypt(blob, seed, tmdbId));
  const sources = (parsed.sources || []).filter((s) => s && s.url);
  return {
    ok: sources.length > 0,
    server: "Yoru",
    sources,
    subtitles: parsed.subtitles || [],
    title,
    year,
    imdbId,
  };
}

function vixsrcHeaders(extra = {}) {
  return {
    "User-Agent": VIXSRC_UA,
    Accept: "application/json, text/javascript, */*; q=0.01",
    "Accept-Language": "en-US,en;q=0.9",
    Referer: `${VIXSRC}/`,
    Origin: VIXSRC,
    ...extra,
  };
}

function extractVixsrcTokenData(html) {
  const token = html.match(/token["']\s*:\s*["']([^"']+)/)?.[1];
  const expires = html.match(/expires["']\s*:\s*["']([^"']+)/)?.[1];
  const playlist = html.match(/url\s*:\s*["']([^"']+)/)?.[1];
  if (!token || !expires || !playlist) return null;
  if (parseInt(expires, 10) * 1000 - 60_000 < Date.now()) return null;
  return { token, expires, playlist };
}

function buildVixsrcMasterUrl({ token, expires, playlist }) {
  const separator = playlist.includes("?") ? "&" : "?";
  return `${playlist}${separator}token=${token}&expires=${expires}&h=1`;
}

function bestVixsrcQuality(playlistText) {
  let best = 0;
  for (const line of String(playlistText || "").split("\n")) {
    if (!line.startsWith("#EXT-X-STREAM-INF:")) continue;
    const res = line.match(/RESOLUTION=\d+x(\d+)/i)?.[1];
    const h = res ? parseInt(res, 10) : 0;
    if (h > best) best = h;
  }
  return best > 0 ? `${best}p` : "Auto";
}

async function resolveVixsrc(type, tmdbId, season, episode) {
  const apiUrl =
    type === "tv"
      ? `${VIXSRC}/api/tv/${tmdbId}/${season}/${episode}`
      : `${VIXSRC}/api/movie/${tmdbId}`;

  const apiRes = await fetch(apiUrl, { headers: vixsrcHeaders() });
  if (!apiRes.ok) {
    return {
      ok: false,
      error: `vixsrc api ${apiRes.status}`,
      cfBlocked: apiRes.status === 403 || apiRes.status === 401,
    };
  }

  let apiJson;
  try {
    apiJson = await apiRes.json();
  } catch {
    return { ok: false, error: "vixsrc api bad JSON" };
  }

  const srcPath = apiJson?.src;
  if (!srcPath) return { ok: false, error: "vixsrc api missing src" };

  const pageUrl = srcPath.startsWith("http")
    ? srcPath
    : `${VIXSRC}${srcPath.startsWith("/") ? "" : "/"}${srcPath}`;

  const pageRes = await fetch(pageUrl, {
    headers: vixsrcHeaders({
      Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      Referer: apiUrl,
    }),
  });
  if (!pageRes.ok) {
    return {
      ok: false,
      error: `vixsrc embed ${pageRes.status}`,
      cfBlocked: pageRes.status === 403 || pageRes.status === 401,
    };
  }

  const html = await pageRes.text();
  const tokenData = extractVixsrcTokenData(html);
  if (!tokenData) return { ok: false, error: "vixsrc token missing/expired" };

  const masterUrl = buildVixsrcMasterUrl(tokenData);
  let quality = "Auto";
  try {
    const plRes = await fetch(masterUrl, {
      headers: vixsrcHeaders({
        Accept: "*/*",
        Referer: apiUrl,
      }),
    });
    if (plRes.ok) {
      quality = bestVixsrcQuality(await plRes.text());
    }
  } catch {
    // quality probe is best-effort; master URL alone is enough for playback
  }

  return {
    ok: true,
    server: "VixSrc",
    sources: [
      {
        url: masterUrl,
        quality,
        type: "hls",
      },
    ],
    referer: apiUrl,
  };
}

function authorize(request, env, url) {
  const secret = env.YORU_RELAY_SECRET || "";
  const key =
    request.headers.get("x-yoru-key") || url.searchParams.get("key") || "";
  return Boolean(secret) && key === secret;
}

async function cachedJson(cacheKeyUrl, compute) {
  const cacheKey = new Request(cacheKeyUrl);
  const cache = caches.default;
  const cached = await cache.match(cacheKey);
  if (cached) {
    const hit = new Response(cached.body, cached);
    hit.headers.set("x-yoru-cache", "HIT");
    return hit;
  }

  const result = await compute();
  const res = json(result, result.ok ? 200 : 502);
  if (!result.ok) return res;

  const store = new Response(await res.clone().text(), {
    status: 200,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "access-control-allow-origin": "*",
      "cache-control": "public, max-age=120",
      "x-yoru-cache": "MISS",
    },
  });
  await cache.put(cacheKey, store.clone());
  return store;
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method === "OPTIONS") {
      return new Response(null, {
        headers: {
          "access-control-allow-origin": "*",
          "access-control-allow-methods": "GET, OPTIONS",
          "access-control-allow-headers": "x-yoru-key, content-type",
        },
      });
    }
    if (url.pathname === "/health") {
      return json({ ok: true, routes: ["/resolve", "/vixsrc"] });
    }

    if (url.pathname !== "/resolve" && url.pathname !== "/vixsrc") {
      return json({ ok: false, error: "not found" }, 404);
    }
    if (!authorize(request, env, url)) {
      return json({ ok: false, error: "unauthorized" }, 401);
    }

    const type = url.searchParams.get("type") === "tv" ? "tv" : "movie";
    const tmdbId = url.searchParams.get("tmdb") || "";
    if (!tmdbId) return json({ ok: false, error: "tmdb required" }, 400);
    const season = url.searchParams.get("season") || "1";
    const episode = url.searchParams.get("episode") || "1";

    try {
      if (url.pathname === "/vixsrc") {
        return await cachedJson(
          `https://yoru-cache.internal/vixsrc?type=${type}&tmdb=${encodeURIComponent(tmdbId)}&season=${encodeURIComponent(season)}&episode=${encodeURIComponent(episode)}`,
          () => resolveVixsrc(type, tmdbId, season, episode)
        );
      }

      // Yoru /resolve (unchanged behaviour)
      return await cachedJson(
        `https://yoru-cache.internal/resolve?type=${type}&tmdb=${encodeURIComponent(tmdbId)}&season=${encodeURIComponent(season)}&episode=${encodeURIComponent(episode)}`,
        () =>
          resolve(
            type,
            tmdbId,
            season,
            episode,
            url.searchParams.get("title") || "",
            url.searchParams.get("year") || "",
            url.searchParams.get("imdb") || ""
          )
      );
    } catch (e) {
      return json({ ok: false, error: String(e?.message || e) }, 500);
    }
  },
};
