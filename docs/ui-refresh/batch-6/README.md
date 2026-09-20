# Batch 6: Scanner and Options Calculator

Batch 6 covers UI-14 Scanner and UI-15 Options Calculator. The work is prepared for local review. It has not been committed, pushed, or deployed. Final visual acceptance remains manual.

## UI-14 Scanner

The Scanner now uses the same dark, compact hierarchy as the refreshed dashboard while preserving both existing workflows:

- Volume mode keeps the authoritative most-active universe, returned ranking, source date, source identity, aggregate volume, average put/call ratio, watchlist state, and result-to-dashboard navigation.
- Wall mode keeps the 1d, 7d, 14d, and 30d groups; percentage and point thresholds; Near, Call, and Put sorting; EOD/intraday identity; call/put side; strikes; distance percentages and points; source dates; and the complete wall profile.
- The wall-detail view retains the enhanced Net GEX chart, exact strike rows, split series when supplied, activity focus, dense-strike grouping, zoom reset, and PNG download.
- Result density changes presentation only. It does not change the requested universe, returned rows, or selection.
- Volume and wall requests own their results. A late response cannot replace a newer filter set or wall-detail selection.
- Scanner state is kept in the URL so Back and Forward restore the mode, applied controls, density, and result context.

The market-wide `hot_option_symbols` snapshot remains authoritative. When its source defines the ranking window, the interface labels that scope instead of claiming that a local 5/10/20-day selection recalculated the market-wide universe. A database fallback may use the selected 5/10/20-day window only when the authoritative snapshot is unavailable, and the fallback is labeled as such.

Wall responses include additive coverage diagnostics so an unavailable, stale, or unusable snapshot is distinguishable from a valid scan with no qualifying hits. Large universes are sent in API-safe chunks and merged without dropping rows.

## UI-15 Options Calculator

The Calculator is organized into four steps: choose an expiration, choose the exact contract, set position assumptions, and inspect results.

- Expiration readiness, contract family, provider ticker, bid, ask, pricing-input source, IV, DTE, quote provenance, chain snapshot, and raw publication metadata remain available.
- Near 15%, Wide 40%, and All strike filters affect the local presentation only.
- Call/put switching stays within the exact provider contract family. A missing counterpart clears the selection rather than silently substituting another contract.
- Quantity, manual premium, underlying scenario, target scenario, strike range, table density, and local contract switching issue no market-data requests.
- Raw input strings remain intact while a user is typing. Invalid quantity, premium, underlying, or target values show a correction path and pause dependent calculations without silently changing the input.
- The expiration payoff remains 51 scenarios across the existing plus-or-minus 40% range. The time curve retains every daily row from current DTE through expiration.
- Pointer, touch, native-select, and keyboard inspection keep a selected scenario visible. The selected chart point agrees with its exact table row.
- Current/scenario price and breakeven references are drawn at their exact values rather than snapped to the nearest scenario point.
- Full payoff, time-decay, normalized-contract, response-metadata, and raw-expiration data start collapsed but remain available.

The financial formulas remain unchanged: cost is premium times 100 times the positive whole-number quantity; call breakeven is strike plus premium; put breakeven is strike minus premium; maximum loss is premium paid; and expiration payoff is intrinsic value less premium. Long-call profit potential is unbounded. Long-put maximum expiration profit is bounded by the zero-underlying outcome and never displays a negative reward/risk value.

The time curve uses Black-Scholes at one-day intervals with a constant provider IV, or an IV fitted from the current contract price when provider IV is absent. It uses the existing 4% risk-free assumption, assumes zero dividends and European exercise, and does not model American early exercise or changing volatility.

## Data and interaction safeguards

- Numeric zero remains different from missing or unavailable data.
- Retained data is always labeled with the scope and metadata that produced it.
- Exact rows and response objects remain available even when the primary view uses compact values.
- Loading, partial, preparing, empty, stale, unavailable, failed, forbidden, unauthorized, and rate-limited states use text in addition to color.
- Chart animation is disabled so recalculation does not morph financial values. Interface feedback is brief and removed under reduced-motion preferences.
- Dense tables scroll inside their panels and do not create horizontal page scrolling.
- Scanner dialogs and chart inspection support keyboard use, touch, Escape, and focus restoration.

## Local data note

The current filtered local snapshot contains AAPL, IWM, QQQ, and SPY market data dated September 11, 2026. On a later calendar date, the wall freshness guard and quote policy can correctly reject that snapshot. For populated visual review only, use the local API review clock documented in [local-review.md](local-review.md). Keep the queue worker stopped and do not start provider refreshes.

## Validation record

- Focused Scanner and Calculator frontend contracts: 8 files, 143 tests passed.
- Full frontend suite: 32 files, 367 tests passed.
- Production Vite build: passed with 271 modules transformed.
- Focused Scanner Laravel contracts: 8 tests and 81 assertions passed in the main project, where Composer dependencies are available.
- PHP syntax checks passed for the changed controllers, routes, and feature tests.
- Independent Scanner and Calculator contract audits found no remaining release blockers.
