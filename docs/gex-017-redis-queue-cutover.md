# GEX-017: isolated Redis queues and delivery recovery

## Production topology

The worker host has two Redis processes. Port 6379 retains application cache, locks, the provider semaphore, and queue restart signals. Private port 6380 is reserved for queue payloads. Queue isolation does not increase provider concurrency.

The operator recipe `operations/gex-017-create-queue-redis.sh` provisions only the new service. It binds to loopback and 10.10.0.3, permits the web host 10.10.0.2 through the private firewall, enables AOF everysec and RDB snapshots, caps Redis data memory at 512 MiB, and uses noeviction. The generated password is in `/etc/gexoptions-queue/password`, readable by root and the forge group. Never paste that file or Redis configuration into logs or chat.

AOF everysec targets about one second of delivery-state loss after a machine/power failure. It is not a zero-loss guarantee or a substitute for backups. Memory overhead and AOF rewriting require host headroom beyond the Redis data cap. See [Redis persistence](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/).

## Application changes

`database.redis.queue` is a dedicated connection. `REDIS_QUEUE_CONNECTION=queue` switches both standard and long queue transports to it. The `redis-legacy` and `redis-legacy-long` queue aliases remain fixed on the former Redis process for drain and rollback. No existing queue payload is copied, truncated, or deleted.

Expired running intraday/calculator WorkRuns are recoverable on the same generation after both their lease and last-activity safety window expire. Recovery revokes the old token before re-enqueue. It stops after three total dispatch reservations by default. Bootstrap retains its existing durable phase recovery. Pending dispatch loss uses the existing reservation/lease reconciliation. Default pending lease is 12 hours; running leases are 30 minutes intraday and 60 minutes calculator. An actual loss incident requires inspection; this change does not promise immediate recovery of a lost pending delivery.

Recovery protects state transitions. It does not make arbitrary external side effects exactly once. Data writers retain their own identity, publication, and freshness checks. A hard timeout/transport-lease floor prevents a short configuration TTL from prematurely reclaiming running work.

`queue:readiness --json` inspects Redis isolation, persistence, memory, ready/reserved/delayed counts, monitor coverage, old database backlog, and recovery bounds. `checks_passed=true` covers those checks only. `activation_verified=false` intentionally remains false because worker processes, scheduler ownership, recovery drills, and market-hours latency require independent evidence.

New payloads include enqueue time. Sampled `queue.job.processing` events record age since enqueue, including intentional delays and retries. The ready-head age is not a global oldest-job guarantee after delayed/retry migration. Legacy payload ages remain unknown. `queue:readiness --log` emits a safe queue-monitor snapshot; GEX-018 schedules it every five minutes.

## Deployment sequence

1. Deploy the application changes to both servers without changing the active queue connection. Confirm identical SHAs and healthy workers.
2. Transfer the generated credential only through encrypted SSH stdin pipes to `php8.3 docs/operations/gex-017-configure-queue.php prepare` in each current release. The helper creates protected backups, preserves unrelated variables, and leaves the former transport active. Rebuild cached configuration on both servers.
3. Test authenticated private connectivity and candidate readiness with a process-local configuration override. Compare the queue and cache Redis process identities. Verify the semaphore remains on port 6379 with the same limit.
4. Inspect all former queue lanes, including reserved and delayed sets, database jobs, failed-job connections, and actual Supervisor arguments. Keep the former transport's consumers available while old producers or old work remain.
5. Run the helper with `activate` on both servers and rebuild cached configuration. Restart workers after both settings are ready. Inspect every old and new lane and wait for the former workers to finish. If old ready/reserved/delayed work remains, consume it through the explicit legacy aliases; never clear it.
6. Verify live chart reads, both release SHAs, all worker lanes, queue health and new failures. Only then enable singleton ingestion in the separate GEX-018 phase.

Do not change `REDIS_HOST`, `REDIS_PORT`, or the existing cache password to activate queues. Do not run cache clearing or Redis flush commands as part of cutover.

## Rollback and disaster handling

For rollback, run the configuration helper with `rollback`, rebuild configuration, and provide consumers on both transports while new-queue work drains. Do not disable or delete the dedicated Redis service with outstanding work. A protected pre-change environment backup exists beside the shared environment file; preserve newer unrelated settings when restoring.

For Redis data loss, inspect durable WorkRuns and run `work-runs:reconcile`. Pending/running lease limits still apply. Keep the former generation fenced and investigate `recovery_exhausted` results instead of repeatedly forcing them. Inspect `failed_jobs` and replay only reviewed IDs with the standard retry command. Failed-job connection names remain `redis`/`redis-long`, which follow the selected active transport.

Durable recovery covers API calculator/intraday/bootstrap requests and, after GEX-018, scheduled/warmup intraday singleton intents. Other legacy scheduled jobs retain their existing stored inputs and schedules rather than a new universal outbox. Restore/re-run their reviewed date/symbol/export scope after a disaster. This work does not claim universal durable intent coverage or recovery of a vendor snapshot that is no longer available.

## Validation evidence

The operator successfully provisioned port 6380 and proved authenticated writes, AOF fsync, and new-service graceful restart. Web-to-worker private authentication passed with zero evictions and less than 1 MiB used before cutover.

A separate disposable Redis on loopback port 6381 was force-killed after fsync and cold-restarted. Owned ready, reserved, and delayed test payloads survived. Neither production process was restarted for this drill.

`RedisQueueCutoverTest` uses a marked disposable Redis through local tunnel port 16381 and the guarded local MySQL test database. It covers retries/delay, expired reservations, lost ready/running payloads, durable intent reconstruction, stale tokens, and drained database-to-Redis-to-database transitions. It verifies one final synthetic token-fenced result per intent, not arbitrary external side effects. No application Redis settings are inherited and only explicitly owned random test keys are removed.

## Manual checks

On the worker, from the current release:

```bash
php8.3 artisan queue:readiness --json
sudo supervisorctl status
php8.3 artisan work-runs:reconcile --limit=100
```

In the browser, verify SPY, QQQ, and V EOD Strikes and Intraday still populate. During the next market session, request one intraday refresh and verify completion, an advancing timestamp, and unchanged total arithmetic. Market-hours saturation and long soak measurements remain necessary; weekend probes cannot establish those SLOs.
