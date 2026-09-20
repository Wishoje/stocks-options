# Batch 5: Intraday Live Flow and Live Strikes

Batch 5 covers UI-12 Intraday Live Flow and UI-13 Intraday Live Strikes. It is prepared for final validation and local review. It has not been committed, pushed, or deployed. Final visual acceptance remains manual.

## Scope

### UI-12 Intraday Live Flow

The Live Flow tab now presents the session summary and volume-by-strike response as one self-contained view:

1. Session volume and volume put/call ratio are the primary readings. Call volume, put volume, and estimated premium notional remain visible as supporting metrics.
2. Freshness, market state, trade date, refresh eligibility, and source-clock status appear with the data they describe.
3. The strike chart shows cumulative call and put contract volume. A native strike selector gives keyboard and touch users the same inspection values as chart selection.
4. Activity focus, dense-strike grouping, zoom reset, and PNG download affect only the plotted view. Grouped bars sum their source rows. The exact source rows remain available.
5. Snapshot metadata, refresh metadata, exact totals, every strike reading, and the complete returned objects start collapsed and render only when opened.

Intraday flow is cumulative for the stored trade date. It is not a day-over-day volume change. The endpoint aggregates all returned expiries by strike, and the EOD expiry timeframe does not scope this view.

The flow view checks response ownership before displaying data. Rows from the prior symbol stay hidden while a new symbol loads. An explicitly available snapshot remains available when every total and strike value is zero. An explicitly unavailable snapshot does not become a false zero-valued result.

### UI-13 Intraday Live Strikes

The Intraday Strikes tab keeps the complete composite strike response and separates it into three focused panels:

- Session volume divided by EOD open interest at each strike.
- Session put volume divided by session call volume at each strike.
- Estimated call and put premium notional at each strike.

Each panel shows the symbol, session state, source-clock kind, source time when available, primary summary, selected strike, chart coverage, and raw row count. Every returned field remains available in a lazy collapsed table. This includes repriced GEX fields that are not plotted in these three panels.

When `snapshot_available=false`, the three charts and their metric summaries do not render. The endpoint can still return EOD-open-interest scaffold rows with zero-filled live fields before a completed intraday snapshot exists. Those objects remain available in one collapsed pre-snapshot diagnostic, clearly separated from live activity.

The ratio charts preserve a returned ratio when one exists. If it is missing, the interface derives a ratio only when all required components are present and the denominator is greater than zero. A grouped ratio is recomputed from the grouped numerators and denominators rather than averaged from row ratios. Aggregate ratio summaries require complete component coverage.

Large ratio outliers can cap the visible y-axis so ordinary strikes remain readable. The source value is not changed. The selected-strike reading, tooltip, clipped-reading notice, and complete table retain the returned value.

## Data contracts

| Surface | Source | Preserved fields and behavior |
| --- | --- | --- |
| Intraday summary | `/api/intraday/summary` | Trade date, market-open state, source and response clocks, snapshot availability, refresh eligibility and reason, market session, call volume, put volume, total volume, PCR, and estimated premium. The full returned summary remains inspectable. |
| Live flow by strike | `/api/intraday/strikes` | Every raw `items` row, cumulative call and put volume, EOD call and put OI, returned Vol/OI and PCR, estimated call and put premium, repriced GEX fields, and any additional returned fields. |
| Live Vol/OI | `/api/intraday/strikes` | Returned `vol_oi`, or `(call_vol + put_vol) / (oi_call_eod + oi_put_eod)` only when the returned ratio is absent and every component is available. A zero denominator remains unavailable. |
| Live PCR | `/api/intraday/strikes` | Returned `pcr`, or `put_vol / call_vol` only when the returned ratio is absent and both volumes are available. Zero call volume leaves the ratio unavailable. A returned or derived numeric zero remains zero. |
| Live premium | `/api/intraday/strikes` | Estimated call and put premium in dollars, including returned zero and null values, plus every additional field returned on the row. |
| Freshness and refresh state | Both intraday endpoints | `source_asof`, legacy `asof`, `captured_at`, `received_at`, `ingestion_completed_at`, `source_timestamp_status`, `snapshot_available`, `refresh_eligible`, `refresh_reason`, and `market_session` when returned. |

The Dashboard continues to own request deduplication, the one-minute browser cache, refresh admission, pending retries, cancellation, and symbol ownership. A provider-start request is made only when the server says refresh is eligible, with the existing rolling-deployment fallback for older responses.

## Provenance limits

`source_asof` is the provider market clock when the additive source-clock contract is present. An explicitly returned null `source_asof` stays unavailable. `received_at`, `captured_at`, and `ingestion_completed_at` describe transport or processing and are not substituted for market time.

Older cached responses may contain `asof` without the additive source-clock fields. The interface keeps this rolling-deployment fallback and labels it as a **legacy response time**. It must not be described as a verified provider source clock.

Legacy composite timestamps can arrive as unzoned database text. The dashboard uses the summary endpoint's zoned ISO clock for age and Eastern-Time formatting. If neither endpoint supplies an explicit timezone, the displayed source time remains unavailable rather than assuming the browser timezone. The original `asof` text remains in the raw metadata.

The intraday composite aggregates included expiries by strike and does not return each expiry contribution. The interface cannot isolate one expiry or apply the dashboard EOD timeframe. Volume PCR and Vol/OI therefore describe the aggregate returned strike universe.

Vol/OI combines current-session volume with the latest available EOD open-interest denominator. It is a ratio, not a percentage. PCR also remains a ratio. PCR above 1 is put-led; below 1 is call-led. Neither ratio determines whether trades opened or closed positions.

Premium is an estimate calculated as price × volume × 100. The ingestion path selects VWAP, then day close, then last trade, then quote. A contract with no usable price contributes zero. A returned zero can therefore include missing price input and should not be interpreted as proof of zero economic premium.

The composite also returns repriced `net_gex_live` and `net_gex_delta`. The current `net_gex_delta` implementation subtracts a hardcoded zero baseline, so it equals the live repriced value. Batch 5 preserves the field in complete tables under its returned name and does not present it as a measured time change.

An explicit `snapshot_available` value controls whether a snapshot exists. This keeps a valid all-zero snapshot distinct from a placeholder response. When the field is absent on a legacy response, the existing `asof` fallback supplies the compatibility decision.

## Interaction and presentation

- Dark surfaces remain the base. Brighter teal, coral, blue, and amber accents identify call activity, put activity, selected data, and stale or limited provenance.
- Primary metrics use compact values. Exact totals and source values remain in collapsed details.
- Chart bars support pointer and touch selection. Native strike selectors provide keyboard inspection without requiring hover.
- Activity focus is side-aware so a dominant call or put series does not hide meaningful activity on the other side.
- Dense series start grouped for a readable and bounded plot. Source rows and grouped totals remain intact, and the user can turn grouping off.
- Ratio scale clipping changes only the viewport. It never replaces the returned reading.
- Reset zoom and PNG download remain available on populated charts. Download names include the symbol and available snapshot context.
- Intraday charts mount only for the active intraday view. Complete tables render only after their disclosures open.
- Loading, refreshing, empty, unavailable, stale, unknown-clock, closed-market, and refresh-error states use text as well as color.
- Help opens in the shared contained dialog, closes with Escape, and returns focus to its trigger.
- Motion stays limited to short flow-chart and control transitions. The operating-system reduced-motion preference removes chart and interface motion while keeping state changes visible.

## Four-symbol local snapshot

The local review database currently resolves all four symbols to trade date September 11, 2026. The market was closed during this inventory. These responses contain legacy `asof` values and do not contain the additive provider-source contract, so the interface should label their clock as legacy.

| Symbol | Legacy `asof` | Strike rows | Call volume | Put volume | PCR | Vol/OI available | PCR available | Estimated premium |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| SPY | 2026-09-11 14:52:23 | 365 | 3,836,958 | 4,974,658 | 1.297 | 360 / 365 | 309 / 365 | $960,507,161.10 |
| QQQ | 2026-09-11 10:56:06 | 391 | 1,280,878 | 1,847,985 | 1.443 | 391 / 391 | 274 / 391 | $512,230,580.66 |
| IWM | 2026-09-11 10:55:06 | 185 | 237,777 | 407,596 | 1.714 | 184 / 185 | 145 / 185 | $76,732,066.32 |
| AAPL | 2026-09-11 10:30:11 | 115 | 1,039,080 | 485,556 | 0.467 | 115 / 115 | 113 / 115 | $273,468,603.24 |

The local rows give direct zero-versus-missing review cases:

| Symbol | Zero call-volume rows | Zero put-volume rows | Missing Vol/OI | Missing PCR |
| --- | ---: | ---: | ---: | ---: |
| SPY | 56 | 55 | 5 | 56 |
| QQQ | 117 | 132 | 0 | 117 |
| IWM | 40 | 41 | 1 | 40 |
| AAPL | 2 | 19 | 0 | 2 |

These values are a review inventory, not production expectations. Do not refresh or prime provider data to reproduce them.

## Validation record

| Check | Result |
| --- | --- |
| Intraday Live Flow component tests | Passed in the focused suite |
| Intraday Live Strikes component tests | Passed in the focused suite |
| Dashboard intraday freshness and ownership tests | Passed in the focused suite |
| Focused Batch 5 frontend suite | Passed: 4 files, 78 tests |
| Full Vitest suite | Passed: 30 files, 320 tests |
| Production frontend build | Passed: 267 modules |
| UI preview build | Passed: 24 modules |
| Four-symbol local endpoint inventory | Captured from the current local database |

Use [local-review.md](local-review.md) for the four-symbol visual and interaction review.
