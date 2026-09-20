# Batch 3: Overview and Volatility

Batch 3 covers UI-08 Overview and UI-09 Volatility. The work is staged for local review. It has not been committed, pushed, or deployed. Final visual acceptance remains manual.

## Scope

### UI-08 Overview

The Overview tab keeps the existing market data and reorganizes it into a clearer reading order:

1. Q-Score overall result, interpretation, four component scores, component explanations, weights, scoring anchor, and source dates.
2. Primary snapshot metrics: high-volatility level (HVL), volume put/call ratio, total open interest, and total volume.
3. Supporting metrics: call and put open-interest share, open-interest change, and volume change.
4. Call/put open-interest and volume distributions.

Values use compact display precision. The underlying response values remain unchanged. A real zero remains `0`; a missing value remains `Unavailable`. A total is unavailable when either required side is missing.

Q-Score now uses the dashboard snapshot date when one is available. Its controller also resolves an omitted date through the shared completed-session selector. This prevents a pre-close request from scoring an incomplete current session. The response reports the scoring anchor and separate source dates for option data, volatility, momentum, and seasonality. Source dates may differ because each component uses its latest eligible row at or before the anchor.

### UI-09 Volatility

The Volatility tab keeps all three existing datasets:

- Term structure: every returned expiry, IV reading, dashboard snapshot date, and per-row chain source date when supplied.
- Variance risk premium: date, one-month implied volatility, the stored RV(20) measure, premium, z-score, and source metadata.
- Five-session seasonality: D1 through D5, cumulative five-session return, z-score, source note, and date.

The first reading in each card is the main visual anchor. Supporting values and calculation details remain available with less visual weight. Full term readings and calculation disclosures start collapsed so the tab stays readable without dropping rows.

## Data contracts

| Surface | Source | Preserved fields and behavior |
| --- | --- | --- |
| Snapshot metrics and distributions | `/api/gex-levels` | `data_date`, prior comparison date and gap, HVL, call/put OI shares and totals, call/put volume totals, total OI and volume changes, and volume PCR. The selected EOD timeframe still scopes the request. |
| Q-Score | `/api/qscore` | `symbol`, completed-session `date`, option/volatility/momentum/seasonality score and explanation, plus `source_dates`. The weighted result still uses 35%, 25%, 30%, and 10%. |
| Term structure | `/api/iv/term` | Snapshot date and every item, including expiry, IV, and source chain date when present. |
| Variance risk premium | `/api/vrp` | `date`, `iv1m`, `rv20`, `vrp`, `z`, and `source_meta`. |
| Seasonality | `/api/seasonality/5d` | `variant` fields D1-D5, `cum5`, `z`, date, and the returned note. A completed empty result remains different from loading or failure. |

The existing lazy-load, caching, retry, partial-success, request cancellation, and response-ownership behavior remains in `Dashboard.vue`. A failed volatility request can be retried by card, while successful sibling cards stay available. An older response cannot replace the active symbol after a quick symbol change.

The existing `realizedVol20()` backend method normally derives 21 adjacent returns from 22 closes. Batch 3 does not recalculate stored volatility history. Its UI therefore calls this field the stored RV(20) measure instead of claiming an exact observation count.

## Interaction and presentation

- Dark surfaces remain the base. Brighter semantic accents identify the main result, positive or negative direction, warnings, and selected chart items.
- Distribution choices, the term scrubber, and seasonality days can be inspected without a mouse. Arrow, Home, and End behavior follows each control's supported pattern.
- Help opens in the shared contained dialog. It stays inside the viewport, supports keyboard focus, closes with Escape, and returns focus to its trigger.
- Motion is limited to short hover, focus, selection, and chart transitions. The reduced-motion preference removes those transitions.
- Loading, preparing, sparse, unavailable, and request-error states use explicit status messages. Missing data is not labeled neutral and is not converted to zero.

## Validation record

| Check | Result |
| --- | --- |
| Overview component contract tests | Passed: 9 tests |
| Volatility component contract tests | Passed: 11 tests |
| Dashboard request-lifecycle tests | Passed: 32 tests |
| Full Vitest suite | Passed: 26 files, 266 tests |
| Production frontend build | Passed: 250 modules |
| UI preview build | Passed: 24 modules |
| Q-Score snapshot contract feature tests | Passed: 2 tests, 23 assertions |
| Changed PHP syntax and Pint checks | Passed |

Use [local-review.md](local-review.md) for the four-symbol visual and interaction review.
