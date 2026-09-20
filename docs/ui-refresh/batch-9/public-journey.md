# Public journey states

| Visitor state | Primary action | Destination | Notes |
| --- | --- | --- | --- |
| Guest | Start the configured free trial | `/register` | Carries the normalized plan and billing interval. |
| Authenticated, checkout needed | Continue to checkout | `/checkout` | Uses a native link so the existing controller can return the Stripe redirect. |
| Authenticated, active access | Open dashboard | `/dashboard` | Applies to subscribed and eligible trial access. |

Home, Features, Pricing, and Contact are always public navigation entries. Profile and Log out appear only when authenticated. The footer uses the same public links, the Terms and Privacy routes, and the support address handled by the Contact workflow.

The `hero_cta_click` event records a fixed source, location, destination, plan, and billing value. Checkout actions also retain the existing `checkout_start` meaning. Product preview selection records only fixed view identifiers. Symbol, contract, strike, expiry, account, message, and payment data are excluded from event parameters.

The trial begins after checkout according to the current billing flow. Home and Features use the server-provided trial length and refer visitors to Pricing for current amounts and terms.
