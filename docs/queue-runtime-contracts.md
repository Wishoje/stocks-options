# Queue runtime contracts

Card: GEX-003
Status: Queue-contract foundation implemented locally; full GEX-003 acceptance remains open
Updated: 2026-07-16

## Safety rule

For every queue lane:

    job timeout < worker timeout < queue retry_after < Supervisor stopwaitsecs

`retry_after` belongs to a Laravel queue connection. A queue name alone cannot provide a different reservation window. During a connection migration, one physical Redis queue must be consumed through only one Laravel connection definition.

## Interim production lease

Use a 1,080-second reservation on the existing Redis connection during the first rollout. This protects legacy serialized `BuildAiExportJob` payloads that may still be in `default` with a 900-second job timeout. It trades faster crash recovery for protection against two workers writing the same job concurrently.

After legacy exports are drained and the bounded worker lanes are active, reduce the standard/heavy connection to a 720-second lease. Shorter connection tiers can be introduced only when their physical queues are exclusive and every job on them is bounded below the tier ceiling.

| Lane | Job ceiling | Worker timeout | Rollout retry_after |
|---|---:|---:|---:|
| quotes | 90s | 120s | 1080s |
| intraday | 105s | 120s | 1080s |
| prime | 110s | 120s | 1080s |
| calculator | 270s | 300s | 1080s |
| bootstrap child | 270s | 300s | 1080s |
| default | 540s | 600s | 1080s |
| intraday-heavy | 540s | 600s | 1080s |
| exports | 900s | 960s | 1080s |

Supervisor `stopwaitsecs` must be at least 1,200 seconds for these workers. The repository cannot enforce that host setting. Production restart safety remains unverified until the Supervisor configuration is changed and tested.

The machine-readable worker definition is [ops/forge-workers.json](../ops/forge-workers.json). The per-job contract inventory is [config/queue_contracts.php](../config/queue_contracts.php).

## Long export lane

New AI exports use the `exports` queue through the `redis-long` connection in production. The required Forge process is:

    php8.3 artisan queue:work redis-long --sleep=3 --timeout=960 --memory=512 --tries=2 --force --queue=exports

Do not deploy the new web release until this consumer is running. Existing serialized exports remain on `default` and must be allowed to drain before the main retry window is reduced.

## Coordinated rollout order

1. Complete GEX-000 credential rotation.
2. Create and verify database and Redis backups and a non-production restore procedure.
3. Pass the automated gates in [GEX-002 and GEX-003 verification](gex-002-003-verification.md) against a disposable `_test` database. Do not run destructive tests against production.
4. On both Forge sites, set `REDIS_QUEUE_RETRY_AFTER=1080`, `REDIS_LONG_QUEUE_RETRY_AFTER=1080`, `QUEUE_LONG_CONNECTION=redis-long`, and `QUEUE_LONG_QUEUE=exports`. On the scheduler/worker site set the complete monitor value: `QUEUE_MONITOR_TARGETS=redis:bootstrap,redis:prime,redis:default,redis:intraday,redis:intraday-heavy,redis:calculator,redis:quotes,redis-long:exports`.
5. Rebuild Laravel's cached configuration on the active worker release. Verify effective `queue.connections.redis.retry_after=1080`, `queue.connections.redis-long.retry_after=1080`, the long connection/queue, and all eight monitor targets before restarting workers. `queue:restart` alone does not rebuild cached configuration.
6. Restart the existing workers and verify they use the 1,080-second lease.
7. Set and reload Supervisor `stopwaitsecs=1200` for every existing queue program. Verify a controlled long-running stop.
8. Deploy the worker site first, then repeat the effective cached-configuration checks.
9. Check market-hours resident memory and CPU before adding a process. The current 25 workers have a 12.5 GB theoretical PHP memory ceiling; the export worker raises it to 13 GB on a 16 GB host. Reallocate an existing process temporarily if the capacity check does not leave safe headroom.
10. Add the `exports` Forge worker. Set and reload `stopwaitsecs=1200` for this new Supervisor program, then verify both its command and effective stop setting.
11. Deploy the web site. New exports can now route to the verified dedicated consumer.
12. Run non-mutating effective-config, queue-consumer, and export smoke checks. Confirm the legacy `default` queue contains no old export payloads before considering a shorter standard lease.

For rollback, stop or roll back the web producer first while retaining the new exports consumer. Drain ready, reserved, and delayed `exports` work. Then roll back the worker release and remove the lane only after no compatible payload remains. Never roll back the worker consumer first after the web release has emitted `redis-long:exports` payloads.

## Implemented safeguards

- Every application job extends one base contract with explicit timeout, tries, backoff, timeout-failure behavior, deterministic diagnostic identity, structured tags, and sanitized terminal-failure logging.
- Queue failure events include run ID, attempt, queue, safe error category, and a replay command without logging provider responses or credentials.
- AI exports use a distinct long connection and queue and store a sanitized terminal error.
- Scheduled tasks have explicit overlap and single-leader policies.
- Provider clients have bounded connection and request timeouts.
- Daily price, historical-price, and quote batches throw after an HTTP/provider failure so dependent chains do not silently continue. Historical prices fall back from Finnhub to Yahoo on every HTTP failure and use bounded bulk upserts. Quote writes reject an older `asof` value.
- EOD Massive pagination carries an explicit transport-completeness result. Pages accumulated before an unrecovered HTTP failure are withheld, while a complete per-expiration repair may still publish.
- Intraday totals are withheld when any requested expiration is incomplete. The job then throws so Laravel retries it instead of advancing a chain with zero or partial totals.
- Positioning, unusual-activity, term/VRP, and skew rebuilds publish their per-symbol replacement inside transactions. Cache invalidation happens after the transaction.
- Lifecycle mail has one queue boundary. A notification exception prevents the lifecycle log transaction from being committed, but provider acceptance immediately before process/transaction failure can still duplicate mail until an outbox or provider idempotency key is added.

## Remaining GEX-003 limitations

The current jobs are not yet bounded enough to certify every kill/retry case:

- Calculator and EOD jobs can fetch many pages in one job. Bounded expiration/page jobs belong to GEX-008/GEX-015.
- A historical-price provider response is treated as usable when it contains at least one valid bar. Full requested-range coverage needs a later manifest/health rule so young listings remain supported without silently accepting truncated mature-symbol history.
- Calculator ingestion can still publish a partial provider result. Intraday can retain raw per-contract rows from completed expirations before a later expiration fails. Atomic generation publication belongs to GEX-008, GEX-009, GEX-012, and GEX-013.
- Intraday nullable total keys can still duplicate in MySQL. GEX-012 owns the schema correction.
- Multi-symbol work can keep unrelated symbols together. GEX-018 owns singleton intraday dispatch.
- Cache-based bootstrap claims can expire before the full child graph finishes. GEX-010 owns the durable run manifest.
- Watchlist preload still performs the legacy global cache flush. Targeted versioned invalidation and proof that unrelated cache entries survive belong to GEX-014.
- Provider-wide concurrency is not yet known or enforced. GEX-021 owns the shared limiter and adaptive backpressure.
- Production worker-kill tests require a disposable MySQL/Redis environment and must not be run against the live databases.

These gaps prohibit claiming that all queue failure modes are fixed. The GEX-002 comparison harness is the gate for each later bounded-work and publication change.
