# Bootstrap, calculator, and frontend verification

## Deployment

Deploy the same commit to web and worker nodes through the normal release workflow. These changes require no new environment variables, schema migration, Redis flush, or maintenance window. Keep the existing durable-publication, queue-isolation, and cache-isolation settings unchanged.

Confirm the active release commit on both nodes, the web build manifest, the public health response, and the normal queue-worker restart. Do not delete queued work or mark failed bootstrap records completed manually.

## Manual checks

1. Reload the site to load the new JavaScript. Select a symbol with completed EOD preparation. The filling indicator must stop. A failed enrichment phase must show a stopped/error state while keeping available EOD data usable. Expiration counts alone do not prove analytics completed.
2. Open Strikes for SPY, QQQ, IWM, and TSLA. Try one week, two weeks, one month, and three months. Confirm rows load, timeframe changes update the view, and repeated visits remain responsive.
3. Select a recently added symbol. Check Overview, positioning, volatility, unusual activity, and Strikes. Empty activity filters or an expiry horizon without eligible contracts can legitimately return no rows. Check the displayed data dates rather than treating every empty result as a transport failure.
4. Open Intraday. During market hours, confirm a snapshot appears and its age changes after eligible refreshes. Outside market hours, a new symbol without a stored live snapshot must show the market-closed explanation. EOD fallback rows must not be presented as a live snapshot.
5. Open the calculator, choose an expiration and a priced call, then a put. The expiration payoff table must appear even if an automatic underlying quote is unavailable. Manually changing the option premium must update the table.
6. Enter a positive underlying scenario price. Confirm time-decay calculations appear when the contract also has valid DTE and pricing inputs. The manual scenario must be labeled hypothetical. Clear it or enter an invalid value: time decay pauses, while expiration payoffs remain available. Switch symbols and confirm the manual underlying resets.
7. Rapidly switch between two symbols and between EOD and Intraday. Only the final selection may update the visible data. Change unusual-activity expiration and filters while a previous request is pending. Old responses must not replace the current result.
8. Open Volatility. Term structure and VRP should start together. If one tile is still preparing or fails, completed sibling tiles remain usable. Return to a fresh tab and confirm it reuses its cached data. Wait beyond its freshness interval and return again to confirm a refresh occurs.
9. Navigate away while a tab or symbol is loading. In browser developer tools, confirm the old Dashboard stops polling. A previously accepted server-side job may still finish normally; canceling browser work does not cancel durable jobs.

## Request and render checks

Use the Network panel with Preserve log enabled. Separate Dashboard-owned traffic from AppShell watchlist and child-component traffic.

- The Dashboard starts one EOD levels request and does not probe every inactive heavy tab.
- Volatility uses one term, one VRP, and one seasonality request when uncached. Repeated clicks during the same pending load share requests.
- A pending volatility tile retries while successful siblings reuse their cache. Explicitly unavailable seasonality is displayed without an endless preparing loop.
- Duplicate in-flight AppShell selections share one status request and one set of required warmups. Superseded selections cannot emit the final navigation event.
- An unmounted DexTile makes no further four-second retries.
- One calculator state update schedules one render pass. Each of the two charts is recreated at most once in that pass. Unmount cancels pending frames and destroys chart instances.

Client freshness intervals remain one minute for term, VRP, unusual activity, and intraday snapshots; five minutes for seasonality and EOD payloads. These are reuse limits on activation, not a promise that every inactive tab continuously refreshes.

## Automated coverage

Frontend tests cover terminal/bootstrap generations, delayed responses, symbol/mode/expiration races, partial volatility readiness and errors, freshness reuse, unmount cleanup, duplicate selections, and calculator payoff/render behavior.

The holiday-price tests cover ordinary sessions, weekends, observed holidays, early closes as valid sessions, already-serialized jobs, missing bars on valid trading days, and unchanged frozen option-snapshot anchors.

GEX-029 tests compare the direct wall-spot lookup with a frozen legacy service, including missing/stale quotes and wall payloads. The existing WallService age cutoff and snapshot fallback are unchanged. Its public spot result remains a nullable number; this change does not introduce timestamp fields or adopt the calculator's separate quote policy. The optimized accessor currently has no repository caller, so service benchmarks must not be reported as scanner-page improvements.

Run MySQL tests only with the guarded test configuration and an explicitly disposable database ending in `_test` or `_testing`. Optional Redis drills and platform-specific concurrency tests have separate prerequisites. Never point destructive test suites at production.
