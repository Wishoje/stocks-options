# Private swing experiments

Swing experiments lives at `/swing-research`, linked from Setup study. The existing setup-study owner email controls both pages and every research endpoint. Other accounts cannot read, collect, acknowledge, or receive experimental cases. Production keeps its existing owner email; a local review account can use its own existing override.

Each symbol, source date, and holding period starts a paired research group with three versions:

| Version | Change |
| --- | --- |
| Baseline | Existing swing qualification and daily-close rules. |
| Nearer entry | Consider the top ten ranked entry walls instead of three. The two-ATR limit, trend checks, nearest opposing target, and other admission gates stay the same. |
| Earlier exit | Preserve the baseline entry, invalidation, and qualification. Freeze a target halfway from the far entry edge to the original target. |

The baseline applies the existing formula to the research cohort. It is not a copy of historical customer alerts. A larger candidate pool can produce a closer wall, the same plan, or no qualifying plan. It does not guarantee a closer entry. The study reports the actual original distance to the entry area.

The earlier-exit version does not admit plans that failed baseline admission. It preserves the original full target and admission evidence. Qualification-day highs and lows do not count as a later target result. All versions use the same completed daily bars and retain their original levels.

Groups remain paired until all versions have stopped tracking. An earlier exit therefore cannot create more replacement cohorts than the baseline. No-plan cases count toward reviews and coverage, but wholly no-plan groups do not fill the case-card list. Material groups have a separate 1,000-group display limit with an explicit warning.

## Measurements

The two holding periods remain separate: 2–5 trading days and 1–3 weeks. Rates show their numerator and eligible denominator. Only qualified, completed, continuous follow-ups without a correction flag enter the target rate.

Entry distance is measured from the original recorded EOD close to the nearest edge of the fixed entry area. The signed move at outcome compares the qualifying close with the recorded target, invalidation, or holding-deadline close. A positive move follows the scenario direction. These are underlying-price observations, not fills, option returns, trading profits, or portfolio results. Higher target frequency alone does not establish a better exit.

## Evidence and collection

The collector reads existing EOD data through `SwingDataSource`, once per selected symbol. Both horizons and all versions reuse that input. It makes no provider requests, sends no emails, and dispatches no queue jobs.

The additive tables are `swing_research_groups`, `swing_research_cases`, and `swing_research_daily_bars`. They belong to the configured owner and cascade on account deletion. Groups retain their source hash and compact initial daily-price context. Case plans and first-observed follow-up prices remain frozen. Later price revisions retain their new values separately and quarantine affected results. A new cohort that already used a revised historical price is not quarantined solely because an older cohort saw the previous value.

An open case continues receiving daily-price follow-up if its symbol leaves the watchlist or a new options comparison is temporarily unavailable. Removed symbols cannot create new cohorts. Gaps and invalid bars require review. Collection never qualifies a plan using a price range from before its publication or activation.

`swing-research:collect --limit=6` rotates through saved symbols and symbols with open research cases. The maximum batch is twenty symbols, with a shared lock, a ninety-second admission budget, and thirty-minute failed-source cooldowns. The time budget is checked between symbols; it cannot interrupt a source read already running.

Page and alert GET requests read the private archive only. They do not collect source data or change read status. Manual collection checks at most two symbols and has a two-per-minute route limit.

## Experimental alerts

The first saved qualification creates one alert identity per research case. The owner sees an Experimental tag and the version name in popups and a separate section of the existing Alerts inbox. Popups poll while the site is visible and online; initial catch-up is silent. Recorded alerts remain available while the browser is closed. The inbox retains the latest forty-five days, including later outcomes and server-saved read status. It does not clear experimental swing history at the intraday midnight reset.

Other accounts do not poll the research feed. Local synthetic examples suppress experimental polling and never write cases, send notifications, or enter the study.

## Release and local review

Apply `2026_10_31_000000_create_swing_research_tables.php` before enabling collection. The new flag `SWING_RESEARCH_ENABLED` defaults to false. Enable it on the web and worker releases and rebuild their configuration caches. The web flag enables the owner's bounded manual refresh. Only the designated worker runs Laravel's scheduler; it runs the command every fifteen minutes on weekdays from 18:00 through 22:00 America/New_York. Page and alert reads remain available when the flag is off, but the page then reports collection as paused.

For a local interface preview, open `/swing-research?demo=1` using the configured local study-owner account. Examples are visibly synthetic. Compare the two horizons, filter SPY or QQQ, review the nearer entry, and compare the earlier exit with an unfinished baseline. Opening the real view shows only actual locally recorded research cases.

The related price-backfill safeguard preserves complete existing daily bars during routine history fills. An explicit `prices:backfill --replace-existing` remains available for intentional corrections. Existing paused, never-qualified customer plans may yield to a separate later-source plan only after their original entry window ends. Their original evidence remains stored; qualified paused plans still require review. These fixes change data handling and liveness, not qualification thresholds.
