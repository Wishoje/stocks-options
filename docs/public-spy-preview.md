# Public SPY GEX preview

The public `/spy-gex-levels` page gives visitors a free, dated SPY EOD chart. It links to the reading guides and the existing trial registration flow. The chart and explanatory text are present in the initial HTML for search engines and visitors without JavaScript.

## Data and calculations

The page reuses `GexController::getGexLevels()` with fixed server-owned inputs: SPY, `14d`, `latest_eod`. Public query parameters cannot change the symbol, scope or view, request a refresh, or expose the underlying API response.

To compare with the dashboard, select SPY, End of day, Latest EOD data and 2W. The page converts the legacy GEX values by multiplying by 0.01, matching wall analysis in USD per 1% underlying move. The legacy Net GEX chart uses a scale 100 times larger. The largest negative net exposure defines the put wall; the largest positive net exposure defines the call wall. Total GEX includes every numeric strike, while the chart focuses on the main exposure range and retains both wall peaks.

The displayed date comes from the actual EOD response. The latest completed session follows the existing market calendar, including holidays and early closes. An older reading remains dated and is labeled as a recorded view. Intraday tracking in the subscription workspace continues to use its existing 1W scope and delayed observations.

## Caching and operations

The sanitized preview is cached for five minutes. The cache key includes the existing SPY GEX publication version and the New York calendar date. A shared nonblocking lock prevents concurrent cold requests from rebuilding the same preview. Failed builds are cached for one minute. A dated last-good preview can be retained for 14 days. Without an accepted preview, the page shows a preparation message and its explanatory content.

The page accepts the existing small gamma-gap policy from `wall_tracking.minimum_oi_coverage_pct`. It rejects mixed source dates, missing expirations, material input problems and future EOD dates. Internal quality fields, contract rows and work-run metadata are excluded from the public response.

No new environment variables, worker processes, schedules or external services are required. Deploy through the normal application build. The public route has a 60-request-per-minute throttle; the underlying authenticated data APIs keep their current access requirements.

## Local review

- Open `http://127.0.0.1:8000/spy-gex-levels` without signing in.
- Check the displayed EOD date, the two wall strikes and total net GEX.
- Compare with the dashboard using the matching selections and units above.
- Check the page at desktop and phone widths, including the chart and navigation menu.
- Expand the expiry details and FAQs. Follow the guide, feature and trial links.

Automated coverage checks fixed inputs, calculation units, source acceptance, cache reuse, publication changes, concurrent requests, dated fallback, initial HTML, metadata, protected APIs and the existing trial flow.
