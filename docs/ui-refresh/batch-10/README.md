# Batch 10: Home and Features

Batch 10 implements cards UI-22 and UI-23 on the Batch 9 public foundation.

Home now explains the product through a plan, check, and model workflow. It uses a state-aware primary action, links to the complete feature map and current Pricing page, reads the trial length from the shared offer prop, and generates FAQ structured data from the same answers visible on the page.

Public-page search metadata is sourced from `config/marketing_seo.php`. The Blade shell renders the title, description, canonical URL, Open Graph fields, and Twitter card in the initial HTML, while the matching Inertia head keys keep client-side navigation synchronized without duplicate or overwritten tags.

Features covers the five EOD tabs, both intraday tabs, Watchlist, Volume Scanner, Wall Scanner, Options Calculator, and AI Export. Copy describes stored snapshots, dataset-specific scope, source timing, units, provider availability, and operational EOD Health accurately. It does not promise a one-minute interval, universal symbol coverage, pin alerts, or EOD Health as a general entitlement.

Product media placements use the shared manifest. Pending capture states are deliberate and remain visible until verified screenshots of the delivered interface are available.
