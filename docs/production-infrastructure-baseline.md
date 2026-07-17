# GEXOptions production infrastructure baseline

Card: GEX-001
Status: In review; baseline recorded with accepted evidence gaps
Captured: 2026-07-16
Scope: Laravel Forge on Hetzner

This document is intentionally redacted. Exact server IDs, public/private IP addresses, credentials, connection URLs, and tokens are not stored in the repository.

## Security prerequisite

On 2026-07-16, production environment contents containing active credentials were pasted into an external support conversation. The values are not copied into this repository. Treat every disclosed secret as compromised, rotate it, verify both releases, and revoke the old value. Record only the credential owner, rotation date, and verification status.

Follow [the production credential rotation runbook](production-credential-rotation-runbook.md) before any further production mutation.

## Confirmed topology

### Web server

| Item | Value |
|---|---|
| Logical name | gex-web |
| Hetzner plan | CCX13 |
| Forge type | App server |
| Capacity | 2 vCPU, 8 GB RAM, 80 GB local disk |
| Included outbound traffic | 1 TB |
| Reported monthly server price | 19.99 |
| Operating system | Ubuntu 24.04 |
| PHP | 8.3 |
| MySQL installed | 8.0 |
| Primary site | gexoptions.com |
| Repository/release branch | Wishoje/stocks-options, main |
| Application queue workers | None reported |
| Laravel scheduler | None; confirmed |
| Runtime snapshot | 2 vCPU; load 0.00/0.01/0.02; 7.6 GiB RAM, 6.3 GiB available |
| Swap | 1 GiB total, about 629 MiB used |
| Root filesystem | 75 GiB filesystem, 59 GiB used, 14 GiB available, 82% used |

### Worker server

| Item | Value |
|---|---|
| Logical name | gex-worker |
| Hetzner plan | CCX23 |
| Forge type | App server used as a worker host |
| Capacity | 4 vCPU, 16 GB RAM, 160 GB local disk |
| Included outbound traffic | 2 TB |
| Reported monthly server price | 39.99 |
| Operating system | Ubuntu 24.04 |
| PHP | 8.3 |
| MySQL installed | 8.0 |
| Worker site | stocks-options-ss7u2nu2.on-forge.com |
| Repository/release branch | Wishoje/stocks-options, main |
| Private networking | Present |
| Laravel scheduler | One schedule:run process every minute |
| Background definitions | 7 |
| Configured queue processes | 25 |
| Runtime snapshot | 4 vCPU; load 0.04/0.02/0.00; 15 GiB RAM, 13 GiB available |
| Swap | 1 GiB total, unused |
| Root filesystem | 150 GiB filesystem, 8.3 GiB used, 136 GiB available, 6% used |

The two Hetzner server plans total 59.98 per month before backups, provider services, storage, traffic overage, or other infrastructure.

## Worker definitions

All reported workers use the Laravel Redis queue connection.

| Queue | Processes | Timeout | Memory per process | Tries | Sleep |
|---|---:|---:|---:|---:|---:|
| bootstrap | 1 | 300s | 512 MB | 3 | 3s |
| prime | 2 | 120s | 512 MB | 3 | 3s |
| intraday-heavy | 2 | 600s | 512 MB | 3 | 3s |
| default | 6 | 600s | 512 MB | 3 | 3s |
| calculator | 4 | 300s | 512 MB | 3 | 3s |
| quotes | 2 | 120s | 512 MB | 3 | 3s |
| intraday | 8 | 120s | 512 MB | 3 | 3s |
| Total | 25 | — | 12.5 GB theoretical combined limit | — | — |

The 512 MB value is a per-process recycle limit, not reserved memory. Even so, 25 processes can theoretically reach 12.5 GB on a 16 GB host. That leaves only 3.5 GB for Ubuntu, Supervisor, the worker site, Redis/MySQL if local, scheduler processes, filesystem cache, and safety headroom. Actual resident memory during market hours is required before changing process counts.

Current queues do not reserve separate capacity for:

- New-symbol fast coordination.
- First-use interactive intraday.
- Interactive selected-expiration calculator work versus background calculator fill.
- Heavy calculator fill versus normal calculator fill.
- Long exports with a different queue lease.

## Deployment and scheduler

- Web and worker sites deploy the same main branch.
- Both active releases were verified at commit 93aa755d7dd7bcdd272ecd7395308da818c396c3.
- The most recent reported deployment was approximately two months before this capture.
- The worker site runs Laravel schedule:run every minute.
- The repository schedules market jobs in America/New_York.
- Only the worker host runs Laravel schedule:run.
- Both deploy scripts activate a zero-downtime release and call the Forge queue-restart macro after activation.
- The web deployment runs additive migrations before activation and also issues an earlier redundant queue:restart. The worker deployment does not run migrations; deployment order and compatibility must therefore be documented.

## Preliminary findings

### Queue lease was shorter than worker timeouts at capture

At the 2026-07-16 capture, effective production `REDIS_QUEUE_RETRY_AFTER` was 90 seconds. Production workers had timeouts from 120 to 600 seconds. A reserved job could therefore become available while its original worker was still running.

At the 90-second default, every lane needs correction:

- bootstrap worker timeout: 300 seconds.
- prime, quotes, and intraday worker timeout: 120 seconds.
- intraday-heavy and default worker timeout: 600 seconds.
- FetchCalculatorChainJob timeout: exactly 90 seconds, with no safety margin.
- BuildAiExportJob timeout: 900 seconds even though it runs under a shorter default worker/connection contract.

The reported Forge commands do not set backoff, and the audited heavy jobs do not define a consistent backoff policy. A provider/job failure can therefore retry immediately.

Required evidence:

- Any unreported queue-specific connection configurations.
- Supervisor graceful stop/stopwaitsecs.
- Explicit job-level timeout values for each queue family.

No timeout or retry setting should change until GEX-002 can verify idempotency and data equivalence.

Operational update, 2026-07-17:

- `REDIS_QUEUE_RETRY_AFTER=1080` was added to the worker environment.
- The active worker release rebuilt its configuration cache and verified `queue.connections.redis.retry_after=1080` through Artisan.
- A graceful `queue:restart` signal was issued. Prime, heavy intraday, intraday, quotes, calculator, and bootstrap processes returned as running; three of six default processes were still starting in the collected snapshot and require a follow-up status check.
- `stopwaitsecs=1200` was verified in the collected Supervisor files for bootstrap, prime, intraday-heavy, default, calculator, and intraday. The quotes configuration was not included in that collection and remains to be verified.
- The `redis-long:exports` worker has not been created. The worker-before-web deployment gate therefore remains closed.

### Redis is already the production queue

GEX-017 is a validation and durability card rather than a database-to-Redis migration.

Confirmed:

- Queue driver: Redis.
- General cache store: Redis.
- Queue/locks use logical database 0.
- General cache uses logical database 1.
- MySQL and Redis are shared by both application releases over the Hetzner private network.
- MySQL is hosted with the web application server.
- Redis is hosted with the worker server.
- The web release effectively monitors the Redis queues.
- The worker environment declares the queue monitor connection as database even though schedule:run executes there. Effective worker configuration must be confirmed, but the scheduler is likely monitoring the wrong backend.

Still unknown:

- Redis version.
- Persistence mode and recovery procedure.
- maxmemory and maxmemory-policy.
- Current memory and evictions.
- Whether incomplete work has a durable MySQL intent that can reconstruct a lost Redis job.

### Worker host is highly concurrent

There are 25 queue processes on 4 vCPU. This can be reasonable for mostly idle I/O-bound work, but it can also create CPU contention, excessive MySQL connections, provider bursts, and memory pressure. Process count must be judged from market-hours CPU, load, resident memory, database time, and provider rate-limit measurements.

### Web disk needs investigation

The web root filesystem is 82% used with about 14 GiB available. This is not an immediate outage, but release directories, logs, database files, backups, caches, and application storage must be measured before additional build artifacts or indexes are introduced. Do not delete anything until ownership and retention are verified.

### Current queue boundaries create head-of-line blocking

- The calculator scheduler can dispatch up to 75 one-symbol jobs every five minutes.
- Scheduled intraday work groups up to 15 symbols in one job.
- SPY and QQQ can be grouped into the same heavy job and execute sequentially.
- Other normal symbols, currently including IWM, can share a 13–15 symbol job.
- Quote refreshes use batches up to 50 symbols.
- The default queue mixes EOD repair/preload, exports, metrics, emails, and other jobs with very different runtimes.
- New-symbol intraday work is delayed behind the earlier full EOD and derived bootstrap phases.

### Off-hours queue snapshot is healthy but not representative

At approximately 02:40 America/New_York, all seven reported Redis queues had size zero. This proves the queues can drain off-hours. It does not measure market-hours oldest age, active/reserved work, retries, or throughput.

### Provider subscription

- Massive Options Starter, Individual.
- Massive Stocks Starter, Individual.
- The account dashboard did not provide a numeric REST rate limit or supported concurrency limit in the collected evidence.
- Provider concurrency and 429 behavior remain unknown. Queue fan-out must therefore start conservatively and remain behind a shared concurrency control until measured.

### Redis memory and durability

- Redis `maxmemory` is `0`, so Redis has no configured application-level memory ceiling.
- Redis version, current/peak memory, eviction policy, eviction count, and AOF/RDB status were not captured.
- With `maxmemory=0`, Redis will not begin maxmemory-driven eviction at a configured threshold. Host memory exhaustion remains possible, especially because queues, cache, and locks share this Redis process.

### Backup and restore status

- No database backup schedule is configured or known.
- No Redis backup schedule is configured or known.
- No database or Redis restore test is documented.
- This is an accepted operational risk for closing evidence collection, but it must be corrected before queue durability or destructive schema work.

### Supervisor shutdown evidence

- Seven Supervisor configuration files containing `queue:work` definitions were found on the worker host.
- The collected output did not include `stopwaitsecs`, `stopsignal`, `stopasgroup`, or `killasgroup` values.
- Graceful-stop duration therefore remains unknown. Do not assume that long-running jobs can survive a deploy or Supervisor restart.

## Known evidence gaps carried forward

The owner elected to stop further read-only collection for this card. The items below remain unknown or deferred. They are not evidence that the production behavior is safe.

1. Credential rotation:
   - Rotate every disclosed production credential.
   - Verify web, workers, scheduled jobs, payments/webhooks, mail, market-data providers, database, and Redis.
   - Revoke old credentials and record rotation dates without values.
2. Effective worker monitoring configuration:
   - Confirm queue.monitor.connection after config caching.
   - Correct the current database-versus-Redis mismatch under a separate reviewed change.
3. Redis durability:
   - Redis version.
   - used_memory, maxmemory-policy, and evicted_keys. `maxmemory=0` is confirmed.
   - AOF/RDB configuration and last successful persistence result.
   - Backup/restore or durable-intent reconstruction procedure.
4. Market-hours queue state:
   - Depth and oldest-job age for all seven queues.
   - Ready, delayed, reserved/running, failed, and retry counts.
   - Capture before market open, near 10:00 ET, midday, near 15:30 ET, and after close.
5. Host measurements for both servers:
   - CPU, load, RAM, swap, disk use/latency, and network during one full market session.
   - Actual resident memory and CPU by queue worker group.
   - MySQL active/max connections, slow queries, and lock/deadlock counts.
6. Provider limits:
   - Provider and plan names are confirmed as Individual Options Starter and Stocks Starter.
   - Requests per second/minute/day.
   - Maximum supported concurrency.
   - Response to 429 and Retry-After behavior.
7. Deployment and recovery:
   - Deployment order and cross-release database compatibility.
   - Supervisor stopwaitsecs.
   - Database and Redis have no known backup schedule or restore test.
8. Product SLOs:
   - New-symbol request acknowledgement.
   - Queue start, first quote, expiration catalog, initial EOD, and first intraday times.
   - Interactive calculator queue wait and selected-expiration readiness.
   - Normal/heavy full-fill completion.
   - Acceptable API p95/p99 and error rate.

Do not send passwords, private keys, full environment files, provider tokens, database URLs, Redis URLs, or unredacted deploy secrets.

## Safe collection commands

Run the effective-configuration command from the current release directory on both sites. Run queue and worker measurements on the worker host. These commands output configuration names and numeric values, not credentials.

### Effective Laravel queue/cache configuration

    php8.3 artisan tinker --execute="foreach (['queue.default','queue.connections.redis.connection','queue.connections.redis.retry_after','queue.monitor.connection','queue.monitor.queues','queue.monitor.max_size','cache.default','cache.stores.redis.connection','cache.stores.redis.lock_connection','database.default','database.redis.default.database','database.redis.cache.database'] as \$key) { \$value = config(\$key); echo \$key.'='.(is_array(\$value) ? implode(',', \$value) : (string) \$value).PHP_EOL; }"

### Queue sizes

    php8.3 artisan tinker --execute="foreach (['bootstrap','prime','default','intraday','intraday-heavy','calculator','quotes'] as \$queue) { echo \$queue.'='.Illuminate\Support\Facades\Queue::connection('redis')->size(\$queue).PHP_EOL; }"

Capture queue sizes at the five market-session times listed above. The size call is useful but does not replace oldest-age, runtime, delayed, reserved, and failure metrics.

### Host capacity snapshot

    nproc
    uptime
    free -h
    df -h /
    ps -eo pid,ppid,%cpu,%mem,rss,etime,cmd --sort=-rss | head -n 35

Run the host snapshot on both servers during market hours. Review output for credentials embedded in process command lines before sharing; the reported Laravel worker commands are expected to be safe.

### Active release identity

    git rev-parse HEAD

Run this in the current release directory on both sites.

## Proposed initial SLOs for approval

These are starting product targets, not final commitments. GEX-001 measurements may require adjustment.

| User-visible milestone | Proposed p95 |
|---|---:|
| Add-symbol request acknowledged with run ID | 1 second |
| New-symbol fast job starts | 5 seconds |
| First real quote | 10 seconds |
| Expiration catalog visible | 15 seconds |
| Initial EOD view usable | 30 seconds |
| First intraday aggregate usable | 60 seconds |
| Interactive calculator job starts | 5 seconds |
| Selected calculator expiration usable | 30 seconds |

Full normal/heavy fill targets should be set after measuring provider pages, contracts, and current runtimes.

## GEX-001 completion criteria

GEX-001 is complete when:

- The unknown service locations and effective non-secret configuration are filled in.
- One market-hours measurement set is attached.
- Numeric SLOs and capacity budgets are approved.
- Current queue lease/timeout safety is classified.
- Deployment, scheduler ownership, backups, and rollback are documented.
- No production configuration was changed while collecting the baseline.

The collected evidence is sufficient to stop discovery and begin local-only work, but it does not prove that every GEX-001 acceptance criterion has been met. Market-hours metrics, approved SLOs, Redis persistence details, provider numerical limits, and Supervisor graceful-stop behavior remain open risks.

GEX-000 credential rotation remains the production-change gate. Local work can begin on GEX-002, which builds the MySQL-backed data and API no-regression harness before queue, calculator, schema, or ingestion behavior changes. Do not deploy a schema/data migration until a backup and restore procedure exists.
