# September 20 release validation

All redesign batches are included together in implementation commit `703bf52b0286af3510a887e3b4dc6311782b2036`. The product captures were reviewed from that implementation before it was committed; their source revision now identifies the committed implementation.

- Frontend: 46 files and 431 tests passed.
- PHP 8.3, guarded local MySQL database: 1,504 tests, 17,114 assertions, zero failures or errors, 28 skipped. Optional integration and unavailable-platform checks remain skipped.
- Production Vite build: passed, 285 modules.
- Content audit: passed with the explicit owner-accepted Batch 8 manual-review exception.
- Live Stripe price verification: monthly and yearly prices match the configured public display amounts, currency, cadence, and live mode.
- Production database backup: completed before migration; compressed archive passed integrity validation.
- Migration preview: two additive tables, `positioning_regimes` and `conversion_events`. No existing market-data table is replaced.

The PHP run uses the documented MySQL runner with a process-only 512 MiB limit and the default disabled expiration-batch flag. The initial SQLite run could not execute MySQL migrations. Fixture corrections keep dated cache/publication scenarios within their intended time window, keep access valid across the weekend cache test, provide an empty subscription relation for an isolated user fixture, and update the social-image description to the delivered capture.

The owner approved the current legal drafts and production deployment on September 20. Batch 8 manual checks are not represented as completed; the recorded release exception preserves that distinction. No conversion improvement is claimed before measurement.
