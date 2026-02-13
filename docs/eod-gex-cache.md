# EOD GEX Cache and Prewarm

This document describes the EOD cache strategy for `/api/gex-levels`, how prewarm works, and how to run it manually.

## What was added

1. Server-side cache in `GexController@getGexLevels`.
2. EOD performance logs (`eod.perf`, `eod.perf.slow`).
3. `gex:warm-cache` Artisan command for manual and scheduled prewarm.
4. Scheduler entries to warm `SPY/QQQ` at open and during RTH.

## Cache behavior

Endpoint: `GET /api/gex-levels?symbol=SPY&timeframe=14d`

Cache key pattern:

`gex:levels:v2:{symbol}:{timeframe}:{version}`

Where `version` is derived from:

1. `symbol`
2. `timeframe`
3. latest `data_date` for targeted expirations
4. latest `data_timestamp` for that `data_date`
5. expiration count in scope
6. expiration-date signature

This means cache invalidates automatically when a newer EOD snapshot lands.

Default cache TTL:

`8 hours`

Force refresh query flag:

`/api/gex-levels?...&refresh=1`

When `refresh=1`, the payload is recomputed and cache is overwritten.

## Scheduled prewarm

Defined in `routes/console.php`:

1. `09:25 ET` weekdays:
`gex:warm-cache --symbols=SPY,QQQ --timeframes=14d,30d,90d`
2. Every 30 minutes during RTH (`09:35` to `15:55` ET):
`gex:warm-cache --symbols=SPY,QQQ --timeframes=14d,30d,90d`

## Manual trigger

Run default prewarm:

```bash
php artisan gex:warm-cache
```

Force recompute and overwrite cache:

```bash
php artisan gex:warm-cache --refresh
```

Custom symbols:

```bash
php artisan gex:warm-cache --symbols=SPY,QQQ,IWM
```

Custom timeframes:

```bash
php artisan gex:warm-cache --timeframes=14d,30d,90d
```

Both custom symbols and timeframes:

```bash
php artisan gex:warm-cache --symbols=SPY,QQQ,IWM --timeframes=7d,14d,30d,90d
```

## How to verify it is working

### 1) Command registration

```bash
php artisan list --raw | rg "^gex:warm-cache"
```

### 2) Perf logs

Check log entries:

```bash
rg -n "eod\\.perf|eod\\.perf\\.slow" storage/logs/laravel.log
```

Fields include:

1. `duration_ms`
2. `symbol`
3. `timeframe`
4. `cache_hit`
5. `status_code`
6. `strike_count`
7. `data_date`

### 3) API behavior

Call once:

```bash
curl "http://your-host/api/gex-levels?symbol=SPY&timeframe=90d"
```

Call again and compare `duration_ms` from logs. Second call should typically be faster (`cache_hit=true` in sampled log lines).

## Operational guidance

1. Keep prewarm focused on heavy symbols (`SPY`, `QQQ`, optionally `IWM`).
2. Keep cache enabled for all symbols (cold-load on first request).
3. Add symbols to prewarm only when logs show high demand and slow misses.

## Troubleshooting

1. `schedule:list` fails with DB connection errors:
   Your scheduler uses DB-backed locks/cache; ensure DB is reachable.
2. Prewarm returns 404 "No data":
   EOD chain not ready for that symbol/timeframe yet; this is expected early or on first seed.
3. No perf logs:
   Slow logs are threshold-based, normal logs are sampled. Increase sampling in code if needed.
