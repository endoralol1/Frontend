/**
 * Cloudflare Worker: Yoru (Cineplay/Videasy) resolve relay.
 *
 * Why: VPS datacenter IPs are Cloudflare-banned on api.speedracelight.com.
 * This Worker fetches with Origin https://www.vidking.net from CF edge,
 * decrypts enc=2 payloads, and returns plain HLS URLs for our native player.
 *
 * Deploy (Cloudflare dashboard → Workers → Create → paste this file):
 *   Route or workers.dev URL, then set on VPS:
 *     YORU_RELAY_URL=https://yoru-relay.<your-subdomain>.workers.dev
 *     YORU_RELAY_SECRET=<same as wrangler secret YORU_RELAY_SECRET>
 *
 * Bind secret: wrangler secret put YORU_RELAY_SECRET
 */
const API = "https://api.speedracelight.com";
const META = "https://db.speedracelight.com/3";
const ORIGIN = "https://www.vidking.net";

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
    if (url.pathname === "/health") return json({ ok: true });
    if (url.pathname !== "/resolve") return json({ ok: false, error: "not found" }, 404);

    const secret = env.YORU_RELAY_SECRET || "";
    const key = request.headers.get("x-yoru-key") || url.searchParams.get("key") || "";
    if (!secret || key !== secret) return json({ ok: false, error: "unauthorized" }, 401);

    const type = url.searchParams.get("type") === "tv" ? "tv" : "movie";
    const tmdbId = url.searchParams.get("tmdb") || "";
    if (!tmdbId) return json({ ok: false, error: "tmdb required" }, 400);
    const season = url.searchParams.get("season") || "1";
    const episode = url.searchParams.get("episode") || "1";

    // Short edge cache so many viewers of the same title share one upstream scrape.
    const cacheKey = new Request(
      `https://yoru-cache.internal/resolve?type=${type}&tmdb=${encodeURIComponent(tmdbId)}&season=${encodeURIComponent(season)}&episode=${encodeURIComponent(episode)}`
    );
    const cache = caches.default;
    const cached = await cache.match(cacheKey);
    if (cached) {
      const hit = new Response(cached.body, cached);
      hit.headers.set("x-yoru-cache", "HIT");
      return hit;
    }

    try {
      const result = await resolve(
        type,
        tmdbId,
        season,
        episode,
        url.searchParams.get("title") || "",
        url.searchParams.get("year") || "",
        url.searchParams.get("imdb") || ""
      );
      const res = json(result, result.ok ? 200 : 502);
      if (result.ok) {
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
      return res;
    } catch (e) {
      return json({ ok: false, error: String(e?.message || e) }, 500);
    }
  },
};
