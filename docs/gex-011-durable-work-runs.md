# GEX-011 durable work runs

GEX-011 moves the provider-backed market-data refresh paths named below behind authenticated write endpoints and records accepted work in MySQL before it is sent to Redis. Their read endpoints no longer start jobs.

## Runtime contract

- `POST /api/prime-calculator` starts or joins calculator work.
- `POST /api/intraday/pull` starts or joins one run per canonical symbol. Input is deduplicated before the configured distinct-symbol limit is checked. An over-limit request is rejected; it is never truncated.
- `POST /api/prime` starts or joins a symbol bootstrap.
- `POST /api/watchlist` uses the same bootstrap and intraday run slots while preserving its existing response body.
- `GET /api/work-runs/{run_id}` reports durable state without starting work. Status access is checked against the run kind: calculator, intraday, and bootstrap runs require their matching feature entitlement.
- `GET /api/option-chain`, `GET /api/symbol/status`, and `GET /api/gex-levels` are pure reads.
- Calculator and intraday HTTP requests always enqueue work. The previous synchronous execution paths are disabled.

An identical compatible request receives the current active run. A recently completed run is reused for its configured freshness window. Calculator `force` may start a new generation after completion, but it does not bypass an active compatible run or a failed run's cooldown.

Symbols are canonicalized before admission. Unsupported, mixed-validity, and over-limit batches fail as a whole; valid dotted symbols such as `BRK.A` remain supported. An intraday `force` request bypasses only completed-run reuse and still joins active work or a failed-run cooldown.

MySQL owns the run identity and generation. Redis is only the delivery transport. The scheduled `work-runs:reconcile` command recovers an accepted intent if the process stops between the database commit and Redis dispatch. Delivery tokens, attempts, and row-locked transitions prevent an old or duplicate queue delivery from completing a newer attempt.

Symbol bootstrap uses a durable orchestration token for its child chain. The run becomes complete only after the final child succeeds. Dispatching the parent job is not reported as completion.

## Access and limits

The market-data refresh routes listed above require a signed-in user, an active subscription or trial, and the applicable feature entitlement. Active subscriptions whose Stripe price is missing from the configured plan map fail closed on these routes with `403 plan_unmapped`; they do not inherit every feature. The established fail-open behavior remains unchanged for unrelated legacy web middleware. JSON requests receive `401` or `403` JSON responses instead of web redirects.

This card does not change the access contract for existing read-only product APIs or the authenticated AI-export workflow. Those paths do not start the provider refresh jobs owned by GEX-011. Reading or deleting a watchlist and listing or downloading owned exports remain available to their authenticated owner; only adding a watchlist symbol enters the strict durable-refresh path.

Ingress user and IP limits protect the HTTP endpoints. Symbol and shared-provider limits are admission budgets for new durable scopes; coalesced requests do not consume that capacity. A watchlist add that reaches the budget retains a durable pending run for the reconciler instead of losing the work intent. These counters are not a hard provider-concurrency guarantee. The provider concurrency gate remains the in-flight request bound. A `429` response includes both `Retry-After` and `retry_after_seconds`.

Ingest health and raw debug routes require operator access. Ingest health is cached for one minute and performs no ingestion.

The defaults are in `config/work_runs.php` and `.env.example`. The provider-start limit is an application start budget. It is not a claim about the Massive subscription's API-call allowance; a single accepted job can make multiple provider requests.

## Deployment prerequisite

This release adds `work_runs` and `work_run_slots`. Take and verify a restorable MySQL backup before deploying the migration. Do not deploy this schema change while production has no database backup procedure.

Web and worker sites deploy separately, and the recorded worker deploy script does not run migrations. Use this short maintenance rollout so neither new web ingress nor the new worker reconciler can run before the shared schema exists:

1. Take the MySQL backup and record its timestamp and verification result.
2. Put the web site in Laravel maintenance mode and confirm expensive write requests are no longer entering.
3. Deploy the web site. Its deploy script runs `artisan migrate --force`, which creates the shared work-run tables before the release is activated.
4. Confirm the migration succeeded and leave the web site in maintenance mode.
5. Deploy the same SHA to the worker site. Confirm its SHA, queue workers, and `work-runs:reconcile` schedule.
6. Rebuild the configuration cache on both sites and restart queue workers once both sites use the same SHA.
7. Bring the web site out of maintenance mode.
8. Run the read-only checks and one controlled write smoke test in the production validation section below.

No new environment variable is mandatory because every setting has a conservative default. Copy the `WORK_RUN_*` values from `.env.example` to both sites if production should pin the values explicitly. The worker/scheduler site must use the same values as the web site.

Do not flush Redis, clear queues, delete work-run rows, or remove active run slots during rollout or rollback.

The calculator-specific immutable publication and browser validation steps are documented in [GEX-008 and GEX-009 calculator publication](gex-008-009-calculator-publication.md).

## Production validation

Run these commands on the worker site after both sites have the same release:

```bash
cd /home/forge/stocks-options-ss7u2nu2.on-forge.com/current
git rev-parse HEAD
php8.3 artisan migrate:status | grep work_runs
php8.3 artisan list --raw | grep '^work-runs:reconcile'
php8.3 artisan schedule:list | grep 'work-runs:reconcile'
sudo supervisorctl status | grep -E 'RUNNING|FATAL|BACKOFF'
```

Verify the resolved configuration without printing secrets:

```bash
php8.3 artisan tinker --execute='$keys=["work_runs.max_symbols_per_request","work_runs.pending_ttl_seconds","work_runs.running_ttl_seconds.calculator_refresh","work_runs.running_ttl_seconds.intraday_refresh","work_runs.running_ttl_seconds.symbol_bootstrap","work_runs.rate_limits.user_per_minute","work_runs.rate_limits.ip_per_minute","work_runs.rate_limits.accepted_symbol_per_minute","work_runs.rate_limits.accepted_provider_per_minute"]; foreach($keys as $key){echo $key."=".var_export(config($key),true).PHP_EOL;}'
```

Inspect active runs and queue depth:

```bash
php8.3 artisan tinker --execute='$rows=\App\Models\WorkRun::query()->whereIn("status",\App\Models\WorkRun::ACTIVE_STATUSES)->orderBy("requested_at")->limit(20)->get(["id","kind","symbol","generation","status","queue","dispatch_attempts","attempt","requested_at","dispatched_at","started_at","lease_expires_at"]); foreach($rows as $row){echo json_encode($row).PHP_EOL;} $q=\Illuminate\Support\Facades\Queue::connection("redis"); foreach(["bootstrap-fast","intraday-interactive","intraday-heavy","calculator-interactive","calculator-fill","calculator-fill-heavy"] as $name){echo $name."=".$q->size($name).PHP_EOL;}'
```

From a signed-in entitled browser, start one normal calculator symbol. The first request should return `202`, a `run_id`, `status_url`, and `coalesced=false`. Submit the same request again. It should return the same `run_id`, normally with `200` and `coalesced=true`, and it must not add a second provider job. Poll only the returned status URL until the run is `completed` or `failed`.

A completed run must have `completed_at`, no lease, and usable calculator rows. A failed run is a truthful terminal result; inspect its safe `error_category` and `error_code` before retrying. Stop rollout investigation if identical requests produce different active run IDs, a GET endpoint increases queue depth, a run reports complete before its bootstrap child chain finishes, or queue depth grows without a matching running worker.

## Rollback

Deploy the previous application SHA to both sites outside market hours, rebuild configuration caches, and restart workers. Do not drop the additive tables during the rollback window. Old code ignores them, while retaining them preserves incident evidence and allows the new release to resume its durable generations later.
