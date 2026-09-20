# Batch 12: authentication and first-use guidance

Batch 12 gives every Fortify and Jetstream authentication screen one dark, responsive shell. It replaces the dashboard's blocking first-run modal with an inline guide that preserves the user's current symbol, mode, tab, and timeframe.

## Implemented contracts

- Login, registration, forgot password, reset password, password confirmation, email verification, and two-factor challenge use the same authentication shell and support links.
- Login client validation requires a nonempty password but does not apply the registration password-length rule. The server remains the authentication authority.
- Registration requires a name in both client and server validation and does not invent one from the email address.
- Existing Fortify form actions, autocomplete values, throttles, password reset tokens, session handling, and disabled feature flags remain unchanged.
- Email verification and two-factor authentication were not enabled. Their views are styled for the day those existing features are deliberately enabled and validated.
- The first-use guide is inline and nonblocking. It displays the current dashboard context and never changes the symbol, dataset, tab, timeframe, or URL.
- Loading, empty, error, background fetch, URL flags, and tutorial dismissal do not count as useful product activity.
- `first_useful_reading` is sent once in local storage scope only after usable data is ready and the user explicitly selects a chart reading. Its payload contains only fixed `surface=dashboard` and `state=ready` values.
- Immediate and delayed subscription confirmation share the canonical `subscription_activation_confirmed` event and the same session-storage dedupe key.

## Validation

- Focused authentication, first-use, pricing, and journey tests passed as part of 38 Vitest tests across five files.
- Existing EOD strike and intraday flow interaction suites passed after adding explicit reading events.
- The production Vite build passed with 288 modules transformed.

## Release gates

- Test login, registration, password reset, and billing handoff against the deployed auth provider configuration.
- Two-factor and email-verification tests remain outside the active product because both Fortify features are disabled.
