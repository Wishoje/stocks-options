# Homepage and Features update

The homepage explains a practical sequence: find a price level, observe the reaction, and review the setup’s price boundaries. It includes separate intraday and swing-trading examples. The first product banner is Net GEX by strike. The intraday example shows the wall-and-price chart. The swing example shows EOD wall analysis. Scanner, IV and skew, and alerts each have a distinct product image below them.

The Features page includes a direct directory of all 20 feature sections. Net GEX by strike is named explicitly and leads the EOD tools. The page also covers dealer DEX, wall analysis, the expiry map, gamma context, intraday IV and skew, alerts, scanners, volatility tools, the calculator and AI Export. Existing feature URLs remain valid.

## Content decisions

Explain the question a tool answers before introducing its terminology. The main copy uses price levels, reactions, entry areas, targets and the price that invalidates a setup. Technical names remain available for traders searching for a particular indicator.

The subscription offer uses the existing shared pricing and trial configuration. Monthly and yearly plans retain the same feature entitlement. Checkout, renewal and cancellation terms remain visible. The pages make no claims about customer counts, testimonials, win rates or trading returns.

Intraday prices are delayed at least 15 minutes. Setup alerts operate while the site is open. Today’s received alerts remain in the current browser’s inbox. The pages do not advertise closed-browser push notifications or email alerts.

Eight additional product views use actual interface captures. Built-in walkthroughs and the Test alert are identified as examples. The EOD wall analysis, expiry map and IV/skew images use recorded local market data. Dates and scope remain visible in recorded captures. The image registry and capture manifest describe these sources.

## Reference review

[SpotGamma](https://spotgamma.com/), [MenthorQ](https://menthorq.com/) and [Tradytics](https://tradytics.com/) were reviewed for product presentation. Their pages connect product tools with trading tasks and use interface images to explain the offer. This update applies that presentation pattern to the features delivered in GEX Options. No competitor testimonials, integration claims or market-performance claims are reused. Conversion improvement remains a hypothesis to measure after release.

## Local review

Open the homepage at `http://127.0.0.1:8000/` and Features at `http://127.0.0.1:8000/features` while signed out.

1. Confirm that the first banner shows Net GEX by strike and expands to its complete image.
2. Read the intraday and swing-trading examples. Each should describe a useful task without requiring prior GEX knowledge.
3. Use the Features directory to open Net GEX, DEX, alerts, the expiry map and IV/skew. Check that the destination heading and screenshot match the selected tool.
4. Check both pages on a phone-size screen. Images should stay within their frames and links should remain easy to select.
5. Open an image, close it with Escape, and confirm keyboard focus returns to Expand.
6. Follow a trial button to the registration page. Confirm plan and billing selection are retained without completing checkout.

See [social post formats](social-post-formats.md) for five product-based formats covering SPY, QQQ and TSLA. Those templates do not change posting automation.

## Release scope

This update changes marketing pages, their shared content and styling, SEO text, screenshot assets, the media manifest and related checks. It requires no environment variables, migrations, queue changes or additional market-data subscription. Keep unrelated reliability work outside this release.

## Search and AI crawler access

The initial HTML for Home and Features contains readable product descriptions, feature links and FAQs. Every visitor receives this content, without user-agent detection. Vue replaces the initial content with the interactive layout when it mounts. Other application routes retain the existing Inertia shell.

`npm run build` exports the public content from the same JavaScript registry used by the visible pages into `resources/marketing/public-content.json`. Commit that generated file whenever the registry changes. A parity check catches stale exports. The server substitutes the configured trial length when rendering the FAQ, so it does not freeze an old trial offer into the page.

Both pages send canonical URLs, search descriptions, social metadata and structured data in the initial response. The structured data describes the organization, web application and current page. Features also lists the 20 tool destinations. It includes no fabricated ratings or reviews. The existing robots rules allow the marketing pages and keep application routes out of crawler discovery. The sitemap records the actual content-update date for Home and Features.

[Google's JavaScript SEO guidance](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics) recommends server rendering or pre-rendering because some crawlers cannot execute JavaScript. [Bing's webmaster guidelines](https://www.bing.com/webmasters/help/bing-webmaster-guidelines-30fba23a) explain that clear discovery, accurate content and structured pages also support AI search eligibility. These changes improve access and interpretation; they do not guarantee indexing, rankings, rich results or AI citations.

After production release, inspect both URLs in Google Search Console and Bing Webmaster Tools and submit the existing sitemap. Search results change after recrawling. No ownership-verification settings or search-engine account submissions are part of this code change.

## Verification

- Production asset build completed, including the public-content export.
- 47 frontend checks passed for content, metadata, pricing, signup journeys, feature navigation, screenshot dialogs and reduced-motion behavior.
- 9 server checks passed with 230 assertions, including initial HTML without JavaScript, configured trial terms, structured data, public routes, sitemap and crawler rules.
- The UI audit passed in review mode with zero errors. Its two existing notices concern older capture revisions and the previously approved manual account checklist.
- Desktop and phone-size reviews cover both pages, image frames, feature destinations and signup handoff. Screenshot frames reserve their size before lazy images load.
