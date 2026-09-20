# Batch 11 local review

Use a test account and Stripe test-mode price IDs. Do not use a real payment method.

1. Open `/pricing` signed out. Confirm the configured monthly and yearly labels are `$29.99` and `$299`, the annual savings reads `$60.88`, and no “all symbols,” one-minute, price-increase, or proration promise appears.
2. Switch billing intervals. Confirm the selected price, interval, and CTA update together. Click once and confirm registration retains the choice. Use the login link and confirm it also retains the choice.
3. Complete registration. Confirm the same-origin “Account created” handoff appears briefly, records one sign-up event, and then opens Stripe as a full browser navigation without an Inertia error page. Log in through the same selected flow and confirm Stripe also opens correctly.
4. Double-click or rapidly reopen checkout. Confirm only one Stripe session opens and the duplicate request returns an “already opening” notice. While that session is active, choose the other billing interval and confirm pricing explains that another choice already has an active checkout instead of creating a second session.
5. Cancel Stripe checkout. Confirm pricing shows a canceled state and retains the selected interval.
6. Complete Stripe test checkout. Confirm the return first says “Confirming your subscription” if the webhook/local subscription record is delayed, then opens the preserved product path only after local Cashier state becomes active.
7. Refresh or manually revisit a copied success URL. Confirm it cannot generate a second activation confirmation. An already-active account should return to the dashboard without a new confirmation event.
8. Temporarily remove one public display interval in local configuration and refresh pricing. Confirm it says “Price unavailable,” disables that option, and shows no hard-coded amount. Restore the configuration afterward.
9. Submit `/contact` empty and confirm focus moves to the first invalid field. Submit a safe test message and confirm the success notice is announced. Verify the configured mail transport receives one message at `support@gexoptions.com`.
10. Open `/terms-of-service` and `/privacy-policy` from the public footer. Confirm navigation, canonical tags, dark layout, mobile wrapping, and support link.
11. Read both legal drafts against the current product, billing, analytics, and deletion behavior. Confirm the approval manifest says `drafted_pending_owner_approval`; production release remains blocked until the owner records approval for the exact document hashes.
