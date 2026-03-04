# Watchlist Preload Runbook

This runbook covers how to run and verify `watchlist:preload` so all watchlist symbols are processed for EOD data.

## What `watchlist:preload` does

- Loads all distinct symbols from `watchlists`.
- Dispatches batched/chained jobs for each symbol chunk:
  - `PricesBackfillJob`
  - `PricesDailyJob`
  - `FetchOptionChainDataJob`
  - `ComputeVolMetricsJob`
  - `Seasonality5DJob`
  - `ComputeExpiryPressureJob`
  - `ComputePositioningJob`
  - `ComputeUAJob`

## Schedule and workers

Scheduled in `routes/console.php`:

- Weekdays at `16:15` America/New_York.
- Uses `withoutOverlapping(120)` and `onOneServer()`.
- Core liquid repair pass every 30 minutes from `18:15` to `20:15` America/New_York:
  - `watchlist:repair-missing --check-incomplete --profile=core --symbols=SPY,QQQ,IWM,AAPL,MSFT,NVDA --chunk=6 --days=90`
- Broad watchlist repair pass every 30 minutes from `18:30` to `20:30` America/New_York:
  - `watchlist:repair-missing --check-incomplete --profile=broad --chunk=10 --days=90`

Required at runtime:

- Scheduler process running.
- Queue workers running (at least queue `default` for this command chain).

## Manual run

```bash
php artisan watchlist:preload
```

Dry-run missing-symbol detection only:

```bash
php artisan watchlist:repair-missing --dry-run
```

Manual missing-symbol repair run:

```bash
php artisan watchlist:repair-missing --check-incomplete --profile=broad --chunk=10 --days=90
```

Manual repair including backfill (heavier):

```bash
php artisan watchlist:repair-missing --with-backfill --chunk=10 --days=90
```

Useful incomplete-data thresholds:

```bash
php artisan watchlist:repair-missing --check-incomplete --min-expirations=1 --min-strikes=20 --min-strike-ratio=0.60 --dry-run
```

Profile defaults:

- `--profile=broad`:
  - `min_expirations=2`
  - `min_strikes=12`
  - `min_strike_ratio=0.45`
- `--profile=core`:
  - `min_expirations=8`
  - `min_strikes=35`
  - `min_strike_ratio=0.65`

You can override profile defaults with:

- `--min-expirations=...`
- `--min-strikes=...`
- `--min-strike-ratio=...`

Common incomplete reasons:

- `missing_call_or_put`: only one side (calls or puts) was ingested.
- `low_expirations`: symbol has fewer than `--min-expirations`.
- `low_strike_count`: symbol has fewer than `--min-strikes`.
- `strike_ratio_below_threshold(x<y)`: target strike count dropped too much vs previous snapshot.

## Check latest batch completion

Use this first. It tells you if the preload batch finished cleanly.

```bash
php artisan tinker --execute='$b = DB::table("job_batches")->where("name","Watchlist EOD Preload")->orderByDesc("created_at")->first(); if(!$b){ echo "no_batch".PHP_EOL; return; } $fmt = fn($ts) => $ts ? \Carbon\Carbon::createFromTimestamp((int)$ts, "America/New_York")->toDateTimeString() : "null"; echo "id=".$b->id.PHP_EOL; echo "created_et=".$fmt($b->created_at).PHP_EOL; echo "finished_et=".$fmt($b->finished_at).PHP_EOL; echo "cancelled_et=".$fmt($b->cancelled_at).PHP_EOL; echo "total_jobs=".$b->total_jobs.PHP_EOL; echo "pending_jobs=".$b->pending_jobs.PHP_EOL; echo "failed_jobs=".$b->failed_jobs.PHP_EOL;'
```

Healthy result:

- `pending_jobs=0`
- `failed_jobs=0`
- `cancelled_et=null`
- `finished_et` has a timestamp

## Check symbol coverage for latest EOD chain date

This verifies which watchlist symbols have chain data on the latest `option_chain_data.data_date`.

```bash
php artisan tinker --execute='$w = DB::table("watchlists")->pluck("symbol")->map(fn($s)=>\App\Support\Symbols::canon($s))->filter()->unique()->values(); $latestData = DB::table("option_chain_data")->max("data_date"); echo "watchlist_symbols=".$w->count().PHP_EOL; echo "latest_chain_date=".$latestData.PHP_EOL; $covered = DB::table("option_chain_data as o")->join("option_expirations as e","e.id","=","o.expiration_id")->whereDate("o.data_date",$latestData)->whereIn("e.symbol",$w)->pluck("e.symbol")->map(fn($s)=>\App\Support\Symbols::canon($s))->filter()->unique()->values(); $missing = $w->diff($covered)->values(); echo "covered=".$covered->count().PHP_EOL; echo "missing=".$missing->count().PHP_EOL; if($missing->count()){ print_r($missing->all()); }'
```

## Diagnose missing symbols (stale vs no data)

Run this when `missing > 0`.

```bash
php artisan tinker --execute='$syms = ["META","MSFT","PSLV","QQQ","TLT","UCG.MI"]; $haveExp = DB::table("option_expirations")->whereIn("symbol",$syms)->distinct()->pluck("symbol"); $noExp = collect($syms)->diff($haveExp)->values(); echo "no_option_expirations=".$noExp->count().PHP_EOL; if($noExp->count()){ print_r($noExp->all()); } $rows = DB::table("option_expirations as e")->leftJoin("option_chain_data as o","o.expiration_id","=","e.id")->whereIn("e.symbol",$syms)->select("e.symbol", DB::raw("MAX(o.data_date) as latest_data_date"))->groupBy("e.symbol")->orderBy("e.symbol")->get(); print_r($rows->toArray());'
```

Notes:

- If `latest_data_date` is older than the latest chain date, that symbol is stale.
- If symbol is in `no_option_expirations`, provider/universe coverage is likely the issue.

## Rerun only missing symbols

Replace symbol list with the `missing` output from the coverage check.

```bash
php artisan tinker --execute='(new \App\Jobs\FetchOptionChainDataJob(["META","MSFT","PSLV","QQQ","TLT","UCG.MI"],90))->handle(); echo "done".PHP_EOL;'
```

Then rerun the coverage check.

## Optional queue health checks

```bash
php artisan tinker --execute='echo "jobs_default=".DB::table("jobs")->where("queue","default")->count().PHP_EOL; echo "jobs_all=".DB::table("jobs")->count().PHP_EOL; echo "failed_jobs=".DB::table("failed_jobs")->count().PHP_EOL;'
```

If backlog is high, make sure workers are running and sized correctly.

## Repair visibility logs

The app now writes detailed per-symbol repair/fetch outcomes to:

- `storage/logs/eod-repair-YYYY-MM-DD.log`

Useful tail command:

```bash
tail -F storage/logs/eod-repair*.log | grep --line-buffered -E "eod\\.repair|eod\\.fetch"
```

Key events:

- `eod.repair.scan`: summary of missing/incomplete candidates per run.
- `eod.repair.chunk_queued`: each chunk queued to the batch.
- `eod.fetch.symbol.ok`: symbol ingested with `rows_kept`.
- `eod.fetch.symbol.no_provider_data`: provider fallback exhausted.
- `eod.fetch.symbol.no_expiries_in_window`: provider returned data but none within configured horizon.
- `eod.fetch.symbol.skipped`: duplicate guard prevented a concurrent pull.

## EOD health dashboard

Authenticated route:

- `/eod-health`

The page is color-coded by status per symbol:

- `Missing`: no rows for target date.
- `Alert`: incomplete hard checks (missing side / low expirations / low strikes).
- `Warn`: softer degradation (typically strike-ratio drop).
- `OK`: no threshold violations.

API used by the page:

```bash
GET /api/eod/health?profile=broad&date=YYYY-MM-DD
```

Optional query params:

- `symbols=SPY,QQQ,...`
- `min_expirations=...`
- `min_strikes=...`
- `min_strike_ratio=...`

See also: [EOD Data Integrity Audit](eod-data-integrity-audit.md) for cross-view stale/mixed-data risks.
