# Admin source test timing

Shows how long each provider takes to return a **playable** stream in `/admin/sources`.

## What
- `SourcesService::test()` records `elapsedMs` / `scrapeMs` / `probeMs`
- Probes the first returned URL (HLS `#EXTM3U` or media bytes)
- Persists `elapsed_ms` + `playable` on `source_test_logs`
- Admin list shows last test badge: `⏱ 1.08s · playable`
- Test toast includes scrape + probe breakdown

## DB
```sql
ALTER TABLE source_test_logs
  ADD COLUMN elapsed_ms int unsigned NULL DEFAULT NULL AFTER source_count,
  ADD COLUMN playable tinyint(1) NOT NULL DEFAULT 0 AFTER ok;
```
