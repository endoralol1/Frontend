# Chillflix patches (VPS `/var/www/chillflix.lol`)

## Ads

Site-player ads restored to the original Monetag integration:

```json
{"integration":"llvpn","zone":"11200416","llvpnTag":"https://llvpn.com/tag.min.js"}
```

The Mapple-style `/api/rum` anti-adblock experiment was reverted. Unused `/api/rum` / cron artifacts may still exist on disk but are not used by the player.
