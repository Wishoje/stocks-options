# Batch 11: pricing, checkout, support, and legal routes

Batch 11 makes the public offer come from `config/plans.php`, carries a normalized monthly or yearly choice through authentication, and treats Stripe's return as pending until the local Cashier subscription state confirms access.

## Implemented contracts

- `BillingIntent` stores only configured plan and billing keys. It also keeps an allowlisted product return path.
- Guest login and registration preserve an explicit pricing choice through Laravel's intended URL.
- Successful registration first lands on a one-time, same-origin handoff. That server-confirmed page records the canonical sign-up event before a document navigation opens Stripe.
- Checkout uses the configured Stripe price ID and public display amount for the same plan. The UI shows “Price unavailable” if display configuration is incomplete; it does not invent a monetary fallback.
- A 20-second atomic cache marker is scoped to the account and subscription name, so simultaneous monthly and yearly requests cannot create separate sessions. A later click or second tab reuses the same hosted Checkout Session only for the same selection. A different selection returns to pricing without replacing the active attempt. The cancel return clears it before a new session can start.
- A checkout attempt is bound to the authenticated user, a random return token, and a hash of the created Checkout Session ID. Its expiry follows Stripe's Checkout Session expiry when provided. Invalid returns do not consume a valid proof; valid proofs are single use.
- Inertia checkout navigation returns a 409 `X-Inertia-Location` response for Stripe. Normal document navigation keeps Cashier's 303 redirect.
- A valid checkout return is consumed once. Access and conversion UI wait for `subscribed()` or a subscription trial in local Cashier state. A generic application trial still grants product access but continues to checkout.
- Delayed activation polls the authenticated `/billing/status` endpoint. Query parameters alone never grant access or produce a confirmed activation event.
- Pricing, contact, terms, and privacy pages use the shared dark public presentation and complete canonical, description, Open Graph, and Twitter metadata.
- Contact validation, throttle, recipient, and success status remain unchanged.
- Terms and privacy routes render repository Markdown without enabling Jetstream terms acceptance.

## Preserved data and security

- Stripe price IDs remain the checkout authority.
- Public amounts remain integer minor units in `config/plans.php`.
- The authenticated billing routes, Cashier portal, cancel, and resume behavior remain in place.
- Return destinations accept only known product paths and safe dashboard query keys.
- Checkout Session IDs and attempt tokens are stored as hashes in the proof. The active hosted Checkout URL stays only in the authenticated server session until cancel, completion, or expiry so duplicate clicks can reuse it.
- No market symbol, strike, expiry, watchlist, email, or payment value is sent in client analytics.

## Validation

- PHP syntax validation passed for every Batch 11–12 PHP file.
- Focused PHPUnit: 20 tests and 122 assertions completed; 19 passed and the disabled-registration branch was skipped because registration is enabled.
- Focused Vitest: 38 tests passed across pricing, journey, authentication, first use, EOD strikes, and intraday flow.
- The production Vite build passed with Node 20.

## Release gates

- `resources/markdown/terms.md` and `policy.md` contain behavior-based drafts dated September 18, 2026. They remain pending owner approval in `docs/ui-refresh/batch-13/legal-approval.json` and must not be published as approved legal copy until that review is recorded.
- A real Stripe test-mode registration/login to hosted Checkout, cancel return, webhook delay, and portal round trip still needs manual validation with configured test price IDs.
- Batch 8 removed the ignored custom `auth:sanctum` provider and verified the real framework authentication, entitlement, and throttle boundary for every first-party API route. Account/settings and route contracts are automated; the local authenticated walkthrough remains a release gate.
