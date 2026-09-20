# Batch 4 local review

Batch 4 is staged for local review. It has not been committed, pushed, or deployed. Final visual acceptance remains manual.

## Review setup

Use the current four-symbol snapshot database with the Laravel server at `http://127.0.0.1:8000` and Vite at `http://127.0.0.1:5173`. Keep the queue worker stopped. Sign in with the existing local review account.

Start Unusual Activity at:

```text
http://127.0.0.1:8000/dashboard?symbol=SPY&mode=eod&tab=ua&timeframe=14d
```

Start EOD Strikes at:

```text
http://127.0.0.1:8000/dashboard?symbol=SPY&mode=eod&tab=strikes&timeframe=14d
```

Review `SPY`, `QQQ`, `IWM`, and `AAPL`. The current local data is based on the September 11, 2026 snapshot. If the local snapshot is refreshed, compare the screen with the refreshed API response.

The current local snapshot contains 2,768 unusual-activity rows across the four symbols, but every stored `premium_usd` value is zero. The existing server rules remove nonpositive premium and apply the automatic floor, so the local Unusual Activity page is expected to show the real empty-result state. This is a local data condition rather than a rendering failure. Scope, filter, pending-change, loading, and empty-state checks remain available. The populated table contract is covered by six component tests; perform the row-detail checks below after an intentional fixture seed or a refresh that supplies positive premium.

Review every symbol at approximately 1440 px, 768 px, and 390 px wide. Use this matrix so no symbol or viewport is skipped:

| Symbol | 1440 px | 768 px | 390 px |
| --- | --- | --- | --- |
| SPY | [ ] | [ ] | [ ] |
| QQQ | [ ] | [ ] | [ ] |
| IWM | [ ] | [ ] | [ ] |
| AAPL | [ ] | [ ] | [ ] |

## Unusual Activity checklist

Repeat these checks for each symbol:

1. Open Unusual Activity in EOD mode. Confirm the selected symbol, returned signal count, included expiration count, effective premium floor, and rank order are visible before the full readings.
2. Inspect the expiration selector and its dates. Change the dashboard timeframe, then return to Unusual Activity. The unusual-activity expiration scope should continue to come from its latest snapshot and should not be replaced by the GEX timeframe expiration list.
3. With requested minimum premium set to zero, confirm the interface labels the automatic post-screen floor. The effective floor should be $100,000 for SPY and QQQ and $50,000 for IWM and AAPL.
4. Enter an explicit minimum premium and another filter without applying. Confirm the screen marks the edits as waiting, while existing rows and applied chips retain the old values. Apply the filters and confirm the active scope, rows, and effective premium update together.
5. Confirm the filter guide says premium is applied after the z-score and Vol/OI signal screen. It must not imply that premium participates in the baseline or candidate calculation.
6. When the local response contains signals, check the threshold behavior against the returned API data. A row can qualify through the minimum z-score or the minimum Vol/OI threshold. Side, expiration, volume, near-spot range, premium, and limits then narrow the returned set.
7. On a fresh response, confirm row order matches the requested API rank. Use a desktop header or mobile sort control and confirm only that explicit action changes the local order. Reload or change the applied request and confirm sorting starts from the new API order.
8. Select a signal. Confirm strike, expiration, z-score, Vol/OI, volume mix, and premium stay easy to scan. Expand details and verify call, put, and total volume; premium totals; baseline mean and standard deviation; history samples; confidence; and baseline-excludes-today are still available when returned.
9. Expand the complete readings disclosure. It should start collapsed and contain every response row and any additional returned fields.
10. Check a numeric zero and an unavailable value if the selected symbol supplies them. Zero must display as zero. Missing premium, volume, or Vol/OI must remain unavailable. Remember that Vol/OI uses a one-contract denominator floor and raw open interest is not returned.
11. Use Show more. Confirm it increases per-expiry and global limits without losing the other applied filters. Continue until the controls reach their limits of 20 per expiry and 200 overall.
12. Switch quickly through SPY, QQQ, IWM, and AAPL. The final table, applied scope, effective floor, expiration dates, and selected detail must all belong to the final symbol.

Local premium can differ from production when the stored or estimated premium inputs differ. Compare the interface with the local `/api/ua` response before treating that difference as a display defect.

## EOD Strikes checklist

Repeat these checks for each symbol:

1. Open EOD Strikes with the 14-day timeframe. Confirm the symbol, timeframe, September 11, 2026 summary snapshot date, and included expirations are clear.
2. In Net GEX, confirm the headline reading, strongest positive and negative areas, selected strike, and zero line are easy to find. Numeric labels should use consistent compact precision.
3. Switch between net and call/put split views. Put bars may appear below zero in the split view, while the collapsed raw table must retain the API's positive put-GEX magnitude.
4. Select strikes with the chart and keyboard controls. Confirm the visible selected reading updates without requiring hover and focus remains visible.
5. Toggle activity focus and automatic bucketing, use Reset zoom, and download the chart image. These actions must not alter the raw readings. A dense plotted series may be grouped, but the full table must retain every current strike and preserve bucket totals.
6. Expand the full Net GEX readings. Confirm it starts collapsed and includes every raw `strike_data` row, including extra fields that are not charted.
7. Inspect Open-interest change and Volume change. Each panel must label its source as Daily, Weekly, or Unavailable and show the comparison date, gap, and stale state when supplied.
8. Confirm daily is used when a dated daily comparison exists. Change to a timeframe or symbol with no daily date, if present locally, and confirm weekly becomes the labeled fallback. When neither dated source exists, the panel should state that comparison is unavailable instead of plotting the returned fields as a valid change.
9. Expand the OI and volume readings. Valid zero changes must remain zero. Missing values must remain unavailable. If either call or put coverage is incomplete for any current raw strike, the aggregate total must be labeled incomplete.
10. Change among available EOD timeframes. Confirm the included expirations, current strike set, comparison basis, dates, charts, and full tables update together.
11. Switch quickly through all four symbols while the Strikes tab is active. A late response from the prior symbol must not replace the final symbol's rows or provenance.

Interpret strike comparisons within these limits:

- Deltas and totals cover strikes present in the current selected rows. A strike found only in the prior snapshot is not added to the response.
- The backend currently treats a missing prior side as zero when calculating a current-strike delta.
- Included expirations may use separately selected source dates. The displayed snapshot date is the maximum selected date, and the aggregated strike row does not identify the source date of each expiration contribution.
- The displayed comparison date is also a summary across included expirations. It does not establish complete comparison coverage for every expiration.
- A returned 0% change can also represent a zero or unavailable prior denominator. The interface cannot recover that denominator from the current response.

## Layout, accessibility, and motion

Perform these checks at all three widths in the review matrix:

- At 1440 px, primary summaries should lead each tab and supporting filters, tables, and disclosures should align without oversized empty regions.
- At 768 px, panels should reflow in reading order. Controls and labels must remain readable without overlap.
- At 390 px, Unusual Activity should use readable cards or contained scrolling. Strike charts and tables must stay within their panels. The page itself must not scroll horizontally.
- Tab through filters, sort controls, rows, chart controls, disclosures, and help buttons. Every interactive item needs a visible focus indicator and a logical focus order.
- Open help near both viewport edges. The dialog and Close control must remain visible. Escape should close it and return focus to the trigger.
- Confirm selected state, positive and negative direction, warnings, and provenance do not rely on color alone.
- Enable the operating system's reduced-motion preference and reload. Hover, selection, and chart movement should stop without removing state changes or focus indicators.
- Confirm collapsed sections can be opened and closed with the keyboard and retain complete readable content at each width.

Record each mismatch with the symbol, tab, timeframe, viewport width, visible value, expected value, and relevant API field. Do not refresh or prime provider data during this review.
