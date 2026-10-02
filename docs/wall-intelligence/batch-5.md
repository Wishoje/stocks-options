# GEX by strike and expiration

The EOD Strikes tab includes an expiration map directly below Net GEX by strike and above the open-interest and volume change charts. It follows the selected symbol, expiry scope, and EOD view. Select a cell to inspect its call, put, and net GEX, or jump to the primary put wall, call wall, or dated close. The inspector shows the dominant expiry and the share tied to the earliest selected expiration.

## Calculations and units

Each cell aggregates the selected stored option-chain rows for one strike and expiration. Net GEX is call GEX minus put GEX under the existing inventory convention. Values use USD per 1% underlying move, calculated as gamma × open interest × 100 × underlying price squared × 0.01. The underlying price and gamma come from each stored contract row, following the existing strike calculation.

For a strike, each expiry contributes its absolute net GEX divided by the sum of absolute net GEX across that strike's expirations. Exposures of −6M and +4M therefore contribute 60% and 40%, despite a signed total of −2M. The largest share is `wall_expiry_concentration`. `expiring_next_ratio` uses the explicit `next_expiry_dates` set, currently the earliest date in the selected scope. These fields are ratios from 0 to 1; individual `contribution_pct` values are percentages.

Concentration describes expiry sensitivity. It does not measure the probability of a wall holding or breaking. A zero denominator produces null shares. Concentration also requires aligned source dates, at least 98% of known open interest covered, and no more than 10% excluded contract rows. All recorded exposures remain in the response; audit fields retain the calculation basis. An absent strike/expiry pair is distinct from a recorded zero.

The existing EOD Net GEX chart now displays the same USD per 1% units through an opt-in display conversion. Raw API and existing export values retain their units. Other uses of that chart, including intraday and social images, retain their current display behavior.

## Data and performance

`GET /api/gex-expiry-strike` requires authentication and product entitlement. It accepts `symbol`, `timeframe`, optional `view`, and optional `session_date`, and uses the existing market-data read throttle. The response schema is `gex-expiry-strike.v1`.

The service uses the dashboard's selected expirations and source anchor. Call, put, and net totals must reconcile with the selected GEX generation. A mismatch or a publication change during construction returns HTTP 409. Responses are cached for five minutes against the base payload and publication version. AI Export calls the same controller through the `gex_expiry_strike` indicator.

The frontend loads the map only when the Strikes tab is active, cancels superseded requests, and validates scope and totals before rendering. At most 21 strikes and eight expirations appear together. Paging and the strike selector expose the complete selection without another request. Color intensity uses one fixed scale across the selection. The complete table renders only after expansion, with 50 readings per page. JSON downloads include every returned cell.

No database migration, environment change, provider subscription, new dependency, or scheduled job is required.

## Validation

The targeted backend suite passed 20 tests and 83 assertions, covering signed exposure reconciliation, offsetting expirations, zero values, scope isolation, source dates, access control, and API/export parity. Frontend regression checks passed 82 distinct tests across the expiration map, gamma profile, dashboard navigation, request lifecycle, AI export history, and EOD/intraday strike charts. The final frontend build passed.

Browser checks covered SPY 2W, QQQ 2W and 1M, wall shortcuts, the reading guide, and a 390-pixel mobile viewport. The QQQ 1M JSON download retained all 367 strikes and 2,452 cells. The mobile heatmap scrolls inside its panel and keeps a compact selected-cell reading visible while browsing.

## Local review

1. Open `/dashboard`, choose SPY, End of day, Strikes, and 2W. The expiration map appears directly below Net GEX by strike.
2. Choose Put wall or Call wall. Inspect cells and use arrow keys. The right-hand reading and expiry shares should follow the selection.
3. Use Later expiries, Earlier expiries, and the strike selector. Browse beyond the initial grid and confirm that the current selection remains identifiable.
4. Compare Latest EOD data with Next-session preparation, then change to QQQ and another timeframe. The previous map should clear while the next selection loads.
5. Expand All strike and expiration readings, change table pages, and export the map JSON. In AI Export, select GEX by strike and expiration with the same symbol, scope, and EOD view. Totals and dates should agree.
6. Open Reading guide and review the layout in a narrow browser window. The heatmap may scroll horizontally within its panel; the page should remain usable.
7. Switch to Intraday. The EOD expiration map should disappear and the existing intraday views should remain available.

Local review used the existing September 11, 2026 dataset. Deployment uses the production database through the same endpoint and requires the standard web and worker release process. This feature does not change stored market data.
