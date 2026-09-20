# Batch 8 local review

Use `http://127.0.0.1:8000`. Sign in with the local review account. Do not open the Stripe portal, cancel a subscription, submit account deletion, or start checkout during this UI review.

## Authenticated product

- Open Dashboard, Scanner, Calculator, AI Export, and Watchlist navigation. Confirm each view loads without a `401` or `403` for the subscribed review account.
- Switch between EOD and Intraday, then inspect SPY, QQQ, IWM, and AAPL. Confirm the selected symbol, timeframe, and tab remain clear and the populated cards and charts do not disappear.
- In a signed-out private window, request `/api/dex?symbol=SPY` with an `Accept: application/json` header. Confirm it returns `401` and no market payload.
- Inspect a ready dashboard reading and confirm one `/product-events/first-useful-reading` request appears. Repeated inspection must not create repeated confirmed records.

## Account settings

- Open `/user/profile`. Confirm the plan, billing interval, access state, trial/end date, and status match the local subscription. No next-charge date should be invented.
- Check profile, password, two-factor, session, and subscription sections at desktop and mobile widths. Labels, help text, errors, and focus indicators must remain readable.
- Open the cancellation confirmation and account-deletion confirmation, then close them without submitting. Confirm each dialog stays inside the viewport, has an unambiguous title, receives keyboard focus, closes with Escape while idle, and restores focus to its trigger.
- Confirm no duplicate account-deletion alert appears and the permanent action is visually distinct from routine settings.

## Public and legal pages

- Open `/terms-of-service` and `/privacy-policy`. Confirm headings, lists, email links, internal policy links, and long paragraphs are readable at 1440 px, 1024 px, and 390 px widths.
- Review the decisions listed in `docs/ui-refresh/batch-13/legal-approval.json`. Legal documents remain a release blocker until the owner approves their exact hashes.

## Accessibility and motion

- Navigate the account page, dialogs, dashboard tabs, and public-page links with the keyboard only.
- Repeat with reduced motion enabled. Content must remain available without animated transitions.
- Confirm browser zoom at 200% does not hide controls or create horizontal scrolling in the main page.

Record the review result in `validation.json`. Change checks to `verified` only after every item above passes.
