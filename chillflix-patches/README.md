# Chillflix patches (VPS `/var/www/chillflix.lol`)

## Ads note (reverted)

Site-player ads were temporarily switched to a Mapple-style `/api/rum` anti-adblock test, then **reverted** to the previous Monetag `llvpn.com/tag.min.js` integration so revenue keeps working.

Live site-player config again:
`{"integration":"llvpn","zone":"11200416","llvpnTag":"https://llvpn.com/tag.min.js"}`

Experimental `/api/rum` + cron files may still exist on disk but are unused by the player.
