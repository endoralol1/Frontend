# VAPlayer via Cloudflare Worker

## Status
VAPlayer (`streamdata.vaplayer.ru`) often still works **direct from the VPS**.
If they throttle the datacenter IP, route resolve through the Yoru Worker instead.

## How
1. Deploy updated `newsite/workers/yoru-relay.js` to Worker `divine-frost-3156`
   (adds `GET /vaplayer`).
2. Cinepro `vaplayer-resolver.ts` prefers Worker when
   `YORU_RELAY_URL` + `YORU_RELAY_SECRET` are set (`VAPLAYER_PREFER_WORKER=1`).
3. Falls back to direct VPS fetch if Worker fails / route not deployed yet.

## Note
- **Yoru/Videasy** already uses Worker `/resolve` (speedracelight CF bans).
- **VixSrc** Worker still gets CF 403 — needs residential proxy.
- Worker helps only if upstream treats Worker egress better than the VPS IP.
EOF