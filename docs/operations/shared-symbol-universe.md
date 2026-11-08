# Shared symbol coverage

`ProductUniverse::symbols()` returns the configured stock and ETF symbols plus distinct symbols saved by any customer. `ProductUniverse::symbols($userId)` returns only that customer's saved symbols. The configured list lives in `config/product_universe.php`; it does not require an owner watchlist row.

The November 8, 2026 expansion contains 37 symbols. Combined with the 106 saved production symbols audited that day, this gives 143 distinct symbols. SPX, XSP, NDX, RUT and VIX are outside this expansion because index quote identifiers, settlement and model behavior need separate support.

## Discovery and notifications

- Swing Ideas opens with All symbols. My watchlist narrows the results, and both holding periods remain available.
- Scanner's intraday ideas open with All symbols and Conditions met. My watchlist remains available.
- The intraday API keeps personal scope unless Scanner explicitly requests `scope=universe`. Shared and personal polling state stay separate.
- Inbox and popup notifications follow the signed-in customer's watchlist. New alert settings enable conditions-met alerts, wall-testing alerts and sound. Explicit saved preferences remain unchanged. Browsers can require interaction before playing sound.
- Customer pages show dated ideas and reference levels. Private Study retains outcome tracking and remains restricted to the authorized account. Owner-only experiments continue using the owner's personal watchlist.

No idea is created merely because a symbol was added. Existing data-quality, freshness and qualification rules still apply. Pages read saved data and do not request chains from providers.

## Collection

The shared universe feeds recurring EOD preparation and repair, intraday chains, underlying quotes, wall computation, setup reviews, swing preparation and calculator scheduling. Collection remains per symbol, rather than per customer. Queue isolation, rate controls, freshness gates, retry limits and existing per-run budgets are unchanged.

`universe:prepare --limit=2` gradually initializes configured symbols without a current option catalog or a price for the last completed session. It uses the existing symbol bootstrap pipeline. Catalog and price presence indicate basic initialization, not certified chain completeness; normal EOD repair and publication retain responsibility for complete inputs.

The initializer runs every ten minutes between 6:00 PM and 8:30 AM ET, including weekends. Each run handles at most two symbols by default, with a hard maximum of five. Shared locks and a six-hour attempt cooldown prevent repeated dispatch; enqueue failures retry after thirty minutes. Existing durable work-run reconciliation handles provider deferrals.

Inspect the next batch without jobs or writes:

```sh
php artisan universe:prepare --dry-run --limit=5
```

The audited expansion increases symbol count by approximately 35%. Chain sizes differ, so symbol count is not a prediction of request volume or job duration. Check queue wait times, provider deferrals, collection freshness and disk headroom during rollout.

## Release configuration

No database migration or new credential is required. The configured expansion is enabled by default. Normal deployment must rebuild Laravel's configuration cache and reload long-running workers so they use the updated universe. `PRODUCT_UNIVERSE_ENABLED=false` disables the configured additions while retaining shared saved-symbol coverage. Tests disable the configured additions unless a case explicitly supplies its own fixture universe.
