# Batch 12 local review

1. Open `/login`, `/register`, and `/forgot-password` at desktop and mobile widths. Confirm the logo, heading, form, error text, primary action, and footer links remain visible without horizontal scrolling.
2. Log in with an existing valid account whose password is shorter than eight characters, if a safe test fixture exists. Confirm the browser submits it and the server decides whether it is valid.
3. Start at `/pricing?plan=earlybird&billing=yearly`, continue to registration, then switch to login. Confirm the yearly selection survives both pages and reaches checkout after successful authentication.
4. Exercise forgot password, reset password, and password confirmation with test credentials. Confirm keyboard focus, autocomplete, server error messages, and processing-disabled buttons.
5. Do not enable two-factor authentication or email verification for this review. If those features are enabled later, repeat their full server and recovery-code test plans.
6. Clear `gex_onboarding_v1` in local storage. Open a deep dashboard URL such as `/dashboard?symbol=IWM&mode=intraday&tab=flow`. Confirm the inline guide appears without a blocking overlay and the symbol, mode, tab, timeframe, and URL remain unchanged.
7. Choose “Continue in this view.” Confirm it dismisses the guide and scrolls to the existing context without fetching a different symbol or changing filters.
8. Clear the key again, choose “Hide for today,” and confirm the guide stays hidden for the rest of the local calendar day.
9. With analytics debug enabled, confirm loading, tutorial dismissal, tab changes, and background fetches do not emit `first_useful_reading`. Select an actual strike reading after data is ready and confirm one event with only `surface=dashboard` and `state=ready`. Select more readings and confirm it does not repeat.
10. Complete one valid Stripe test checkout. Confirm immediate-dashboard and delayed-pricing paths emit at most one `subscription_activation_confirmed` event.
11. Enable reduced motion at the operating-system level and repeat the auth and guide flows. Confirm no required state depends on animation.
