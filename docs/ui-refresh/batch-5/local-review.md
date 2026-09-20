# Batch 5 local review

Batch 5 is prepared for local review. It has not been committed, pushed, or deployed. Final visual acceptance remains manual.

## Review setup

Use the current four-symbol snapshot database with the Laravel server at `http://127.0.0.1:8000` and Vite at `http://127.0.0.1:5173`. Keep the queue worker stopped. Sign in with the existing local review account.

Start Intraday Live Flow at:

```text
http://127.0.0.1:8000/dashboard?symbol=SPY&mode=intraday&tab=flow&timeframe=14d
```

Start Intraday Live Strikes at:

```text
http://127.0.0.1:8000/dashboard?symbol=SPY&mode=intraday&tab=strikes&timeframe=14d
```

Review `SPY`, `QQQ`, `IWM`, and `AAPL`. The current local intraday data resolves to the September 11, 2026 trade date. The saved responses use a legacy `asof` clock and were closed-session snapshots during this inventory. Keep those conditions in mind when checking the source label and closed-market state.

The `timeframe=14d` query parameter remains in the URL because it is part of the shared dashboard route. It must not change intraday totals, rows, ratios, or premium. Both intraday views aggregate all expiries returned by the intraday composite.

Review every symbol at approximately 1440 px, 768 px, and 390 px wide:

| Symbol | 1440 px | 768 px | 390 px |
| --- | --- | --- | --- |
| SPY | [ ] | [ ] | [ ] |
| QQQ | [ ] | [ ] | [ ] |
| IWM | [ ] | [ ] | [ ] |
| AAPL | [ ] | [ ] | [ ] |

## Local data reference

Use these values to identify a stale local response or a display mismatch. Visible metric cards use compact formatting. Exact values remain in the collapsed details.

| Symbol | Rows | Call volume | Put volume | PCR | Estimated premium |
| --- | ---: | ---: | ---: | ---: | ---: |
| SPY | 365 | 3,836,958 | 4,974,658 | 1.297 | $960,507,161.10 |
| QQQ | 391 | 1,280,878 | 1,847,985 | 1.443 | $512,230,580.66 |
| IWM | 185 | 237,777 | 407,596 | 1.714 | $76,732,066.32 |
| AAPL | 115 | 1,039,080 | 485,556 | 0.467 | $273,468,603.24 |

Expected ratio coverage is:

| Symbol | Vol/OI values | PCR values | Missing Vol/OI | Missing PCR |
| --- | ---: | ---: | ---: | ---: |
| SPY | 360 / 365 | 309 / 365 | 5 | 56 |
| QQQ | 391 / 391 | 274 / 391 | 0 | 117 |
| IWM | 184 / 185 | 145 / 185 | 1 | 40 |
| AAPL | 115 / 115 | 113 / 115 | 0 | 2 |

Do not run a provider refresh to make these values match. If the local database is intentionally replaced, compare the interface with the new `/api/intraday/summary` and `/api/intraday/strikes` responses.

## Intraday Live Flow checklist

Repeat these checks for each symbol:

1. Open Live Flow. Confirm the selected symbol and trade date are visible. The status should say that a stored closed-session snapshot is shown, and the source badge should say the market is closed.
2. Confirm Session volume and Volume put/call ratio lead the page. Call volume, put volume, estimated premium, and the call/put mix should be easy to scan without making every number colorful.
3. Compare the exact totals in the collapsed Snapshot and refresh details with the local data reference. Visible cards may be compact, but the exact totals must remain unchanged.
4. Confirm the copy describes cumulative session volume across all returned expiries. It must not call this flow a daily delta or imply that the EOD timeframe filters it.
5. Inspect the source-clock details. The current local response should say legacy response time. It must not present receipt or ingestion completion as provider market time.
6. Expand Snapshot and refresh details. Confirm trade date, raw `asof`, source timestamp status, capture/receipt/ingestion clocks when returned, snapshot availability, market state, refresh eligibility and reason, next open, and exact totals remain available. The disclosure should start closed and load its body only when opened.
7. Inspect the session-volume chart. Calls should use the positive teal accent and puts the coral accent. The selected strike should remain clear without hover.
8. Click or tap a bar, then select another strike from the native Inspect strike control. Confirm call volume, put volume, PCR, and grouped source count update for the selection. Repeat with only the keyboard.
9. Group dense strikes should start enabled. Turn off Focus on activity and confirm the full numeric strike range remains represented within the grouped chart limit. Turn grouping off and back on; displayed grouped totals must still sum their source rows.
10. Reset zoom and download a PNG. Confirm the filename identifies intraday flow, the symbol, and available trade-date/source context.
11. Expand All exact strike readings. Confirm it starts closed, renders only when opened, contains every current symbol row, preserves numeric zero, labels missing fields unavailable, and includes additional returned fields in the complete row objects.
12. Use the Reading guide. Confirm it defines PCR as a ratio, explains the all-expiry session scope, explains the estimated-premium source order and missing-price zero, and states that display controls leave raw data intact.
13. Use Refresh. During a soft refresh, the aligned snapshot should remain visible with a refreshing state. If the request fails, the retained snapshot and its error should remain visible together.
14. Switch quickly from one symbol to another. The old symbol's totals, source time, and rows must disappear while the new symbol loads. The sticky dashboard freshness area should say that the new symbol is loading rather than showing the old symbol's clock.
15. Check an explicit all-zero snapshot with a test fixture if one is available. `snapshot_available=true` must render zero as valid data. `snapshot_available=false` must render the unavailable state instead of zero cards.

## Intraday Live Strikes checklist

Repeat these checks for each symbol:

1. Open Intraday Strikes. Confirm three panels appear in this order: Volume / open interest, Put / call ratio, and Estimated premium. Each panel should repeat enough symbol and source context to stand on its own.
2. Confirm the source line says Legacy response time for the current local snapshot. A response with explicit `source_asof` should say Provider source time. An explicit null provider source time should remain unavailable even if another response clock exists.
3. In Volume / open interest, confirm the UI labels the reading as a ratio in `x`, not a percentage. A missing returned ratio may be derived only from complete call volume, put volume, EOD call OI, and EOD put OI with a positive total-OI denominator.
4. In Put / call ratio, confirm a value above 1 is put-led and below 1 is call-led. A real zero remains zero and call-led. A zero or missing call-volume denominator leaves a put-only ratio unavailable.
5. Confirm each ratio panel identifies whether a selected value came from the API or was derived from returned components. Grouped ratios must be recomputed from the grouped numerator and denominator rather than averaged.
6. Inspect ratio outliers. When the useful y-axis is capped, the panel should show how many readings exceed the visible scale. The exact selected value, tooltip, and table value must remain unchanged.
7. In Estimated premium, confirm dollar units appear in metrics, axis labels, tooltips, and selected-strike details. Call and put premium should remain separate. A returned null keeps the aggregate incomplete; a returned zero remains zero.
8. Open the premium Reading guide. Confirm it describes price × volume × 100 and the VWAP, day-close, last-trade, quote fallback. It should state that the endpoint initializes an absent side to zero and that a missing price contributes zero, so zero does not prove measured $0 premium.
9. Use the native Inspect strike control and chart selection in every panel. Confirm selection works with keyboard, touch, and pointer input without relying on hover.
10. Toggle Focus on activity and Group dense strikes, then use Reset zoom and Download PNG. Display controls must not alter the returned rows. Grouped ratios must preserve denominator arithmetic; grouped premium must preserve call and put sums.
11. Expand each complete returned-data disclosure. It should start closed and render the table only when opened. Confirm every response row and every returned field is present, including invalid-strike rows and additional fields not plotted.
12. Find the returned `net_gex_live` and `net_gex_delta` fields in a complete table. They should remain raw fields only. The interface must not describe `net_gex_delta` as a measured change because its current backend baseline is zero.
13. Compare zero and missing cases using the local coverage table. A zero must remain numeric zero. Missing Vol/OI, PCR, OI, or GEX must remain unavailable unless the documented ratio derivation has complete components. Apply the premium zero limitation from the guide.
14. Switch between Live Flow and Intraday Strikes. The intraday charts should mount only when their view is active, and collapsed tables should not build until opened.
15. Change the URL timeframe or switch through the shared timeframe state in EOD mode before returning. Intraday rows and metrics must continue to match the same intraday composite response.

## Market, refresh, and ownership states

Exercise these states with existing fixtures or request mocks when the local snapshot cannot produce them directly:

- **Fresh open:** provider source age under 90 seconds, current source time visible, and no stale warning.
- **Delayed open:** source age at least 90 seconds, delayed minutes visible, and data retained.
- **Unknown provider clock:** snapshot available, source time unavailable, receipt and ingestion clocks shown only in details.
- **Closed with snapshot:** last stored values remain visible with the next-open context.
- **Closed without snapshot:** no zero-valued metric cards; the next session message remains visible.
- **Pending open snapshot:** retry polling reads status without starting duplicate provider work.
- **Pending session read:** the header says the session state is loading or unavailable; it must not claim that the market is closed before the endpoint supplies `open=false`.
- **Unavailable composite with scaffold rows:** no live strike chart or zero-valued live metric appears; exact pre-snapshot objects remain in the collapsed diagnostic.
- **Refresh eligible:** one provider-start request follows the eligibility read.
- **Refresh not eligible:** refresh reads the stored endpoints without starting provider work.
- **Soft refresh error:** the aligned prior snapshot remains visible with an error state.
- **Symbol transition:** prior-symbol rows and source clock stay hidden until the selected symbol owns the response.

## Layout, accessibility, and motion

Perform these checks at all three widths in the review matrix:

- At 1440 px, primary metrics should lead the reading order. Charts should use the available width without oversized empty panels.
- At 768 px, metric cards and chart controls should reflow without overlap. Selected-strike values and provenance should remain readable.
- At 390 px, controls should reach a comfortable touch size. Charts and lazy tables must stay within their panels, and the page itself must not scroll horizontally.
- Tab through Refresh, Reading guide, chart toggles, Inspect strike, zoom, download, and disclosures. Every interactive control needs a visible focus indicator and logical order.
- Open help near both viewport edges. The dialog and Close control must remain visible. Escape should close it and return focus to the trigger.
- Confirm selected state, call/put activity, source state, and warnings use text or shape in addition to color.
- Enable the operating system's reduced-motion preference and reload. Flow-chart and control animation should stop without removing values, focus, selection, or status changes.
- Confirm every disclosure opens and closes with the keyboard. Large tables should scroll inside their own region and remain sortable.

Record each mismatch with the symbol, tab, viewport width, visible value, expected value, source-clock kind, snapshot availability, and relevant API field. Do not refresh or prime provider data during this review.
