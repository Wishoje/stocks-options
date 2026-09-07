# GEX-026 through GEX-028 rollout

These changes are disabled by default. Code deployment, durable publication initialization, Redis cache cutover, and query-feature activation are separate steps. A successful deployment alone does not activate them.

## Prerequisites

- Confirm a current restorable MySQL backup before deploying the additive publication migration.
- Verify the same release on the web and worker nodes. Preserve their existing selector policies and provider concurrency limits.
- Record effective Redis connections, database numbers and key prefixes without copying credentials into logs or reports. A Redis database number is not process isolation.
- Keep queue Redis, provider coordination and locks unchanged. Never run a global cache flush, queue purge or destructive schema rollback as part of this rollout.

The publication migration adds `eod_cache_publication_state` and `eod_cache_publications`. It does not alter market-data tables or remove indexes. The publication head's symbol key uses a binary collation to retain exact key identity.

After each environment change, rebuild configuration on both nodes with `php8.3 artisan config:cache`. Use the normal deployment activation to reload the web PHP process, and gracefully restart the relevant managed workers. Do not substitute `cache:clear` or `optimize:clear` for this step.

## Phase 1: durable publications and isolated cache

Start with both switches disabled on both application nodes:

```dotenv
EOD_CACHE_PUBLICATIONS_WRITE_ENABLED=false
EOD_CACHE_PUBLICATIONS_READ_ENABLED=false
```

After the additive migration, inspect the current state from the worker release:

```bash
php8.3 artisan gex:publications
php8.3 artisan gex:publications --inventory
```

The inventory is read-only. It scans only the legacy publication namespace with iteration, key-count and elapsed-time checks. It requires phpredis, complete version/issuance metadata and the original cache connection. It refuses unknown ordering instead of guessing a timestamp. Keep its counts and fingerprint in private deployment evidence.

Before initialization, pause the relevant producers and drain the old data writers and finalizers. Check ready, reserved and delayed jobs, not just running worker processes. Identify the exact scheduler and Supervisor groups from the running server; do not guess their names. Do not purge old work. If it cannot be drained safely, postpone cutover.

While both publication switches remain false and the original cache is still selected:

```bash
php8.3 artisan gex:publications --prepare --writers-drained
```

`--writers-drained` is an operator attestation, not an automatic queue drain. The command compares two inventories and imports exact completed tokens once. A newer durable GEX certificate takes precedence over a stale Redis head. The initialization also records a cutover floor. Pre-cutover finalizers cannot create a new head afterward. Missing heads receive a new unpublished namespace, never an old `initial` payload key.

Enable both publication switches while producers remain paused:

```dotenv
EOD_CACHE_PUBLICATIONS_WRITE_ENABLED=true
EOD_CACHE_PUBLICATIONS_READ_ENABLED=true
```

Refresh configuration on both nodes and gracefully restart the managed workers. Confirm effective settings through `gex:publications`. Durable reads require durable writes. Database failure is not converted into a stale Redis fallback. Publishing the selected domains and an eligible GEX certificate uses the same per-symbol database transaction. Redis mirrors and manifest repair happen only after the outer transaction commits.

Prepare an independent cache service using the reviewed `gex-026-create-cache-redis.sh` recipe. It provisions only a new private service on port 6381 with a conservative `noeviction` policy. It must not restart or reconfigure existing lock/provider or queue Redis. Check host memory headroom and private connectivity from both application nodes first.

Before changing the payload-cache connection, enable coordination-state routing on both nodes:

```dotenv
CACHE_COORDINATION_ENABLED=true
REDIS_COORDINATION_DB=1
```

Set `REDIS_COORDINATION_DB` to the original effective cache database when it differs from 1. Preserve both existing key prefixes and any original Redis URL routing. This keeps gamma-strength facts, legacy calculator claims, provider retry budgets and rate limits on the original Redis process. Locks continue using the original no-eviction connection. Verify the old gamma fields and calculator state before moving payloads.

Configure only the cache-specific connection overrides for the new private service:

```dotenv
REDIS_CACHE_HOST=<private cache host>
REDIS_CACHE_PORT=6381
REDIS_CACHE_PASSWORD=<new cache service password>
```

Use the actual authenticated service settings. Do not paste the placeholders literally. Leave queue and original `REDIS_*` settings unchanged. A cache-specific URL takes precedence; an inherited original URL is suppressed when explicit cache connection overrides are supplied.

After refreshing configuration and explicitly restarting the affected Supervisor workers:

```bash
php8.3 artisan gex:publications --mirror
php8.3 artisan gex:publications --topology
php8.3 artisan gex:warm-cache --symbols=SPY,QQQ,IWM,TSLA,AAPL --timeframes=7d,14d,30d,90d
```

Run topology checks on both nodes. `checks_passed` must be true. Check identical effective role mapping, original coordination state, queue health and complete endpoint payloads. A topology report is point-in-time evidence, not proof of restart durability. The original Redis restart signal remains on the default cache, so `queue:restart` alone cannot notify workers still connected to the old cache.

Resume producers only after the checks pass. Inspect newly completed work, publication ordering, manifest health and error logs. Do not claim that restarting the coordination service itself is safe: legacy claims and derived facts still rely on that service. The isolated payload-cache restart is the failure boundary covered here.

## Phase 2: activity pricing

Keep `ACTIVITY_BATCH_PRICING_ENABLED=false` until parity probes pass on stable data. Compare calls, puts, duplicate quote keys, missing pricing inputs, filters and 1/10/100/200-row requests. Include a request larger than one internal batch. Freeze the estimator's native clock in the verification harness.

Use a process-local array response cache for cold paired probes. Never flush shared Redis to obtain a cold measurement. Record complete payload hashes, all SQL statements, summed SQL time, response timing and raw pricing-query handler reads. Include metadata queries introduced by durable publication reads. Stored-premium and warm responses already avoid most fallback work; report their results separately.

Then enable on both nodes, refresh configuration and restart managed workers:

```dotenv
ACTIVITY_BATCH_PRICING_ENABLED=true
```

Check both cold and repeated activity requests before proceeding. Disable this switch to return to the legacy estimator lookup path if parity fails. Keep the durable publication and coordination switches enabled.

## Phase 3: expiration batch

Keep `EXPIRY_PRESSURE_BATCH_ENABLED=false` until the final query shape passes payload, row-work and timing checks. A lower statement count alone is insufficient: an optimizer plan that repeatedly scans the large chain table must not be activated.

Compare 1, 15, 250, 500 and 501 symbols, plus a heavy-symbol fixture and mixed missing/stale/future-only inputs. Preserve the endpoint's existing uncapped admission; internal chunks are not a new request limit. Record query plans and handler reads. If statement-level rows-examined counters are unavailable, report them as unavailable rather than zero.

After verification:

```dotenv
EXPIRY_PRESSURE_BATCH_ENABLED=true
```

Refresh configuration on both nodes and verify expiration watchlist summaries and the unchanged single-symbol expiration page. The candidate and legacy response-cache namespaces are separate, so switching this flag off restores the legacy namespace without a global flush.

## Rollback

Activity and expiration switches can be disabled independently. Each candidate has its own response-cache namespace, so rollback restores the legacy namespace. These switches do not change the queue connection or publication ordering.

For payload-cache rollback, retain durable publication reads/writes and coordination routing. Restore only the previous cache connection settings, refresh configuration, explicitly restart managed workers, repair mirrors and warm representative pages. Original coordination and queue data must remain untouched.

If legacy Redis publication reads are temporarily required, first repair mirrors and verify them, then disable only `EOD_CACHE_PUBLICATIONS_READ_ENABLED`. Keep durable writers enabled. This temporary mode gives up the durable-read cache-loss guarantee and should not be treated as the normal operating mode. Never disable both switches casually or delete the publication tables to roll back a page-query change.

## Manual checks

1. Open Strikes for SPY, QQQ, IWM, TSLA and AAPL. Select 1 week, 2 weeks, 1 month and 3 months. Verify first load and repeat load, no 504, sensible dates, nonempty strikes when data exists, and unchanged gamma-strength fields.
2. Compare the same symbol/timeframe before and after activation against stable completed data. Different timeframes can legitimately contain different expirations and totals; do not require their totals to be equal.
3. Open unusual activity. Try calls, puts, different sorts and filters, estimated-premium rows, and Show More. Confirm ordering, premiums, empty states and repeat-load behavior.
4. Open the watchlist expiration summaries and individual expiration pages. Include a symbol without data. Missing symbols must not prevent other entries loading or become fabricated zero-valued data.
5. Open the calculator for a heavy and a newly selected symbol. Confirm one refresh progresses to completion, existing results remain usable and repeated clicks do not create duplicate work.
6. Check deployment/config parity on both nodes, managed workers, durable work counts and new application errors. Save timings and payload comparisons privately before marking each phase complete.
