# Private intraday experiments

Intraday experiments lives at `/intraday-research`, linked from Setup study and Swing experiments. The configured setup-study owner controls the page, API, collection, and alerts. Other accounts cannot access its data or poll its feed.

The experiment uses saved near-term 1W wall assessments and completed five-minute stock or ETF price bars. It makes no provider calls, sends no email, and dispatches no queue jobs. Regular customer qualification rules stay unchanged.

## Two paired profiles

The signal-close baseline retains the original qualifying close, target, invalidation, and session end. It is a study reference and never sends experimental alerts.

The retest profile opens a new 15-minute research window when the private watch is recorded. A whole five-minute bar must start at or after that time and close inside the original entry area by the deadline. For bullish cases the close must be above the lower edge and at or below the upper edge. Bearish cases use the opposite boundary convention. The original native entry expiry remains stored as metadata. The new window is an explicit research policy, and does not extend the customer signal.

The target, invalidation, and regular-session end stay fixed. A target or invalidation close before experimental qualification ends that candidate. Highs and lows alone do not qualify or end these close-based comparisons. The entry window is capped at the actual market close, including early closes.

## Delayed observations and alerts

The feed remains delayed 15 minutes. Original signal time, original receipt, private watch creation, experimental qualification close, and alert receipt are separate fields. A conditions-met event describes a recorded close, and does not promise that the current market price still offers the same entry.

Only a newly observed experimental qualification can produce a conditions-met notification. The collector first evaluates all known later bars. Completed, revised, gapped, historical, stale, and closed-session cases cannot generate a fresh popup. Both the qualifying bar and latest supporting evidence must be between 15 and 22 minutes old at collection. Each case has one immutable notification event. Later outcomes remain in the study and do not produce popups or rewrite that event.

The owner sees Experimental intraday in a separate section of the Alerts inbox. Read status is stored on the server. Initial browser catch-up is silent. The inbox retains fourteen days of research events independently of the normal daily alert reset.

## Study measurements

Each profile has its own qualification close and follow-up window. A completed close reaching the fixed target ends the case at that target observation; a close crossing invalidation ends it at that invalidation observation. Otherwise the case ends at the session close. A missing expected bar or changed evidence requires review rather than an assumed result.

Target rate shows targets divided by eligible completed cases. Waiting, active, unqualified, and review cases do not enter that denominator. Percentage movement compares the qualifying close with the recorded outcome close in the scenario direction. Best and adverse movement also use recorded closes. These are underlying-price observations, without assumed orders, fills, option returns, or trading profit. The profiles can qualify different subsets of their paired groups; their raw target rates alone do not establish which rule is better.

## Storage and scheduling

The additive tables are `intraday_research_groups`, `intraday_research_cases`, `intraday_research_bars`, and `intraday_research_cursors`. All belong to the owner and cascade on account deletion. Compact original assessments, original plans, first-observed completed bars, and notification events remain frozen. Corrections retain separate revision evidence and quarantine affected comparisons.

Collection reads each selected symbol once through the incremental saved-evidence adapter. A per-symbol session cursor advances in the same transaction as its paired cases and retained bars. Batches rotate through saved symbols and symbols with open cases. Removed symbols can finish existing cases but cannot create new groups. The default batch covers 100 symbols, capped at 200, with a 45-second admission budget and a shared collection lock. The budget is checked between symbols and cannot interrupt a source read already running.

Apply `2026_10_31_000001_create_intraday_research_tables.php` before enabling `INTRADAY_RESEARCH_ENABLED=true`. The flag defaults to false. Enable the same flag on the web and designated scheduling worker, then rebuild their configuration caches. Only the designated worker should run Laravel's scheduler. The command is `intraday-research:collect --limit=100`, scheduled once per minute during the configured weekday window. Runtime guards allow collection from 9:45 AM ET through 30 minutes after the actual regular-session close. No collection runs on weekends or exchange holidays.

Page and alert GET requests read compact private archives without collecting source data. Manual refresh checks at most five symbols, requires the enabled flag, observes the same market-time guards, and is throttled to twice per minute.

## Local review

Open `/intraday-research?demo=1` with the configured local study-owner account. Synthetic examples include target, invalidation, and no-entry outcomes. They never persist cases, collect evidence, issue alerts, or enter the study. The saved-research view shows actual local records and explicitly reports when collection is paused.
