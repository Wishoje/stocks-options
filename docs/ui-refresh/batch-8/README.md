# Batch 8: account settings and authenticated product validation

Batch 8 is a hard release gate. The account page, billing-state presentation, account deletion safeguards, authenticated product routes, and first-use measurement must be reviewed and verified together before the UI refresh can ship.

Explicit approval was received on 2026-09-18. The account/settings and first-use implementation now:

- fails closed unless every locally persisted Cashier subscription has both a recognized terminal provider state and a past saved end date before account deletion;
- removes terminal local subscription items and rows transactionally when an account is deleted;
- represents paused, unpaid, incomplete-expired, unknown, conflicting, and grace-period subscription states truthfully;
- keeps irreversible account dialogs open while a request is processing;
- retries first-use measurement after an unrecorded `204` response while retaining server-side deduplication and the fixed privacy contract.

Authenticated product routes now use Laravel's real `auth:sanctum` middleware, strict feature-family entitlements, and the existing read/work rate limiters. The ignored custom `auth:sanctum` provider registration was removed. Automated coverage classifies every first-party API route and verifies guest, subscription, feature, diagnostic, identity, and work-run boundaries.

No live Stripe, portal, cancellation, deletion, checkout, email, or production action is part of this validation. Automated evidence is recorded in [validation.json](validation.json). Complete [local-review.md](local-review.md) before changing the Batch 8 checks from `automated_verified` to `verified`.
