# Conversion measurement after release

## Event contract

| Event | Authority | Meaning |
| --- | --- | --- |
| `page_view` | Browser | A sanitized application route view. Consecutive duplicate paths in one runtime are suppressed. |
| `hero_cta_click` | Browser | A named fixed CTA placement was activated. It is engagement, not conversion. |
| `product_preview_select` | Browser | A visitor chose a public product-preview tab. Report separately from conversion. |
| `pricing_view` | Browser | Pricing mounted after analytics transport was installed. |
| `plan_select` | Browser | A normalized earlybird monthly/yearly choice and intended next step. |
| `register_start` | Browser | Registration opened with a normalized plan and billing choice. |
| `sign_up` | Browser | Canonical browser registration-complete observation emitted on the same-origin handoff. It is diagnostic until reconciled with the server registration row. |
| `register_complete` | Browser | Legacy alias for the same handoff as `sign_up`. Retain for existing reports and never add the two events together. |
| `checkout_start` | Browser | An authenticated user requested checkout. It does not prove Stripe opened or payment succeeded. |
| `checkout_canceled` | Browser | Exact `canceled=1` return state. |
| `checkout_activating` | Browser | A server-recorded activation check is pending. |
| `subscription_activation_confirmed` | Browser | The browser observed an active local subscription after an immediate or delayed return. It is a state/UX diagnostic and never replaces the server trial or paid event. |
| `registration_confirmed` | Server database | The first Laravel `Registered` event for an account. |
| `trial_activation_confirmed` | Server database | The first active/trialing Stripe subscription-created webhook with a future trial end, recorded only after Cashier has persisted the matching local subscription. |
| `paid_activation_confirmed` | Server database | The first positive paid-invoice webhook for a qualifying post-launch subscription. Renewals cannot create a second first-paid event, and pre-existing subscriptions are excluded from the new cohort. |
| `first_useful_reading` | Browser diagnostic | A usable current dashboard response plus an explicit reading inspection. The local-storage marker remains useful for browser debugging, but it is not the account-level authority. |
| `first_useful_reading` | Server database | The first authenticated, subscribed dashboard account to report the fixed `reading_inspected` interaction after the measurement boundary. The database uniqueness contract records it once per account. |

The server table is the authority for account, trial, first-use, and paid counts. Its `authority` value identifies the trusted collector, such as Fortify, the authenticated dashboard, or a Stripe webhook; it is not an acquisition source. Browser analytics supplies session, page, source, device, and interaction context. There is no approved user-level join between the two stores, so confirmed server conversions can be reported as overall and monthly/yearly cohorts only. Do not segment confirmed conversion by browser acquisition source or device, add the two registration client aliases together, or treat a browser event as billing truth.

## Privacy and deduplication

- Browser event parameters use fixed keys and short fixed values. Names, email, messages, account IDs, symbols, strikes, expirations, watchlists, tokens, sessions, and payment fields are rejected.
- Page paths redact password-reset and verification tokens and numeric path identifiers. Only known plan/billing/status values and a small fixed acquisition-source list survive in custom page URLs.
- Provider references are never stored directly in `conversion_events`. The event key uses HMAC-SHA-256 with the application key.
- The database enforces one event type per user and one event key. Listener retries and duplicate Stripe webhooks remain idempotent.
- Deleting an account cascades to its pseudonymous conversion rows. Aggregate conversion reports must disclose that deleted accounts are absent from retained server cohorts.
- `CONVERSION_MEASUREMENT_STARTED_AT` defines the UTC cohort boundary. Registrations, trial webhooks, and authenticated first-use inspections before it are ignored. A paid invoice qualifies only when the matching local subscription was created inside the measurement window or the account already has a qualifying post-boundary trial activation. This prevents the next renewal for a pre-existing subscriber from being mislabeled as a new paid activation.
- Test GA automatic/enhanced measurement separately. The custom sanitizer cannot protect events collected outside this wrapper.
- Use the server row with `authority=authenticated_dashboard` for account-level first-use reporting. The browser event remains diagnostic and must not be used as the account numerator. Join the server event only to an eligible confirmed-trial cohort and apply the defined 24-hour window.

## Baseline and comparison

Use the last 28 complete days before release and the first 28 complete days after release. Keep the GA reporting timezone, consent rules, internal-traffic filter, route allowlist, and event definitions unchanged. Show raw numerator, denominator, sample size, and missing-data rate before showing a percentage. Browser-only journey reports may include device and acquisition-source segments. Server-confirmed trial and paid cohorts may include normalized plan and billing cadence; they must remain unsegmented by browser source/device until a separately reviewed, consented join exists.

Use these windows:

- Same session for landing, CTA, Pricing, plan selection, and checkout request.
- Registration within 24 hours of registration start.
- Confirmed trial within 7 days of registration.
- First useful dashboard use within 24 hours of confirmed trial uses the authenticated server event. The browser diagnostic may be reported separately without an account conversion denominator.
- First paid activation within 30 days of trial start, using only cohorts with a complete 30-day observation window.

Primary measures available at release are eligible landing sessions, Pricing reach, registration rate, confirmed trial rate, first-use activation for mature 24-hour trial cohorts, and mature paid conversion.

The guardrails below are planned measurement work. Do not report a value until its named source and query have been approved and validated. A pending guardrail has no release collector or repeatable query in this repository.

| Guardrail | Owner | Data source and query | Release status |
| --- | --- | --- | --- |
| Registration form error rate | Product analytics and frontend engineering | Pending: define a privacy-reviewed fixed error-category event and a GA query using form submissions as the denominator. No collector or approved query exists yet. | Pending source, event definition, and validation. |
| Checkout error rate | Billing engineering and product analytics | Pending: define server-side failure categories for checkout-session creation and Stripe redirect failures, then compare failures with eligible checkout requests. Existing browser journey events do not record these failures. | Pending source, event definition, and validation. |
| Critical route failures | Application engineering | Pending: select the production exception/APM source, define the critical route allowlist, and save a query grouped by route and response class. | Pending source, allowlist, and query. |
| p75 LCP, INP, and CLS by device | Frontend engineering and product analytics | Pending: select and configure a consent-aware real-user monitoring source. No Web Vitals collector or approved percentile query exists yet. | Pending source, collector, and validation. |
| Support-friction themes | Product and support operations | Pending: select the support system, approve a theme taxonomy, and define a weekly tagged-case query. No support connector or repository query exists yet. | Pending source, taxonomy, and access. |
| Data-unavailable rate | Product analytics and application engineering | Pending: define fixed unavailable-state categories and a privacy-reviewed collector, then divide unavailable responses by eligible product-data requests. Current UI states are not emitted as analytics events. | Pending event definition, collector, and validation. |
| Duplicate conversion events | Application engineering and product analytics | Server source: `conversion_events`; uniqueness constraints cover user/event type and event key. Validate with grouped counts by `user_id, event_type` and separately by `event_key`, each using `HAVING COUNT(*) > 1`. Browser duplicate checks still require an approved GA query for the fixed journey events. | Server check available at release; browser query pending. |

Do not infer that the redesign caused a change from a simple before/after comparison. Report source mix, device mix, seasonality, incomplete cohorts, and confidence limits. Create follow-up work only for a concrete observed failure or friction pattern.

## Validation before production

1. Use a test GA property and Stripe test mode.
2. Set a test `CONVERSION_MEASUREMENT_STARTED_AT`, then prove that a pre-boundary subscription renewal creates no conversion row while a new post-boundary trial and first positive invoice do.
3. Inspect a direct Home, Pricing, Register, reset-token, and dashboard URL. Confirm emitted page locations contain no token, email, symbol, or arbitrary campaign value.
4. Exercise CTA, period selection, registration, canceled checkout, delayed activation, confirmed trial, duplicate webhook, zero-dollar invoice, first positive invoice, and renewal.
5. Confirm query flags create only client state events. Confirm server rows appear only for verified transitions and remain one per account/type.
6. Compare server counts with test billing records. Then approve the production measurement start date and observation window.

Production baseline values remain pending GA/property access and a defined consent configuration. Missing values must remain pending rather than being entered as zero.
