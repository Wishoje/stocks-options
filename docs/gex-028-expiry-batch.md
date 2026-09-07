# GEX-028 expiration batch lookup

## Behavior and activation

`EXPIRY_PRESSURE_BATCH_ENABLED=false` is the default. With it enabled, multi-symbol `/api/expiry-pressure/batch` requests use bounded date, spot, and pressure queries. One-symbol requests keep the original calculation and avoid the capability check. The single-symbol `/api/expiry-pressure` endpoint is unchanged.

The optimized path preserves the original canonicalization, requested order, fields, dates, missing-symbol states, and one-hour cache lifetime. It uses a separate `expiry_pressure_batch:v3` namespace; rollback returns to the existing `v2` namespace. Publication-version invalidation is unchanged. No cache flush is required.

The 500-symbol query unit is internal, not an API or watchlist limit. Tests cover 1, 15, 250, 500, and 501 symbols. The provider-work start budget does not impose a new limit on this read endpoint.

Follow the [GEX-026–028 rollout procedure](operations/gex-026-028-rollout.md). Enable the flag only after release-specific parity and performance checks pass. Apply configuration through the normal deployment process on servers serving this endpoint. Set it back to `false` and rebuild cached configuration to roll back.

## Query shape and fallback

For a complete batch of 2–500 symbols, a cold response uses three market-data statements and one schema capability statement:

1. Read anchored and unrestricted latest pressure dates in one grouped query. The original whole-batch fallback rule is preserved when no symbol has an anchored date.
2. Check positive spot existence with one bounded row of scalar subqueries. An installed visible BTREE index must lead with `expiration_id, data_date`, and the actual column must be `DATE`. The discovered index name is validated before the query grammar receives it. Only these spot subqueries use the index hint.
3. Read the selected pressure rows in one cursor and fold each symbol's maximum integer score. This avoids aggregation temporary-table work while retaining the original rows and arithmetic.

The capability check runs once per cold multi-symbol request, not once per symbol or query chunk. Warm responses do not query this metadata. It supports renamed compatible indexes and rejects incompatible or unsafe index names.

If the index or metadata capability is unavailable, spot checks use the original per-symbol queries. If an operator removes or hides the selected index between inspection and execution, only the missing-index database error takes that same fallback. Other database errors still propagate. Fallback preserves the response but is not the optimized query path.

With GEX-026 durable publication reads enabled, one additional publication-version query is expected: five total queries for a complete cold batch and one for a warm hit. The combined flag-on regression verifies those counts separately from market and schema work.

## Local verification

The paired fixture contains 500 synthetic symbols, 3,000 pressure rows, and 3,000 option rows. Each phase has five cold and ten warm samples. SQL logging is enabled equally. Small-sample p95 uses the nearest rank. Exact response bytes and their SHA-256 match the frozen pre-change controller oracle.

| Measurement | Legacy | Enabled |
| --- | ---: | ---: |
| Cold total SQL | 1,001 | 4 |
| Cold p50 | 451.849 ms | 189.826 ms |
| Cold p95 | 453.034 ms | 209.207 ms |
| Cold handler read operations | 7,505 | 6,134 |
| Warm total SQL | 0 | 0 |
| Warm p50 | 1.349 ms | 1.304 ms |

Handler counters measure read operations, not MySQL statement rows examined. Statement-row instrumentation was unavailable and is recorded as `null`. Query-plan row counts are estimates. The test also records separate query-category replays outside timing. These measurements exclude HTTP, TLS, PHP-FPM, and production Redis.

A second fixture adds 19,200 option rows across six expirations and four historical dates. The one-heavy-symbol request retains identical work: three SQL statements and 35 handler reads. The mixed 15-symbol request reduces 31 total SQL statements to four and 860 handler reads to 327. It retains the usable-spot early exit instead of scanning the historical chains.

Validation passed: 21 MySQL tests / 1,971 assertions and six pure tests / 39 assertions. An additional stored-case regression passed one test / five assertions after reproducing a lowercase/trailing-space row mapping failure. Returned pressure rows now fold into canonical requested keys only. The suite also checks duplicate and case-variant inputs, missing and stale symbols, zero spots, future-only data, cutoff/weekend boundaries, day coercion, cache expiry, publication invalidation, rollback, cross-chunk fallback, compatible-index safety, and missing-index races. The 500-symbol fixture asserts lower median read work, not only fewer statements.

[Raw synthetic measurements and query plans](reports/gex-028-local-paired-benchmark.json) include every timing sample and the frozen source/payload hashes. Production activation still requires a release-specific comparison; these local results do not establish production latency.

## Manual checks

1. Open an existing watchlist and inspect the `expiry-pressure/batch` response in the browser Network panel. Symbol order, `data_date`, and `headline_pin` should match the flag-off response for the same publication version.
2. Check a one-symbol request, a normal watchlist, and the largest existing watchlist. Include duplicate/case-variant inputs and a missing symbol in the read-only comparison. Existing missing items must remain missing rather than borrowing another symbol's date.
3. Check a heavy symbol alongside ordinary symbols. Expiration values must agree with the single-symbol expiration view for the same selected data date and window.
4. Reload the unchanged request. It should reuse the response cache without rediscovering expiration or chain data. After a normal pressure publication, verify the next response reflects the new publication version. Do not clear shared caches as a test step.
