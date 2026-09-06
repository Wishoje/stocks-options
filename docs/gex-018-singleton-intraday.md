# GEX-018: one intraday symbol per job

## Behavior

The scheduler and warmup command persist one durable WorkRun per canonical symbol and session before enqueueing. Each queued job owns one symbol. A failed symbol or slow heavy symbol no longer forces unrelated symbols through the same batch retry. Concurrent compatible requests reuse the existing run. A queue dispatch failure leaves a durable intent for reconciliation and does not prevent later symbols from being accepted.

Watchlist canonicalization and deduplication happen in SQL. Warmup ranking is applied after canonical grouping. The job still fetches its existing eight-expiration scope and uses the GEX-019 writer. No contract fields, publication scope, provider request limit, or row-retention policy are reduced.

All producers use the shared queue classifier. Existing API and bootstrap paths already submit one-symbol jobs and retain their bounded interactive behavior. Scheduled heavy symbols stay on intraday-heavy; normal symbols use intraday. The CLI uses interactive capacity for normal symbols and the heavy lane for full heavy refreshes. Provider pagination uses the same configurable heavy-symbol list.

## Activation and rollback

Deploy the same revision to both servers. The code defaults remain off:

```dotenv
INTRADAY_SINGLETON_JOBS_ENABLED=false
INTRADAY_SINGLETON_ROLLOUT_VALIDATED=false
```

After GEX-017 production and disposable recovery checks pass, run `php8.3 docs/operations/gex-017-configure-queue.php singletons-enable` from each current release, rebuild cached configuration, and restart workers. The helper checks current queue readiness before enabling the flags. The dispatcher requires both flags, the dedicated queue Redis connection, isolated lanes, distinct normal/heavy/interactive queues, and the shared provider semaphore.

For routing rollback, use `singletons-disable`, rebuild config, and restart workers. Existing queued singleton jobs remain compatible. Queue-transport rollback also disables singleton production before returning to the former Redis process. Never clear a queue or remove existing payloads for rollback.

Legacy array deserialization remains intentionally supported. Remove it only in a later cleanup after the agreed soak period covers queued, reserved, delayed, and retryable legacy payloads. Old failure records have not been deleted.

## Verification

Frozen-fixture tests compare a 15-symbol legacy batch with 15 durable singletons. They compare stored source fields, totals, null values and capture timestamps, normalizing only generated database IDs. Failure isolation tests hold one heavy job and fail another symbol while the other thirteen complete. Gate, duplicate admission, Redis failure, SQL deduplication, scheduler, warmup, and CLI paths are covered.

Read-only production measurement on 2026-09-06, eight upcoming expirations per symbol:

| Symbol | Provider requests | Contracts | Fetch time |
| --- | ---: | ---: | ---: |
| IWM | 9 | 1,022 | 792 ms |
| TSLA | 9 | 1,508 | 835 ms |
| SPY | 12 | 1,620 | 1,026 ms |
| QQQ | 12 | 2,076 | 1,160 ms |
| V | 8 | 974 | 713 ms |

Every tested expiration was complete. No market data was written for this measurement. SPY and QQQ remain the configured heavy symbols; IWM and TSLA remain normal. The measured provider limit stays six, normal workers four, heavy workers two, and interactive workers two. Weekend measurements do not establish peak market-hour duration or saturated queue-wait SLOs.

## Manual test plan

1. Open SPY, QQQ, IWM, V, and one normal watchlist symbol. Verify EOD Strikes, Intraday, and Calculator still show data.
2. Switch among tabs and symbols. Previously cached charts should remain populated. Inspect Network for successful responses and check that no new 504 or empty-strike regression appears.
3. During an open market session, request an intraday refresh for a normal symbol and for SPY or QQQ. Each should receive its own work status. A pending heavy symbol should not prevent the normal symbol from completing.
4. Refresh the same symbol twice quickly. Compatible requests should share the active work rather than create duplicate provider work. After completion, verify call volume plus put volume equals the displayed total and does not double.
5. Check `php8.3 artisan queue:readiness --json`, Supervisor status, and the queue-monitor log. Check the five-minute `intraday.singleton_scheduler` result for created/reused/deferred/failed counts.
6. Observe at least one active market session before accepting latency/fairness SLOs or removing legacy compatibility. Closed-session timestamps may remain on the last completed trading session; this is not a stuck ingestion signal by itself.

`checks_passed=true` is the command's automated configuration result. Its `activation_verified=false` field remains an explicit reminder that process coverage, recovery drills, browser testing, and market-hours observations are separate checks.

## Known issue observed during validation

Sunday first-use bootstrap requests for SPY/QQQ attempted the expired 2026-09-04 expiration and reported an incomplete intraday response. The same date-selection behavior predates this rollout in commit `29d35be`. Direct GEX read probes do not create bootstrap runs. This is GEX-020 session/freshness follow-up, not a queue transport failure. Existing stored EOD/intraday chart reads remained populated. Failed records were preserved.

Do not fix this by excluding expired contracts while continuing to publish under Friday's trade date: that could replace complete Friday totals with only the remaining expirations. A follow-up must distinguish reuse of a complete closed-session snapshot from unavailable historical data.
