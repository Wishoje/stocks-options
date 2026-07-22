# Option-Chain Partitioned Fetch Rollout Runbook

This runbook covers the production behavior and controlled rollout of
partitioned EOD option-chain fetches in `FetchOptionChainDataJob` on the worker
and scheduler site. A configured canary uses partitions immediately. Any other
symbol starts on the legacy path and switches to partitions when the legacy
reference, bulk, or repair path reports a structural pagination failure.

## Why this path exists

The Massive option-chain snapshot endpoint returns at most 250 contracts per page. Broad pulls for symbols such as `IWM`, `QQQ`, and `SPY` can reach the application page cap before the provider returns the end of the result set. Raising the broad cap increases request volume without proving that the result is complete.

The partitioned path first probes the reference catalog for at most four pages.
If that bounded catalog probe is dense or capped, it discovers expirations with
exact-date catalog checks. It then fetches every discovered expiration as
separate call and put snapshot partitions. This preserves the expiration
window, option sides, strikes, data fields, validation, and database upsert
behavior. An incomplete partition must keep the symbol incomplete. It must not
publish a partial chain as successful.

This behavior does not require a permanent list of dense symbols. `CVX`, `IWM`,
`QQQ`, `SPY`, and `TSLA` are rollout examples, not a classification of special
symbols. Here, eligible means a symbol whose EOD chain fetch reaches Massive
with a valid expiration window and provider configuration.

The paginator keeps the original endpoint and immutable symbol, expiration,
side, and window filters on every request. It accepts only an opaque cursor
from `next_url`. A cursor that changes endpoint scope, introduces an unknown or
duplicate query parameter, cycles, or makes no progress fails closed.

Adaptive fallback is allowed for these structural statuses:

- `pagination_capped`
- `cursor_cycle`
- `pagination_no_progress`
- `scope_violation`
- `cursor_scope_violation`

The incomplete legacy contracts are discarded before the partitioned retry.
They are never merged into the partition result. HTTP, authentication, empty,
and malformed-response failures do not trigger extra partition fanout. If any
partition remains incomplete, the symbol is not published.

## Configuration

Configure these values in the Forge environment for the worker and scheduler site:

```dotenv
EOD_CHAIN_PARTITIONED_FETCH_ENABLED=false
EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS=
EOD_CHAIN_MAX_PAGES_PER_PARTITION=40
EOD_CHAIN_REFERENCE_PROBE_MAX_PAGES=4
```

- `EOD_CHAIN_PARTITIONED_FETCH_ENABLED` enables both eager partitioned fetches
  and adaptive structural fallback. It is also the rollback switch.
- `EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS` selects symbols that use partitions
  eagerly. A populated list does not disable adaptive fallback for other
  symbols. An empty value makes every eligible symbol use partitions eagerly
  when the global switch is `true`.
- `EOD_CHAIN_MAX_PAGES_PER_PARTITION` limits one bounded provider partition. Keep this fixed during the canary rollout. A cap is a safety boundary, not a completeness target.
- `EOD_CHAIN_REFERENCE_PROBE_MAX_PAGES` limits the first catalog probe. Dense or capped catalogs automatically use exact-date discovery.

Do not increase `EOD_CHAIN_MAX_PAGES`, reduce the expiration horizon, narrow the stored strike band, or relax completeness checks as part of this rollout.

## Preconditions

Before enabling the first canary:

- Deploy the release containing the partitioned fetch implementation and its regression tests.
- Keep queue-lane isolation and the Massive concurrency gate at their separately approved rollout values. Do not combine those changes with this canary.
- Confirm the `default` and `bootstrap` queues have no option-chain work ready, reserved, or delayed.
- Record the release SHA and current EOD coverage.
- Run after the provider's 15-minute delay following market close, after
  scheduled EOD work has drained, and before expired same-day contracts leave
  the current snapshot.
- Use only the current completed New York trading session. Do not pass `--allow-nonhistorical-chain-repair`.

Check the completed session:

```bash
php8.3 artisan tinker --execute='echo app(\App\Support\EodSnapshotSelector::class)->completedSessionDate(now("America/New_York")).PHP_EOL;'
```

Check the relevant Redis queue states:

```bash
php8.3 artisan tinker --execute='$r=\Illuminate\Support\Facades\Redis::connection(config("queue.connections.redis.connection","default")); foreach(["default","bootstrap"] as $queue){echo $queue.":ready=".$r->llen("queues:".$queue).",reserved=".$r->zcard("queues:".$queue.":reserved").",delayed=".$r->zcard("queues:".$queue.":delayed").PHP_EOL;}'
```

Unrelated delayed work does not block the canary. Do not start while an option-chain job is ready, reserved, or delayed.

## Apply an environment change

Perform each canary expansion in the Forge environment for the worker and scheduler site. After saving the values, run:

```bash
cd /home/forge/stocks-options-ss7u2nu2.on-forge.com/current
php8.3 artisan config:clear
php8.3 artisan config:cache
php8.3 artisan queue:restart
```

Verify the effective configuration before dispatching work:

```bash
php8.3 artisan tinker --execute='foreach(["services.massive.eod_chain_partitioned_fetch_enabled","services.massive.eod_chain_partitioned_canary_symbols","services.massive.eod_chain_max_pages_per_partition","services.massive.eod_chain_reference_probe_max_pages"] as $key){$value=config($key); echo $key."=".(is_array($value)?implode(",",$value):var_export($value,true)).PHP_EOL;}'
```

Do not add, remove, or resize Forge workers before the canary. Existing
processes are sufficient to begin validation, but universal rollout still
requires the runtime gates below in the actual `prime`, `bootstrap`, and
scheduled per-symbol queue contexts.

## Canary order

Expand the eager canary list one symbol at a time, then remove it:

1. `CVX`
2. `CVX,IWM`
3. `CVX,IWM,QQQ`
4. `CVX,IWM,QQQ,SPY`
5. `CVX,IWM,QQQ,SPY,TSLA`
6. Empty value: every eligible symbol

For each canary step:

1. Change `EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS` to the next cumulative value.
2. Clear and rebuild the configuration cache.
3. Restart the queue workers.
4. Verify the effective configuration.
5. Dispatch only the newly added symbol.
6. Wait for its queue work to finish.
7. Apply all acceptance gates below before expanding the canary set.

Set the feature switch to `true` for the first step:

```dotenv
EOD_CHAIN_PARTITIONED_FETCH_ENABLED=true
EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS=CVX
EOD_CHAIN_MAX_PAGES_PER_PARTITION=40
EOD_CHAIN_REFERENCE_PROBE_MAX_PAGES=4
```

After all five canaries and the queue-context runtime gates pass, clear the eager list and repeat the
effective-configuration, queue, failure, and EOD coverage checks:

```dotenv
EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS=
```

Do not dispatch the entire watchlist manually at this point. First observe one
scheduled cycle while the eager canary list is still populated and verify
that its per-symbol jobs drain inside their existing timeout. Then clear the
list, observe the next scheduled cycle, and sample both ordinary and
dense symbols.

## Dispatch one canary

Use the completed-session date printed by the precondition check:

```bash
php8.3 artisan watchlist:repair-missing --date=YYYY-MM-DD --symbols=CVX --chunk=1 --days=90
```

Replace `CVX` with the newly enabled symbol at each subsequent step. If the symbol already has complete data for that session, dispatch one queued refresh instead of changing or deleting its rows:

```bash
php8.3 artisan tinker --execute='$date=app(\App\Support\EodSnapshotSelector::class)->completedSessionDate(now("America/New_York")); \App\Jobs\FetchOptionChainDataJob::dispatch(["CVX"],90,$date)->onQueue("default"); echo "queued date=".$date.PHP_EOL;'
```

For `SPY` and `TSLA`, also prove the shorter prime-lane contract. This uses the
same 110-second job limit as `PrimeSymbolJob`:

```bash
php8.3 artisan tinker --execute='$symbol="SPY"; $date=app(\App\Support\EodSnapshotSelector::class)->completedSessionDate(now("America/New_York")); $job=(new \App\Jobs\FetchOptionChainDataJob([$symbol],90,$date,110))->onQueue(\App\Support\QueueLanes::enrichment()); dispatch($job); echo "queued symbol={$symbol} date={$date} queue=".\App\Support\QueueLanes::enrichment().PHP_EOL;'
```

Do not clear the eager canary list unless both jobs finish without timeout and
their `partition_duration_ms` is below 80000, leaving at least 30 seconds for
queue startup, persistence, and the rest of the job lifecycle. A prime-lane
pass also fits the longer 270-second bootstrap child contract.

Do not dispatch two canaries together. Do not restart workers or deploy another release while a canary is running.

## Acceptance gates

A canary passes only when all the following checks pass.

### Fetch metadata

Read the metadata for the completed-session date:

```bash
php8.3 artisan tinker --execute='$symbol="CVX"; $date=app(\App\Support\EodSnapshotSelector::class)->completedSessionDate(now("America/New_York")); $m=\Illuminate\Support\Facades\Cache::get("eod:fetch-meta:{$symbol}:{$date}",[]); $keys=["status","provider","provider_status","provider_complete","massive_status","chain_fetch_strategy","partition_trigger","provider_pages_total","legacy_pages","legacy_reference_status","legacy_reference_pages","legacy_reference_pagination_capped","legacy_bulk_status","legacy_bulk_pages","legacy_bulk_pagination_capped","legacy_repair_failure_statuses","pagination_capped","reference_status","reference_complete","reference_pages","reference_expiries_found","reference_strategy","reference_probe_status","reference_probe_pages","reference_probe_pagination_capped","partition_dates_scanned","partitions_expected","partitions_resolved","partitions_failed","partition_pages","partition_page_limit","partition_max_pages","partition_cursor_cycles","partition_no_progress","partition_scope_violations","contracts_seen","contracts_unique","partition_duration_ms","partition_failure_reason","partition_failure_expiry","partition_failure_contract_type","expiries_in_window","rows_kept","recorded_at"]; $out=["symbol"=>$symbol,"date"=>$date]; foreach($keys as $key){$out[$key]=$m[$key]??null;} echo json_encode($out).PHP_EOL;'
```

Replace the symbol for each canary. The exact metadata gates are:

- `status=ok`
- `provider=massive`
- `provider_status=ok`
- `provider_complete=true`
- `massive_status=ok`
- `chain_fetch_strategy=partitioned_expiry_side`
- `partition_trigger=configured_eager` for a configured canary, or a
  `legacy_reference_*`, `legacy_bulk_*`, or `legacy_repair_*` trigger for an
  adaptive fallback
- `pagination_capped=false`
- `reference_status=ok`
- `reference_complete=true`
- `reference_strategy` is `bounded_catalog` or `exact_date_fallback`
- `reference_probe_pages` is between `1` and `4`
- `reference_expiries_found > 0`
- `partitions_expected=reference_expiries_found * 2`
- `partitions_resolved=partitions_expected`
- `partitions_failed=0`
- `partition_page_limit=250`
- `partition_max_pages=40`
- `partition_cursor_cycles=0`
- `partition_no_progress=0`
- `partition_scope_violations=0`
- `contracts_unique > 0`
- `partition_duration_ms < 80000` for the prime-lane `SPY` and `TSLA` checks
- `partition_failure_reason`, `partition_failure_expiry`, and `partition_failure_contract_type` are `null`
- `rows_kept > 0`
- `recorded_at` is from the canary invocation
- `expiries_in_window >= 2` for `CVX`
- `expiries_in_window >= 8` for `IWM`, `QQQ`, and `SPY`

For an adaptive fallback, preserve the `legacy_*` fields and
`provider_pages_total` with the validation record. They identify the structural
legacy failure and include both legacy and partition request counts. A fallback
pass is valid only when the final partition metadata meets every gate above.

Reference-strategy gates:

- `bounded_catalog`: `reference_probe_status=ok`,
  `reference_probe_pagination_capped=false`, `partition_dates_scanned=0`, and
  `reference_pages=reference_probe_pages`.
- `exact_date_fallback`: the bounded probe is capped, then
  `reference_probe_status=pagination_capped`,
  `reference_probe_pagination_capped=true`, `partition_dates_scanned=91`, and
  `reference_pages=reference_probe_pages + 91` for `--days=90`.

The code can also recover a failed, cyclic, or malformed bounded probe by
completing every exact-date check. Treat that as a failed rollout canary even
when the final chain is complete; investigate the probe before expanding the
canary scope.

Record `reference_pages`, `partition_pages`, `contracts_seen`, and
`contracts_unique` for comparison. Require `partition_pages <=
partitions_expected * partition_max_pages`. Page count alone does not prove
completeness.

### Persisted data

```bash
php8.3 artisan tinker --execute='$symbol="CVX"; $date=app(\App\Support\EodSnapshotSelector::class)->completedSessionDate(now("America/New_York")); $row=\Illuminate\Support\Facades\DB::table("option_chain_data as o")->join("option_expirations as e","e.id","=","o.expiration_id")->where("e.symbol",$symbol)->whereDate("o.data_date",$date)->selectRaw("COUNT(*) AS rows_n, COUNT(DISTINCT o.expiration_id) AS expirations_n, COUNT(DISTINCT o.strike) AS strikes_n, COUNT(DISTINCT o.option_type) AS option_types_n, COUNT(DISTINCT CASE WHEN o.option_type = \"call\" THEN o.strike END) AS call_strikes_n, COUNT(DISTINCT CASE WHEN o.option_type = \"put\" THEN o.strike END) AS put_strikes_n")->first(); echo json_encode($row).PHP_EOL;'
```

Required results:

- `rows_n > 0`
- `option_types_n=2`
- `expirations_n >= 2` and `strikes_n >= 12` for `CVX`
- `expirations_n >= 8` and `strikes_n >= 35` for `IWM`, `QQQ`, and `SPY`

The EOD health page must show the symbol as covered for the same target date. Existing rows for other symbols and dates must remain unchanged.

### Queue and failure state

- The canary job has left the ready, reserved, and delayed queue states.
- No new terminal failure exists for the canary job.
- The EOD repair log contains `eod.fetch.symbol.ok` for the symbol.
- The log does not contain a later `eod.fetch.symbol.provider_incomplete` for the same symbol and date.

Do not expand the eager canary set or clear it if any gate
fails. Preserve the metadata and log context for diagnosis.

## Rollback

Rollback does not require a code deploy or worker change. In the worker and scheduler site environment, set:

```dotenv
EOD_CHAIN_PARTITIONED_FETCH_ENABLED=false
```

Then run:

```bash
php8.3 artisan config:clear
php8.3 artisan config:cache
php8.3 artisan queue:restart
```

Wait for active canary work to finish before rolling back. Confirm the effective
switch is `false`. This disables eager routing and adaptive fallback. Do not
clear Redis queues, retry all failed jobs, or delete successful EOD rows. Clear
the eager canary list after rollback so it cannot be mistaken for permanent
symbol routing.

## Massive Starter historical limitation

The Massive option-chain snapshot endpoint provides a current or plan-delayed snapshot. It does not accept a historical as-of timestamp for reconstructing a prior chain. The reference endpoint's `as_of` parameter describes contract listings; it does not turn snapshot prices, Greeks, volume, or open interest into a historical snapshot.

For that reason:

- Use this rollout only for the current completed trading session.
- A weekend repair may still identify Friday as the application's completed
  session, but the current snapshot may already omit Friday-expired contracts.
  In that case the strict fetch must fail and preserve the prior snapshot; it
  cannot reconstruct Friday completely on the Starter plan.
- Do not use `--allow-nonhistorical-chain-repair` to label a current snapshot as an older date.
- Historical reconstruction requires a separate licensed historical source or previously captured snapshots.

See the [Massive option-chain snapshot documentation](https://massive.com/docs/rest/options/snapshots/option-chain-snapshot) for the endpoint's supported filters.
