# Batch 6 local review

Batch 6 is prepared for local review. It has not been committed, pushed, or deployed. Keep the queue worker stopped throughout this review.

## Review setup

Use the existing four-symbol local snapshot. It contains AAPL, IWM, QQQ, and SPY data dated September 11, 2026.

To review populated wall results and the saved Calculator quote state after the snapshot has aged, set this local-only value in `.env`:

```dotenv
UI_REVIEW_NOW=2026-09-11T15:30:00-04:00
```

Then clear Laravel's configuration cache and restart the local PHP server. This changes the clock only while local API requests run. It does not alter database rows. Blank `UI_REVIEW_NOW` and clear configuration again when the historical review is finished.

Run Laravel at `http://127.0.0.1:8000` and Vite at `http://127.0.0.1:5173`. Sign in with the existing local review account. Do not start a queue worker, provider refresh, checkout, export, or production operation.

Open:

```text
http://127.0.0.1:8000/scanner?scan=volume
http://127.0.0.1:8000/scanner?scan=gex
http://127.0.0.1:8000/options-calculator?symbol=SPY
```

Review at approximately 1440 px, 768 px, and 390 px wide.

| Surface | 1440 px | 768 px | 390 px |
| --- | --- | --- | --- |
| Scanner volume | [ ] | [ ] | [ ] |
| Scanner walls | [ ] | [ ] | [ ] |
| Calculator | [ ] | [ ] | [ ] |

## Scanner volume checklist

1. Confirm the source, source date, ranking scope, returned count, aggregate volume, and average put/call ratio are visible and agree with the raw response.
2. Review the 100, 200, and 400 choices and Load more behavior. The interface must never claim more than the server's 500-row maximum.
3. Review the 5, 10, and 20-day controls. If the authoritative snapshot has a source-defined window, the interface must say so and must not imply that changing the control recalculated the market-wide ranking. If the database fallback is active, the selected fallback window and fallback source must be explicit.
4. Confirm numeric zero remains visible for volume, PCR, and last price. Missing readings should say Unavailable.
5. Switch Comfortable and Compact density. The source rows, ranking, result count, selection, and network request count must remain unchanged.
6. Open SPY, QQQ, IWM, and AAPL results. Each should navigate to the EOD Overview with the correct symbol and timeframe. Browser Back should restore Scanner mode, applied controls, density, and scroll position.
7. Add or remove a symbol only if you are willing to change the local review account's watchlist. Confirm busy, success, and failure states do not activate the result navigation accidentally.
8. Change a control quickly several times. Old rows must not be relabeled as the new request, and a late response must not replace the latest applied scope.
9. Expand the complete response details. All returned fields and rows must remain available, with exact values and source metadata.

## Scanner wall checklist

1. Confirm 1d, 7d, 14d, and 30d groups remain present in their fixed order.
2. Apply percentage-only, point-only, and combined thresholds. Invalid values must show a correction path and must not run a scan.
3. Switch Near, Call, and Put sorting. Sorting should be local and must not issue another wall API request.
4. For each result, confirm symbol, spot, timeframe, trade date, side, EOD/intraday source, wall strike, distance percentage, and distance points remain readable without hover.
5. Confirm coverage text distinguishes no snapshots, stale or unusable snapshots, and a completed scan with no qualifying matches.
6. Open a wall detail by pointer, keyboard, and touch. Confirm the dialog has a readable name, focus enters it, Escape closes it, and focus returns to the trigger.
7. Compare the selected wall strike with the nearest source strike, exact Net GEX, and percentage of maximum absolute GEX.
8. Exercise split call/put, Focus activity, Group dense strikes, Reset zoom, and PNG download in the Net GEX chart. These controls must not change or refetch source rows.
9. Expand exact strike data and raw wall responses. All rows and additional fields must remain present.
10. Open EOD and intraday wall handoffs. EOD should open dashboard Strikes with the wall timeframe; intraday should open Intraday Strikes with the correct symbol. Browser Back should restore the Scanner.

If populated wall rows do not appear, first confirm `UI_REVIEW_NOW` and Laravel configuration. Do not start provider work to manufacture a result. The empty-state coverage details should explain whether the local snapshot was absent, stale, unusable, or simply had no match.

## Calculator checklist

Repeat the flow with SPY, QQQ, IWM, and AAPL:

1. Confirm quote status, quote source/time, chain snapshot, expiration readiness, and selected contract identity are visible.
2. Switch expirations. The selected chain, raw expiration publication, response metadata, and snapshot must remain aligned. A cached chain must restore its matching metadata.
3. Review Near 15%, Wide 40%, and All strikes. Each adjusted contract family and provider ticker must remain separately selectable.
4. Select calls and puts using pointer, touch, Enter, and Space. Switching type must use the exact counterpart family or clearly show that no counterpart exists.
5. Compare bid, ask, automatic pricing-input source, IV, DTE, ticker, family, and raw selected-contract fields.
6. Enter a whole-number quantity and an unrounded manual premium. Confirm values are retained exactly while typing and no request is issued.
7. Try blank, zero, negative, fractional, and nonnumeric quantities and premiums. The input should remain visible, receive a clear error, and pause calculations without silently becoming one contract or another price.
8. Enter a manual underlying scenario and target scenario. Invalid manual underlying input must never fall back silently to the market quote.
9. Compare cost, maximum risk, breakeven, required move, profit potential, and reward/risk with the exact table rows. A long call should show unbounded profit potential; a long put should show the finite zero-underlying maximum.
10. Inspect both charts using pointer, native selects, and arrow/Home/End keys. The selected value must persist and match the highlighted exact row.
11. Confirm the current/scenario and breakeven reference lines sit at their exact prices, including when those prices fall between two of the 51 payoff scenarios.
12. Switch Flat at Spot, Flat at Breakeven, and Flat at Target. The time curve should keep its selected day visible in Compact mode and expose every daily row in Full mode.
13. Confirm chart values update without animated morphing. With reduced motion enabled, interface transitions should stop while values and focus remain visible.
14. Open the payoff table, full time table, exact market-data inputs, response metadata, raw expiration publications, and normalized contracts. No field should disappear.
15. Review loading, preparing, slow, no-options, stale quote, unavailable quote, failed, rate-limited, unauthorized, forbidden, and retry states with existing fixtures or request mocks when the saved snapshot cannot produce them.

## Calculation parity examples

Use these simple fixtures when a matching contract or test fixture is available:

- Long call: strike 100, premium 5, quantity 1. Cost and maximum risk are $500, breakeven is $105, and expiration P&L at $100 is -$500.
- Long put: strike 100, premium 5, quantity 1. Breakeven is $95, expiration P&L at $60 is $3,500, and maximum expiration profit at a zero underlying is $9,500.
- Repeat both with premium 3 and quantity 2. Every dollar result should scale from the exact inputs without changing intermediate precision.

Record any mismatch with the symbol, viewport, applied Scanner scope or Calculator contract, visible value, expected value, source date, and relevant raw API field.
