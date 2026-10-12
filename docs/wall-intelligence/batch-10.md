# Intraday IV and skew

Dashboard > Intraday > Live Flow includes How options pricing is changing below the volume panel. It compares implied volatility and put-minus-call skew within one recorded session and one expiration. The default expiration is the recorded expiry nearest seven calendar days from that session, with earlier expiry winning a tie. Users can select another recorded expiration or date, inspect individual readings, open the reading guide, and export the displayed comparison as JSON.

## Data and calculation

The feature reads existing `intraday_option_volumes` records. Production inspection on October 2, 2026 confirmed repeated IV and delta captures for SPY, QQQ and TSLA. No additional provider access or collection job is required. The retained history follows the existing intraday pruning policy; the date selector does not promise permanent historical storage.

Each reading uses one exact `captured_at` and expiration. Near-money IV is the mean of call and put IV interpolated at absolute delta 0.50. Skew is put IV at delta -0.25 minus call IV at delta +0.25. IV is returned in percent; skew and changes are in percentage points. Delta targets stay constant while the contributing strikes can change. The near-money measure is a 50-delta proxy, not an exact at-the-money strike measurement.

Interpolation requires positive finite IV, a valid signed delta and positive strike. It never extrapolates. Both bracketing deltas must be within 0.10 of the target, with total width at most 0.20. Exact target matches use the observed value. A metric without both required sides remains null. The API includes the contributing contracts, deltas, strikes and IVs for every target. These values are pricing comparisons, not trade-aggressor classifications or entry signals.

Changes use the first available reading for that metric in the selected session and expiration. The first reading is labeled explicitly and may occur after the open. A later null reading does not inherit earlier values. The chart uses elapsed collection time. Solid lines join consecutive readings up to 20 minutes apart. Lighter dashed lines connect recorded endpoints across longer pauses or intervening null values so the trend stays easy to follow. These connections add no observations or values to the cards, table, slider or exports. The chart never extends a metric before its first or after its last recorded value.

## Time handling

`captured_at` is treated as collection time. The endpoint does not invent an exchange observation timestamp or subtract 15 minutes to create one. Its `timestamp_basis` is `collection_time` and `source_observed_at` is null. The UI labels times Recorded and displays them in ET.

Only captures from 15 minutes after the regular-session open through 15 minutes after the close are considered. This respects the existing early-close calendar and avoids using delayed pre-open pricing as the session baseline. The latest minute is excluded to let chunked ingestion settle. These rules do not establish a precise age for each provider IV value. The local EOD review clock is bypassed for this endpoint, as it already is for wall tracking.

## API and performance

`GET /api/intraday/volatility` accepts `symbol`, optional `session` and optional `expiry`. It requires authentication, intraday access and the existing market-data read throttle. The response schema is `intraday-volatility.v1` and the model is `same-expiry-delta-interpolation.v1`.

The calculation makes four data queries for one symbol, session and expiration. It caps captures at 500 and candidate contracts at 25,000. Exceeding a cap returns an explicit capacity status rather than a truncated comparison. MySQL uses the existing `idx_intraday_symbol_capture_strike` index when present, with a symbol-index fallback for local databases. Index discovery is cached for an hour. No DDL runs on page requests.

A read-only production benchmark initially found a 24.9-second SPY query plan. Selecting the existing symbol/time index reduced the uncached calculation to 534 ms for SPY, 626 ms for QQQ and 394 ms for TSLA. These are individual measurements, not a throughput guarantee. Peak PHP memory was 26 MiB. Endpoint results are cached for 120 seconds. The client keeps a bounded 90-second cache and refreshes the latest session every two minutes while the panel is mounted and the page is visible. Explicit historical dates do not poll. Symbol changes and unmounting cancel requests; responses with a mismatched symbol, session or expiry are rejected.

## Export and release

Export IV & skew JSON downloads the exact selected panel payload without another API request. AI Export also offers an optional Intraday IV & skew indicator. It uses the latest retained session and default expiry for each symbol, independently of the EOD view and timeframe. It is excluded from default EOD bundles and requires intraday entitlement when queued. Select all explicitly includes it. The summary preserves the session, expiration and collection-time basis.

This batch adds no migrations, provider jobs, packages or environment settings. Production inspection confirmed the preferred index; future environments should retain that index for larger histories. Normal application and frontend deployment is sufficient on the current servers.

## Manual review

1. Open `/dashboard?symbol=SPY&mode=intraday&tab=flow` locally and scroll below Session volume by strike to How options pricing is changing.
2. Select session October 2 and expiration October 9. The imported SPY records show 53 readings, latest near-money IV 10.32%, put IV 11.26%, call IV 9.61% and skew +1.65 pp. IV changed -0.77 pp and skew -0.17 pp from the first comparable capture at 9:55 AM ET.
3. Switch between IV level and Put vs call skew. Dashed connections should bridge pauses between recorded endpoints, with dots only at recorded values. Use the slider with arrow keys, Home and End. The cards and recorded time should follow the selected reading. Open All readings to inspect the same values in a table.
4. Change expiration to October 2. The comparison must reset and retain that expiration throughout. Close-to-expiry targets can cease to have a suitable delta bracket; the latest card then shows a dash instead of reusing an older value. Return to October 9 for the full comparison.
5. Switch to QQQ or TSLA. October 9 is available for both in the local import. Confirm the symbol, dates and values change together. Local volume and EOD panels still use their older review fixtures; the new IV panel explicitly dates its October 2 records.
6. Open the reading guide, export the panel JSON and check the dates, units and interpolation inputs. In AI Export, verify Intraday IV & skew starts unchecked and Select all includes it.

## Validation

The chart continuity update passed all eight IV/skew frontend tests and the frontend build. Tests cover dashed endpoint connections, null and zero values, metric switching, no extension beyond recorded values, and unchanged JSON exports. Local browser review confirmed the October 2 SPY chart connects its pauses while retaining 53 readings, 10.32% IV and +1.65 pp skew.

Eight calculation/API tests passed with 46 assertions. Six local-clock tests passed with 26 assertions, and seven export tests passed with 47 assertions on a disposable MySQL database. The focused frontend suites passed 84 tests across IV/skew, exports, navigation, dashboard request lifecycle and intraday freshness. The frontend build and PHP formatting checks passed. Browser review covered real SPY records, expiration changes, skew selection, keyboard inspection and the 390-pixel dashboard layout. A bounded local import contains 6,658 selected IV/delta rows from October 2; production records and collection settings were not modified.
