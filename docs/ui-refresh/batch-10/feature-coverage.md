# Public feature coverage

| Group | Views represented | Public contract |
| --- | --- | --- |
| EOD analysis | Overview, Positioning, Volatility, Unusual Activity, Strikes | EOD is prepared after the close. Overview and Strikes use the selected EOD timeframe. Other tabs show their own dataset scopes and dates. |
| Intraday analysis | Live Flow, Live Strikes | Stored during-session snapshots show market state, trade date, source time, and freshness. `Live` is the product view name rather than a streaming tick-feed claim. |
| Scan and monitor | Watchlist, Volume Scanner, Wall Scanner | Search, ranking, thresholds, coverage, source kind, and dashboard handoff remain explicit. Pin readings are context, not notifications. |
| Plan and export | Options Calculator, AI Export | Calculator inputs retain quote provenance and scenario assumptions. Export preserves selected symbol and indicator scope without changing dataset contracts. |

The product is designed for supported US-listed optionable stocks and ETFs when the required provider data exists. Unavailable values and datasets remain distinct from numeric zero. EOD Health is an authorized operational diagnostic and is not marketed as a subscriber feature.
