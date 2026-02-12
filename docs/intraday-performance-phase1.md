# Intraday Performance Phase 1 (Current State + Tuning Notes)

## What Is Running Now

### Intraday pull scheduler
- `routes/console.php` defines `intraday:polygon:pull` as `->everyFiveMinutes()`.
- The callback exits early when market is closed:
  - skips weekends
  - skips outside RTH via `Market::isRthOpen($nowEt)`
- Symbol universe is pulled from `watchlists` (all unique symbols), with fallback to:
  - `SPY, QQQ, IWM, AAPL, MSFT, NVDA, TSLA, AMZN`
- Symbols are chunked in groups of 15.
- `SPY` and `QQQ` are split to `intraday-heavy` queue so they do not block others.
- Other symbols go to `intraday` queue.

### Intraday warmup
- `intraday:warmup --limit=200` runs at `16:15 ET`.
- It currently warms from `hot_option_symbols` (latest trade date), not from watchlist.
- It dispatches `FetchPolygonIntradayOptionsJob` in chunks of 25.

### EOD preload (not intraday)
- `watchlist:preload` and `preload:hot-options` are EOD-oriented data prep.
- They do not replace intraday polling behavior.

## Are We Preloading Intraday Watchlist Universe?

Short answer: **partially**.

- Yes, watchlist symbols are actively pulled every 5 minutes during RTH by `intraday:polygon:pull`.
- No, dedicated "pre-open warmup for watchlist symbols" is not currently configured; warmup uses hot symbols.

## Does It Make Sense To Preload Intraday Watchlist?

Yes, but with limits:

- Good for first-view latency reduction right after open.
- Most useful for heavy/high-traffic symbols (`SPY`, `QQQ`, `IWM`, top watchlist names).
- Avoid preloading a very large full watchlist universe if queue capacity is limited.

Practical approach:
- Keep broad 5-minute watchlist pull.
- Add focused warmup for top N watchlist symbols near open.
- Keep heavy symbols isolated on `intraday-heavy` queue (already done).

## Is Every 5 Minutes Too Much?

For your current setup, **5 minutes is reasonable** and usually the right baseline.

Why:
- Your UI uses 1-minute snapshots conceptually, but full-chain pulls for many symbols are expensive.
- 5-minute cadence balances freshness and system load.
- You already have queue split for heavy symbols.

When it can be too much:
- If queue lag grows during market hours.
- If request latency (p95/p99) climbs and users get 504s.
- If DB write load spikes from `intraday_option_volumes`.

## Recommended Phase 1 Operating Targets

- Keep pull cadence at 5 minutes.
- Keep retention at 24 hours (already applied) and prune every 30 minutes.
- Ensure worker counts are enough for:
  - `intraday-heavy`
  - `intraday`
- Monitor queue lag and endpoint p95 for `SPY/QQQ`.

## Quick Production Checks

### 1) Verify schedule entries
```bash
php artisan schedule:list
```

### 2) Verify workers by queue
```bash
php artisan queue:work --queue=intraday-heavy,intraday --tries=1 --timeout=120
```

### 3) Verify intraday perf logs
```bash
php artisan intraday:perf-report --hours=24 --symbols=SPY,QQQ --limit=20
```

### 4) Spot-check table growth
```sql
SELECT
  COUNT(*) AS rows_24h
FROM intraday_option_volumes
WHERE captured_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR);
```

## Decision Guidance

- If you still see frequent 504s on many symbols:
  - first scale workers for `intraday` and `intraday-heavy`,
  - then reduce per-run symbol count (or split universes),
  - only then consider moving from 5m to slower cadence for non-core symbols.
- Do not lower freshness for `SPY/QQQ` first; they are high-usage and should stay prioritized.
