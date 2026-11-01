# Search visibility and trading guides

The public guide collection is `/guides`. The first articles are:

- `/guides/how-to-read-spy-gex-levels`
- `/guides/how-to-use-intraday-wall-tracking`

Home, Features and the marketing navigation link to these pages. Full article text is included in the initial HTML. Articles have unique titles, descriptions, canonical URLs, Article and BreadcrumbList structured data, visible publication dates and a real organization byline. The collection and both articles appear in `https://gexoptions.com/sitemap.xml`.

## Search Console setup

Open [Google Search Console](https://search.google.com/search-console) with the Google account that owns GexOptions. The existing domain property is verified. On November 1, 2026, individual URL Inspection results confirmed that Home, Features and the public SPY page were indexed. The aggregate Page indexing report still showed an October 22 update, so use individual inspections to investigate recent releases.

1. Select the existing `gexoptions.com` property if it is already verified. If none exists, add the domain property and complete the DNS verification Google supplies. Do not create a second property unnecessarily.
2. Open Sitemaps and submit `https://gexoptions.com/sitemap.xml`.
3. Use URL Inspection on the homepage, Features, the guide collection and both articles. Check the live response and Google's selected canonical. Request indexing for the new article URLs if they are not indexed.
4. In Performance → Search results, use the Web search type. Export the last 28 complete days for the existing homepage and Features to establish a baseline. New articles start with no pre-publication baseline.
5. Compare complete 28-day periods after launch. Review each page's impressions, clicks, CTR and average position, plus the actual search queries. Check indexing separately before interpreting a low impression count as a content problem.

A sitemap submission or valid structured data does not guarantee indexing, rankings, rich results or inclusion in AI answers. Low initial traffic can make short-period comparisons noisy.

## Conversion measurement

Existing GA4 page-view tracking includes the new guide URLs when GA4 is configured. Trial clicks use the existing `hero_cta_click` event with `event_origin=guides` and `location=guides_index` or `guide_final`. These are fixed allowlisted values. Internal component inputs named `source` are normalized to `event_origin` before transmission so page placement is separate from acquisition-source fields.

Search Console measures search discovery and clicks. GA4 measures visits and the existing registration/checkout journey. Check both before judging whether article traffic leads to subscriptions. No new analytics provider, cookie category or tracking dependency is introduced.

## Updating or adding a guide

Edit `resources/marketing/trading-guides.json`. It is the shared source for Vue, route props, initial HTML and article metadata. Keep examples explicitly hypothetical, or label actual product captures with their recorded date. Use the existing `productMedia` registry for real screenshots. The build adds published guides to the content-version manifest used by the dynamic sitemap. Keep the visible byline and schema author consistent.

## Attribution maintenance

The browser wrapper retains only fixed acquisition sources, media and recognized campaign labels. Existing lifecycle-email links retain `email/lifecycle`. Signup and checkout campaign names remain distinct; account and billing campaigns are reported under the generic `account_updates` label. Existing public `x/social/daily_gex` links may retain only their SPY, QQQ or TSLA content label. Unknown campaign values, identifiers and private market selections are discarded. This tracking does not add a public X link or an account connection.

On a new document reached from the exact HTTPS origin `https://checkout.stripe.com`, GA configuration sets `ignore_referrer: true`. This prevents the hosted payment processor return from acting as an acquisition source. The property is absent for direct visits, search engines, normal referrals and lookalike hosts. Do not set it globally or emit `false`; Google treats any supplied value as an instruction to ignore the referrer. The first page view, sanitized SPA referrers and existing event deduplication remain in place.

GA4 reporting setup is separate from application code. Register `event_origin` as an event-scoped custom dimension when a placement breakdown is needed. Use `sign_up` as the canonical registration event; `register_complete` is an alias for the same action and must not be added to it. `subscription_activation_confirmed` records browser-observed activation, including trials. It is not paid revenue. The server conversion ledger remains the authority for trial and paid activations. Changes affect future measurements and do not rewrite historical source attribution.

After a local review and release, inspect actual collected event parameters and compare new acquisition rows. The observed internal source labels motivated this cleanup, but their specific GA processing mechanism was not proven by the code audit. Do not claim that historical counts or Google rankings were repaired by renaming a parameter.

Run the focused frontend and PHP route checks, then `npm run build`. The build continues to regenerate the separate Home/Features public-content artifact. Do not change historical publication dates just to make content appear new.

## Release checks — October 24, 2026

- 43 frontend tests passed across guide navigation, guide rendering, marketing pages, head serialization, public components and the conversion journey.
- 13 server tests passed with 362 assertions, including initial article HTML, metadata/schema parity, valid internal links, sitemap inclusion and unknown-slug 404 handling.
- Production Vite build passed. No database, queue or environment changes are required.

## References

- [Google: Article structured data](https://developers.google.com/search/docs/appearance/structured-data/article)
- [Google: About Search Console](https://support.google.com/webmasters/answer/9128668)
- [Google: JavaScript SEO basics](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics)
