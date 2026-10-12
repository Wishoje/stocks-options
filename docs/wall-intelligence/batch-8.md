# DEX by strike

Dashboard → End of day → Strikes includes Delta exposure by strike below the expiration map. It shows signed delta exposure for the same symbol, EOD view and expiration basket as Net GEX by strike. The selected-strike panel compares DEX and GEX without combining their units.

The summary shows total net DEX and the largest positive and negative strike contributions. The chart opens near the dated closing price. Users can select a bar, choose any strike, inspect either extreme, or page through all strikes. Arrow keys, Home and End move the selection. The chart displays 31 strikes at desktop width and 15 at narrow widths. The complete table displays 50 rows per page. Pagination uses the loaded response and adds no requests.

## Calculation

DEX is signed option delta × open interest × 100, summed across the selected expirations. Its unit is share equivalents. Call delta contributes positively and put delta negatively; puts are not negated a second time. This matches the existing positioning convention. Actual dealer holdings and future hedge trades are not observed. DEX sign alone does not determine buy or sell pressure.

Call delta must be between 0 and 1, put delta between −1 and 0, and open interest must be nonnegative. Zero open interest has a known zero contribution even without delta. Invalid inputs are excluded from the eligible contribution sum. A side with no contracts contributes zero; a side with contracts but no eligible inputs is null. A wholly unavailable strike or total remains null. Per-strike audit fields record eligible and excluded rows, known and covered OI, and calculation status. These fields remain in the API and exports.

GEX uses the existing stored-gamma, OI and underlying-price calculation with the platform's call-minus-put convention, normalized to USD per 1% underlying move. Its availability is independent of DEX eligibility. Every call, put and net GEX value must reconcile with the selected dashboard values before the response is shown. A changed publication returns HTTP 409 for refresh, including offsetting changes that leave net GEX unchanged.

The reference price is the positive finite daily close for the selected EOD date. It is labeled as a close and never presented as a live quote. The existing dealer-positioning panel by expiration retains its own reporting window, so its total can differ.

## API and export

`GET /api/dex-by-strike` accepts `symbol`, `timeframe`, `view` and optional `session_date`. It uses the same authentication, subscription and market-data read throttle as the other strike endpoints. `GexController` selects the canonical expiration basket and source anchor. `EodSnapshotSelector` selects the stored rows for each expiry.

The payload is versioned as `dex-by-strike.v1`, with model `stored-signed-delta-oi.v1`. It includes scope, units, conventions, dated reference price, every strike, summary values and audit metadata. `pressure_direction` is null and `pressure_status` is `scenario_model_required`. Historical outcome eligibility remains false.

AI Export → DEX by strike uses this same endpoint and scope at `items[].dex_by_strike.data`. Its summary appears at `items[].summary.dex_by_strike`. The panel's Export DEX by strike JSON button downloads all rows, regardless of the visible chart or table page.

## Performance and release

The new service executes three database queries: expiration IDs, one grouped option-chain aggregation, and the dated close. Query count does not grow with the number of strikes. Responses are cached for five minutes using the selected levels and the symbol's GEX publication version. Delta-only publications invalidate this cache as well. The client has a bounded two-minute cache, aborts superseded requests and rejects late or mismatched responses. It requests data only while the Strikes tab is active.

This batch adds no provider calls, queue jobs, intraday collection, database migrations, environment settings, packages or subscriptions. Release requires the normal frontend build and application deployment.

## Manual review

1. Open `/dashboard?symbol=SPY&mode=eod&tab=strikes&timeframe=14d&view=latest_eod` locally. Scroll below the expiration map to Delta exposure by strike.
2. With the September 11 local dataset, SPY Latest EOD 2W shows +20.33M net DEX across 321 strikes. Inspect strike 730: DEX is +2.31M and GEX is −303.76M. The different signs are valid; the metrics measure different sensitivities.
3. Use Near close, select bars, page to lower and higher strikes, and use keyboard navigation. The inspector and selected bar should agree. Open the complete table and change its page.
4. Switch SPY to 1DTE. Net DEX changes to +8.30M across 199 strikes. Switch to QQQ Latest EOD 2W: it shows +16.10M across 283 strikes. Switching to Next-session preparation changes the selected expiration basket and recalculates the values.
5. Open How to read delta exposure. Check the signed-delta example, units, scope explanation and suggestion to use intraday wall tracking for observed price behavior. This is explanatory text, not a forecast of dealer trades.
6. Download the panel JSON or select DEX by strike in AI Export with the same symbol, timeframe and EOD view. Compare the summary and selected strike. Local data is dated September 11; it is not the production feed.

## Validation

- 19 exposure endpoint tests passed with 92 assertions, including 11 new DEX tests and eight existing expiration-map tests. Coverage includes signs, units, true zeros, null inputs, scope and date isolation, GEX reconciliation, route access, bounded query count, publication invalidation and API/export parity.
- Six existing AI-export backend tests passed with 42 assertions on the disposable local MySQL database.
- 24 frontend tests passed across DEX, dashboard navigation, the expiration map and AI-export history. Coverage includes lazy loading, selection, pagination, keyboard navigation, stale-response rejection, scope matching and null/zero formatting.
- PHP style checks and the production frontend build passed.
- Local API/export comparisons matched for SPY and QQQ in 2W and 1DTE. Browser review confirmed scope and symbol changes, next-session preparation, strike inspection, the reading guide and the 390px component layout using recorded local data.

## Dashboard grouping

The existing five EOD tabs and three intraday tabs remain. Put-versus-call pricing now appears first in Volatility, followed by term structure and variance risk premium. Five-session seasonality appears at the end of Overview. Positioning retains wall analysis, gamma regime, dealer positioning and expiry pressure.

Seasonality loads only while Overview is active. Its five-minute cache, pending retries, terminal empty result and independent retry control are retained. Requests and retries stop when the symbol, dataset or tab changes, or the dashboard unmounts. Skew loads only while Volatility is active; its expiry selector remains selected when moving between EOD tabs. Term structure and VRP load independently of skew and seasonality.

Strikes has Jump to links for GEX, Expirations, DEX, OI change and Volume change. At desktop width these share the existing tab row. On narrow screens they wrap. Each link moves keyboard focus to its chart and accounts for the fixed header when scrolling. The links preserve symbol, expiry scope, EOD view and chart state. They add no API requests and respect reduced-motion preferences.

Review Overview and Volatility for the moved cards, then use all five Strikes links. Also select a skew expiry target, visit Positioning and return to Volatility to check that the selection is retained. The grouping changes require only the normal frontend build. The focused dashboard, volatility and skew suites passed 120 tests; browser review checked the desktop layout, chart scroll offset and the dashboard at 390px width.
