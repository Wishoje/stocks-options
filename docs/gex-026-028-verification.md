# GEX-026–028 verification

## Status

Implementation and local verification are complete for all three cards. Production deployment and activation are pending confirmation of a current restorable database backup. The new migration adds two publication tables; it does not change market-data tables. All new feature switches default to `false`.

Local measurements below use synthetic fixtures. They do not establish production page-load times or show a new improvement to the already-cached Strikes endpoint.

## Changes

- **GEX-026:** MySQL becomes the authority for completed publication versions. Atomic, ordered updates prevent a lost payload cache or a late finalizer from selecting an older publication. Legacy Redis heads remain compatibility mirrors. Optional payload-cache separation preserves the original queue, provider coordination, locks and derived state.
- **GEX-027:** Activity rows that need estimated premiums load pricing inputs in bounded batches. Existing premium precedence, filters, sorting, rounding and complete response fields are preserved. Stored-premium and warm responses already avoid most of this work.
- **GEX-028:** Multi-symbol expiration summaries use bounded set-based reads. The single-symbol path remains unchanged. Compatible-index checks and a legacy fallback protect spot lookups from an unsuitable query plan. Internal chunk sizes do not impose a new API or watchlist limit.

## Local performance

Paired measurements use the same fixture and complete response comparisons, with cold array-cache requests and SQL logging in both modes. The representative targeted runs produced:

| Fixture | Measure | Legacy | Candidate |
| --- | --- | ---: | ---: |
| Activity: 200 estimated-premium rows | Total SQL | 1,263 | 7 |
| Activity: 200 estimated-premium rows | Median | 952.887 ms | 45.004 ms |
| Activity: 200 estimated-premium rows | p95 | 981.383 ms | 49.743 ms |
| Activity: pricing SELECTs only | Handler read operations | 1,204 | 1,204 |
| Expiration: 500 symbols | Total SQL | 1,001 | 4 |
| Expiration: 500 symbols | Median | 451.849 ms | 189.826 ms |
| Expiration: 500 symbols | p95 | 453.034 ms | 209.207 ms |
| Expiration: 500 symbols | Handler read operations | 7,505 | 6,134 |
| Expiration: one heavy symbol | Total SQL | 3 | 3 |
| Expiration: one heavy symbol | Handler read operations | 35 | 35 |

Complete response bytes matched in every paired sample. Activity pricing batching reduces round trips without increasing pricing row work in this fixture. A mixed 15-symbol expiration fixture with additional history reduced 31 statements to four and 860 handler reads to 327.

Handler counters are read operations, not statement-level rows examined. Statement-level instrumentation was unavailable. Small-sample p95 uses the nearest rank. These measurements exclude HTTP, TLS, PHP-FPM and production Redis.

These isolated feature benchmarks have durable publication reads disabled. Enabling GEX-026 adds one metadata query for requests with up to 1,000 symbols: eight total for the measured activity candidate, five for a cold expiration batch, and one for a warm expiration hit. Combined flag-on tests verify these costs. GEX-026 is a correctness and isolation improvement, not a measured reduction in warm response latency.

## Regression and failure checks

The final full guarded MySQL suite passed with the Redis 8.4 isolation drill, provider integration tests and queue-cutover proof enabled: 1,294 tests, 15,965 assertions, zero failures or errors, and nine skips. This is 1,285 passed tests. Runtime was 3 minutes 15.855 seconds with 228 MiB peak memory. Seven skips concern disabled application features; two older option-live totals concurrency tests require the unavailable `pcntl` extension on Windows. The new publication race tests use separate PHP processes and passed.

The runner used PHP 8.3 with a 512 MiB CLI memory limit, matching the existing full-suite procedure. An initial run with the CLI's default 128 MiB limit exhausted memory; no production or global PHP setting was changed. All Redis integration services were disposable, loopback-only test containers. The test database guard requires a MySQL database with a test-only name. No production restart, eviction or queue-loss drill was performed.

Durable publication coverage includes transaction rollback, cross-process publication races, exact symbol identity, older and pre-cutover finalizers, certificate ordering, malformed inventory, absent heads, unavailable database reads, mirror failure after commit and mirror repair. A disposable three-service Redis test restarts and evicts only its owned payload cache, then checks publication ordering, queue contents, coordination identity, a held lock and one durable calculator work claim. This does not prove that restarting the original coordination service is safe.

Activity coverage includes the captured legacy JSON oracle, 1/10/100/200/501-row requests, pricing precedence, duplicate and fractional keys, mixed history, filters, missing inputs and separate candidate/rollback cache namespaces. Historical lookup order remains the existing unordered behavior, so stable-data production parity is still required before activation.

Expiration coverage includes a frozen legacy controller oracle, 1/15/250/500/501 symbols, missing/stale/future-only data, whole-batch date fallback, duplicate and stored-case variants, heavy history, cache invalidation, rollback, compatible indexes and missing-index races.

## Deployment and manual verification

Follow the [phased rollout procedure](operations/gex-026-028-rollout.md). Do not enable every switch before initialization. It documents the backup gate, writer drain, exact publication import, worker restart, optional isolated cache provisioning, feature activation and rollback. No production cache flush, queue purge or destructive migration rollback is required.

After each phase passes its server-side checks:

1. Open Strikes for SPY, QQQ, IWM, TSLA and AAPL at 1 week, 2 weeks, 1 month and 3 months. Check first and repeated loads, dates and nonempty available data. Different timeframe totals need not be equal.
2. Open unusual activity. Check calls, puts, filters, sorting, estimated premiums and Show More against the same completed publication.
3. Check watchlist expiration summaries and the individual expiration page. Include a missing symbol and a heavy symbol alongside ordinary symbols.
4. Open the calculator for a heavy and a new symbol. Confirm that refresh work finishes and repeated clicks do not duplicate it.
5. Confirm both servers use the same release and intended flags. Check worker health, new errors and private payload/timing evidence before proceeding to the next phase.

Detailed measurements: [activity](gex-027-activity-pricing.md), [expiration](gex-028-expiry-batch.md), and [synthetic expiration samples and query plans](reports/gex-028-local-paired-benchmark.json).
