# Batch 4: Unusual Activity and EOD Strikes

Batch 4 covers UI-10 Unusual Activity and UI-11 EOD Strikes. The work is staged for local review. It has not been committed, pushed, or deployed. Final visual acceptance remains manual.

## Scope

### UI-10 Unusual Activity

The Unusual Activity tab keeps the existing signal calculation and request lifecycle while making the applied scope easier to inspect:

1. A compact summary shows the returned signal count, included expirations, effective premium floor, and current rank order.
2. Primary filters stay visible. Less common filters are in an advanced disclosure.
3. Draft filter edits remain separate from the applied filter snapshot. Existing rows and their sort label continue to describe the applied request until Apply is used.
4. The primary table shows the most useful readings first. The complete returned row and its metadata remain available in selected-signal details and collapsed full readings.

Unusual Activity has its own expiration scope. Its dates come from the latest unusual-activity snapshot returned by `/api/ua?include_scope=true`; changing the dashboard GEX timeframe does not replace that list.

The requested minimum premium can remain zero so the server chooses an automatic floor. The current automatic floors are $100,000 for SPY and QQQ and $50,000 for IWM and AAPL. The response reports both the requested and effective value. Premium is labeled as a post-signal-screen filter because the baseline, z-score, and Vol/OI candidate screen runs before premium is attached or estimated and filtered.

The existing signal meaning remains unchanged. The server applies the z-score and Vol/OI thresholds as an OR screen, then applies the other requested filters. Vol/OI uses a denominator floored at one contract. Raw open interest is not returned, so zero or missing open interest can produce a large finite ratio that the interface cannot independently reconstruct.

The table preserves API row order when it first receives a response. An explicit table-header or mobile sort choice can then reorder the visible rows locally. Missing premium, volume, or Vol/OI remains different from numeric zero.

### UI-11 EOD Strikes

The EOD Strikes tab keeps the full strike response and presents it in three focused panels:

- Net GEX by strike, including net and call/put split views.
- Open-interest change by strike.
- Volume change by strike.

Net GEX supports selection, a stronger zero baseline, activity focus, automatic dense-series bucketing, zoom reset, and image download. Bucketing changes only the plotted view. The collapsed readings table retains every raw strike row and every returned field.

Open-interest and volume changes identify their comparison source as `Daily`, `Weekly`, or `Unavailable`. A dated daily comparison takes precedence. Weekly is the fallback when daily is absent. If neither source has a comparison date, the interface does not present the returned fields as a valid change series. Numeric zero remains a valid returned reading.

The change totals require complete call and put coverage for every current raw strike. Incomplete coverage is labeled instead of being presented as a complete total.

## Data contracts

| Surface | Source | Preserved fields and behavior |
| --- | --- | --- |
| Unusual Activity scope and filters | `/api/ua?include_scope=true` | `expiration_dates`, `effective_min_premium`, and the applied filter snapshot: expiration, per-expiry and global limits, z-score, Vol/OI, volume, requested and effective premium, near-spot range, side, rank order, and premium inclusion. |
| Unusual Activity signals | `/api/ua` | `exp_date`, `strike`, `z_score`, `vol_oi`, and all returned metadata, including call/put/total volume, premium totals, baseline mean and standard deviation, history sample count, confidence, and the baseline-excludes-today flag. Unknown response fields also remain inspectable. |
| Net GEX | `/api/gex-levels` | Every current `strike_data` row, including strike, net GEX, optional call and put GEX, and any additional returned fields. The selected EOD timeframe still controls the included expirations. |
| OI change | `/api/gex-levels` | Daily call/put OI delta and percent fields, weekly call/put fields, comparison basis, comparison date, gap, and stale state. |
| Volume change | `/api/gex-levels` | Daily call/put volume delta and percent fields, weekly call/put fields, comparison basis, comparison date, gap, and stale state. |

The Dashboard continues to own lazy loading, cache identity, frozen request parameters, readiness polling, cancellation, and response ownership. A late response from a previously selected symbol cannot replace the current symbol. The Unusual Activity cache identity includes its applied filters and rank order.

## Provenance limits

The EOD strike comparison describes the **current strike set**. The backend starts with strikes in the selected current rows, then looks up prior-day or prior-week values for those strikes. A strike that exists only in the comparison snapshot is not added. When a current strike lacks a prior value for one side, the backend arithmetic currently uses zero for that side.

Included expirations can have different EOD source dates. The snapshot selector chooses the latest eligible date separately for each expiration, while the dashboard `data_date` is the maximum selected date. The strike rows aggregate those expirations by strike and do not return a source date for each contribution. The displayed snapshot date is therefore a summary date, not proof that every included expiration used that date.

The comparison date is also a single summary across the included expirations. It identifies the selected daily or weekly branch but cannot prove that every expiration has complete comparison rows on that date. Returned percentage changes can be zero when the prior denominator was zero or unavailable because the backend uses zero in that case. The interface preserves that response but cannot recover the missing denominator provenance.

The call and put GEX fields are raw positive magnitudes. The split chart draws put GEX below zero to create a diverging view; the full readings table keeps the returned value unchanged.

## Interaction and presentation

- Dark surfaces remain the base. Brighter semantic accents identify selected signals, positive and negative direction, warnings, and comparison provenance.
- Full readings and calculation details start collapsed. Primary metrics and applied scope stay visible.
- Table headers, mobile sort controls, row selection, chart selectors, disclosures, and help controls support keyboard use and visible focus.
- Help uses the shared contained dialog. It stays inside the viewport, closes with Escape, and returns focus to its trigger.
- At narrow widths, Unusual Activity rows become cards and dense strike content scrolls inside its own region without causing horizontal page scrolling.
- Motion is limited to short hover, focus, selection, and chart transitions. The reduced-motion preference removes those transitions without hiding state changes.
- Dense strike series may be bucketed to protect chart performance. Raw rows and bucket totals remain available, and the user can turn off automatic bucketing or reset the view.

## Validation record

| Check | Result |
| --- | --- |
| Unusual Activity component contract tests | Passed: 6 tests |
| Focused Batch 4 frontend suite | Passed: 5 files, 87 tests |
| Full Vitest suite | Passed: 28 files, 289 tests |
| Production frontend build | Passed: 257 modules |
| UI preview build | Passed: 24 modules |
| Changed PHP syntax checks | Passed |
| Unusual Activity pricing and scope feature tests | Passed: 25 tests, 88 assertions |
| Laravel Pint | Passed: 2 changed PHP files |

Use [local-review.md](local-review.md) for the four-symbol visual and interaction review.
