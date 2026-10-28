# Owner setup study

`/setup-study` compares recorded intraday wall plans and published swing ideas. The page and both data endpoints require the single configured owner email. This gate is separate from EOD Health's user-ID gate. Navigation is hidden for other accounts, and the server enforces access independently.

## Measurements

The three cohorts are intraday, swing 2–5 trading days, and swing 1–3 weeks. Intraday includes saved qualified plans. Swing also includes published plans still waiting for qualification. Plans with no eligible swing idea are excluded.

The report includes first-pass coverage separately from qualifying-plan counts. Checked symbols with no qualifying plan still count toward coverage. Until every saved or recently archived symbol has been checked, the page labels results partial and shows preparation progress instead of implying that zero records means no signals. Coverage remains owner-scoped and independent of the current report filters.

Date filters use the qualifying close's market date. An unqualified swing plan uses its first publication date until it qualifies. The original EOD source date remains in its evidence.

All move measurements start from the first recorded qualifying close, using the original target and invalidation. They describe price behavior, not executed trades, option returns, or fills. Intraday uses completed five-minute closes; swing uses daily closes. The first target close, invalidation close, or applicable session/holding deadline stops follow-up. Invalidation equality is not a crossing.

- Exact-target rate counts a target close before invalidation.
- +0.5R and +1R rates measure smaller favorable close moves before the same stopping point. A partial move can be recorded even when a later close invalidates the plan.
- R is the distance between the qualifying close and original invalidation. It does not assume an entry at that price was executable.
- Favorable and adverse excursions are maximum completed-close distances from that reference, shown in R and percent.
- Entry-area revisit means a subsequent complete bar overlaps the entry zone after the qualification was available. Bars that started before publication are excluded. Overlap does not establish a fill.

Outcome-rate denominators contain only completed, continuous follow-ups. Open plans and unavailable follow-up are separate counts. A gap cannot be treated as a loss, target success, or proof that no invalidation occurred. Repeated confirmations at the same active intraday levels count as one plan. A later recorded episode after a terminal result can count separately.

Signal time and first retained qualification publication time remain separate because intraday data is delayed. Retrospective imports are labelled. The selected sample consists of the owner's saved symbols when collected, plus symbols already archived; it is not an unbiased sample of every market opportunity. These descriptive results do not establish an out-of-sample trading edge.

Signal-close response rates can include a move completed before the app received the qualification. The separate after-publication target rate considers only completed continuous plans still open at publication. Targets and invalidations at or before that timestamp are both excluded from this denominator and shown as resolved before publication. Unknown timing is counted separately. Original partial-move and excursion metrics are not relabelled as post-publication returns.

## Collection and retention

Apply `2026_10_28_000000_create_setup_study_records_table.php` during release. It adds one compact table with an owner foreign key and owner/cohort/date index. Existing market and plan records are not changed.

The worker schedules `setup-study:collect --limit=6` every fifteen minutes from 10:00 through 22:00 ET on weekdays. The task uses a shared lock, one-server scheduling, and rotating symbol batches. It reads saved wall checkpoints, retained completed-price bars, published swing plans and daily prices. Checkpoint JSON is fetched by primary key in pages of ten after a thin metadata read; cumulative payloads are never retained in the archive adapter. It makes no provider requests and dispatches no jobs. Page reads query only the compact archive. Manual refresh collects two symbols and reports its batch size.

Initial and ordinary intraday collection revisit the previous three days. After an interruption, collection catches up from each batch's previous successful date, bounded at fourteen days. Explicit `--backfill` reads up to fourteen retained calendar days and should use `--limit=1` for historical operator checks. Swing collection revisits forty-five days. Collection reports source limits instead of implying complete coverage.

Study records survive raw-evidence retention. A shorter retained history does not erase an already completed measurement. A detected source correction quarantines that record from rates and preserves the prior evidence for review. No automatic deletion of study records is introduced. Account deletion cascades through the owner foreign key.

`SETUP_STUDY_ENABLED` defaults to true. `SETUP_STUDY_OWNER_EMAIL` defaults to the existing production owner account, so no environment change is required for that account. A local development process can override the email for its dedicated review account. The local-only synthetic preview is never saved and cannot be collected or exported. Production cannot request it.

## Review

Open `/setup-study` and compare all three cohorts. Set dates, symbol and direction, inspect original levels and publication times, and download the filtered CSV. Rates show both numerator and denominator. For example, a plan that moves +1R before a later invalidation contributes to the +1R measure and remains invalidated under the strict outcome.

The default fourteen-day window is a starting point for research. Study sample counts, pending follow-up, rule versions and market conditions before drawing conclusions about signal quality. A couple of weeks may contain too few completed longer-horizon plans to support a reliable comparison.
