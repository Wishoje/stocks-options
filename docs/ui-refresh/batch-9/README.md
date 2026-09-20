# Batch 9: public journey and product media foundation

Batch 9 implements cards UI-20 and UI-21. It establishes the shared public shell, authenticated-state actions, product-media contract, and accessible preview behavior used by Home and Features.

## Delivered behavior

- Home, Features, Pricing, and Contact remain visible in desktop and mobile navigation.
- Guests receive a registration action. Authenticated customers who still need billing receive a checkout action. Customers with active access receive a dashboard action.
- Plan and billing query values pass through registration, login, and checkout with the shared marketing journey helper.
- Mobile navigation identifies its controlled panel, moves focus into the open panel, closes on Escape or outside pointer input, and restores focus.
- Product media accepts responsive desktop and mobile assets, reserves image dimensions, lazy-loads non-priority images, and provides an accessible expand dialog.
- Home leads with GEX levels and presents five concise product stories for dealer positioning, put-versus-call pricing, intraday flow, scanning, and calculator outcomes.
- Features uses compact, keyboard-accessible screenshot galleries for multi-view areas while loading only the selected image.
- The reviewed media registry covers every current product area, including dedicated positioning, volatility, EOD strike, intraday strike, unusual-activity result, and calculator-outcome views.
- Footer support mail uses `support@gexoptions.com`. Terms and Privacy link to the public legal routes delivered by Batch 11.

## Shared-file overlap

`resources/js/lib/ga.js` and `resources/js/Support/marketing-journey.js` are provided by the public-journey foundation work already in progress. Batch 9 consumes their exported functions without rewriting either file. Offer data comes from the existing shared Inertia `offer` prop, which Batch 11 may expand into the full plan catalog.

## Completion boundary

The public shell, conversion-focused Home hierarchy, feature galleries, media registry, and reviewed screenshot set are complete. The capture manifest records the exact dimensions, placements, source revision, and required context for each published asset.
