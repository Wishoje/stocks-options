# GEX-004 queue isolation rollout

Status: Implemented behind a disabled feature flag; production rollout and load proof remain open
Updated: 2026-07-17

## What changes

`QUEUE_LANES_ISOLATED=false` preserves the current queue names and behavior. When isolation is enabled, producers use these lanes:

| Lane | Work | Processes after drain | Worker timeout |
|---|---|---:|---:|
| bootstrap-fast | Existing ordered cold-symbol graph; two reserved consumers | 2 | 300s |
| intraday-interactive | One normal symbol requested by an active user | 2 | 120s |
| calculator-interactive | User-selected expiration | 2 | 300s |
| calculator-fill | Normal full-catalog/background calculator work | 2 | 300s |
| calculator-fill-heavy | SPY, QQQ, IWM full-catalog/background work | 1 | 300s |
| quotes | Existing quote refresh | 2 | 120s |
| intraday | Normal single-symbol non-interactive work | 6 | 120s |
| intraday-heavy | SPY/QQQ singletons and remaining multi-symbol scheduled batches | 2 | 600s |
| default | Enrichment and scheduled compute | 6 | 600s |
| exports on redis-long | AI exports | 1 | 960s |

The bootstrap graph has not been split into durable fast and fill phases. It moves unchanged to two reserved consumers so a single long job cannot occupy all cold-symbol capacity. GEX-010 owns the bounded fast/fill redesign.

SPY and QQQ are dispatched as separate scheduled intraday jobs. Normal batching remains unchanged until GEX-018 proves singleton data equivalence and provider load. A normal 15-symbol batch still runs on `intraday-heavy`, so it can wait behind both heavy consumers when SPY and QQQ are active. GEX-004 reserves other lanes but does not close the bulk-scheduling issue.

The machine-readable rollout and steady profiles are in `ops/forge-workers-isolated.json`. Both profiles use 26 processes. Do not add the new workers on top of the current 26.

## Shared Massive concurrency gate

Every Massive request made by the calculator, EOD chain, intraday client, quote client, price fallback, or symbol search passes through one Redis concurrency gate when `MASSIVE_CONCURRENCY_ENABLED=true`.

The first rollout statically partitions the verified limit between interactive and background requests. For an even limit each class receives half; for an odd limit the extra permit goes to interactive work. The two class limits sum to the provider ceiling, so neither class can consume the other's guaranteed capacity. Idle capacity is not borrowed in this conservative version. GEX-021 may add measured borrowing later.

A permit wraps one fully retried HTTP request, not an entire paginated job. Exceptions and normal completion release it; the Redis lease recovers it after a crashed process. Acquisition waits are bounded by each caller's runtime budget: quotes use one second, daily prices use two, intraday uses five, calculator uses ten, and EOD chain work uses fifteen. The 45-second setting is only a fallback for a future caller without an explicit budget. Quote capacity pressure aborts the batch so the queue can retry instead of waiting once per symbol.

Synchronous symbol search waits at most two seconds before returning a retryable `503`, and that response is never cached as an empty result. GEX-004 remains open until mixed-load testing proves these waits and the normal job retry budgets under production-like contention.

Do not guess the provider limit. Confirm the active Massive plan allowance and set a value no higher than that allowance after accounting for any external process using the same API key. `MASSIVE_CONCURRENCY_LIMIT` must be at least `2`.

Add these settings to both Forge sites for the initial code deployment. Keep both feature flags off and leave the provider limit blank so this deploy preserves the current queue routing and provider throughput:

```dotenv
MASSIVE_CONCURRENCY_ENABLED=false
MASSIVE_CONCURRENCY_CONNECTION=default
MASSIVE_CONCURRENCY_KEY=provider-concurrency:massive
MASSIVE_CONCURRENCY_LIMIT=
MASSIVE_CONCURRENCY_RELEASE_AFTER=90
MASSIVE_CONCURRENCY_BLOCK_FOR=45
MASSIVE_CONCURRENCY_WEB_BLOCK_FOR=2
MASSIVE_CONCURRENCY_SLEEP_MS=100
MASSIVE_CONCURRENCY_METRICS_TTL=172800
QUEUE_LANES_ISOLATED=false
INTRADAY_HEAVY_SYMBOLS=SPY,QQQ
CALCULATOR_HEAVY_SYMBOLS=SPY,QQQ,IWM
```

After the code is live, confirm the numeric simultaneous-request allowance with Massive. In a controlled off-market window, put that value in `MASSIVE_CONCURRENCY_LIMIT` on both sites and change only the gate setting to:

```dotenv
MASSIVE_CONCURRENCY_ENABLED=true
MASSIVE_CONCURRENCY_LIMIT=<verified-provider-concurrency>
QUEUE_LANES_ISOLATED=false
```

Enable the gate on both sites before lane isolation. Gate activation is itself a throughput change: existing bootstrap, prime, and calculator work uses the background partition. Rebuild both configuration caches and reload their long-running PHP/queue processes before testing it.

There is no default provider limit. If the gate is enabled without an explicit limit of at least `2`, provider calls fail closed. If lane isolation is enabled without a valid Massive gate, both queue resolution and request execution throw. The request-time check protects already-serialized jobs when web and worker configuration drift during a rollout.

Daily counters are stored for two days in `provider-concurrency:massive:metrics:YYYY-MM-DD`. Fields include acquisitions, completions, provider exceptions, acquisition timeouts, and total wait milliseconds for each priority. Inspect them without secrets using `redis-cli --askpass HGETALL <key>`.

## Forge rollout profile

Use site-level background processes on the worker site. Keep each definition on one connection and one queue. During cutover, change existing process counts to:

| Queue | Connection | Processes |
|---|---|---:|
| bootstrap | redis | 1 legacy drain |
| prime | redis | 2 legacy drain |
| calculator | redis | 1 legacy drain |
| bootstrap-fast | redis | 2 |
| intraday-interactive | redis | 2 |
| calculator-interactive | redis | 2 |
| calculator-fill | redis | 2 |
| calculator-fill-heavy | redis | 1 |
| quotes | redis | 2 |
| intraday | redis | 4 |
| intraday-heavy | redis | 2 |
| default | redis | 4 |
| exports | redis-long | 1 |

This totals 26. Every definition keeps `--memory=512`, the queue contract's timeout, and `stopwaitsecs=1200`.

New worker commands:

```text
php8.3 artisan queue:work redis --sleep=3 --timeout=300 --memory=512 --tries=3 --force --queue=bootstrap-fast
php8.3 artisan queue:work redis --sleep=3 --timeout=120 --memory=512 --tries=3 --force --queue=intraday-interactive
php8.3 artisan queue:work redis --sleep=3 --timeout=300 --memory=512 --tries=3 --force --queue=calculator-interactive
php8.3 artisan queue:work redis --sleep=3 --timeout=300 --memory=512 --tries=3 --force --queue=calculator-fill
php8.3 artisan queue:work redis --sleep=3 --timeout=300 --memory=512 --tries=3 --force --queue=calculator-fill-heavy
```

Also make the existing `default` worker explicit during the reallocation. Its current Forge command omits a queue name; use:

```text
php8.3 artisan queue:work redis --sleep=3 --timeout=600 --memory=512 --tries=3 --force --queue=default
```

Monitor both new and legacy queues during the transition:

```dotenv
QUEUE_MONITOR_ENABLED=true
QUEUE_MONITOR_CONNECTION=redis
QUEUE_MONITOR_QUEUES=bootstrap,bootstrap-fast,prime,default,intraday-interactive,intraday,intraday-heavy,calculator,calculator-interactive,calculator-fill,calculator-fill-heavy,quotes,exports
QUEUE_MONITOR_TARGETS=redis:bootstrap,redis:bootstrap-fast,redis:prime,redis:default,redis:intraday-interactive,redis:intraday,redis:intraday-heavy,redis:calculator,redis:calculator-interactive,redis:calculator-fill,redis:calculator-fill-heavy,redis:quotes,redis-long:exports
QUEUE_MONITOR_MAX_SIZE=250
```

## Cutover order

1. Capture a GEX-002 market-data baseline and save the current Forge/Supervisor configuration.
2. Deploy the code to the worker site and web site with both flags off.
3. In a controlled off-market window, configure and verify the Massive gate on both sites. Leave `QUEUE_LANES_ISOLATED=false`.
4. Perform the process reallocation and producer cutover as one controlled off-market operation while the legacy queues are empty. Do not leave the reduced legacy profile running during market traffic with the producer flag off.
5. Reallocate the existing workers to the 26-process rollout profile, update monitor targets, and confirm every legacy/new consumer is `RUNNING` with `stopwaitsecs=1200`.
6. Immediately set `QUEUE_LANES_ISOLATED=true` on the worker/scheduler site, rebuild config cache, restart workers, and verify the effective gate, limit, and lane settings.
7. Set the same flag on the web site and rebuild its config cache. If either flag/config verification fails, restore the legacy process counts before returning to market-hours operation.
8. Add a normal cold symbol and a selected calculator expiration. Confirm jobs enter `bootstrap-fast`, `intraday-interactive`, and `calculator-interactive`.
9. Run the mixed-load proof below. Keep the legacy consumers running.
10. Remove legacy `bootstrap`, `prime`, and `calculator` consumers only after ready, reserved, and delayed work are all zero. Move their four processes to `intraday` and `default` according to the steady profile.

## Required proof before closing GEX-004

- Queue 15 normal scheduled symbols, SPY, QQQ, one new normal symbol, selected-expiration calculator work, full calculator fill, one retry, and one terminal failure.
- Confirm SPY and QQQ are separate jobs.
- Confirm a cold-symbol coordinator and selected-expiration calculator job start within the agreed SLO while scheduled work is active.
- Confirm both interactive and background provider classes continue acquiring permits.
- Compare the final market-data artifact with the saved serial baseline. Row counts, expiration sets, stored source fields, derived values, API payloads, and freshness timestamps must remain equivalent except for approved run timestamps.
- Confirm queue depth returns to zero and no new failed jobs remain.

## Rollback

First return worker allocation to the rollout profile and confirm the legacy `bootstrap`, `prime`, and `calculator` consumers are `RUNNING`. This step is mandatory after the steady profile has removed them. Reducing other processes and recreating these four legacy processes must be one controlled off-market reallocation so the host remains at 26.

After legacy consumers exist, set `QUEUE_LANES_ISOLATED=false` on the web site, rebuild its configuration cache, and restart PHP. Then set it false on the worker/scheduler site, rebuild that cache, and restart workers. Keep every new consumer running while new-lane ready, reserved, and delayed work drains. Restore the full legacy process counts only after both producer flags are verified false. Do not remove any new or legacy consumer while a compatible payload can still reach its queue.

The provider gate can remain enabled during rollback. Disable it only after all queue producers and synchronous provider callers are confirmed stable, because it is independent of queue naming.
