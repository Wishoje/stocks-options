# Direct Scanner assessments

Opening `/scanner` defaults to Wall activity. Explicit `scan=gex` and `scan=volume` links retain Near walls and Options volume. Activity mode reads its existing aggregate feed without starting either of those scans.

Each activity row identifies the symbol, wall, completed-close time and price response. Pending rows show the same backend next condition as wall tracking, including a completed-close count when recorded. Qualifying rows show a bullish or bearish scenario, entry area, invalidation close and reference target. The existing ordering, filters and incremental display remain in place.

View assessment opens the matching symbol and recorded assessment above its wall chart. Every valid interaction reading now has an evidence-derived ID, so a repeated status can be opened even when it created no new event icon. Existing event IDs and alert identities retain their original calculation. IDs include the wall identity, evidence bar, interaction status and rule version. Corrected evidence produces a different ID.

The tracker resolves `wall_assessment` against today's loaded readings. It adds no historical-session request. If the reference cannot be resolved, it displays an explicit notice and keeps the latest current-session view. Back to latest clears the reference. Changing symbol or leaving wall tracking removes it from dashboard navigation. Event selection, setup-time inspection and supporting bars use the same selected reading.

The feed projects a next condition only when its setup rule version, wall identity, observation time and setup status match. Expired pending windows lose their close progress; a setup that fails current eligibility cannot contribute a Conditions met explanation. Older saved records without the explanation use general watch text. The projection still uses three uncached queries, retrieves no full contract chains or bar histories, and performs no provider requests, writes or job dispatches. Collection cadence, queues, subscriptions and environment settings remain unchanged.

## Local review

1. Open `http://127.0.0.1:8000/scanner` without a `scan` parameter. Wall activity should be selected. Use Preview local demonstration to review the three labeled synthetic examples when today's local feed is empty.
2. Check the SPY direction, entry, invalidation and target, and the next-condition text on the other rows. View assessment should open the matching symbol, wall and recorded time. View supporting bars should show that assessment's evidence.
3. Return to latest and change symbol. The old assessment reference should not reopen or carry over. A reference unavailable in today's timeline should show the latest view with a clear notice.
4. Switch Scanner to Near walls and Options volume. Explicit links and their existing controls should still work. Returning to Wall activity should stop the other mode's in-flight work.
5. Review at a narrow width. Row details and entry values should wrap without horizontal page overflow.

Sixty focused PHP tests passed with 324 assertions. One hundred six frontend tests passed across Scanner, watchlist activity, wall tracking, assessment summaries, dashboard navigation and alerts. The frontend build and scoped formatting checks passed. Authenticated local HTTP checks returned 200 for Scanner and verified that all three synthetic rows match their chart's symbol, wall, time and next condition. Browser automation could not bind the local Chrome tab because the CDP focus command timed out; visual review remains manual. Release uses the normal application and frontend deployment with no additional configuration.
