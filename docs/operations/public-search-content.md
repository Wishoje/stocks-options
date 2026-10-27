# Public search content

Public workflow pages are `/swing-trading-ideas`, `/intraday-alerts` and `/about`. Their shared content is in `resources/marketing/public-workflows.json`. Guide articles are in `resources/marketing/trading-guides.json`. Both the Vue interface and the initial HTML read these registries. Keep publication dates unchanged and update an article?s `updated` date when its explanation changes.

Run `npm run build` after editing public content. Its prebuild exports Home and Features content and updates `resources/marketing/sitemap-content.json`. Commit the generated files with the change. Content hashes preserve modification dates across unchanged builds. Do not update all dates just because a deployment occurred.

`/sitemap.xml` is a Laravel response rather than a static file. It lists stable public URLs. The SPY entry uses the later of its content-version date and the last published public-preview date. Reading the sitemap uses cached metadata and never starts a market-data fetch or chart build. An unavailable cache leaves the committed content date in place.

Public authentication pages send `X-Robots-Tag: noindex, follow` and an initial HTML robots directive. They remain crawlable so search engines can read that directive. Dashboard, account, billing and API restrictions remain in robots.txt and their authentication middleware remains in force.

Pricing, contact, legal documents, guides and workflow pages include visible initial HTML for visitors without JavaScript. Vue replaces that content after loading. Pricing amounts come from the same public plan configuration used by the interactive page. The existing checkout and support form behavior is unchanged.

After release, verify sitemap XML, canonical URLs, initial article text and the login noindex header. Search Console discovery and indexing happen independently of deployment; a successful release does not establish indexing or ranking.
