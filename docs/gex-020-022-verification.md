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

### GEX-020 results

Commit `b463d01` is deployed and active on both servers. Targeted checks passed 208 PHP tests / 4,362 assertions, all 105 frontend tests, and the production build. Eight live closed-session job/summary probes returned HTTP 200 in 3.22–20.21 ms with zero provider calls and the unchanged baseline total-row fingerprint. All 26 workers were running; the public dashboard bundle matched the release.

## GEX-021: provider backoff and fill pressure

All 12 Massive HTTP boundaries retain the shared semaphore. Admissions no longer block workers, and HTTP clients make one transport attempt. HTTP 429/5xx, timeouts and network failures become typed deferrals. Numeric and HTTP-date Retry-After values are respected with positive jitter. Concurrent cooldown responses can extend, but not shorten, the shared deadline. A local fallback also protects the current process if writing the shared cooldown fails. Coordination failure is observable; distributed communication failures cannot guarantee delivery of a cooldown to another process.

WorkRuns and bootstrap phases persist retry deadlines and revoke old delivery tokens. Zero-HTTP admission waits do not consume the real failure budget. Wait lifetimes are bounded and stale callbacks are rejected. Legacy queued chains retain their original payload and use a fixed 12-hour retry deadline. Synchronous commands propagate the deferral instead of reporting success without fetching data.

Successful pages can be reused within the same generation or queue UUID. Checkpoints expire after 20 minutes, with a 2 MiB uncompressed page cap and 16 MiB stored cap per execution. They use cache Redis, not queue Redis, and do not store authentication data. Expiration, eviction or size limits can cause repeat reads; they never authorize partial publication. A measured two-page whole-job retry used 3 provider calls with reuse versus 4 without, with identical final data. This does not claim fewer calls than the former sleeping page-level retry.

Scheduled calculator fills now persist stable WorkRuns before queueing and recheck completed publication freshness at execution. Fill admission pauses at interactive ready depth 6, interactive ready-head/due-intent age 30 seconds, fill depth 50, or fill ready-head/due-intent age 120 seconds. Existing fill consumers check only interactive pressure to avoid deadlocking on their own backlog. Sampling uses a bounded Redis pipeline and one grouped DB query. Ready-head age and oldest due durable intent are labelled separately.

The optional request-window allowance remains unset because no plan-specific numeric rate is verified. Concurrency stays at six, divided 3+3; this is not a requests-per-second limit.

Activation after both releases, the additive migration, and draining old provider jobs:

```bash
php8.3 docs/operations/gex-refresh-policy.php 021 enable
php8.3 artisan config:cache
php8.3 artisan queue:restart
php8.3 artisan market-data:refresh-status --symbols=SPY,QQQ,IWM,TSLA
```

Run on both servers. The helper changes only PROVIDER_BACKPRESSURE_ENABLED and makes a protected backup. Old legacy payloads lack the new fixed retry deadline, so inspect their queues before activation. Rollback uses `021 disable`; retain the additive metadata. Do not flush Redis or raise the provider ceiling to clear a cooldown.

### Manual checks

1. Run the status command. Check enabled flags, the unchanged 3+3 split, cooldown, queue depth and due-intent age. It makes no provider calls or market-data writes.
2. Open calculator expirations for SPY, QQQ, IWM and TSLA. Existing published chains should remain usable while fills wait. Repeated scheduling should reuse pending intent.
3. If the provider throttles naturally, inspect the WorkRun or bootstrap phase deadline. It should wait and resume. Do not deliberately generate real provider 429s.
4. Check worker status and logs for new terminal errors, comparing their timestamps with the release rather than treating historical failures as new regressions.

## GEX-022

Implementation and results are recorded after GEX-021 passes live verification.
