# GEX-007 calculator scheduler state

Status: Implemented; production Redis and market-hours smoke proof remain

## Behavior

The five-minute schedule now runs the `calculator:prime-watchlist` command. The command reads the configured watchlist once, loads all calculator catalog states with one cache `many` operation, and queues only due symbols.

Each invocation writes a structured `calculator.scheduler.run` summary to the scheduler log with its source, generation, counts, dispatched symbols, coalesced symbols, and safe dispatch-failure classes.

The fallback set is `SPY,QQQ,IWM` by default. It is used only when the canonical configured watchlist is empty. A configured watchlist whose symbols are all fresh, pending, or started queues nothing.

The scheduler orders eligible symbols as follows:

1. Symbols that have never been dispatched.
2. Oldest effective service time, using the later of the last successful publication and last dispatch. For completed states this is the successful publication time. A recently retried failure therefore moves behind older stale work.
3. Never-successful work when effective service times tie.
4. Symbol as a deterministic tie-breaker.

The 75-symbol cap applies to claims, including claims whose queue dispatch fails. Active symbols do not consume the cap. This lets later symbols enter the next run instead of repeatedly selecting the same first 75.

## State contract

Scheduled full-catalog work uses two cache records per symbol:

- `calculator:refresh-state:v1:catalog:{SYMBOL}` stores the latest state envelope.
- `calculator:refresh-active:v1:catalog:{SYMBOL}` is the atomic active claim.

The state envelope records the generation, random claim token, queue, status, attempt, request/start/completion/failure times, active lease, failure count, next eligible time, last successful generation, and last dispatch time.

Valid statuses are:

- `pending`: the claim exists and dispatch is being attempted or the job is waiting.
- `started`: a worker owns the matching generation and claim token.
- `completed`: a full-catalog fetch reached its terminal cursor and committed at least one usable contract.
- `failed`: dispatch failed, the provider result was incomplete or unusable, or Laravel exhausted the job retries.

Only `completed_at` from a current `completed` state drives the ten-minute freshness check. Pending, started, failed, malformed, missing, and expired-active records are never reported as fresh. A failed refresh retains its prior `last_success_at` for fair ordering but does not use that timestamp to suppress recovery.

Selected-expiration requests do not participate in this symbol-level scheduler state. They cannot make a full catalog look complete.

Every transition compares both generation and claim token under a short per-symbol cache lock. A late callback from an older job cannot overwrite a newer state. A redelivered job without the current claim exits before provider access.

## Failure and retry timing

The calculator job keeps its existing execution contract:

- Job timeout: 270 seconds.
- Attempts: 3.
- Backoff: 15, 60, and 180 seconds.
- Redis `retry_after`: 1080 seconds in production.
- `failOnTimeout`: false.

Pending work has a 12-hour claim so jobs near the tail of a 75-symbol fan-out cannot be queued again before a calculator worker reaches them. When a worker starts an attempt, it changes the lease to at least 3600 seconds. That covers the approximately 2430-second worst case for three hard timeouts with Redis redelivery. Terminal failure releases the active claim. Failure cooldown starts at five minutes, doubles after consecutive failures, and is capped at one hour. A successful completion resets the failure count.

Cache loss is treated as stale. It may cause a safe refetch, but it cannot create a false completion. Durable calculator run manifests and atomic version publication remain GEX-008 scope.

## Configuration

The defaults preserve the current five-minute schedule and 75-symbol cap:

```dotenv
CALCULATOR_SCHEDULER_MAX_SYMBOLS=75
CALCULATOR_SCHEDULER_FRESH_MINUTES=10
CALCULATOR_SCHEDULER_INTERVAL_MINUTES=5
CALCULATOR_SCHEDULER_PENDING_TTL=43200
CALCULATOR_SCHEDULER_STARTED_TTL=3600
CALCULATOR_SCHEDULER_FAILURE_COOLDOWN=300
CALCULATOR_SCHEDULER_FAILURE_COOLDOWN_MAX=3600
CALCULATOR_SCHEDULER_STATE_TTL=2592000
CALCULATOR_SCHEDULER_FALLBACK_SYMBOLS=SPY,QQQ,IWM
```

These variables are optional because the same values are code defaults. If they are set in Forge, keep them identical on both sites. The worker/scheduler site is authoritative for scheduled runs. Pending values below 43200 and started values below 3600 are raised to those safe minimums at runtime.

## Deployment

No migration or new worker process is required.

1. Deploy the same commit to the web and worker sites.
2. Rebuild the configuration cache on both sites.
3. Restart the existing queue workers on the worker server.
4. Confirm the worker site lists one `calculator:prime-watchlist` schedule every five minutes on weekdays.
5. During regular market hours, allow one scheduled run or invoke the command once manually. Confirm the command reports `source=watchlist`, the expected dispatched set, and no unexpected fallback.
6. Confirm scheduled jobs are on `calculator-fill` or `calculator-fill-heavy` when queue isolation is enabled, or `calculator` while legacy routing is active.

The old `calculator:primed:{SYMBOL}` keys are no longer read or written. Their existing 15-minute TTL removes them naturally. Do not flush Redis or the application cache during rollout.

Useful checks on the worker site:

```bash
php8.3 artisan list --raw | grep '^calculator:prime-watchlist'
php8.3 artisan schedule:list | grep 'calculator:prime-watchlist'
php8.3 artisan tinker --execute='$symbols=["SPY","QQQ","IWM"]; echo json_encode(app(\App\Support\CalculatorRefreshState::class)->many($symbols), JSON_PRETTY_PRINT).PHP_EOL;'
php8.3 artisan tinker --execute='$redis=\Illuminate\Support\Facades\Queue::connection("redis"); foreach(["calculator","calculator-fill","calculator-fill-heavy"] as $queue){echo $queue."=".$redis->size($queue).PHP_EOL;}'
```

## Rollback

Rolling back code does not delete calculator rows. The old scheduler ignores the new state keys. Pending claims expire within 12 hours and started claims within one hour unless a retry refreshes them. Because the old scheduler uses dispatch-time markers and can fan out again, perform a rollback outside market hours when possible. Do not delete state keys while matching jobs are pending or running.

## Regression proof

The automated tests cover exact dispatched sets for:

- Empty configured watchlist.
- All fresh.
- Partly stale, including the exact freshness boundary.
- Pending and started.
- Failed, cooldown, and recovered.
- Expired ownership and duplicate active claims.
- More than 75 never-completed symbols across consecutive runs, plus mixed failed and previously successful stale work.
- Queue dispatch failure under the cap.
- Pending-to-started-to-completed publication.
- Empty provider results and terminal exceptions.
- Generation identity and stale callback rejection.

The production proof still required is one Redis-backed repeated-run check while a job remains pending and one normal market-hours completion check. Those checks must use a harmless existing symbol and must not flush shared cache state.
