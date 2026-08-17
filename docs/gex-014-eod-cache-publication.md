# EOD cache publication

Card: GEX-014

## Runtime contract

`watchlist:preload` no longer calls `Cache::flush()`. EOD response caches use
per-symbol publication versions in four independent domains:

- `gex`
- `volatility`
- `expiry-pressure`
- `activity`

The final job in a successful preload or repair chain advances the affected
symbol versions. Failed chains never reach that job, so an existing last-good
payload remains under the current version. Old payload keys retain their normal
TTL and expire without wildcard deletion.

Publication jobs carry a stable token and issuance timestamp in their serialized
payload. A cache lock makes each symbol/domain comparison atomic. A retried job
reuses its token, and a delayed older finalizer cannot replace a newer version.

Batch expiry-pressure reads load all symbol versions with one cache multi-get.
Publishing one domain does not cold the others. Publishing one symbol does not
change another symbol.

## Producer boundaries

| Producer | Publication boundary |
| --- | --- |
| `watchlist:preload` | All four domains after the complete per-symbol chain |
| `watchlist:repair-missing` | All four domains after the complete repair chunk |
| `options:fetch` | All four domains after all synchronous computations |
| Bootstrap/enrichment | Base GEX and expiry domains plus only the enrichment domains actually written |
| `vol:compute` and scheduled core volatility | Volatility only, after successful computation |
| `expiry:compute` | Expiry pressure only, after successful computation |
| `ua:compute` | Activity only, after successful computation |
| `chain:snapshot` | GEX regime fields, expiry pressure, and activity after the atomic snapshot and synchronous derived work |
| `eod:recover-session --publish/--rollback` | GEX only, after exact slice insertion or deletion and before terminal recovery artifacts |

Directly invoking `FetchOptionChainDataJob` does not advance a cache version.
Callers must use one of the orchestrated producers above so a partial or failed
raw fetch cannot invalidate the current GEX generation.

Positioning publishes the GEX domain because `GexController` embeds its
`gamma_strength` result in the response. This creates one intentional cold GEX
generation after a successful positioning or snapshot publication. Prewarm
heavy symbols after deployment or an operator-triggered bulk publication.
GEX-024/GEX-025 own intrinsic cold-response latency and automatic early warmup.

Historical EOD recovery stores a stable GEX publication token and microsecond
issuance time in its signed prepared intent. If cache publication fails after
the exact database change, the terminal receipt is withheld and the operation
can resume without rewriting rows or minting a new generation. Prepared intents
from before GEX-014 fall back to their signed intent hash and preparation time.

`/api/gex-levels?refresh=1` may recompute a diagnostic response, but it does not
overwrite an existing payload in the current published generation. If that key
does not exist, it may seed the response visible during an in-progress raw
write. Fully manifest-backed cache misses and early cache lookup remain
GEX-025/GEX-026 work; GEX-014 does not claim raw snapshot fencing on a miss.

## Deployment

No environment variables or database migrations are required. Deploy worker
and web releases together and restart workers so queued finalizers use the new
serialized job class.

The first deployment changes response-key namespaces and therefore starts cold.
After deployment, prewarm the common heavy-symbol views without forcing a
replacement:

```bash
php8.3 artisan gex:warm-cache \
  --symbols=SPY,QQQ,IWM \
  --timeframes=7d,14d,30d,90d
```

## Heavy-symbol timeout mitigation

The AppShell now limits unusual-activity badge requests to four concurrent
calls. Starting a newer watchlist reload aborts queued and in-flight requests
from the older reload. This reduces CPU and database contention while a cold
SPY or QQQ GEX response is being built.

This mitigation does not change the GEX response or remove strikes. It also
does not replace GEX-024, which owns the set-based rewrite of the cold GEX
query and aggregation path. Until that card is complete, prewarm SPY, QQQ, and
IWM after deployment and after an operator-triggered bulk EOD publication.

## Verification

Confirm that the global flush is gone:

```bash
rg -n "Cache::flush" app/Console/Commands/PreloadWatchlistSymbols.php
```

The command should return no matches.

Inspect current production versions:

```bash
php8.3 artisan tinker --execute='$v=app(\App\Support\EodCacheVersion::class); foreach(["SPY","QQQ"] as $s){foreach(\App\Support\EodCacheVersion::ALL_DOMAINS as $d){echo "$s:$d=".$v->current($d,$s).PHP_EOL;}}'
```

Confirm the heavy-symbol cache is warm. Run the same command twice; the second
run should be much faster and should not report failures:

```bash
time php8.3 artisan gex:warm-cache \
  --symbols=SPY,QQQ,IWM \
  --timeframes=7d,14d,30d,90d
```

In the browser network panel, reload the application with a populated
watchlist. At most four `/api/ua` requests should be active at once. Rapidly
reloading or changing the watchlist should cancel the older request group.

Run the automated regression proof locally:

```bash
php artisan test tests/Feature/EodCacheVersionTest.php
php artisan test tests/Unit/QueueContractTest.php tests/Unit/ScheduleContractTest.php
```

## Rollback

Roll back web producers before worker consumers. Allow queued
`PublishEodCacheVersionJob` payloads to drain before removing the class. A code
rollback returns readers to the earlier cache-key namespaces; warm the heavy
symbols after rollback. Do not restore the global cache flush.
