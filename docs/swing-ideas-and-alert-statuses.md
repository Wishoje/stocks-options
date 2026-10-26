# Swing ideas and alert statuses

The alert inbox and Wall activity scanner share the same status labels. Swing ideas has a separate navigation item because its plans use daily closes and longer holding periods.

## Statuses

| Status | Meaning |
| --- | --- |
| Watching | The recorded price conditions have not passed. |
| Conditions met | The rules passed at the recorded close. This does not establish an entry at the current price. |
| In progress | Later closes are being compared with the qualified scenario's fixed levels. No entered position is assumed. |
| Finished | A target, invalidation, missed entry or time limit ended the comparison. The reason appears beside the status. |
| Needs update | The latest unfinished state is not established, or its recorded evidence needs review. |

Unread is independent of qualification. Opening an alert marks it read without changing its scenario status. A later setup on the same symbol cannot replace the original alert's assessment or levels. Terminal results remain recorded facts when collection pauses; unfinished stale states need an update.

Scanner starts on Conditions met. Filters remain selected when their count reaches zero. Symbol, direction and status filters are shared with the inbox. The inbox also has an Unread only toggle.

## Swing research rules

The initial rule version is `swing-wall-reclaim.v1.1`. It prepares two independent plans per saved symbol:

| View | Entry window | Maximum review after qualification | Options expiry scope |
| --- | --- | --- | --- |
| 2–5 trading days | Three sessions after the EOD date | Five sessions | 1M |
| 1–3 weeks | Five sessions after the EOD date | Fifteen sessions | 3M |

New plans require 50 continuous completed daily bars. Price, its 20-day average, its 50-day average and the five-session return must agree on direction. ATR14 is the mean of the last fourteen true ranges.

The watched level must be one of the three largest signed GEX walls on the pullback side of price and within two ATR. The nearest of the top three opposing walls supplies the target. The rule does not skip that ranked obstacle to improve the apparent room. Room from the far edge of the entry area must be at least 1.5 times the distance to invalidation. A target more than four ATR away in the short view, or eight ATR in the longer view, is rejected. A target is also rejected when more than half its absolute expiry exposure rolls off before the plan's maximum possible exit date.

The wall test band is ±0.1 ATR. The reclaim trigger is 0.1 ATR beyond the wall in the plan direction. The entry area extends another 0.35 ATR. Invalidation is 0.75 ATR on the opposite side. A qualifying daily bar must follow an observed wall test and close inside the entry area. Outcomes use subsequent daily closes, not wick touches or assumed fills.

GEX uses the existing call-minus-put inventory convention in USD per 1% move. DEX, comparable open-interest change, expiry exposure and same-date volatility metrics provide context. DEX and volatility do not independently qualify the plan. There is no blended confidence score, AI decision, estimated option profit or validated win rate. Earnings and major events require manual review before an overnight hold.

Publication freezes the original levels and evidence. A plan cannot qualify from a completed session that occurred before its first publication. Each later daily review is unique by idea and date. A corrected published source or previously reviewed price bar pauses an open plan for review without rewriting its original record. Finished plans never reopen.

No-idea results are shown in a compact reasons list, outside the active lifecycle counts. The rules can legitimately produce no qualifying plans. Local examples are synthetic, visibly labelled, and never persisted or sent as alerts.

## Data and operations

The page reads prepared records for the signed-in user's saved symbols. Page views do not fetch provider data, run the research rules or enqueue work. The command reuses existing EOD option chains, daily prices, DEX, IV, realized volatility, skew and open-interest data. It requires no new provider subscription or AI service.

The two new tables are additive: `swing_ideas` and `swing_idea_reviews`. Apply migration `2026_10_26_180000_create_swing_ideas_tables.php` during a future release. Original plans and daily reviews are retained; this release adds no automatic deletion of those records. An idea requiring evidence review remains paused until an operator reviews it. This version sends no swing notifications.

Explicit local or operator refresh:

```sh
php artisan swing:refresh --symbols=SPY,QQQ,TSLA
php artisan swing:refresh --watchlist --limit=10
```

The command accepts at most twenty symbols, uses a shared lock and stops admitting another symbol after ninety seconds. Failed saved-symbol attempts wait thirty minutes before retrying. Both horizons share one source read per symbol. It does not add an intraday queue consumer.

`SWING_IDEAS_AUTOMATIC_REFRESH_ENABLED` defaults to false. When enabled on the scheduler worker after migration, saved symbols refresh in batches every fifteen minutes on weekdays from 18:00 through 22:00 America/New_York. Only one scheduler should own this task. No production flag or schema change is part of the local review.

Existing intraday collection and qualification thresholds are unchanged. The existing collection step now saves a compact, versioned lifecycle projection. The account feed reads that projection with the existing three-query uncached budget. It does not load individual price-bar histories in the browser request. The original alert eligibility feed is preserved.

The Q-Score option-positioning sign now follows call GEX minus put GEX. A regression test covers a put-heavy chain whose gamma inputs are individually positive.

## Local review

1. Open `/scanner?scan=activity`. Use Preview local demonstration. Conditions met shows only the qualified example; All and Watching show the other examples. Search for a symbol and try an empty filter combination. The selection should remain unchanged.
2. Open Alerts. Check the same status buttons, symbol search, direction and Unread only. Preview example is separate from saved alert history. Escape closes the panel and returns focus to Alerts.
3. Open `/swing-ideas`. Switch between 2–5 trading days and 1–3 weeks. The real-data review may contain no qualifying plans; open the reasons list.
4. Use Preview local examples of every status. Filter to Conditions met, In progress and Finished. Compare the fixed entry, target and invalidation levels and their recorded dates. These examples are interface demonstrations, not trade recommendations.
5. At a narrow viewport, check that filters wrap, levels remain readable and there is no horizontal page overflow.

Automated coverage includes immutable publications, daily review corrections, causal activation, bullish and bearish geometry, expiry rolloff, nearer obstacles, gaps, lifecycle identity, stale-state handling, user isolation and the account-feed query budget. Browser review covers the rendered local pages; live swing scheduling remains disabled until release.
