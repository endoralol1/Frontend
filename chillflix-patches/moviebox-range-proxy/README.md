# MovieBox black screen / stuck at 0:01

## Cause
MovieBox (and HollyBox) mint media-proxy tokens via `StremifySources::signedProxyUrl`.
`/api/player/media-proxy` tries `VidmolySources::proxyMedia` first; with a shared `auth_secret`
those tokens validate there and hit the **buffered** `proxyFetch` path, which:

- returns `206` without forwarding `Content-Range`
- sets `Content-Length` to the buffered chunk size only

HTML5 progressive MP4 then stalls (black frame ~0:01) because the browser cannot learn the
full file size or seek to `moov`.

## Fix
When the token has no playlist-rewrite flag (`r`), stream with curl and forward
`Content-Type` / `Content-Length` / `Accept-Ranges` / `Content-Range` (same pattern as
`StremifySources::proxyMedia`).

## Verify
```bash
curl -sSID - -H 'Range: bytes=0-1023' \
  "$(curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=1339713&provider=moviebox' \
    | python3 -c 'import sys,json;print(json.load(sys.stdin)["sources"][0]["url"])')"
# expect: Content-Range: bytes 0-1023/<total>
```
