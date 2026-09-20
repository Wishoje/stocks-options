# Acquisition and activation measurement

## Audit result

The repository tracks visits and several frontend funnel actions. It does not establish confirmed trial activation, confirmed paid activation, or first useful dashboard use. Baseline rates are **not available** from source code. GA property access and production validation remain pending; no measured conversion lift or baseline is claimed.

This batch documents the existing contract and gaps. It does not change tracking code, event names, consent behavior, billing, offers or existing event consumers. The local component gallery sends no analytics events and does not load GA.

## Event dictionary

| Step | Current event / source | Meaning, denominator and deduplication |
| --- | --- | --- |
| Page view | `page_view` / `resources/js/lib/ga.js`, `app.js` | Runs globally for Inertia pages, not only acquisition pages. It is a page view, not a unique visitor. The wrapper suppresses consecutive identical URLs in one runtime; a full reload resets that guard. Define landing visits by an allowlist of public entry paths before using eligible sessions as the acquisition denominator. |
| CTA click | `hero_cta_click` / Home, MarketingLayout | Named placement and destination. Repeated clicks remain interactions; count unique eligible sessions with a click when computing funnel rates. Never count as a trial. |
| Pricing view | `pricing_view` / Pricing mount | Source `pricing_page`. On a direct/full document load, this mount event is currently dropped because the app initializes GA after mounting Vue. It can fire after an Inertia visit once GA is present. Fix and validate initialization before using it as pricing reach. |
| Plan selected | `plan_select` / Home, Pricing | Plan, billing period, source, next step. Count sessions with selection divided by eligible pricing/offer sessions. Selection is intent only. |
| Registration opened | `register_start` / Register mount | Source and plan/billing handoff. This is also dropped on a direct/full document load by the current initialization order. Query-derived values need validation. Form view is not account creation. |
| Registration completed | `sign_up`, `register_complete` / Register successful submit | Both names describe one successful registration. Use `sign_up` as the canonical funnel event; retain the legacy event for existing reports, never sum them. Server-confirmed registration ID is the proposed durable deduplication key for a future collector. |
| Checkout requested | `checkout_start` / Home, Pricing, MarketingLayout | Client requested checkout navigation for already-authenticated users. The post-registration redirect to `/checkout` does not emit it, so it does not cover every checkout request. It does not prove Stripe opened or billing succeeded. Keep its existing meaning. If adding GA's recommended `begin_checkout`, define the true checkout stage first and keep old reports separate. |
| Checkout canceled | `checkout_canceled` / Pricing query flag | Intended as a return-page signal, not trusted billing truth. The Stripe return is a full document load, so the current initialization order drops the event. Detection uses substring matching for `canceled=1`, which can produce false positives after initialization is fixed. Report separately from server-confirmed outcomes. |
| Checkout activating | `checkout_activating` / Pricing query flag | Intended as a pending-return signal. It is currently dropped on the full document return, and its substring flag check can produce false positives. It cannot count as an active subscription or trial. |
| Confirmed trial | Missing acquisition event | Source must be a verified server billing transition to an active trial. Proposed durable key: provider subscription ID + trial-start transition. Count each eligible account's first confirmed trial once, including webhook retries and refreshes. |
| Confirmed paid activation | Missing acquisition event | Source must be verified first successful paid billing activation. Proposed durable key: provider invoice/transaction ID, scoped to first paid activation. Separate recurring renewals, trial creation, canceled/incomplete attempts and refunds. |
| First useful dashboard session | Missing event | Proposed task: a user explicitly selects a symbol, a requested tab reaches ready with usable data, and the user inspects a reading by click, touch or keyboard. Do not count loading, an empty/error response, background prefetch or onboarding dismissal. Record once per account for activation analysis without sending symbol or watchlist content. |

The later UI work keeps a browser-only `first_useful_reading` diagnostic and adds an authenticated server event with the same interaction rules. Use the server row, deduplicated by account and event type, for account activation analysis. The local-storage event remains browser diagnostic data.

For any future server success collector, persist deduplication server-side and use an opaque event key. Client memory or local storage cannot guarantee exactly-once billing success. Verify server state and transitions; do not derive paid/trial events from `?activating=1`, CTA clicks, or repeated page props.

GA documents recommended event names and ecommerce stage semantics in its [event reference](https://developers.google.com/analytics/devguides/collection/ga4/reference/events) and [ecommerce guide](https://developers.google.com/analytics/devguides/collection/ga4/ecommerce). A purchase transaction identifier supports purchase deduplication. These references do not establish that the application's current custom events implement those semantics.

### Current parameter schema and target allowlists

| Event | Parameters observed in source | Target allowed values before validation |
| --- | --- | --- |
| `page_view` | `page_location`, `page_path`, `page_title` | Redacted, application-owned path and title. Acquisition entry-path allowlist: `/`, `/features`, `/pricing`, `/register`. Report `/contact` as support traffic. Drop auth tokens, email, symbol, billing session IDs and unreviewed query parameters. |
| `hero_cta_click` | `location`, `destination` | Location: `home_hero_primary`, `home_hero_secondary_pricing`, `home_hero_offer_pricing`, `home_pricing_section_link`, `home_scanner_primary`, `marketing_nav_start_free`. Destination: `pricing`, `register_with_plan`. |
| `pricing_view` | `source` | `pricing_page`. |
| `plan_select` | `plan`, `billing`, `source`, `next_step` | Plan: `earlybird`; billing: `monthly`, `yearly`; source: `home_pricing`, `pricing_page`; next step: `register`, `checkout`. |
| `register_start` | `source`, `plan`, `billing` | Source: `register_page`; plan: `earlybird`, `none`; billing: `monthly`, `yearly`, `none`. Current code does not enforce these values. |
| `sign_up`, `register_complete` | `method` | `email`. These are aliases for one successful registration. |
| `checkout_start` | `plan`, `billing`, `source` | Plan: `earlybird`; billing: `monthly`, `yearly`; source: `home_pricing`, `pricing_page`, `marketing_nav`. Current marketing-nav query values are not validated. |
| `checkout_canceled`, `checkout_activating` | `source` | `pricing_page`; exact return parameter must equal `1` after the initialization issue is fixed. |

Do not add free-form fields to this contract. Review any new event or value against the privacy rules and existing report consumers first.

## Existing implementation gaps

| Finding | Evidence | Follow-up before measuring a redesign |
| --- | --- | --- |
| Initial component events are dropped on full loads | `app.js` mounts before `initGA`; Vue mount hooks run during mount; the wrapper drops calls when `gtag` is unavailable | Establish a consent decision, then initialize or queue before relevant mount events. Verify direct/full loads and Inertia navigation separately. This affects `pricing_view`, `register_start`, and the normal checkout return flags. |
| Two registration names for one action | Register success invokes `sign_up` and `register_complete` | Keep both histories but define one canonical success metric. Confirm form error/retry does not create extra completed accounts. |
| Page URLs and logs are unsanitized | Global `trackPageView` sends and console-logs the full URL/path. Examples include `/reset-password/{token}?email=...` and `/dashboard?symbol=...` navigation from Scanner. | Redact sensitive path segments, drop unapproved query parameters and remove raw URL logging. Preserve only reviewed attribution parameters. Audit automatic/enhanced measurement as well as the custom wrapper. |
| Query-derived event values are unchecked | Register and marketing checkout read plan/billing from query | Restrict event values to known plan/period enums; never forward arbitrary user text. |
| Checkout return flags use substring tests | Pricing checks whether the URL contains `canceled=1` or `activating=1` rather than reading an exact parameter value | Parse the query and require an exact key/value after resolving the initialization problem. |
| Billing success is not measured durably | BillingController and subscription props provide application state, not an acquisition event stream | Add verified, deduplicated transition events with test fixtures before publishing paid/trial rates. Lifecycle notification names are not proof of analytics delivery. |
| Consent behavior is not implemented in the inspected client | When a GA4 ID exists, `initGA` injects GA. No in-repository consent gate was found. Property-side consent configuration and traffic filters were not available for review. | Establish the required consent behavior and document property settings before enabling or changing collection. Treat the current behavior as unverified, not as an existing consent rule. |
| No activation, form-error or performance baseline in inspected collector | No corresponding acquisition event found | Define allowlisted error categories and aggregate performance measurements; collect before public-page redesign. |

Do not send names, email addresses, authentication tokens, payment details, form text, watchlist contents or selected symbols. Review campaign parameters too; free-form parameters can contain personal data. Google specifically cautions against personal data in URLs and page titles in its [PII guidance](https://support.google.com/analytics/answer/6366371?hl=en).

## Baseline collection sheet

Use the last 28 complete days before a public-page change. Keep the property's reporting timezone and exclude internal/test traffic consistently after verifying the property settings. Compare the previous 28-day period for seasonality/context. Retain raw counts and note missing instrumentation rather than treating missing values as zero.

For click-path rates, use one analytics session: eligible entry, CTA, Pricing, plan selection and checkout request must occur in that session. Attribute registration completion to a registration-open session when it completes within 24 hours. Attribute a confirmed first trial to registration when the verified trial begins within 7 days. Measure first useful use within 24 hours after confirmed trial start. Measure first paid activation within 30 days after trial start and include only cohorts with a complete 30-day observation window. Use the first eligible public entry in the session for source/device segmentation; any cross-session or cross-system attribution requires a separately approved pseudonymous join and a documented window.

| Measure | Definition | Baseline |
| --- | --- | --- |
| Eligible landing sessions | Sessions entering an allowlisted public acquisition path after documented consent and traffic-filter rules | Pending GA and property-settings access |
| CTA reach | Sessions with a named CTA click / eligible landing sessions | Pending |
| Pricing reach | Sessions reaching pricing / eligible landing sessions | Pending |
| Registration completion | Server registration records joined through an approved pseudonymous attribution method / sessions opening registration. If using GA alone, label the numerator as users with canonical `sign_up`, not unique accounts. | Pending; no durable account/event identifier exists in the current analytics events; reconcile dual event names |
| Confirmed trial start | Unique first confirmed trial accounts / unique completed accounts in the cohort | Missing confirmed event |
| First useful use | Trial accounts completing the defined task within 24 hours / confirmed trial accounts | Missing activation event |
| Paid conversion | Trial accounts with first confirmed paid activation within 30 days of trial start / trial accounts with a complete 30-day observation window | Missing confirmed event; cohort must mature |
| Form errors | Submit attempts with an allowlisted validation/server failure category / submit attempts | Missing measurement |
| Page performance | Landing/pricing/register p75 LCP, INP, CLS by device; sample counts | Pending field measurements |
| Support friction | Aggregate themes and counts for access, data readiness, navigation, billing and interpretation | Pending support review; exclude personal message contents |

Segment by acquisition channel/source, device and new/returning status where available under current consent rules. Preserve the first acquisition source for cohort attribution and report same-session click paths separately. Fix the definitions and timezone before comparing variants. Avoid interpreting small segments without their counts and observation windows.

Set numerical release criteria only after baseline values and sample sizes are available. Guardrails must include registration/checkout failure rates, activation, page performance and support friction. A visual refresh alone does not demonstrate improved conversion.

## Test-environment validation plan

1. Define and implement the required consent behavior first. Then use a test analytics destination and test billing account. Observe the initial Home visit, each CTA placement, Pricing, plan selection, Register, failed form submit and successful registration in DebugView.
2. Compare event names and parameters with the dictionary. Confirm the two registration aliases represent one account. A CTA click or checkout return flag must produce no confirmed trial/paid event.
3. Test internal navigation, reload, slow/blocked analytics script and consent denied. Verify the intended page/session counts and document expected gaps from blocked analytics.
4. Before adding server activation events, test verified trial, first paid invoice, renewal, failed/canceled checkout, refund, duplicate and out-of-order webhooks. Confirm durable deduplication and reconciliation with billing records.
5. Verify first useful use with rapid symbol/tab changes, loading, empty, partial and failed responses. Only the current request and a real reading interaction may qualify.
6. Inspect transmitted payloads for sensitive data, including auth recovery routes, arbitrary query strings and page titles. Validate both automatic GA collection and application calls.
7. Fill the baseline sheet from the property and billing source of truth, record collection dates and filters, then attach the report to UI-02. Repeat the same definitions after UI-22–25 changes.

These live event checks and baseline values have not been completed. They can proceed alongside Batch 2 without treating UI-02 as fully accepted.
