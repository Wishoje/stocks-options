# Recorded setup results

Intraday > Wall tracking includes **What happened after the setups?** below the conditional setup cards. It reviews later completed price closes against the levels used by each scenario. The selected symbol, session and expiry scope govern the review. Users can filter outcomes and sides, inspect the fixed levels and supporting closes, and switch recorded sessions through the existing timeline selector.

The first view shows setups whose conditions were met. All scenarios also includes sequences that missed their entry zone, failed the setup rules, or ended before an eligible close. Those cases remain visible without entering the qualified outcome counts. The panel describes recorded price paths without simulated orders, profit or loss, success rates or win probabilities.

## Review rules

The schema is `wall-setup-reviews.v1`; the rules are `frozen-levels-60m-close.v1`. Setup definitions remain `conditional-walls.v1`. Each setup ID contributes at most one case. The first eligible five-minute close starts its review, with the original entry zone, invalidation boundary, reference target, evidence grade and comparison identity held fixed.

The follow-up lasts up to 60 minutes, ending sooner at the regular market close or when the wall comparison changes. A later completed close at or beyond the reference target is Target reached. A completed close strictly beyond the invalidation boundary is Invalidated. These rules mirror for bullish and bearish scenarios. Wick touches do not determine either result, so the evaluator never invents an order between intrabar high and low prices.

A fully observed window that ends without either price outcome is Expired. A changed wall comparison also expires the review before a close using that changed context can supply an outcome. A break in continuous five-minute evidence is Unresolved. In progress requires that the review window is still running and its price evidence is recent. The calendar caps reviews at early market closes.

The 15-minute entry window from Batch 11 and the 60-minute price review serve different purposes. Reaching an eligible close starts the follow-up; later movement beyond the entry zone does not erase that earlier eligibility. A missed entry before any eligible close contributes no qualified result.

The summary separates all scenarios from qualified outcomes. Overlapping reviews are marked when a later qualifying scenario starts before an earlier review ends. These cases share market observations and cannot be treated as independent performance samples. No aggregate percentages are published.

## Saved assessments and replay

The existing interaction collector stores compact `review_seeds` inside its append-only assessment payload. For each setup, the history reader preserves the first saved eligible assessment, including its original levels and source hashes. A later revised assessment cannot replace that baseline or erase its earlier qualification. A pending scenario can become eligible in a later saved assessment.

Historical sessions recorded before review seeds existed use Session replay. This reconstructs the scenario under the current setup rules from retained wall and price evidence. The label stays visible in the table and inspector. Saved assessment identifies a preserved qualifying scenario, but later price bars can still be revised. Neither basis establishes an as-published trading backtest. `historical_outcome_eligible`, `executed_trade` and `actionable` remain false; `win_rate` and `win_probability` remain null.

## Storage and performance

The review adds one bounded metadata query for the selected symbol, session, scope and setup-rule version. It loads compact seed arrays and source hashes rather than full assessment payloads. The first 160 saved assessments are considered; a 161st row detects the limit and the UI labels it. The response includes the review rules, summary, scenarios and their follow-up close IDs. The table shows eight cases per page and at most 12 follow-up closes per qualified review.

Read requests do not write assessments or contact the provider. Collection adds only compact seeds to the existing payload. No extra collection jobs, provider calls, migrations, packages, subscription or environment variables are required. Normal application and frontend deployment is sufficient.

## Exports

Export timeline JSON contains `wall_interaction.setup_reviews` with the same cases and supporting closes displayed by the panel. Optional Intraday walls & setups in AI Export also includes the full response. Its summary adds `recorded_reviews` and `outcome_rule_version` beside the current setup assessments. Existing authentication, intraday entitlement, scope validation and export defaults remain in force.

## Local manual checks

1. Open `http://127.0.0.1:8000/dashboard?symbol=SPY&mode=intraday&tab=walls`. Select 2W and the October 1, 2026 session. Scroll below the conditional setup cards to What happened after the setups.
2. Conditions met shows one qualifying setup from six total scenarios. The qualifying put-wall bounce at 10:15 AM ET remains Unresolved because its continuous comparison ended before a target or invalidation close. Inspect it to check entry 760.38–761.14, invalidation 759.62, frozen target 770 and the later recorded closes. Session replay identifies the review basis.
3. Select All scenarios to inspect the five sequences that did not reach an eligible close. Filter outcomes and wall sides, then change scope or symbol to confirm filters and inspection reset.
4. Preview local demonstration. The labeled synthetic session contains two qualifying setups: the put-wall continuation at 10:45 AM ET is invalidated by the later close, and the call-wall continuation at 11:30 AM ET reaches its reference target at 11:35 AM ET. Both show their fixed levels and later close evidence. Two other scenarios expire without eligibility.
5. Open How to read setup results. Return to recorded sessions and export the timeline. Confirm summary counts, scenario IDs, source basis and supporting closes match the panel. Review the narrow-screen layout manually; the summary wraps and the table scrolls horizontally.

The local development server uses localhost session settings for the review account. It uses the existing local review database and starts no provider queue or scheduler. Production configuration is unchanged.

## Validation

Seventy-two PHP tests passed with 389 assertions across setup outcomes, frozen setup rules, interaction detection and collection, history storage and the timeline endpoint. Sixteen MySQL export tests passed with 115 assertions. Coverage includes preserved first eligibility, revised targets, duplicate readings, nearer target geometry, mirrored price boundaries, wick-only touches, future bars, comparison changes, discontinuities, overlapping reviews, early closes, retained unqualified scenarios, scope isolation and bounded storage reads.

Fifty frontend tests passed across result counts, qualification filtering, pagination, detail evidence, symbol and scope resets, replay and synthetic labels, mounting below price behavior without another request, existing interaction controls, setup cards, navigation and exports. The frontend build and PHP formatting checks passed. Authenticated HTTP checks on the running local server returned the expected recorded and synthetic review summaries. Browser automation was unavailable during this review, so visual confirmation remains a manual check.

The October 1 local read and review took approximately 90 ms in one smoke check. This measurement is not a production throughput guarantee. This batch is prepared for local manual review and has not been committed, pushed or deployed.
