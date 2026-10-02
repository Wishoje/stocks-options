# Price behavior at the walls

Dashboard → Intraday → Wall tracking includes a price-behavior panel beneath the wall timeline. It describes completed price reactions at the leading modeled put and call walls. A chart marker, recent-event button, or event selector opens the supporting five-minute bars. The reading guide explains each label. No new dashboard tab is added.

## Detection rules

The immutable profile is `completed-5m.v1`, in `WallInteractionRules`. It applies to US equities and ETFs during the regular market session, using completed, unadjusted five-minute OHLC bars. These are initial descriptive thresholds. They have not been calibrated into a trading edge or a hold/break probability.

| Label | Required observations |
| --- | --- |
| Approaching | The close moves closer to the wall and is within three touch-band widths. |
| Touch | A completed bar overlaps the band. Its high and low do not establish the order of intrabar events. |
| Testing | The close is inside the band, or a retest/reclaim comparison is in progress. |
| Bounce / rejection | After a touch, a later close moves at least one band width away on the original side within 15 minutes. An upward reaction is a bounce; a downward reaction is a rejection. |
| Break | A completed close crosses from the established original side to beyond the opposite band edge. |
| Acceptance above / below | At least two consecutive five-minute closes beyond the band on the break side. The two bar intervals span ten minutes; this does not assert continuous trade-by-trade time beyond the level. |
| Reclaim | After a break, two consecutive completed closes return to the original side. This invalidates the preceding break sequence. |
| Failed reclaim | After acceptance, and within 30 minutes of the break, a separate later bar retests the band and a subsequent completed bar closes back on the break side. Remaining on one side without a retest does not qualify. |
| Confirmed breakout / breakdown | Acceptance, a failed retest, then another close at least one band width farther through the break, within the 30-minute window. Confirmation refers only to this observed sequence. |
| Watching | An approach or comparison has not formed, or a new comparison must start. The export status is `unknown`. |

The band is ±5 basis points of the fixed wall strike, with a one-cent minimum. Equality at either edge belongs to the band and cannot count as a close beyond it. A wick cannot confirm a break. A single OHLC bar cannot supply an ordered break, retest, failure and continuation. Event and first-touch timestamps identify completed bar ends, not an assumed exact trade time within the bar. Retests must follow an already completed acceptance; acceptance and retest are not inferred in sequence within one bar.

Each side keeps its own comparison. It starts from the price's observed side of that wall; a put wall can be above price, and a call wall can be below it. The comparison resets when the leading strike, scope, option-input basis, quote source or session changes. A wall change within a bar excludes that bar from classification. Skipped, invalid or duplicate bars interrupt the sequence; no candles are synthesized. A wall reading must precede the bar start and be at most 15 minutes old at its close. A wall first observed later is never projected backward.

MarketSession supplies holidays, regular hours and early closes. The bar cutoff is current time minus at least 15 minutes of feed delay and 30 seconds of finalization time. A bar's provider timestamp is its start, so its end must also pass that cutoff. If recorded price bars lag the eligible cutoff by more than ten minutes, the UI identifies the last recorded behavior. Session changes do not carry confirmation forward.

## Data, collection and export

The existing Massive client uses the stock custom-bars endpoint once per symbol and session per collection cycle. It requests five-minute, split-unadjusted bars with a bounded one-day range. Responses for the wrong symbol, adjusted prices or incomplete pagination are rejected. Existing provider concurrency and backpressure controls apply. Permission or rate-limit responses start a shared 15-minute pause. No provider requests, queue dispatches or evidence writes occur when someone opens or refreshes the timeline.

`walls:capture-interactions` discovers symbols with recorded wall observations for the current session. A request serves every recorded expiry scope for that symbol. Collection has a 60-second work budget and resumes from a cursor on the next run. A five-minute per-symbol check prevents duplicate collection. The schedule runs only when `WALL_INTERACTIONS_ENABLED=true`, from 20 minutes after the market opens through 30 minutes after it closes. The feature is disabled by default.

The existing append-only `wall_observations` table stores `intraday_price_bars` bundles and `wall_interactions` assessments. Every assessment includes its rule version, timestamps, price evidence hash, source wall hashes, basis keys, completed bars and individual transitions. Provider corrections append evidence rather than replacing earlier records. A correction that reverts to an older price value is also recorded. Reads replay the latest stored bundle against the selected wall history under the same versioned rules.

`GET /api/intraday/walls` adds `wall_interaction` to its existing response. Authentication and the strict intraday feature entitlement remain unchanged. Export timeline JSON contains the same object, suitable for AI analysis, including the complete event list and bar evidence. No intraday indicator is exposed through the EOD-only AI Export permission. Event evidence covers bars from `episode_started_at` through `observed_at`; `evidence_bar_ids` identifies the triggering bar. This avoids repeating the entire evidence ID list for each row.

The collector stores delayed observations and can replay corrected or imported bars. It does not establish point-in-time availability suitable for trading backtests. `historical_outcome_eligible` and `actionable` stay false. Strength scores, entry rules, predictions and probability calculations remain outside this batch.

The provider API defines bar timestamps and absent-interval behavior in its [custom-bars documentation](https://www.massive.com/docs/rest/stocks/aggregates/custom-bars). Local verification returned 78 regular-session SPY bars for October 1 with the existing key. No subscription or dependency was added. Current-session recency should be checked during market hours before enabling automatic production collection.

## Local review

The site runs at http://127.0.0.1:8000. Local SPY 2W history includes 44 production wall observations from October 1 and 78 completed price bars fetched with the existing provider key. Production was read only. The EOD fixture clock still applies to EOD APIs; recorded intraday wall requests use their actual session dates.

1. Open Dashboard → SPY → Intraday → Wall tracking → 2W. Choose October 1. Scroll to “What price did at the walls.”
2. Select a recorded event, such as the put-wall bounce at 1:05 PM ET. Check the level, close, explanation and supporting bars. Later bars must not appear as evidence for an earlier event.
3. Chart icons distinguish approaching, touch, testing, bounce/rejection, breaks and later reactions. The compact legend labels the symbols shown; coral identifies put-wall events and green identifies call-wall events. Click an icon or press Enter/Space to open a summary beside it without scrolling. Escape, the close button or clicking elsewhere dismisses the summary. Choose “View evidence below” to open the supporting bars beneath the sticky navigation. All events remain in the selector even when markers are spaced apart for a narrow chart.
4. Choose “Preview local demonstration.” The synthetic sequence contains a break lower, acceptance, a failed retest, continuation, a reclaim and a later break higher. “Return to recorded sessions” restores actual records.
5. Change symbol or expiry scope. The previous symbol's evidence should clear. The local demonstration is available for any valid symbol and scope; it is explicitly labeled and never written to the database.
6. Open “How to read price behavior.” Compare its definitions with the selected sequence. The guide and detailed tables remain collapsed until requested.
7. Download Export timeline JSON and inspect `intraday_wall_history.wall_interaction`. The chosen session, rule version, events, timestamps and OHLC bars should match the display.

Manual local collection, when needed:

```text
php artisan walls:capture-interactions --local-review --symbol=SPY --session=2026-10-01
```

The command requires existing wall observations for that symbol/session. Historical collection and the demonstration are restricted to the local environment.

## Validation

The targeted backend suite passed 51 tests with 268 assertions. It covers wick-only touches, band boundaries, unfinished bars, sequence ordering, absent bars, wall/basis changes, session resets, early closes, scope isolation, provider failures, append-only corrections, local-only review and existing route permissions. Frontend checks passed 26 tests across the interaction panel, timeline and dashboard navigation. The production asset build passed.

The actual SPY local response contained 44 wall readings and 78 price bars. One measured local read took 59 ms and returned about 278 KB before compression. Browser review confirmed recorded-event selection, synthetic examples and evidence positioning below sticky navigation. Detailed bar tables open only on selection, and every event remains accessible from the selector.

## Release requirements

This batch requires no database migration or new package. Before production activation, deploy the code to web and worker, verify current-session aggregate access with the existing subscription, enable `WALL_INTERACTIONS_ENABLED=true` on the scheduler worker only, and rebuild its configuration cache. Leave the flag off on the web node. Confirm one collection cycle and compare its API/export output against stored bars. Turning the flag off stops collection without deleting evidence.

All files in this batch are prepared for local review. Commit, push and deployment are separate actions.
