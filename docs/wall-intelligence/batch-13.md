# Scanner wall activity

Scanner → Wall activity shows **Which symbols need attention?**. It lists recent price reactions at the current walls for the signed-in user's saved symbols. The view shows All saved symbols and has no single-symbol chart. Setups whose conditions passed appear first and include the underlying-price entry area, invalidation close and reference target. Passed price-confirmation, wall-persistence and target-room checks appear beside those setups. Other rows describe reactions while entry confirmation remains pending.

View assessment links to Dashboard → Intraday → Wall tracking for that symbol, recorded assessment and the 1W scope. If the assessment is unavailable in today's timeline, the page explains this and shows the latest view. Wall tracking contains only that symbol's timeline and setup details. It starts no calculator or collection work. Six rows appear initially; Show more and the Conditions met filter use the loaded response. The component mounts only in Scanner's Wall activity mode and stops polling when the user leaves that mode. The dashboard retains its three symbol-specific intraday tabs.

Scanner has three modes: Wall activity, Near walls and Options volume. The existing proximity and volume modes keep their calculations, controls and explicit URLs. New visits to `/scanner` without an explicit mode default to Wall activity. Wall activity does not eagerly load those scans. Switching modes cancels in-flight work from the mode being left, and late results cannot overwrite the active mode. Normal assessment links contain no demonstration parameters; local example links carry an event ID, which the tracker honors only when the server permits local demonstrations.

## Data and scope

`GET /api/intraday/wall-watchlist` requires authentication, intraday entitlement and the existing market-data read throttle. It reads only the requesting user's watchlist. The feed is fixed to today's regular market session and the existing 1W model. Other users' symbols, older sessions and other expiry scopes cannot supply rows. There are no provider calls, database writes or queue jobs on a read.

An uncached response uses three database queries: saved symbols, latest modeled walls and latest saved interaction assessments. The two projections retrieve compact current fields instead of full price-bar histories and strike arrays. Indexed seeks select the latest observation time and then the latest revision ID. The assessment scope uses the indexed key written by the existing collector. Projections are cached privately for 30 seconds; watchlist membership is checked on every request.

Each reaction must match the current wall's strike, basis, scope, quote source and revision. The modeled wall must meet the existing delayed-quote age limit. The assessment must fall within ten minutes of the completed-price cutoff. The cutoff preserves the existing minimum 15-minute price delay. A qualifying setup also needs the current rule version, matching assessment and wall identity, recorded eligibility, valid levels and an unexpired entry window. Entry boundaries match the setup evaluator. At the regular or early session close, the feed stops displaying current setups.

The browser refreshes once per minute while the view is visible, prevents overlapping requests, respects rate-limit backoff and cancels requests when the panel unmounts. Watchlist changes clear the previous membership immediately. A small local clock removes expired qualifying rows between polls. Assessment times remain visible in ET. Conditions met describes a recorded delayed close; it does not claim a current market entry.

Daily OI changes and IV/skew remain separate supporting context. They are not mixed into an unvalidated confidence score. The watchlist reuses the existing setup evaluator's confirmation and price-geometry checks.

## Local review

1. Open `http://127.0.0.1:8000/scanner?scan=activity`. The feed has its own Scanner mode and shows All saved symbols. There is no single-symbol wall chart in this view.
2. Select Preview local demonstration. Three labeled synthetic historical examples use the existing wall demonstration: a qualifying SPY setup, QQQ approaching and TSLA testing. Review the SPY entry, invalidation, target and passed checks.
3. Click View assessment. Wall tracking opens the example's symbol and recorded assessment, with a separate link to its supporting bars. Use Scanner → Wall activity to return to the feed. A normal activity row opens its assessment within today's timeline. Return to today's timeline exits the local example and removes its URL parameters.
4. Filter to Conditions met, return to All activity and inspect the layout at a narrow width. Edit the saved watchlist to confirm its membership updates. Switch to Near walls and Options volume to check their existing filters and results. Leaving Wall activity stops its feed polling.

The local example is unavailable in production. The local review database has no current-session captures, so its normal feed waits for assessments. Production reads were tested in memory without deploying code or changing configuration, cache, collections or queues.

The watchlist endpoint bypasses the local EOD review clock, as the wall timeline and IV endpoints do. The response keeps today's session date even when EOD review is fixed to an earlier date.

## Release

This batch needs the normal PHP and frontend deployment. It adds no environment settings, subscriptions, packages, migrations, schedules or worker processes. Code is prepared locally for manual review.

## Validation

Thirty-six focused PHP tests passed with 193 assertions across the watchlist endpoint, interaction detector and collector. Eighteen clock and watchlist tests passed with 86 assertions after the local-clock correction. Sixty-four focused frontend tests passed across Scanner, watchlist activity, wall navigation, URL restoration and the tracker. Coverage includes ownership, entitlement, scope, revisions, late older inserts, stale and future data, entry boundaries, expiry, market close, request cancellation, filtering, rate-limit backoff, existing scanner calculations and server-gated local examples. The frontend build, PHP formatting and authenticated local HTTP checks passed. The local example's qualifying row reconciles with its chart event and setup levels.

A read-only production probe used the current owner's 95 saved symbols. After replacing session-wide aggregation with indexed latest-record seeks, one uncached read took 969 ms across three queries, compared with an initial 2,801 ms. These are individual observations under changing production load, not a throughput guarantee. The probe used an in-memory cache and performed only SELECT queries.
