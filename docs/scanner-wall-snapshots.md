# Scanner And Wall Snapshots

## Purpose

This documents how the GEX wall scanner gets its symbol universe, how
`symbol_wall_snapshots` is populated, and which failure modes can make the
scanner appear stuck on a single symbol such as `SPY`.

## Scanner Universe

There are now two separate concepts:

- Personal watchlist: user-scoped add/remove state used for UI markers and
  watchlist actions.
- Scanner universe: distinct symbols aggregated across all users' watchlists.

The scanner page uses the global watchlist universe for GEX scanner mode.

Relevant files:

- `app/Http/Controllers/WatchlistController.php`
- `routes/api.php`
- `resources/js/Pages/Scanner.vue`

## Snapshot Source

The scanner itself does not calculate walls. It reads from
`symbol_wall_snapshots` via `WallScannerController`.

Relevant file:

- `app/Http/Controllers/WallScannerController.php`

Snapshots are built by:

- Command: `walls:compute`
- Implementation: `app/Console/Commands/ComputeSymbolWallSnapshots.php`
- Helper service: `app/Services/WallService.php`

## Why The Scanner Can Look SPY-Only

These were the main failure modes:

1. The scanner UI used to post only the signed-in user's watchlist. If that
   user mostly had `SPY`, the scanner looked `SPY`-only.
2. `walls:compute` used to run with `--source=hot`, so snapshot coverage only
   matched the hot universe, not the global watchlist universe.
3. The 5:40 PM ET scheduled snapshot build used a 30-minute spot freshness
   window on weekdays. After the close, that could skip most symbols.
4. The snapshot schema had `intraday_put_wall` columns, but the compute path
   did not populate them, so the scanner had incomplete intraday fallback data.

## Current Expected Behavior

`walls:compute` now supports:

- `--source=hot`
- `--source=watchlist`
- `--source=both`
- `--source=all`

The default is `both`, which merges:

- the latest `hot_option_symbols` universe
- all distinct symbols from `watchlists`

After the cash close, the compute job now tolerates older quotes instead of
requiring a strictly fresh 30-minute quote.

## Manual Rebuild

If `symbol_wall_snapshots` for the current trade date is missing or obviously
under-populated, run:

```bash
php artisan walls:compute --timeframe=all --limit=400 --source=both
```

For broader coverage:

```bash
php artisan walls:compute --timeframe=all --source=all
```

`--source=all` is heavier because it uses all distinct symbols from
`option_expirations`.

## Scheduled Runs

The scheduler should run `walls:compute` with `--source=both`:

- intraday every 15 minutes during RTH
- once after close

Relevant file:

- `routes/console.php`

## If Today Still Only Shows SPY

If the scanner still only shows `SPY` after rebuilding snapshots, inspect the
upstream data tables rather than the scanner controller:

- `watchlists`
- `hot_option_symbols`
- `underlying_quotes`
- `option_chain_data`
- `option_expirations`
- `symbol_wall_snapshots`

At that point the likely issue is missing source coverage, not scanner UI logic.
