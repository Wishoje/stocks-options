# GEX-020, GEX-021 and GEX-022 verification

## Scope and release order

Each phase is deployed and checked on both production servers before the next phase is activated. Queue transport remains on Redis port 6380. Cache, locks and provider coordination remain on port 6379. Provider concurrency remains six: three interactive permits and three background permits. No requests-per-second allowance is inferred from this concurrency setting.

The production checks below were performed on Sunday, September 6, 2026. They prove closed-session behavior, stored-data consistency and current request performance. They do not constitute a peak-market load test.

## GEX-020: source timestamps and ingestion freshness

Commit: `b463d01`. Deployed and active on both servers.

The additive `intraday_refresh_states` table records provider source time, collection start, receipt, successful publication time and the owning WorkRun separately. Existing totals and strike calculations are not rewritten. Counter `asof` remains an internal ordering clock; the API exposes trustworthy `source_asof` as its display timestamp. Existing rows without trustworthy source time report it as unknown.

A completed ingestion is reusable for 90 seconds independently of source age. Pending runs coalesce; failed runs retain their retry deadline. Admission and execution reject closed-session work and jobs for a different frozen trade date. The collection window is 09:30 through 15 minutes after the core-session close, with the upper boundary excluded.

Before opening, on weekends and on holidays, stored intraday data remains available. A new symbol without a snapshot waits for the next session. That closed-session outcome does not block its EOD bootstrap, and `intraday_ready` remains false.

Calendar rules include regular holidays, 13:00 early closes, daylight-saving changes and the January 9, 2025 exceptional closure. Dates were checked against the [NYSE calendar](https://www.nyse.com/trade/hours-calendars). Exceptional future closures require a calendar update. These are application collection hours, not every venue's options or extended-hours schedule.

Source time comes from valid option `day.last_updated` values. Missing values, midnight markers, future values and timestamps from another New York session are not replaced with the local clock. Field selection follows the [Massive option-chain snapshot response](https://massive.com/docs/rest/options/snapshots/option-chain-snapshot).

### Measured verification

- 208 targeted PHP tests / 4,362 assertions passed.
- All 105 frontend tests and the production build passed.
- Eight live closed-session job/summary checks returned HTTP 200 in 3.22-20.21 ms with zero provider calls.
- All 26 workers were running.
- SPY, QQQ, IWM and TSLA total rows were unchanged.

## GEX-021: durable provider backoff and queue pressure

Commit: `63db249`. Deployed and active on both servers.

All Massive request boundaries retain the shared semaphore. Admissions do not block workers. Each HTTP request makes one transport attempt; HTTP 429/5xx, timeouts and network failures become typed deferrals. Numeric and HTTP-date Retry-After values are respected with positive jitter. Shared cooldown updates can extend a deadline, but cannot shorten it.

WorkRuns and bootstrap phases persist retry deadlines and revoke old delivery tokens. Zero-HTTP admission waits do not consume the actual failure budget. Wait lifetimes are bounded and stale callbacks are rejected. Legacy queued chains retain their original payload and a fixed 12-hour retry deadline. Synchronous callers propagate a deferral instead of claiming a completed fetch.

Successful option pages can be reused within the same execution generation. Checkpoints expire after 20 minutes. Limits are 2 MiB per uncompressed page, 16 MiB stored payload per execution and 1,024 pages. They use cache Redis and do not store authentication data. Expiration, eviction and size limits may cause repeat requests; they never allow partial publication. Encoded-payload limits are not an exact bound on Redis allocator memory.

Scheduled calculator fills persist stable WorkRuns before enqueueing and recheck completed publication freshness at execution. Fill admission pauses at these thresholds:

| Signal | Threshold |
| --- | --- |
| Interactive ready depth | 6 |
| Interactive ready-head or due-intent age | 30 seconds |
| Fill ready depth | 50 |
| Fill ready-head or due-intent age | 120 seconds |

Existing fill consumers check only interactive pressure, so their own backlog cannot stop them all. Sampling uses a bounded Redis pipeline and one grouped database query. Ready-head age and durable due-intent age are separate metrics.

If a shared cooldown write fails, the issuing process retains the deadline and logs `other_workers_share_deadline=false`. Other workers cannot know an unpersisted deadline. Full coordination failure admits zero HTTP requests. The optional numeric request-window allowance remains unset because the account's numeric rate limit was not verified.

### Measured verification

- Real Redis and retry/replay tests passed: 64 tests / 211 assertions.
- Ten independent PHP workers observed no more than three concurrent requests per class or six total. Both classes progressed.
- A killed worker's test lease expired and capacity recovered.
- A two-page whole-job retry used three physical requests with replay versus four without, with identical reconstructed data. This does not claim fewer requests than the former sleeping page-level retry.
- Additional durable, HTTP-boundary, queue, calculator, intraday and EOD regression groups passed. Groups overlap; their counts are not added together.
- Both live servers reported enabled policies, the unchanged 3+3 split, no provider cooldown and empty monitored queues.
- An isolated production Redis probe used a fake 429 with Retry-After 120. The first wait and a second limiter instance both observed a 126-second deadline. Only one fake callback ran; no real provider request was made.
- Live intraday reads took 1.89-26.06 ms. Stored totals retained the baseline fingerprint, and all 26 workers were running.

## GEX-022: coalesced quote refreshes

The additive `quote_refresh_states` table records successful quote collection separately from the published quote's source time. The scheduler creates stable per-symbol, session and phase WorkRuns before enqueueing. Repeated ticks reuse active intent. A new five-minute request-start bucket makes a completed symbol due again; a two-second response time cannot accidentally stretch the cadence to ten minutes.

Each queue message contains at most four due symbols. The provider receives a bounded nonempty `tickers` filter. This uses the documented [Massive batch snapshot endpoint](https://massive.com/docs/rest/stocks/snapshots/full-market-snapshot), whose snapshot fields correspond to the [single-ticker endpoint](https://massive.com/docs/rest/stocks/snapshots/single-ticker-snapshot). Empty input makes no request; the implementation never falls through to an unfiltered whole-market fetch.

Execution rechecks freshness and takes a nonblocking per-symbol shared lock. Successful symbols finish independently. Missing or malformed quotes fail only their own intent. A shared provider deferral preserves every unfinished intent and its retry deadline. Lost deliveries can recover as single-symbol messages. Volatile quote responses bypass page replay so a partial HTTP 200 cannot keep replaying an unavailable quote.

Scheduled work uses background provider permits. Explicit first-use bootstrap uses interactive permits. Outside scheduled hours, first-use work reuses a valid stored quote or makes one bounded request when none exists. An unavailable quote is not reported as ready.

Publication checks the current WorkRun slot, token, attempt and frozen session/phase before writing. The v2 writer preserves its existing source ordering, price fields and timestamp behavior, including clearing a missing previous close. The separate v3 calculator quote writer is unchanged.

### Session policy

| Period | Scheduled behavior |
| --- | --- |
| Regular session, 09:30 to close | Due symbols refresh on five-minute ticks |
| Close through close +15 minutes | No scheduled quote fetch |
| Close +15 through close +30 minutes | One successful final refresh per symbol; failed attempts may retry |
| After that window, weekends and holidays | No scheduled quote fetch |

Window ends are excluded. A normal final window is 16:15-16:30 ET; an early-close window is 13:15-13:30 ET. Final completion requires both collection start and receipt after the final window opens. A regular-session request finishing late cannot claim the final refresh. The 15-minute delay accommodates delayed snapshots; it is a collection policy, not a guarantee of provider source freshness.

### Verification

- The existing provider account accepted a real four-symbol request: HTTP 200, four requested and returned symbols, 58.06 ms, no database writes.
- Controlled single-versus-batch fixtures produced identical normalized fields and raw timestamps with four requests versus one (75% fewer for a full batch).
- The focused quote/retry integration run passed 173 tests / 1,135 assertions.
- Calendar tests include early closes, holidays, final-window boundaries, request-start buckets and old serialized payload compatibility.
- The broader regression run passed 650 tests; seven optional Redis checks were then run successfully against explicitly marked disposable instances. Combined: 657 passing PHP tests / 8,825 assertions, with no remaining skipped checks in that selected set.
- All 105 frontend tests and the production asset build passed. PHP syntax and changed-file whitespace checks passed.
- Post-deployment results are recorded after the final phase's live checks.

## Deployment and rollback

The guarded helper modifies only the selected flag, makes a protected environment backup and verifies prerequisites. From the current release on each server:

```bash
php8.3 docs/operations/gex-refresh-policy.php 020 enable
php8.3 docs/operations/gex-refresh-policy.php 021 enable
php8.3 docs/operations/gex-refresh-policy.php 022 enable
php8.3 artisan config:cache
php8.3 artisan queue:restart
php8.3 artisan market-data:refresh-status --symbols=SPY,QQQ,IWM,TSLA
```

Enable each phase only after its shared migration and both releases are present. The 021 switch also requires old provider queues to drain; older legacy payloads lack the fixed retry deadline. These checks were performed before activating 021.

For rollback, run the helper with the affected phase and `disable`, then rebuild configuration and restart workers on both servers. Disable 022 before disabling its 021 prerequisite. Existing durable quote messages retain their managed behavior while draining after a 022 rollback. Retain additive tables and metadata. Do not flush Redis, delete pending jobs or raise provider concurrency to clear a cooldown.

## Manual checks

1. Hard-refresh the browser. Open SPY, QQQ, IWM and TSLA in EOD Strikes. Test 1M and 3M, then 1W and 2W. Expect populated rows without repeated Retry clicks or a 504. Switch quickly between ranges; the final selected range must retain its own data.
2. Switch away and back to 1M. Values should remain stable when no EOD publication has changed. Longer ranges include more expirations; net GEX and wall positions need not change monotonically.
3. Open the calculator for the same symbols and select several expirations. Published contracts and the underlying price should remain available while background fills wait.
4. Open Intraday outside collection hours. Existing strikes should stay visible. A new symbol without data should show the next session, not an endless loading promise. Monday, September 7 is a holiday; the next opening is Tuesday, September 8 at 09:30 ET.
5. In browser Network, inspect `/api/intraday/summary?symbol=SPY`. Check `snapshot_available`, `source_asof`, `received_at`, `ingestion_completed_at`, `refresh_eligible`, `refresh_reason` and `market_session`. Legacy unknown source time is valid and must not appear as newly captured market time.
6. During market hours, repeated intraday visits within 90 seconds of completion should reuse the snapshot. Repeated quote scheduler ticks in the same five-minute bucket should not add duplicate active work. A new bucket can refresh without changing the stable scope key.
7. Run `php8.3 artisan market-data:refresh-status --symbols=SPY,QQQ,IWM,TSLA` from the current release. Check all three policy states, provider 3+3 limits, quote window, ready/reserved/delayed queue counts, pending/running quote intents and oldest pending intent age. This command does not fetch provider data.
8. After a normal close, check quote `final_captured_at`, `final_received_at` and `final_completed_at` during 16:15-16:30 ET. Repeated ticks after a successful final refresh should not fetch that symbol again. Use 13:15-13:30 on an early-close day.
9. If throttling occurs naturally, verify that WorkRuns wait until their persisted retry deadline and resume. Do not deliberately generate real provider 429s. When reporting a problem, include symbol, tab, timeframe and time; omit cookies, authorization headers and environment contents.

## Stored-data fingerprints

The four-symbol canonical total-row fingerprint for September 4 is:

`93022975c02249c95b08b34817676bbbfe5b26231a0e75bd018f90629676e01f`

Before GEX-022, all 196 stored underlying quote rows had fingerprint:

`298a20daf78b57e6f0d4c7058a20dc288fe2b653491d956ac5e461f8479ff4fa`

Fingerprints include all stored fields. EOD and calculator before/after checks compare response data separately from time-varying health metadata. Earlier EOD optimization results and separate data-semantics follow-ups remain in [eod-large-timeframe-hotfix.md](eod-large-timeframe-hotfix.md).
