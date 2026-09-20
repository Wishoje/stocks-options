# Batch 13: release and measurement

Batch 13 prepares the UI refresh for one coordinated release. It does not deploy, change a live subscription, send a contact message, or run provider work. Production release remains gated on the manual review and the release checks below.

## Release evidence

The repository now has two complementary measurement paths:

- Browser analytics records privacy-filtered page and funnel interactions. Analytics starts before Vue mounts, so direct Pricing and Register loads can queue their events. Page URLs redact recovery tokens, numeric identifiers, symbols, arbitrary query values, and personal fields.
- `conversion_events` records the first confirmed registration, trial activation, authenticated useful reading, and paid activation from server-owned events. Trial activation is written after Cashier persists the matching subscription. The first-use endpoint accepts only the fixed dashboard inspection contract and stores no symbol or market value. Provider references are protected with a keyed hash, event properties use a fixed allowlist, account deletion removes the account's conversion rows, and unique constraints make retries idempotent.

CTA clicks, plan selections, checkout starts, cancellation returns, and activation-pending screens remain intent or state events. They never count as a confirmed trial or payment. A URL flag cannot create a confirmed conversion. The configured UTC measurement boundary excludes old webhook replays and pre-existing subscription renewals from new activation cohorts.

Run `npm run ui:audit` during local review. Run `npm run ui:audit:release` as a hard production gate. The release mode fails while Batch 8 validation is pending, a verified product capture is missing, legal Markdown lacks approval for its exact hash, a public route is absent, a legacy screenshot is referenced, the support address is inconsistent, or an audited unsupported claim returns.

## Open release gates

1. Complete the Batch 8 local walkthrough. The approved account/settings implementation, authenticated route boundary, and dead-provider cleanup have automated coverage; manual authenticated product and account review remains.
2. Capture the verified local product screens after the authenticated product walkthrough. Replace every pending media entry with desktop and mobile assets, alt text, dimensions, source revision, and recorded-example context.
3. Review and approve the drafted Terms and Privacy copy. Confirm the operator disclosures, dispute choices, refund position, analytics settings and regional consent needs, and retention schedule listed in `legal-approval.json`, then record approval for the exact hashes.
4. Complete [manual-review.md](manual-review.md) at desktop, tablet, and mobile widths, including keyboard and reduced-motion checks.
5. Validate analytics in a test GA property and billing in Stripe test mode. Confirm that automatic/enhanced measurement does not reintroduce sensitive URLs.
6. Run the full PHP and frontend suites, the production build, the review audit, and the release audit against the exact staged revision.
7. Validate the authenticated first-use endpoint in the release environment. Confirm repeated inspections retain one server row per account and that loading, errors, page loads, guidance dismissal, and background fetches create none. The browser event remains diagnostic.

No conversion lift is claimed before deployment. Baseline and post-release windows must use the same definitions, filters, reporting timezone, and mature billing cohorts described in [measurement.md](measurement.md).

## Release boundary

All UI batches are intended to ship together after review. Do not publish new marketing captures before the corresponding authenticated screen is accepted. Do not deploy unapproved legal copy, enable two-factor authentication, start live provider jobs, or add a new billing offer as part of this visual release.

The additive conversion table can remain in place during a code rollback. Dropping it would erase measurement evidence and is not part of the normal rollback path. The table migration must run on the prepared release before any request, webhook, or worker uses the new listeners.
