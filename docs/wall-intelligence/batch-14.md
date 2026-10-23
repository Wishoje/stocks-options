# Watchlist wall alerts

Scanner → Wall activity includes Alert me to new setups. New setup alerts are on by default for signed-in accounts without a saved preference. A saved opt-out remains off, and users can turn alerts off at any time. Also alert when price tests a wall and Sound remain optional and off initially. Preferences are saved for that account in the current browser. Existing events are skipped when monitoring starts.

A notification shows the symbol, wall strike and recorded time in ET. Open chart opens that symbol's Intraday → Wall tracking with the fixed 1W scope. The notification describes a completed delayed assessment. It asks users to check current price against the recorded entry area. Testing notifications describe an area to watch without implying entry confirmation.

The application layout hosts notifications across authenticated pages. Wall alerts on links back to the controls. Up to three notifications appear at the lower right. They can be dismissed with a labeled button and disappear after 90 seconds or when their event is no longer current. Keyboard focus stays in the current page. Notification announcements use a polite live region. Test alert shows a labeled example without inventing a market event.

## Event rules

The existing wall-watchlist response adds opaque `alert_keys`. A setup key includes the existing setup ID, symbol and session. A testing key includes the wall identity, observation episode, symbol, session and interaction-rule version. Repeated completed-bar readings preserve these keys. New setup sequences or comparison episodes have different keys. An expired or unqualified setup has no setup alert key. A reading without the required episode identity has no testing key.

The browser accepts only today's regular session and the 1W scope. It preserves the existing minimum 15-minute price delay and freshness window. Setup notifications also require valid underlying-price levels, an unexpired entry window and a close inside the same exclusive/inclusive entry boundaries as the evaluator. Historical and synthetic payloads cannot supply live alerts.

Enabling alerts or changing watchlist membership primes the current response silently. Older catch-up observations cannot trigger notifications. Current-session seen keys suppress repeats during navigation and browser refreshes. Keys are scoped to the signed-in account and stored without prices or setup levels, with a 2,000-key limit. Other open tabs merge saved keys before deciding which events are new. This browser storage coordination is best effort rather than a distributed exactly-once delivery guarantee.

## Polling and lifecycle

The Scanner panel and the alert monitor share one account-scoped feed in each open tab. Wall activity keeps its existing read while mounted. Alerts retain that feed on other authenticated pages when enabled. Turning alerts off stops their subscription; leaving Wall activity stops its own subscription. The final subscriber cancels outstanding requests and removes polling listeners.

The feed makes at most one scheduled request per minute while the page is visible. It does not overlap requests, observes Retry-After, retries initial network failures and stops automatic retries after authentication or entitlement denial. Outside the market session, the server's next-refresh time controls when polling resumes. Watchlist changes clear retained membership immediately. Account changes clear notifications and cancel the prior account's requests. Local demonstration reads stay separate from the live feed.

There are no additional provider requests, collection jobs, queues or worker processes. The API continues to read compact existing wall and price assessments. No changes to the setup predicates, strength rubric or exposure calculations are required.

Sound uses a short browser-generated tone. It is off initially and must be enabled through a user gesture. After a full reload, Test alert activates saved sound for that visit. In browsers where audio cannot run, on-screen notifications remain available. Notifications require the site to remain open; this batch adds no email, push or operating-system notifications.

## Local review

1. Open `/scanner?scan=activity`. Alert me to new setups starts checked unless that account previously turned it off in this browser. Testing and sound remain unchecked unless saved otherwise. Turn alerts off, reload, and confirm they remain off. Saved preferences survive page changes and reloads.
2. Click Test alert. A labeled example appears at the lower right. Check dismissal, its Wall activity link and the narrow-screen layout. Toggle Sound and click Test alert to check the tone.
3. Open another authenticated page. Wall alerts on remains visible and links back to the controls. Live notifications keep monitoring the saved symbols while that tab is visible. Turn alerts off to stop monitoring outside Wall activity.
4. Preview local demonstration in the Scanner. Historical examples appear in its list without creating live notifications. Live alerts retain their separate current-session feed.

The local review database has no current-session wall captures. Test alert exercises the visible notification; focused tests exercise new confirmations, testing, expiry and polling with controlled time. Production configuration and data collection are unchanged.

## Release and validation

Normal PHP and frontend deployment is sufficient. No environment variables, migrations, packages or subscriptions are added. This batch is prepared locally for manual review.

Fifty focused frontend tests passed across alert identity and freshness rules, optional testing, account-scoped preferences, actual layout injection, shared polling, request cancellation, rate-limit backoff, local demonstrations and existing Scanner behavior. Thirteen PHP tests passed with 67 assertions, covering stable event identities, access, scope, freshness and read-only projection behavior. The frontend build, scoped PHP formatting and authenticated local HTTP checks passed. Browser automation could not connect, so visual placement and sound remain manual review checks.
