# Direct setup alerts

Setup notifications show the symbol and named bullish or bearish scenario, wall, recorded market time, completed close, entry area, five-minute invalidation boundary and reference target. All levels are underlying prices. The message identifies the delay and asks the user to check current price against the recorded entry area. Scanner and alerts share the same scenario wording; an unrecognized pattern receives a general setup label rather than an invented price sequence.

View assessment carries the original row's `assessment_id`, so it opens the recorded assessment that caused the notification. It does not replace that reference with a later repeated reading of the same setup. The tracker explains when a reference is unavailable rather than silently claiming a matching record. Testing alerts keep a separate pending-confirmation message and display no entry levels.

Test alert renders a bullish-bounce example using clearly labeled synthetic underlying prices. It bypasses no live alert eligibility check and sends no market event through the alert engine. Its link returns to Wall activity. The preview remains in memory and expires under the existing notification timer.

Notification timing, defaults, saved preferences, deduplication, sound, expiry and eligibility remain unchanged. The update adds no API calls, provider requests, collection jobs, migrations or configuration settings. Scenario text and numerical levels come from the existing aggregate feed. An unavailable invalidation comparison is not guessed for the level display.

The stack retains three notifications and scrolls within the available viewport height. Its stable polite live region does not move keyboard focus. An empty stack does not intercept page input.

## Local review

1. Open `http://127.0.0.1:8000/scanner`. In Wall activity, click Test alert.
2. Review Test alert · SPY · Bullish bounce. Confirm the example label, put wall 765, entry 765.3825–766.1475, invalidation below 764.6175 and target 770. These are synthetic review values.
3. Click Open Wall activity or dismiss the example. Review a narrow window and the scroll behavior when multiple real alerts are present.
4. Real setup alerts require a newly qualifying current-session assessment after monitoring starts. Existing events and local demonstration rows remain silent. A testing alert requires the user's separate opt-in.

Ninety-six focused frontend tests passed across alert presentation, eligibility, shared polling, Scanner, assessment navigation and wall summaries. The frontend build passed. Coverage checks bullish reclaim and bearish rejection labels, exact recorded values and links, preview labeling, testing without entry levels, expiry, opt-outs, account changes, duplicate suppression, rate limits and demonstration isolation. Browser automation could not bind the local Chrome tab in prior checks because its CDP focus command timed out; desktop and mobile visual review remain manual.
