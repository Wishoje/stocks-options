# Swing wall reactions

Swing Ideas uses `swing-wall-reaction.v2` across the shared product universe. It replaces the customer-facing daily trend/reclaim list. Existing daily-close plans, private experiments, reviews and Study records remain stored under their original versions.

## Rules

The 2–5 trading day view uses a 1M expiration basket, three sessions for entry and five sessions after an observed entry touch. The 1–3 week view uses a 3M basket, five sessions for entry and fifteen after entry. Deadlines follow the market calendar and early closes.

The engine selects the nearest eligible wall among each side's three largest signed net-GEX strikes. It requires proximity within two ATR and magnitude at least 1.5 times the mean absolute GEX of the two nearby strikes on either side. Price trend, DEX and volatility remain context rather than forcing direction.

Each eligible wall can supply two scenarios. A hold/reclaim requires a wall-band touch and a separate five-minute price range entirely beyond the reaction trigger. A break/retest requires three consecutive ranges entirely beyond the wall, a later wall retest and another separate range away on the break side. These predicates use highs and lows, not daily or five-minute closing-price thresholds. The recorded close is displayed as price context only.

The touch band is ±0.1 ATR. Entry extends from 0.15 to 0.5 ATR on the scenario side; the stop is 0.75 ATR on the opposite side. The nearest significant ranked obstacle supplies the target, including obstacles inside the proposed entry area. A scenario cannot skip that obstacle to improve its apparent room. Target room from the far entry edge must cover at least 1.5 times stop distance and stay within four ATR for short ideas or eight for longer ideas. More than half the target exposure cannot expire before the maximum planned review ends.

Readiness and entry are distinct. A qualifying price sequence records readiness. Only a later complete price bar starting after readiness was published can establish an entry-area touch. The entry reference uses the far edge of the area as a conservative, reproducible measurement reference. Neither an observed touch nor that reference establishes an executed fill.

## Lifecycle and Study

Active states are Watching, Ready and Entry touched. A target or stop before entry cancels the idea without a trade win or loss. After entry, an ordered target or stop touch ends the comparison. Entry and exit in one bar, both exits in one bar, or relevant unresolved gaps are ambiguous. Time limits remove the idea from the active list. No terminal state reopens.

Original plans are immutable. New daily structure can withdraw an unentered scenario; entered levels remain frozen. Prior-session follow-up runs before a new structure review. Large price discontinuities stop the comparison for internal review. Outcome-bar metrics do not count favorable or adverse extrema that may have occurred after exit.

The owner-only Setup Study defaults to the v2 strategy for swing cohorts. The original daily-close version remains selectable. CSV includes the rule and measurement versions. Before-entry cancellations and no-entry scenarios are recorded separately from completed, entered outcomes. Ambiguous observations are excluded from verified target rates.

## Collection and rollout

Apply migration `2026_11_11_000000_create_swing_reaction_tables.php`. It adds separate ideas, transition reviews and shared price-evidence tables. It does not delete or rewrite existing swing records.

The existing worker flag `SWING_IDEAS_AUTOMATIC_REFRESH_ENABLED` controls the new `swing:react --limit=60` scheduler. It runs rotating, non-overlapping batches each minute from 07:00 through 22:00 ET on weekdays, with a 75-second execution budget. One successful EOD source read prepares both horizons and patterns per symbol per source date. Failed source reads retry after fifteen minutes. Customer requests only read prepared records.

Run an initial shared-universe assessment with `php artisan swing:react --all`. If its time budget is reached, repeat the command; the cursor resumes the rotation. Explicit diagnostic symbols use `--symbols=SPY,QQQ,TSLA`.

Collection reuses existing EOD structure and retained five-minute price bundles. It creates no provider requests, queue jobs or extra queue workers. Existing wall-price ingestion must remain enabled. Prices retain the existing fifteen-minute delay and completion buffer. New publications cannot claim past entries merely because historical price bars are available.

Each collection tick also archives changed v2 scenarios to the owner’s Study in a compact batch of at most two hundred records. It does not replay the legacy intraday archive. The original bounded Study collector remains available for historical cohorts.

Only changed source bundles are pinned. Symbols without active scenarios skip price pinning. A daily bounded `swing:prune-prices` task removes shared price observations older than ninety days when no active scenario needs them. Plans, transition reviews and Study records are retained.

Rollback the customer route and scheduler to the previous release if necessary. Leave the additive tables and existing Study records intact; no destructive rollback is required.
