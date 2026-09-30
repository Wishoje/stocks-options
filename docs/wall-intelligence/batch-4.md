# Wall intelligence: gamma regime and flip

The EOD Positioning tab includes a Gamma regime & flip panel between wall analysis and dealer positioning. It follows the dashboard symbol, expiry scope, and EOD view. Three summary cards show the regime at the EOD close, detected flips, and net modeled GEX. An expandable curve supports scenario inspection, crossing selection, and returning to the EOD close. The reading guide explains interpretation and assumptions.

## Calculation and scope

`GET /api/gamma-profile` requires authentication and product entitlement and uses the existing market-data read throttle. Parameters are `symbol`, `timeframe`, optional `view`, and optional `session_date`. The response schema is `gamma-profile.v1`; the model is `eod-fixed-iv-gamma-profile.v1`.

The service selects the same expiration dates and EOD chain generation as `/api/gex-levels`. It reconciles stored strike GEX before modeling. Every selected expiry must have rows on the declared EOD date. The reference price must be the daily close on that date. A changed generation returns HTTP 409. A scope without a usable model basis returns a neutral not-ready response instead of inventing values.

For each hypothetical underlying price `S`, the model recalculates Black-Scholes gamma while holding each contract's IV and open interest fixed:

```text
t = seconds to the option expiry's market close / (365 * 86400)
d1 = (ln(S / strike) + 0.5 * IV^2 * t) / (IV * sqrt(t))
gamma = normal_density(d1) / (S * IV * sqrt(t))
GEX = gamma * open_interest * 100 * S^2 * 0.01
net GEX = sum(call GEX) - sum(put GEX)
```

The unit is USD per 1% underlying move. The model uses zero interest and dividend rates, European exercise, and a 100-share multiplier. It evaluates time at the actual EOD date's market close, even when next-session preparation selects future expirations. It does not advance time to the future session or use live quotes. Expiry times respect the existing market calendar, including early closes.

Same-day expirations use an explicit 60-second time floor because gamma at expiration is singular. These scenarios can produce narrow peaks and many crossings. The UI and exported assumptions disclose that floor. Next-session preparation excludes expired contracts through the existing dashboard scope selection.

The implementation uses the same gamma definition as the existing intraday model. The [QuantLib Black-Scholes calculator](https://github.com/lballabio/QuantLib/blob/master/ql/pricingengines/blackscholescalculator.hpp) provides the reference formulation. No new pricing package or provider request is required.

## Crossings and limits

- The grid spans 10% below to 10% above the dated close, with 401 regular samples and additional centers and shoulders for 0DTE contracts. Sign-change brackets are refined by bisection.
- The reference point is calculated directly and included in the curve. Its sign determines the summary regime. Inspecting another price does not change that summary.
- Every detected crossing includes the sign below and above it. `gamma_flip` is populated only for a single crossing. Multiple crossings retain a separate nearest crossing and the full list.
- No detected crossing means none was detected within the sampled range. Narrow crossings between samples or crossings outside that range may remain unresolved. Returned levels are numerical approximations.
- Balanced curves and numerical underflow do not manufacture flips. Extreme inputs are rejected before they can produce non-finite JSON values.
- Modeling requires at least 98% of known OI and no more than 10% excluded contract rows. This is an input gate, not a percentage of GEX captured. Audit counts remain in the export.
- Modeling is limited to 30,000 selected contracts. Responses are cached for five minutes against the selected GEX payload and publication version.

This curve models exposure under a call-minus-put inventory convention. It is not an observation of dealer holdings, a price forecast, or a trade-entry signal. It is separate from HVL and from the sign boundary between strike bars. The existing dealer-positioning gamma label remains available, now labeled as stored fixed-2W context to distinguish its scope and provider-Greek method.

## Exports and operations

The panel can download the full profile as JSON. AI Export includes a Gamma regime & flip indicator with the curve, crossings, reference close, assumptions, scope, and audit metadata. The API and AI export use the same controller.

No database migration, environment variable, provider subscription, scheduled job, or new dependency is needed. Standard PHP application code and frontend assets are sufficient. This batch is prepared for local review and staging; deployment is separate.

## Validation

- Backend: 28 tests passed, 142 assertions, using `artisan test --compact --filter='GammaProfile|WallIntelligence'`. Coverage includes a known gamma value, an analytically solvable flip, multiple crossings, balanced and negative curves, extreme numbers, 0DTE handling, scope/date checks, access control, and API/export parity.
- Frontend: 30 tests passed across gamma-profile, dashboard-wall-navigation, ai-export-history, positioning-dex-parity, and dex-tile-loading. Coverage includes stale-response cancellation, scope mismatch, retries, summary anchoring, scenario inspection, the guide, and EOD/intraday placement.
- The production frontend build passed. Local browser review covered the expanded chart, crossing inspection, return to the EOD close, and the reading guide.
- Six broader `AiExportControllerTest` cases could not complete setup because the existing `2025_10_12_052816_create_unusual_activity_table_index.php` migration queries MySQL `information_schema.statistics` under SQLite. Both that migration and the existing test file are unchanged. The new gamma API/export parity test passed independently.
- Local SPY and QQQ checks used stored September 11, 2026 data. Ready scopes took approximately 0.3–2.2 seconds on the first uncached calculation. Some local short-expiry scopes return not-ready under the model's input gate.

## Manual review

1. Open `/dashboard` locally. Select SPY, End of day, Positioning, and 2W. Find Gamma regime & flip below the wall cards.
2. Open Explore price scenarios. Click the chart or a crossing, then use the price slider. The inspection readout should move while the three EOD summary cards remain fixed. Back to EOD close should restore the initial point.
3. Switch between Latest EOD data and Next-session preparation. Confirm the expiration list and profile update together. Expiring options can cause many crossings in the latest-EOD view.
4. Switch SPY to QQQ and try 1W, 2W, and 1M. Old results should clear while the next profile loads. A scope that cannot be modeled should show the neutral not-ready state without stale summary values.
5. Open Reading guide. Check readability, closing behavior, and narrow-window layout.
6. Download Export gamma profile JSON. In AI Export, select Gamma regime & flip with the same symbol, scope, and EOD view. Compare the reference date, modeled regime, and crossings.
7. Switch to Intraday. The EOD gamma-profile panel should disappear; existing wall tracking remains separate.
