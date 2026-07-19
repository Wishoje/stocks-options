# Option-Chain Partitioned Fetch Rollout Runbook

This runbook covers the controlled production rollout of partitioned EOD
option-chain fetches in `FetchOptionChainDataJob` on the worker and scheduler
site. The algorithm is the normal fetch path for every eligible symbol once the
temporary canary restriction is cleared.

## Why this path exists

The Massive option-chain snapshot endpoint returns at most 250 contracts per page. Broad pulls for symbols such as `IWM`, `QQQ`, and `SPY` can reach the application page cap before the provider returns the end of the result set. Raising the broad cap increases request volume without proving that the result is complete.

The partitioned path first probes the reference catalog for at most four pages.
If that bounded catalog probe is dense or capped, it discovers expirations with
exact-date catalog checks. It then fetches every discovered expiration as
separate call and put snapshot partitions. This preserves the expiration
window, option sides, strikes, data fields, validation, and database upsert
behavior. An incomplete partition must keep the symbol incomplete. It must not
publish a partial chain as successful.

This is automatic behavior. `CVX`, `IWM`, `QQQ`, and `SPY` are rollout
canaries, not a permanent classification of special symbols.
Here, eligible means a symbol whose EOD chain fetch reaches Massive with a
valid expiration window and provider configuration.

## Configuration

Configure these values in the Forge environment for the worker and scheduler site:

```dotenv
EOD_CHAIN_PARTITIONED_FETCH_ENABLED=false
EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS=
EOD_CHAIN_MAX_PAGES_PER_PARTITION=40
EOD_CHAIN_REFERENCE_PROBE_MAX_PAGES=4
```

- `EOD_CHAIN_PARTITIONED_FETCH_ENABLED` is the rollback switch.
- `EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS` is only a temporary rollout limiter. A comma-separated value restricts the new path to those canaries. An empty value enables it for every eligible symbol when the global switch is `true`.
- `EOD_CHAIN_MAX_PAGES_PER_PARTITION` limits one bounded provider partition. Keep this fixed during the canary rollout. A cap is a safety boundary, not a completeness target.
- `EOD_CHAIN_REFERENCE_PROBE_MAX_PAGES` limits the first catalog probe. Dense or capped catalogs automatically use exact-date discovery.

Do not increase `EOD_CHAIN_MAX_PAGES`, reduce the expiration horizon, narrow the stored strike band, or relax completeness checks as part of this rollout.

## Preconditions

Before enabling the first canary:

- Deploy the release containing the partitioned fetch implementation and its regression tests.
- Keep queue-lane isolation and the Massive concurrency gate at their separately approved rollout values. Do not combine those changes with this canary.
- Confirm the `default` and `bootstrap` queues have no option-chain work ready, reserved, or delayed.
- Record the release SHA and current EOD coverage.
- Run after market close, after scheduled EOD repair work has drained, and before the next trading session begins.
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

All existing queue processes are sufficient. Do not add, remove, or resize Forge workers for this rollout.

## Canary order

Expand the temporary canary restriction one symbol at a time, then remove it:

1. `CVX`
2. `CVX,IWM`
3. `CVX,IWM,QQQ`
4. `CVX,IWM,QQQ,SPY`
5. Empty value: every eligible symbol

For canary steps 1 through 4:

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

After all four canaries pass, clear the temporary restriction and repeat the
effective-configuration, queue, failure, and EOD coverage checks:

```dotenv
EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS=
```

Do not dispatch the entire watchlist manually at this point. Observe the next
scheduled EOD preload and repair cycle, then verify full coverage and sample
metadata from both ordinary and dense symbols.

## Dispatch one canary

Use the completed-session date printed by the precondition check:

```bash
php8.3 artisan watchlist:repair-missing --date=YYYY-MM-DD --symbols=CVX --chunk=1 --days=90
```

Replace `CVX` with the newly enabled symbol at each subsequent step. If the symbol already has complete data for that session, dispatch one queued refresh instead of changing or deleting its rows:

```bash
php8.3 artisan tinker --execute='$date=app(\App\Support\EodSnapshotSelector::class)->completedSessionDate(now("America/New_York")); \App\Jobs\FetchOptionChainDataJob::dispatch(["CVX"],90,$date)->onQueue("default"); echo "queued date=".$date.PHP_EOL;'
```

Do not dispatch two canaries together. Do not restart workers or deploy another release while a canary is running.

## Acceptance gates

A canary passes only when all the following checks pass.

### Fetch metadata

Read the metadata for the completed-session date:

```bash
php8.3 artisan tinker --execute='$symbol="CVX"; $date=app(\App\Support\EodSnapshotSelector::class)->completedSessionDate(now("America/New_York")); $m=\Illuminate\Support\Facades\Cache::get("eod:fetch-meta:{$symbol}:{$date}",[]); $keys=["status","provider","provider_status","provider_complete","massive_status","chain_fetch_strategy","pagination_capped","reference_status","reference_complete","reference_pages","reference_expiries_found","reference_strategy","reference_probe_status","reference_probe_pages","reference_probe_pagination_capped","partition_dates_scanned","partitions_expected","partitions_resolved","partitions_failed","partition_pages","partition_page_limit","partition_max_pages","partition_cursor_cycles","partition_no_progress","partition_scope_violations","contracts_seen","contracts_unique","partition_failure_reason","partition_failure_expiry","partition_failure_contract_type","expiries_in_window","rows_kept","recorded_at"]; $out=["symbol"=>$symbol,"date"=>$date]; foreach($keys as $key){$out[$key]=$m[$key]??null;} echo json_encode($out).PHP_EOL;'
```

Replace the symbol for each canary. The exact metadata gates are:

- `status=ok`
- `provider=massive`
- `provider_status=ok`
- `provider_complete=true`
- `massive_status=ok`
- `chain_fetch_strategy=partitioned_expiry_side`
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
- `partition_failure_reason`, `partition_failure_expiry`, and `partition_failure_contract_type` are `null`
- `rows_kept > 0`
- `recorded_at` is from the canary invocation
- `expiries_in_window >= 2` for `CVX`
- `expiries_in_window >= 8` for `IWM`, `QQQ`, and `SPY`

Reference-strategy gates:

- `bounded_catalog`: `reference_probe_status=ok`,
  `reference_probe_pagination_capped=false`, `partition_dates_scanned=0`, and
  `reference_pages=reference_probe_pages`.
- `exact_date_fallback`: the bounded probe is capped, then
  `reference_probe_status=pagination_capped`,
  `reference_probe_pagination_capped=true`, `partition_dates_scanned=91`, and
  `reference_pages=reference_probe_pages + 91` for `--days=90`.

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

Do not expand the canary set or clear the temporary restriction if any gate
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
switch is `false`. Do not clear Redis queues, retry all failed jobs, or delete
successful EOD rows. Clear the temporary canary restriction after rollback so
it cannot be mistaken for permanent symbol routing.

## Massive Starter historical limitation

The Massive option-chain snapshot endpoint provides a current or plan-delayed snapshot. It does not accept a historical as-of timestamp for reconstructing a prior chain. The reference endpoint's `as_of` parameter describes contract listings; it does not turn snapshot prices, Greeks, volume, or open interest into a historical snapshot.

For that reason:

- Use this rollout only for the current completed trading session.
- A weekend repair may target the preceding Friday while it remains the application's completed session.
- Do not use `--allow-nonhistorical-chain-repair` to label a current snapshot as an older date.
- Historical reconstruction requires a separate licensed historical source or previously captured snapshots.

See the [Massive option-chain snapshot documentation](https://massive.com/docs/rest/options/snapshots/option-chain-snapshot) for the endpoint's supported filters.
