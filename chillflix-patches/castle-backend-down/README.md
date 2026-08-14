# Castle (Multi-Audio) / Nxsha backend down

## What Castle is
Player source `castle` → label **Castle (Multi-Audio)** via `NxshaSources`.
Backend: `https://fantastic-flow-production-4da0.up.railway.app/movie|{tv}/…/castle`

## Root cause (2026-08-14)
Railway returns `404 Application not found` (`x-railway-fallback: true`).
The fantastic-flow app is undeployed/gone, so Castle always shows **No source**.

## Mitigation
- Detect Railway app-missing → `NXSHA_BACKEND_DOWN`
- Disabled DB sources `castle` (+ `awsind`, same host) until a new backend URL is set
