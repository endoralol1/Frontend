#!/usr/bin/env python3
"""ui103: Keep only last 5 Continue Watching titles (DB + client).

Deployed via scp of patched files from chillflix-patches/newsite/.
Key behavior:
- One DB/local slot per movie OR tv show (tv:{id}, not per-episode)
- upsertContinue prunes to 5 after each write
- listContinue LIMIT 5
- Client CW_MAX = 5; cookie/rail/merge collapse to title keys
"""
print(__doc__)
