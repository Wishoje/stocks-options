# Marketing conversion copy review

Reviewed implementation: `2e7b92cec3346a62bf4d73b2dfd80d74df3ef905`.

The homepage states the product workflow in a shorter hero, displays prices from the shared offer configuration, and explains trial cancellation and automatic renewal. Three existing cards now show a SPY workflow from GEX levels through positioning to contract scenarios. Features retains its layout with small wording changes and removal of the operational EOD Health FAQ.

The homepage chart preview uses a vertical presentation crop. Its caption identifies the detail view, symbol, recorded date, and scope. Expand still opens the complete original image. Product screenshot files and the depicted dashboard views are unchanged from `703bf52b0286af3510a887e3b4dc6311782b2036`; this review does not claim new captures.

Validation:

- All 34 tests in the five targeted marketing suites passed, including shared offer pricing, trial copy, metadata, structured data, public components, and the conversion journey.
- The production Vite build passed after the final chart framing adjustment.
- Home and Features had no horizontal overflow at 390px and 768px. The homepage hero and chart framing were visually reviewed at phone, tablet, and desktop widths.
- The full-image dialog opened and closed correctly.
- Existing canonical URLs and SEO metadata remain unchanged. Visible FAQ content and FAQ structured data use the same registry.

No environment, database, dependency, billing configuration, or dashboard data changes are required. Existing owner-approved release exceptions remain unchanged.
