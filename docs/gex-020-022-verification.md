# GEX-020, GEX-021 and GEX-022 verification

## Release order

Each card is deployed and checked on both production servers before the next card is activated. Queue transport stays on Redis6380. Cache, locks and the provider semaphore stay on Redis6379. The verified provider concurrency ceiling remains6, split into3 interactive and3 background permits.

## GEX-020: source time and ingestion freshness

The additive `intraday_refresh_states` table stores provider source time, collection start, receipt, successful publication time and the owning WorkRun separately. No existing totals or strike calculations are backfilled or rewritten. Counter `asof` remains an internal ingestion-ordering clock; the API exposes `source_asof` as its display `asof`. Legacy rows and responses without trustworthy provider timestamps report source time as unknown.

A completed ingestion is reusable for90seconds, independently of source age. Pending runs are coalesced and failed runs retain their existing retry cooldown. Admission and execution reject closed-market requests and jobs whose frozen trade date differs from the current session. The collection window is09:30 through15minutes after the core-session close. Beforeopen, weekends and holidays serve the last available snapshot. A new symbol without a snapshot waits for the next session; this no longer prevents its EOD bootstrap from completing. Bootstrap `intraday_ready` remains false for that closed-session outcome.

Session rules cover regular holidays,13:00 early closes, daylight-saving changes and the2025-01-09 exceptional closure. Scheduled dates were checked against the [NYSE calendar](https://www.nyse.com/trade/hours-calendars). Exceptional future closures require a calendar update. This policy is for application snapshot collection, not every venue's options or extended-hours trading schedule.

Provider source time comes only from valid option `day.last_updated` timestamps. Missing values, midnight session markers, future values and timestamps from another NY session are not replaced with the local clock. Field selection follows the [Massive option-chain snapshot response](https://massive.com/docs/rest/options/snapshots/option-chain-snapshot).

Activation after the shared DB migration:

```bash
php8.3 docs/operations/gex-refresh-policy.php 020 enable
php8.3 artisan config:cache
php8.3 artisan queue:restart
```

Run this from the active release on both web and worker. The helper changes only `INTRADAY_FRESHNESS_ENABLED` and makes a protected environment backup. Rollback uses the same commands with `020 disable`; retain the additive table and existing data.

### Manual checks

1. Hard-refresh the website and open SPY,QQQ,IWM and TSLA in EOD Strikes. Switch through1W,2W,1M and3M. Check that strikes and totals still load and a cached timeframe does not retain an old504 error.
2. Open Intraday. Outside collection hours, existing strikes remain visible. A symbol without data shows the next trading session rather than an endless first-snapshot promise. Monday2026-09-07 is a holiday; the next opening is Tuesday2026-09-08 at09:30ET.
3. Inspect `/api/intraday/summary?symbol=SPY` in browser Network. Check `snapshot_available`, `source_asof`, `received_at`, `ingestion_completed_at`, `refresh_eligible`, `refresh_reason` and `market_session`. An unknown source time is allowed for existing rows and must not be shown as a newly generated market timestamp.
4. During collection hours, after one refresh finishes, repeated visits within90seconds should reuse it even if provider data is delayed. A pending run should be reused. A failed run must retain its retry deadline.
5. When source time is available, compare it with the displayed ET timestamp. Delayed data should be labelled delayed, not simply Live.

### Production baseline

At2026-09-06T07:53:29Z, both servers ran8c808b2. SPY,QQQ,IWM and TSLA canonical total rows for2026-09-04 had a combined SHA256 of `93022975c02249c95b08b34817676bbbfe5b26231a0e75bd018f90629676e01f`. This fingerprint includes all stored row fields, not only displayed volume.

Live verification is performed during a closed Sunday session. Market-hours and failure behavior are also exercised with controlled tests; this does not substitute for a later peak-market traffic measurement.

## GEX-021 and GEX-022

Implementation, activation and measured results are recorded here after their preceding phase has passed verification.
