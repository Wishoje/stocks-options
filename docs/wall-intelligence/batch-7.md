# EOD wall build and unwind

Dashboard → End of day → Positioning now shows side-specific open-interest changes inside each selected wall card. Put walls use put OI; call walls use call OI. Primary, Level 2 and Level 3 each have their own values. Changing symbol, expiry scope or EOD view reloads the existing wall-intelligence response. No new tab or request is added.

The visible panel shows the daily state, daily and five-session changes, current OI, and up to three expirations with the largest absolute daily changes. The reading guide, volume context and complete expiry table start collapsed. The original combined call-plus-put OI change remains in exposure details and is now labeled explicitly.

## Rules

`WallFlowMetrics` defines `wall-flow-oi.v1`. All OI changes and volumes use contracts, not GEX units.

| State | Daily change in the selected option side's OI |
| --- | --- |
| Building | Greater than +2%, or positive current OI from an observed zero baseline |
| Stable | Between −2% and +2%, including both boundaries; also zero to zero |
| Unwinding | Less than −2% |
| No label | The pair cannot be compared like for like |

The 2% band is an explicit descriptive rule, not a calibrated trading threshold. These states do not measure hold probability or reveal buyer, seller or dealer direction. Rapid unwind and rebuilding classifications are reserved for a later rule set.

Daily change uses the prior trading session. Five-session change uses the date five trading sessions before the current EOD date. The existing market calendar skips weekends and exchange holidays. Six session dates are fetched in the same bounded history query. The exposure chart and top-three streak continue to use five dates. The five-session OI comparison uses endpoints; intermediate dates do not need to be present.

Both endpoints must use the same strike, option side and current fixed expiration basket. Scope dates and side coverage must align. Contract counts and expiry membership at the strike must match. Newly listed contracts or changed baskets are never counted as zero historical OI. No older baseline is substituted. Null or negative OI prevents that side's comparison; a zero baseline is valid but produces no percentage. Gamma availability does not determine OI comparability.

Expiry changes sum to the displayed side-specific total. They are sorted by absolute daily change, then expiry. Five-session change may have the opposite sign to daily change; the badge always follows daily change. EOD call and put volume are separate context and never determine the state. Null volume stays null rather than zero.

## API and export

The existing `/api/wall-intelligence` payload gains `walls.put[].wall_flow` and `walls.call[].wall_flow`. The versioned object includes the side, strike, date, current OI, state, neutral band, baseline dates, absolute and percentage changes, comparability, all expiry contributions, EOD volume context and interpretation rules.

AI Export → Wall intelligence contains this same object at `items[].wall_intelligence.data.walls.{side}[].wall_flow`. Existing fields retain their units and behavior. API authentication and subscription rules are unchanged. The response cache namespace is bumped to prevent older responses from hiding the new fields.

## Performance and release

This adds one earlier date to the existing history aggregation. There are still two option-chain queries per uncached analysis: current data and history. Query count does not grow with the number of selected walls. The existing response cache is reused. No provider requests, queue jobs, intraday collection, scheduler changes, database migrations, environment settings, packages or new subscriptions are required.

Build frontend assets through the normal deployment pipeline when releasing. The local review server uses the existing September 11 EOD dataset. Some five-session baselines cannot be compared in that dataset; those fields remain blank instead of displaying an invented change.

## Local review

1. Open `/dashboard?symbol=SPY&mode=eod&tab=positioning&timeframe=14d&view=next_session` on the local server. Each wall has an open-interest panel below its concentration/distance metrics.
2. In the current local dataset, the primary SPY 2W put wall at 750 shows Building, +125.11K contracts (+140.4%) since September 10. The September 18 expiry is the largest daily contributor.
3. Change to 1DTE. The primary put wall at 760 shows Unwinding, −2.19K contracts (−8.1%). Its call wall shows Building. This describes OI direction; it does not predict price direction.
4. Select Level 2 and Level 3, then change symbols or switch Latest EOD data / Next-session preparation. The OI strike, dates, state and expiry changes should follow the selection.
5. Open How to read OI changes and activity. Check the rules, EOD volume context and full expiry table. They start collapsed. At phone width, cards stack and tables can scroll within their container.
6. Export Wall intelligence with the same symbol, timeframe and view. Compare the `wall_flow` values and baseline dates against the displayed panel.

## Validation

- 22 focused backend tests passed, with 168 assertions. Coverage includes side isolation, ±2% boundaries, zero baselines, null OI, changed strikes/expiry baskets/contract counts, fixed five-session baselines, gamma-independent OI, volume separation, route access, bounded query count, and API/export parity.
- 17 frontend tests passed across wall flow, intelligence and wall-level components. They cover selection changes, request reuse, stale-response rejection, collapsed detail, zero/null formatting and matching card values.
- Six existing AI-export regression tests passed with 42 assertions on the disposable local MySQL database.
- PHP style checks and the frontend build passed.
- Local browser review confirmed populated real OI changes, 2W/1DTE selection, readable guide content, and no desktop horizontal overflow.

Release approval includes Batch 7 together with the October 2 intraday collection and queue-priority repair. The frontend build is required; no environment change or migration is needed for Batch 7.
