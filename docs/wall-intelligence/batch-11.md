# Conditional wall setups

Intraday > Wall tracking includes **What would confirm the next setup?** below the price-behavior panel. Each side shows a conditional scenario, status, entry zone, invalidation boundary, reference target and evidence grade. Users can inspect earlier five-minute assessments and open the trigger and reading guide.

The feature uses existing modeled wall observations and completed price bars. It adds no provider requests, collection jobs, subscriptions, migrations, packages or environment settings. Setup evaluation runs inside the existing wall-interaction read and collection paths. Collection includes the assessment in its existing append-only evidence record; reading the dashboard does not write records.

## Confirmation rules

The schema is `wall-setups.v1`; the rules are `conditional-walls.v1`. It consumes the existing `completed-5m.v1` interaction detector. These are initial, explicit rules. They have not established a trading edge or a win probability.

| Scenario | Required sequence | Confirmation window |
| --- | --- | --- |
| Bounce or rejection | A touch, then two consecutive completed closes away on the original side | 15 minutes from the touch |
| Break and retest | Two closes accepting the break, a later retest, a separate failed-retest close, then a further confirming close | 30 minutes from the break |
| Reclaim and retest | Two closes reclaiming the original side, a later retest, then a separate close away on that side | 15 minutes from the reclaim |

The touch band is 0.05% of the wall strike, with a one-cent minimum. The conditional entry zone extends one to three band widths from the wall in the scenario direction. Its inner boundary is exclusive; the outer boundary is inclusive. A completed close beyond the opposite band edge invalidates the scenario. All levels are underlying prices.

At confirmation, the nearest ranked wall in the scenario direction supplies the target. The evaluator considers the ranked put and call walls from the same recorded model observation. It cannot skip a closer level to obtain a better ratio. Target distance, measured from the far edge of the entry zone, must be at least 1.5 times the distance from that edge to invalidation. This is price geometry, without fees, slippage or option-return modeling.

Entry, invalidation and target levels freeze at confirmation. The model must have the expected version and units, correct signed exposure, at least 98% OI input coverage, and a comparable leading wall across at least two observations and five minutes. Unsupported geometry or model context returns No setup. These checks do not estimate the percentage of true exposure captured.

## Status and time

Watching means a required condition has not yet completed. Conditions met means the sequence and geometry passed and the completed close was inside the entry zone at that recorded time. Entry missed means the close moved beyond the zone. It remains terminal until a new price sequence begins. Invalidated, Expired, No setup and Reference target reached also end that sequence. Target reached describes a later completed close, without assuming an executed trade.

An entry window includes the first 15 minutes after confirmation and ends at the regular-session close if earlier. The calendar includes early closes. A change in wall identity, comparison basis or episode, or a break in consecutive five-minute observations, resets the sequence. Evidence never bridges a gap merely because a chart draws a visual connector.

The latest assessment expires when its entry window or session ends. Stale assessments cannot claim current readiness. Historical inspection preserves the status at the selected recorded time. `entry_eligible_at_observation` is a historical fact; `live_entry_eligible` and `actionable` remain false. The page explicitly labels delayed five-minute closes and recorded times.

Only wall observations available before each price bar starts can supply its evidence. Targets cannot use later observations. Assessments retain wall hashes, comparison scope, basis, bar IDs and confirmation timestamps. Replay of retained evidence is not an as-published trading backtest: corrected provider bars can produce different retrospective assessments. `historical_outcome_eligible` remains false and `win_probability` is null.

## Evidence grades

The grade counts three visible checks: ordered price confirmation, the same leading wall across at least three readings and ten minutes, and a recorded target with sufficient room. Strong means three checks, Moderate means two, and Weak means zero or one. Evidence grade is separate from status. A strong grade can remain on an expired or invalidated scenario.

The assessment does not combine EOD scores, IV history or other indicators from different dates or scopes into an artificial confidence score.

## Export

Export timeline JSON includes the exact displayed wall-interaction payload and its nested `setups`, including rules, current assessments and history. AI Export adds optional **Intraday walls & setups**. It requires intraday access and a supported wall scope: 0DTE, 1DTE, 1W, 2W, 1M or 3M. It uses the latest recorded session for each symbol in that scope, independently of the EOD view. Normal EOD export defaults exclude it; Select all includes it explicitly.

The export builder resolves controller dependencies through Laravel and supplies each request by type. Regression coverage checks both the wall controller's tracker dependency and existing controllers whose request parameter has a different name.

## Local review

1. Open `/dashboard?symbol=SPY&mode=intraday&tab=walls` at `http://127.0.0.1:8000`. Select 2W and session October 1, 2026. Scroll below What price did at the walls.
2. Choose **10:15 AM ET** in Inspect setup time. The put-wall card shows a bullish bounce at 760, Conditions met, entry 760.38–761.14, invalidation 759.62 and reference target 770. The evidence grade is Moderate at that time. The call side remains Watching.
3. Open Trigger and evidence. Check the two recorded checks and the pending wall-persistence check. Choose later times to review how status and evidence evolve. Latest assessment should describe the ended session rather than offer a current entry.
4. Select a price event above the cards. The setup-time selector follows that event. Changing symbol, scope or session resets inspection so the previous context cannot leak into the new view.
5. Preview local demonstration, then select the put-wall Confirmed break lower event at **10:45 AM ET**. This synthetic example shows a confirmed bearish continuation. At 11:30 AM the call continuation shows Entry missed. The demonstration is labeled and remains local-only.
6. Return to recorded data. Review the cards on a narrow screen, open the reading guide, and export the timeline. In AI Export, check that Intraday walls & setups starts unchecked.

## Validation and release

Seventy-nine PHP tests passed with 398 assertions across setup evaluation, interaction detection and collection, exports, IV/skew, DEX, gamma profiles and expiry/strike exposure. Forty-four frontend tests passed across setup cards, interaction cards, wall tracking, dashboard navigation and exports. The frontend build and PHP formatting checks passed. Coverage includes causal replay, frozen targets, nearer obstacles, mirrored directions, skipped entries, gaps, invalidation, delayed assessments and an early market close.

Browser review covered the October 1 SPY example, evidence expansion, historical-time selection, session expiry and a 390-pixel viewport. The local recorded-session read and evaluation took about 90 ms in one smoke check; this is not a production throughput guarantee. Local export returned the setup rules successfully. The local demonstration remains separate from recorded market data.

Release requires the normal application and frontend deployment. The existing interaction collector includes setup assessments without a new collection schedule or environment setting.
