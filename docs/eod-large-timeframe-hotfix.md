# Large EOD timeframe performance fix

## Change

The GEX response builder now collects current call/put open interest and volume
by strike while it calculates GEX. Previously, it filtered the entire option
chain four times for every strike. That repeated work made SPY and QQQ cold
requests slow enough to exceed the web request deadline.

The fix retains every selected contract and strike. It does not change GEX
formulas, walls, snapshot selection, timeframe definitions, cache keys, cache
expiration, publication fences, or force-refresh behavior. No environment
changes, migrations, queue changes, or timeout increases are required.

## Automated checks

- `GexAggregationComplexityTest` holds the contract count at 960 and increases
  distinct strikes from 24 to 240. It bounds model reads rather than relying on
  machine-dependent timing. The previous implementation fails this test.
- `GexTimeframeParityTest` checks totals, each strike's GEX and changes, walls,
  HVL, fractional strikes, nulls, zero denominators, spot fallback, historical
  comparison dates, and all six UI timeframe boundaries on Friday/weekends.
- Existing snapshot selection, cache publication, and work-endpoint tests
  cover the unchanged data-read and refresh contracts.

Run against a disposable MySQL test database:

```powershell
$env:DB_DATABASE='gex_profile_test'
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml --filter 'GexAggregationComplexityTest|GexTimeframeParityTest|EodSnapshotSelectorTest|EodCacheVersionTest|Gex011WorkEndpointContractTest|DailyChainSnapshotPublicationTest'
```

## Deployment and server checks

Use the existing Forge deployment on both web and worker servers. The web
server handles browser requests; the worker uses the same builder to warm
Redis. No cache flush is needed because calculated results are unchanged.

On each server, enter the site's `current` directory and confirm the release:

```bash
git rev-parse --short HEAD
```

Optional cache warm on the worker:

```bash
time php8.3 artisan gex:warm-cache \
  --symbols=SPY,QQQ,TSLA \
  --timeframes=0d,1d,7d,14d,30d,90d
```

Expect `Warm complete. OK=18, failed=0` while all six timeframes have stored
expirations. Outside that condition, inspect the reported missing timeframe;
an empty expiration range is different from a timeout. This command may use
existing caches, so its runtime alone does not measure a cold calculation.
`--refresh` recomputes without replacing an existing published payload.

## Manual browser checks

1. Hard-refresh the dashboard once after deployment. Open EOD Strikes for SPY,
   QQQ, and TSLA. Test 1M first, then 3M. Expect populated strikes without a 504
   or repeated Retry clicks.
2. For each symbol, switch through 0DTE, 1DTE, 1W, 2W, 1M, and 3M. Check that
   the selected label stays correct, rows render, and the displayed data date
   is sensible for the latest completed trading session.
3. Switch away and back to 1M. Its values should be stable when no new EOD
   publication occurred, and the cached repeat should be quick.
4. In browser developer tools, open Network and filter `gex-levels`. The 1M
   request uses `timeframe=30d`; 3M uses `timeframe=90d`. Confirm HTTP 200 and
   inspect the response's `expiration_dates`, `data_date`, `date_prev`, and
   `strike_data` if a view looks wrong. Do not share cookies or authorization
   headers when reporting a result.
5. Expect differences between timeframes: a longer range includes more
   expirations. OI/volume totals should not decrease when the selected
   expiration set grows and the source snapshots stay the same. Net GEX and
   walls need not move monotonically. Two short ranges can be identical when
   the catalog contains no additional expiration between their boundaries.

For the September 6, 2026 production audit, all 18 combinations used September
4 snapshots and September 3 daily comparisons. Every selected expiration had
data. OI/volume totals and daily changes matched independent database sums.
Those dates are a recorded audit result, not permanent expected values.

## Separate correctness follow-ups

The audit found no mismatch in the 18 production views, but retained these
existing edge cases for a separate data-semantics change:

- Cache keys do not include the New York horizon or completed-session date.
  An eight-hour cached payload can cross a date boundary before publication.
- When expirations select different last-good dates, the response reports
  their maximum date and uses a global date for historical comparisons.
- Headline changes sum the currently displayed strike deltas. A strike that
  exists only in the prior snapshot is not subtracted from those headlines.
- Timeframe lookaheads count weekdays, not exchange holidays, and advertised
  availability comes from expiration catalog entries.
- The web server's minimum side ratio was 0.35 and the worker's was 0.50.
  Both selected identical dates in this audit, but shared-cache writers
  should use an aligned selection policy in a follow-up.

These policies are not silently changed by the performance hotfix. The new
fixtures record the existing behavior so a later correctness change can be
reviewed explicitly.
