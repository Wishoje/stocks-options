# EOD Data Integrity Audit (2026-02-27)

This document summarizes known ways EOD views can show stale, mixed, or incomplete data.

## Scope

Reviewed EOD-facing endpoints and pages:

- `/api/gex-levels`
- `/api/dex`
- `/api/expiry-pressure`
- `/api/iv/term`
- `/api/vrp`
- `/api/iv/skew*`
- `/api/qscore`
- `/api/seasonality/5d`
- `/api/eod/health`
- Dashboard EOD tabs, Scanner wall mode, EOD Health page

## Known Data Risks

### 1) GEX can mix source dates across expirations

`/api/gex-levels` builds from latest row per expiration (`MAX(data_date)` by `expiration_id`).  
Result: one payload can contain different source dates but expose one top-level `data_date`.

Impact:

- GEX levels and strike data can be partially stale without being obvious.

### 2) Compute-date labeling mismatch in derived tables

`dex_by_expiry`, `expiry_pressure`, `iv_term`, `vrp_daily`, and `iv_skew` are written with `data_date = tradingDate(now())`, while input rows are often pulled from per-expiration `MAX(data_date)`.

Impact:

- Endpoints may report "today" even if underlying chain inputs are from prior day(s).
- A widget may look fresh by label but still be built from stale source data.

### 3) EOD chain ingestion is intentionally narrowed

`FetchOptionChainDataJob` keeps:

- strikes only inside spot +/- 40%
- rows only when `open_interest >= 1` or `volume >= 1`
- Greeks only near spot (+/- 15%)

Impact:

- Thin symbols can look incomplete even when ingestion succeeded.
- GEX/DEX/QScore quality can degrade if OTM wings or one side are dropped.

### 4) QScore uses mixed date selection logic

QScore combines:

- option subscore from strict `option_chain_data.data_date = tradingDate(now())`
- vol subscore from latest `vrp_daily`
- seasonality from strict `seasonality_5d.data_date = tradingDate(now())`

Impact:

- On lag days, one component can be stale/missing while others are fresh.
- Composite score can drift toward neutral/misleading output.

### 5) Seasonality endpoint may return older row

`/api/seasonality/5d` loads rows ordered by latest date, then picks by "deepest meta" (`lookback_years/lookback_days`) instead of always picking newest date.

Impact:

- Seasonality panel can display a non-latest record.

### 6) Skew bucket DTE selection uses absolute day diff

Skew bucket selection uses absolute day difference to target DTE and can pick past expiries in edge cases.

Impact:

- Bucket series can jump to non-ideal expiries and look inconsistent.

### 7) Symbol status "ready" is too permissive

`/api/symbol/status` marks ready when there are any rows for today (`rows_today > 0`), even if data is still thin.

Impact:

- UI can flip from "preparing" to "ready" before chain quality is acceptable.

### 8) Scanner universe mismatch

Scanner GEX mode scans all watchlist symbols, but scheduled wall snapshots are computed from hot-universe source by default (`walls:compute --source=hot`).

Impact:

- Watchlist names that are not in hot universe may be absent from wall scanner results.

## Operational Guidance

Use EOD Health as source-of-truth for coverage quality:

```bash
php artisan watchlist:repair-missing --date=YYYY-MM-DD --profile=broad --check-incomplete --dry-run
```

If `covered` is low for target date, do not assume Dashboard/Scanner EOD widgets are reliable for that date.

## Quick Verification

Check latest chain date and core symbol freshness:

```bash
php artisan tinker --execute='$syms=["SPY","QQQ","AAPL"]; $rows=DB::table("option_chain_data as o")->join("option_expirations as e","e.id","=","o.expiration_id")->whereIn("e.symbol",$syms)->selectRaw("e.symbol, MAX(o.data_date) as latest_date, COUNT(*) as rows_n")->groupBy("e.symbol")->get(); print_r($rows->toArray());'
```

Check repair coverage for one date:

```bash
php artisan watchlist:repair-missing --date=YYYY-MM-DD --profile=broad --check-incomplete --dry-run
```

## Related Docs

- [Watchlist Preload Runbook](watchlist-preload-runbook.md)
- [EOD GEX Cache and Prewarm](eod-gex-cache.md)
