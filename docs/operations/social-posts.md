# Social post operations

The owner-only page is `/admin/social`. It provides independent SPY, QQQ, and TSLA draft choices, PNG/source downloads, approval, and a confirmed Send now action. Subscribers cannot use these routes.

## Daily preparation and automatic posting

Every calendar day between 08:30 and 08:55 America/New_York, the scheduler prepares fresh drafts for all three symbols for the current or next opening session. It reads the latest completed EOD source required for that session. Weekends reuse the last completed market session and retain its actual date. It does not precompute an entire week. An existing unapproved scheduler draft can refresh the next day; owner edits, approval, publication, and ambiguous submissions are preserved.

Only SPY is eligible for automatic posting: Sunday, Tuesday, and Thursday at 08:45 AM ET, with a ten-minute execution window. Sunday prepares Monday. If Monday is a market holiday, the Sunday automatic post is skipped. Tuesday/Thursday market holidays are skipped too. There are no late catch-up sends. New York timezone rules handle daylight saving time.

SPY scheduled drafts can be approved automatically under the owner's standing authorization. Every selected expiry must use the previous completed EOD source. Complete inputs qualify. Missing gamma alone can qualify when known affected open interest is at most 1% of total open interest. Unknown coverage, missing prices/open interest, missing expirations, mixed dates, and larger gaps require manual review. The open-interest fraction is not a measure of missing GEX. Source-quality flags remain unchanged and the acknowledgment records the policy and affected share.

QQQ, TSLA, and SPY drafts on other days are never automatically approved or sent. The owner can approve them and choose Send now. An existing manually prepared draft is not silently reapproved by the daily job.

## Configuration

Both web and worker use matching configuration:

- `SOCIAL_ADMIN_IDS`: explicit owner allowlist.
- `SOCIAL_SCHEDULE_ENABLED=true`: daily preparation and scheduled dispatch.
- `SOCIAL_PUBLISHING_ENABLED=true`: allows X writes after the applicable checks.
- `SOCIAL_AUTOMATIC_SPY_ENABLED=true`: enables standing approval of qualifying scheduled SPY drafts.
- `SOCIAL_AUTOMATIC_OWNER_ID`: allowlisted owner whose standing authorization is recorded.
- `SOCIAL_QUEUE_CONNECTION` and `SOCIAL_QUEUE`: consumed queue connection/name; sync is supported.
- X OAuth 1.0a credentials: `X_CONSUMER_KEY`, `X_CONSUMER_SECRET`, `X_ACCESS_TOKEN`, `X_ACCESS_TOKEN_SECRET`. Never commit these values. Account verification must return @GexOptions.

The existing minute-level Laravel scheduler invokes `social:tick`. Refresh config caches on both hosts after configuration changes. The admin pause control stops preparation and publishing. The additive `scheduled_prepared_on` migration tracks scheduler-owned daily drafts without altering historical market data.

## Review and immediate send

Generate the desired symbol for a current/upcoming trading session. Compare its snapshot date, expiry scope, and metrics against the next-session dashboard. Review text and image; acknowledge disclosed missing inputs if accepting an available-data chart. Approval is bound to the current saved data and image. Saving an edit revokes approval and automatic-refresh ownership.

Send now requires an approved draft and a matching review token, then sends immediately after an explicit UI confirmation. It supports all three symbols outside scheduled slots, using the current session during regular hours or the next opening session otherwise. It rejects stale dates and disabled/paused publishing. Historical local-review sessions cannot publish.

Images remain 1600x1000 PNGs using the dashboard's exact published GEX response. Totals use every returned strike; chart focus retains at least 98% of absolute exposure. Dates and source scope remain visible. Diagnostics remain in admin/JSON, and are omitted from PNGs. Neither manual acknowledgment nor automated approval manufactures missing gamma values.

## Duplicate and failure handling

Publication atomically claims only approved records. Published drafts are skipped by scheduled dispatch, including Sunday posts for Monday. If X does not confirm a submission, status becomes needs_review and no automatic retry occurs. Preparation failures revoke approval. A stopped publication job becomes needs_review after five minutes. Check X before reconciling an uncertain submission.

Existing legacy secondary records are reused by symbol/session so independent QQQ/TSLA selection does not recreate their published posts. No live post is part of automated tests; X writes are mocked.
