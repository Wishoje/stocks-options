# EOD analysis views

Overview and Strikes provide two choices. Latest EOD snapshot resolves expirations from the latest available published source date, including contracts active on that date. Next-session preparation resolves the same timeframe from the upcoming market session and excludes earlier expirations. Both retain the source EOD date; preparation does not reprice gamma or forecast the next session.

The default is Latest EOD during the core session and Next-session preparation outside it. The market calendar handles weekends, holidays, daylight saving time, and early closes. An explicit `view` query parameter survives refresh and browser navigation. During regular hours, manually choosing next-session preparation targets the next opening session; before the open it targets that morning.

The view controls Overview and Strikes data. Positioning, volatility, unusual activity, intraday data, and Q-Score retain their existing independently labeled scopes. No historical source rows are removed or modified.

Social drafts use the exact `next_session` GEX API response, including its frozen quality facts. Their target session is explicit. Matching symbol, timeframe, target session, source date, and published response version yields identical strike rows. An existing saved draft is immutable until regenerated and can differ after a new data publication. The chart may focus the visible range; totals continue to use every returned strike.

AI exports provide the same two choices. The queue request stores the chosen view and target session so queue delay does not silently select another session. JSON includes `options.gex_view`, `options.target_session`, full `gex_levels.data.view_context`, included expirations, and the corresponding summary fields. Other indicators retain their own scopes and dates. Previously queued exports without a view retain legacy behavior.

Server response caches and browser request caches isolate the views. Existing unscoped API consumers retain legacy behavior. Manifest publication checks and warm-cache reads remain in place. Source quality diagnostics remain visible in the social admin page and JSON, but no longer appear on social PNGs. Removing diagnostic text does not remove approval checks or enable publishing.

## Review

1. Open Dashboard, End of day, Strikes, 2W. Switch between the two choices. Check the source date and included expiration badges; on the weekend, Friday's expiration appears only in the EOD view.
2. Refresh and use Back/Forward. Confirm the selection survives and a late response cannot overwrite the active view.
3. Generate a social draft for the dashboard preparation session. Compare total GEX and positive/negative peaks. Download the PNG and confirm its dates and clean layout.
4. Use Export this view. Confirm AI Export retains the view and timeframe; download the resulting JSON and inspect its context, expiration dates, and strike rows.
5. Check narrow screens. Intraday and the independently scoped tabs should retain their existing controls.

Automated validation covers calendar boundaries, distinct cached results, stale browser responses, exact dashboard/social/AI payload parity, and manifest warm reads without market-row scans. This change does not add arbitrary historical date-to-date GEX comparison.
