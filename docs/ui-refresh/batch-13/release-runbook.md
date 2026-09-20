# UI refresh release runbook

## Before deployment

The owner approved production release on September 20, 2026, including the pending approval items. The exact legal drafts are approved in `legal-approval.json`. Batch 8 retains its automated verification status and records an explicit release exception for the unfinished manual checklist. This acceptance does not claim that unperformed manual checks passed.

1. Finish every open gate in [README.md](README.md), including every verified check in the Batch 8 [validation manifest](../batch-8/validation.json), verified product captures, approved legal copy, and signed manual review.
2. Confirm the staged diff contains only the reviewed UI refresh and its tests, migrations, documentation, and assets.
3. Run the complete frontend and PHP suites, production build, `npm run ui:audit`, and `npm run ui:audit:release` on that exact revision.
4. Inspect `php artisan migrate --pretend` for the additive `conversion_events` migration. Back up the production database through the normal operating process.
5. Set `CONVERSION_MEASUREMENT_STARTED_AT` to the approved UTC launch boundary. Confirm it is later than all subscriptions classified as pre-existing and is identical on every web and worker process.
6. Run `php artisan billing:verify-plan-config --mode=test` against test credentials, then `php artisan billing:verify-plan-config --mode=live` against the production read credentials. Confirm each configured price is active and its currency, amount, cadence, and mode match the public display configuration. The command reports the configured trial length separately because the application applies it during Checkout Session creation.
7. Record the current production revision, built asset manifest, public-route responses, representative API counts, and rollback revision.

## Deployment

Use the established atomic release process or a maintenance window. Prepare the new release and run its additive `conversion_events` migration before any web request, webhook, queue worker, or PHP process can execute the new listeners. Switch traffic and workers to the reviewed code and built assets only after the migration succeeds. Do not use the UI review clock or the local four-symbol database in production.

The registration and billing listeners are synchronous. Activating the code before the table exists can fail a registration or webhook, so migration-before-traffic is a mandatory release condition. Keep the approved measurement boundary in place during rollback or retry; moving it changes cohort membership.

## Production smoke check

Use non-destructive actions only:

- Home, Features, Pricing, Contact, Terms, Privacy, Login, and Register return the expected pages and metadata. View Source for the four public marketing routes contains route-specific title, description, canonical, Open Graph, and Twitter fields before JavaScript runs.
- Signed-out public CTAs route to the normalized plan selection. Signed-in subscribers reach Dashboard; signed-in accounts needing checkout reach Pricing/checkout.
- Dashboard loads one known supported symbol. Spot-check every EOD and intraday tab for scope, dates, units, raw-detail reachability, and API row-count parity.
- Scanner and Calculator open without provider priming. Account/Profile loads without a billing action.
- Keyboard, touch, mobile navigation, selected chart detail, reduced motion, and the published screenshots match the reviewed version.
- GA DebugView contains sanitized expected events without duplicates. Listener registration and webhook logs are healthy, URL flags create no server conversion rows, and the first legitimate post-boundary conversion reconciles to its billing record. Synthetic webhook creation is completed in staging/test mode before cutover and is never added to the production cohort.
- Error logs, queue depth, request rate, response latency, and browser console remain within the recorded baseline.

## Rollback

Rollback the application code and built assets to the recorded revision if a critical route, authentication, billing, data-parity, accessibility, or performance regression appears. Leave the additive `conversion_events` table in place so already-recorded evidence is retained. Disable or detach the new listeners only through the rolled-back code. Verify the previous public and authenticated smoke paths after rollback, then document the trigger and affected cohort.
