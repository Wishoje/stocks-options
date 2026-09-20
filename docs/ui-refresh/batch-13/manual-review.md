# Full UI refresh manual review

Use the four-symbol local snapshot for AAPL, IWM, QQQ, and SPY. Keep queue workers and provider refreshes stopped. Do not use checkout, the billing portal, subscription cancellation, account deletion, contact submission, export creation, or watchlist mutation during a visual review.

Run Laravel at `http://127.0.0.1:8000` and Vite at `http://127.0.0.1:5173`. Review at about 1440 px, 768 px, and 390 px. Repeat the keyboard pass with reduced motion enabled.

## Priority walkthrough

This pass covers the most important release decisions in about 30 minutes.

1. While signed out, open `/`, `/features`, `/pricing`, `/contact`, `/login`, and `/register`. Check navigation, the single primary action in each section, truthful data-timing copy, current prices, trial terms, footer links, mobile menu focus, and the absence of old screenshots.
2. Select yearly billing on Pricing, continue to Register, move to Login and back, and confirm the earlybird/yearly selection remains intact. Do not start checkout.
3. Sign in with the dedicated local review account and open `/dashboard?symbol=SPY&mode=eod&tab=overview&timeframe=2w`.
4. Walk all five EOD tabs. Then walk Live Flow and Live Strikes. Confirm symbol, mode, tab, timeframe, date, source, units, freshness, selected detail, and advanced/raw data remain reachable.
5. Change SPY to QQQ, use Back and Forward, then use the watchlist handoff. Scope and selected detail should remain stable; late requests must not overwrite the current symbol.
6. Open `/scanner?scan=volume`, `/scanner?scan=gex`, and `/options-calculator?symbol=SPY`. Check direct dashboard handoffs, exact-data details, keyboard chart inspection, chart reset, and responsive tables.
7. Return to the first dashboard. Guidance must stay inline, never block the data, never switch the user's symbol/tab/timeframe, and remain dismissible without moving focus unexpectedly.
8. Open `/user/profile`. After Batch 8 is approved and implemented, verify profile, password, security availability, sessions, subscription truth, portal visibility, and deletion safeguards. Do not invoke billing or deletion actions.

## Public pages

- Home identifies the intended user, EOD versus stored intraday data, the main workflow, current trial, and the route to complete Features and Pricing detail.
- Features includes Overview, Positioning, Volatility, Unusual Activity, Strikes, Live Flow, Live Strikes, watchlist, both scanners, Calculator, and AI Export. EOD Health is not advertised as a subscriber feature.
- Pricing displays $29.99 monthly and $299 yearly from the configured display source. The annual saving is computed from integer cents. Switching periods is immediate, has a programmatic selected state, and survives authentication.
- `?canceled=1` says checkout was canceled. `?activating=1` says the account is being checked; it never claims success. Only confirmed server state can open the dashboard as activated.
- Contact and footer show `support@gexoptions.com` consistently. Terms and Privacy routes render the complete drafts, all links work, and the audit keeps them gated until owner approval matches the exact document hashes.
- Mobile navigation opens inside the viewport, moves focus into the menu, closes with Escape or an outside action, and restores focus to its trigger.
- Product-preview tabs work by pointer, keyboard, and touch. Pending captures are plainly labeled and no legacy image is presented as the current product.

## Dashboard and tools

- At every tab change, confirm the selected scope before reading a number. Numeric zero must remain visible; missing data must say unavailable.
- Compare visible summary values and row counts with the exact/raw details for one representative panel in each tab.
- Inspect at least one chart point by pointer, keyboard, and touch. The persistent selected reading must match the exact row and include units.
- Change controls rapidly. Loading, retained, partial, empty, stale, unauthorized, forbidden, rate-limited, and failed states must not relabel old data as the new request.
- Test Back and Forward after changing symbol, mode, EOD tab, timeframe, scanner mode, and Calculator contract. The restored page must not issue duplicate work or lose its context.
- At 200% text zoom and 390 px width, no primary page should scroll horizontally. Dense tables may scroll inside their own panels.
- With reduced motion enabled, content, focus, and data remain identical. Brief hover/status transitions stop; chart values do not morph.

## Authentication and billing entry

- Submit each auth form empty and with one invalid field. Errors remain associated with their inputs, typed values are retained where safe, and focus does not jump unpredictably.
- Login accepts the server's existing password rules and does not impose an unrelated eight-character client minimum.
- Registration prevents duplicate submission and carries the normalized plan, billing interval, and safe intended product route.
- Password recovery and verification pages redact tokens and email values from custom analytics URLs.
- Checkout start has a busy state and a server-side short duplicate guard. Test it only with an approved Stripe test account.
- A delayed webhook keeps the user on an honest checking screen. Refresh and Back do not convert a query flag into success.

## Report a finding

Record the route, viewport, account state, symbol/mode/tab/timeframe, action, visible result, expected result, source date, and whether reduced motion was enabled. Include the raw API field or screenshot only when it contains no account information, token, private watchlist data, or temporary credential.
