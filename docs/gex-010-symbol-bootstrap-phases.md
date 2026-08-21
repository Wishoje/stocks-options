# GEX-010 symbol bootstrap phases

Status: Implemented behind a disabled feature flag
Updated: 2026-08-17

## Behavior

One durable WorkRun owns initial data for a symbol, completed market session, and purpose. Concurrent prime, watchlist, and missing-expiration requests join that run. A completed authoritative run is reused for the rest of the session, including after a watchlist remove and re-add.

The manifest keeps the completed EOD session as its data date and freezes a terminal Massive expiration catalog from the New York request date through 90 calendar days. Keeping these dates separate prevents a Friday EOD run started on Monday from requesting already-expired Friday contracts or dropping the last days of the forward horizon. The separate price-history backfill remains 400 calendar days.

The phases are:

1. `quote` on `bootstrap-fast`.
2. `catalog` on `bootstrap-fast`.
3. `fast_eod` on `bootstrap-fast`, using the frozen expirations through 14 calendar days. If that range is empty, the first listed expiration is used.
4. `intraday` on `intraday-interactive` for one bounded first-use attempt. A failed heavy-symbol attempt moves to its normal heavy lane for the durable retry.
5. `fill` on `default`, refetching every frozen expiration for full legacy parity.
6. `enrichment` on `default`, using the manifest session for prices, volatility, seasonality, expiry pressure, positioning, and unusual activity.

Fast EOD writes are merge-only, so an incomplete fast candidate cannot replace an existing row. Fill is the authoritative parity pass and uses normal upsert behavior across every frozen expiration. The full publication head advances only after every frozen expiration has both call and put rows and every phase has completed. A complete empty catalog is recorded as `no_options`; it is not retried as a provider failure.

Phase delivery tokens fence stale and reclaimed jobs. Failed or expired phases receive a new token and retry without replaying completed phases. The per-minute `work-runs:reconcile` command repairs due phases and a completion interrupted after the last phase checkpoint.

The status responses from `POST /api/prime`, `GET /api/symbol/status`, `GET /api/work-runs/{id}`, and `GET /api/gex-levels` include an additive `bootstrap` object when phased delivery owns the run. It reports exact fast and full readiness, the frozen catalog generation, expiration coverage, queues, attempts, and safe failure codes. Legacy responses remain unchanged while the feature is disabled.

## Configuration

The first deployment needs no new environment values. These defaults preserve the existing bootstrap:

```dotenv
SYMBOL_BOOTSTRAP_ENABLED=false
SYMBOL_BOOTSTRAP_FAST_HORIZON_DAYS=14
SYMBOL_BOOTSTRAP_FILL_HORIZON_DAYS=90
SYMBOL_BOOTSTRAP_MAX_PHASE_ATTEMPTS=5
```

Phased bootstrap fails closed unless queue isolation and the shared Massive concurrency gate are active. Activation therefore also requires these existing settings on both Forge sites:

```dotenv
MASSIVE_CONCURRENCY_ENABLED=true
MASSIVE_CONCURRENCY_LIMIT=<verified-provider-concurrency-at-least-2>
QUEUE_LANES_ISOLATED=true
SYMBOL_BOOTSTRAP_ENABLED=true
```

Do not guess the provider limit. Keep the 14-day and 90-day defaults unless a separately tested product change requires different coverage.

## Deployment

Migration `2026_08_17_000005_create_symbol_bootstrap_tables.php` is additive. It does not change or delete the existing market-data tables. A restorable production MySQL backup is still required before applying it.

1. Keep `SYMBOL_BOOTSTRAP_ENABLED=false` on both sites.
2. Deploy the web release so its normal deploy script applies migration `000005`.
3. Confirm the four `symbol_bootstrap_*` tables and their unique indexes exist.
4. Deploy the same SHA to the worker site and restart all queue workers.
5. Complete the GEX-004 queue-isolation rollout. Confirm consumers for `bootstrap-fast`, `intraday-interactive`, `intraday`, `intraday-heavy`, and `default` are running. Keep the Massive concurrency gate enabled with a verified limit.
6. Before activation, confirm there are no active legacy `symbol_bootstrap` WorkRuns. Wait for any legacy pending or running work to finish so the old and phased graphs cannot fetch the same symbol concurrently.
7. Enable `SYMBOL_BOOTSTRAP_ENABLED=true` on the worker site, rebuild config, and restart workers.
8. Enable it on the web site and rebuild its config.

Existing phased runs decide their execution mode from their persisted WorkRun parameters. Turning the feature flag off stops new phased claims but does not convert an active phased run into the legacy graph. Keep the isolated workers and provider gate running until all phased runs are terminal.

## Manual verification

Run the read-only preflight on the worker site:

```bash
cd /home/forge/stocks-options-ss7u2nu2.on-forge.com/current
git rev-parse HEAD
php8.3 artisan migrate:status | grep 2026_08_17_000005
php8.3 artisan schedule:list | grep work-runs:reconcile
sudo supervisorctl status | grep -E 'RUNNING|FATAL|BACKOFF'
php8.3 artisan tinker --execute='$keys=["symbol_bootstrap.enabled","symbol_bootstrap.fast_horizon_days","symbol_bootstrap.fill_horizon_days","symbol_bootstrap.max_phase_attempts","queue_lanes.isolated","services.massive.concurrency.enabled","services.massive.concurrency.limit"]; foreach($keys as $key){echo $key."=".var_export(config($key),true).PHP_EOL;}'
php8.3 artisan tinker --execute='$runs=\App\Models\WorkRun::query()->where("kind","symbol_bootstrap")->whereIn("status",\App\Models\WorkRun::ACTIVE_STATUSES)->get(["id","symbol","status","parameters"])->filter(fn($run)=>empty($run->parameters)); echo "active_legacy_symbol_bootstraps=".$runs->count().PHP_EOL; foreach($runs as $run){echo $run->id." ".$run->symbol." ".$run->status.PHP_EOL;}'
```

Choose one valid cold symbol in the UI and add it to the watchlist. Select it once. The first POST should return one run ID. Repeated selection or another user selecting the same symbol should return the same active run ID.

Inspect the manifest without changing it. Replace `F` with the chosen symbol:

```bash
php8.3 artisan tinker --execute='$s="F"; $session=app(\App\Support\SymbolBootstrapPolicy::class)->sessionDate(); $m=app(\App\Support\SymbolBootstrapCoordinator::class)->latestForSymbol($s,$session); echo json_encode(["session"=>$session,"run_id"=>$m?->work_run_id,"bootstrap"=>$m?app(\App\Support\SymbolBootstrapCoordinator::class)->payload($m->work_run_id):null],JSON_UNESCAPED_SLASHES).PHP_EOL;'
```

Expected progression:

- `quote`, `catalog`, and `fast_eod` complete in order on `bootstrap-fast`.
- `fast_ready=true` appears before full completion and the dashboard labels the remaining work as filling.
- `intraday` starts on `intraday-interactive`. A failed heavy first attempt retries on `intraday-heavy`, not `bootstrap-fast`.
- `fill` refetches all frozen expirations on `default`; `enrichment` runs on background lanes after fill.
- `full_ready=true` appears only when `coverage.completed_expirations` equals `coverage.expected_expirations` and all phases are complete.
- A terminal fill or enrichment failure keeps the fast rows visible and labels them as partial; it never presents partial coverage as full.
- A legitimate empty catalog ends as `state=no_options` without repeated provider starts.

Check queue depth while the run progresses:

```bash
php8.3 artisan tinker --execute='$q=\Illuminate\Support\Facades\Queue::connection("redis"); foreach(["bootstrap-fast","intraday-interactive","intraday","intraday-heavy","default"] as $n){echo $n."=".$q->size($n).PHP_EOL;}'
```

After completion, remove and re-add the same symbol. It should reuse the completed run for that session and enqueue no new provider-bearing bootstrap phase.

Stop the rollout if two active manifests exist for the same symbol/session/purpose, a fast or fill job uses `bootstrap-fast` outside its assigned phase, a stale token advances readiness, a partial run moves the full head, or queue depth grows without a matching running consumer.

## Rollback

Set `SYMBOL_BOOTSTRAP_ENABLED=false` on the web site first and rebuild config. Set it false on the worker site and restart workers. This stops new phased claims. Keep queue isolation, the Massive gate, and all isolated consumers running until existing phased manifests are terminal.

The legacy graph remains in the same release and resumes for new requests when the flag is off. Do not run the migration down, delete manifest rows, clear queues, or flush Redis. The additive tables can remain during a code rollback.
