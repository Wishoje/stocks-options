# Batch 3 local review

Batch 3 is staged for local review. It has not been committed, pushed, or deployed. Final visual acceptance remains manual.

## Review setup

Use the current four-symbol snapshot database with the Laravel server at `http://127.0.0.1:8000` and Vite at `http://127.0.0.1:5173`. Keep the queue worker stopped. Sign in with the existing local review account, then start at:

```text
http://127.0.0.1:8000/dashboard?symbol=SPY&mode=eod&tab=overview&timeframe=14d
```

Review `SPY`, `QQQ`, `IWM`, and `AAPL`. The reference values below describe the September 11, 2026 snapshot. If the local snapshot is refreshed, compare the screen with the new API response instead of these values.

## Snapshot references

Use these values to distinguish a display problem from missing local data.

| Symbol | HVL | Call/put OI share | Total OI | Total volume | OI change | Volume change | Volume PCR |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| SPY | 761 | 24.77% / 75.23% | 5,586,168 | 2,538,777 | +480,277 | +981,474 | 1.61 |
| QQQ | 610 | 39.00% / 61.00% | 3,204,348 | 1,616,439 | +136,960 | +739,942 | 1.80 |
| IWM | 240 | 31.54% / 68.46% | 2,956,384 | 505,128 | +114,946 | -140,231 | 2.67 |
| AAPL | 255 | 58.44% / 41.56% | 1,019,293 | 919,061 | +79,063 | +327,240 | 0.72 |

The interface should show readable compact forms of large values. It does not need to print every raw decimal.

| Symbol | Term rows | IV 1M | RV(20) | VRP | VRP z-score |
| --- | ---: | ---: | ---: | ---: | ---: |
| SPY | 19 | 12.23% | 8.94% | +3.30 pp | +0.31 |
| QQQ | 19 | 18.45% | 13.13% | +5.32 pp | +0.53 |
| IWM | 18 | 17.75% | 12.74% | +5.01 pp | +0.19 |
| AAPL | 13 | 23.66% | 23.23% | +0.43 pp | +0.08 |

All four symbols should have a term snapshot date of September 11, 2026 and a populated five-session seasonality result. The current seasonality note says it was computed from a `+/- 2d` calendar window across 15 years with 15 samples.

## Overview checklist

Repeat this check for each symbol:

1. Open the Overview tab with EOD mode and the 14-day timeframe. Confirm the active symbol, `2W` scope, snapshot date, and prior comparison date are clear.
2. Confirm Q-Score shows an overall result and four component cards: option positioning, volatility, momentum, and seasonality. Each component must retain its score and explanation.
3. Confirm the Q-Score scoring anchor is `2026-09-11`. Inspect the visible source-date list for option earliest/latest, volatility, momentum, and seasonality. A source date can be earlier than the scoring anchor. It must never be later than the anchor.
4. Open the Q-Score guide. Confirm the four weights are 35%, 25%, 30%, and 10%, the dialog stays inside the viewport, Escape closes it, and focus returns to the guide button.
5. Compare HVL, volume PCR, total OI, total volume, OI shares, OI change, and volume change with the reference table. Positive and negative direction should be obvious without coloring every value.
6. Confirm the snapshot guide explains HVL, PCR, totals, comparison fallback, and unavailable values. It should not expose raw diagnostic noise as a primary reading.
7. Inspect both distribution cards. Call and put totals and percentages must match the same Overview response. Use the call/put buttons and arrow keys. The selected segment and its value must remain readable without hover.
8. Change the timeframe from 14 days to another available EOD timeframe and back. Confirm the scope and snapshot metrics update together. Q-Score remains tied to the displayed completed-session date.

If Q-Score shows a September 14 anchor for this snapshot, or all components become neutral because Monday data is absent, treat that as a contract failure. If only one component has an earlier source date, use the source-date list to determine whether its upstream table is older.

## Volatility checklist

Open the Volatility tab for each symbol and check the following:

1. Confirm term structure, volatility premium, and five-session seasonality load independently. A loading or failed card must not hide a successful sibling card.
2. In Term Structure, confirm the expiry count matches the reference table. Move the range control with the mouse and keyboard. The selected expiry, IV, and chain date must update in the visible reading.
3. Expand the full term readings. Confirm every returned expiry is present and the disclosure starts collapsed after a fresh load. A missing IV must read `Unavailable`; a valid zero must read `0.0%`.
4. Open the term guide. Confirm it explains contango, backwardation, and missing-value behavior. Close it with Escape and check focus restoration.
5. In Volatility Premium, compare IV, realized volatility, VRP, and z-score with the reference table. VRP is the main value. The regime label and gauge must agree with the z-score. A missing z-score must say the historical signal is unavailable instead of neutral.
6. Expand the VRP calculation details. Confirm the date and available source metadata remain visible and the disclosure starts collapsed.
7. In Five-Session Seasonality, confirm D1 through D5 are all present, the zero baselines are clear, and positive and negative bars are distinguishable. Confirm cumulative 5D, z-score, date, and the full source note remain available.
8. Select seasonality days with the mouse and keyboard. Arrow keys, Home, and End should move the selected reading and focus. Expand the calculation details and confirm it starts collapsed after a fresh load.
9. Switch quickly from SPY to QQQ to IWM to AAPL while the Volatility tab is active. The final screen must contain only the selected symbol's row count, values, dates, and notes. No earlier response should replace it.
10. Use any visible retry control once if a local request fails. Successful cards should remain in place while the failed request retries.

## Layout, accessibility, and motion

Review both tabs near 1440 px, 768 px, and 390 px wide.

- Primary readings should appear before supporting detail. Cards should stack without clipped text or horizontal page scrolling.
- Secondary copy must remain readable against the dark background. Accent colors should emphasize selected, positive, negative, or warning states only.
- Tab through all controls. Every focus indicator must be visible, and the reading order must follow the visual order.
- Open each help dialog near the left and right edges of the page. Its content and Close control must stay inside the viewport.
- Enable the operating system's reduced-motion preference and reload. Hover and selection movement should stop without removing state changes or focus indicators.
- Check that compact values use consistent units: percentages for IV and returns, percentage points for VRP, ratios without a percent sign for PCR, and compact contract counts for OI and volume.

Record any mismatch with the symbol, tab, timeframe, viewport width, visible value, expected value, and whether the API returned the expected field. Do not refresh or prime provider data during this review.
