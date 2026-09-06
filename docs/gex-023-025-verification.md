# GEX-023 through GEX-025 verification

## Release status

The additive index tooling, expiration resolver and snapshot-health implementation are ready locally. Local regression verification is complete. Production remains on `7b2c25f9331c67256f7f45e01880705d7d89e913`. No index has been added or removed in production. The snapshot-health tables and feature flags have not been deployed.

Production schema changes are blocked until a current database backup and its restore procedure are confirmed. The infrastructure baseline does not document either. The read-only server inspection did not establish an available backup. This is not proof that an external Forge backup does not exist.

GEX-023 duplicate removal remains a separate rollout step after at least one full release and market-session soak. These changes contain no index removal operation. The three cards must not be marked fully production-complete from local tests alone.

## Production baseline

Measurements were collected on Sunday, September 6, 2026. They cover closed-session reads, not browser rendering or market-hours capacity.

The database host runs MySQL 8.0.46. Its data directory and temporary directory were on the same filesystem, with 9,856,757,760 bytes free at 13:49:41 UTC. The session isolation was `REPEATABLE-READ`; division precision was 4. These readings are evidence, not reusable admission values for a later DDL operation.

The intraday raw table held only 186 MCK rows. It cannot demonstrate the production market-hours SPY index benefit. Across the 3,098-second observation window, the nine inspected tables recorded zero additional writes. No peak write-throughput claim follows from this sample.

A bounded read probe checked SPY, QQQ, IWM, TSLA, AAPL, V and MCK across 0d, 1d, 7d, 14d, 30d and 90d. Each combination had one forced cold read and five ordinary reads. There were 234 HTTP 200 responses, 18 HTTP 404 responses, and no timeout or exception. The 404s were MCK 0d, 1d and 7d, whose requested expiration sets were empty. Cold and warm business-payload hashes matched for all 42 combinations. Run/bootstrap metadata was excluded from those hashes.

Warm medians pooled across each symbol's successful timeframe requests were SPY 26.381 ms, QQQ 28.604 ms, IWM 23.472 ms, TSLA 19.493 ms, AAPL 10.104 ms, V 7.914 ms and MCK 10.336 ms. These are server-side controller timings with SQL observation enabled, not authenticated HTTP or browser load times.

Evidence:

- [Index inventory](reports/gex-023-index-inventory-before.json)
- [Query plans and calculator reads](reports/gex-023-query-plans-before.json)
- [Closed-session write counters](reports/gex-023-write-rate-closed-session.json)
- [All EOD baseline samples and expiration sets](reports/gex-023-025-eod-before.json)

## GEX-023: evidence-backed indexes

The proposed addition is `intraday_option_volumes(symbol, captured_at, strike_price)`. It supports the recent per-symbol IV aggregation. The existing contract/capture uniqueness and standalone capture-time pruning index remain in place.

The explicit `gex:indexes:intraday` command is read-only unless `--apply` is supplied. Apply requires a backup reference and a fresh database-host free-space reading. Those arguments are operator attestations; the command does not verify a backup or inspect a remote filesystem. It also verifies the exact column definitions, compatible existing indexes, MySQL/InnoDB support, a bounded actual row count, and disk admission estimates. It refuses table-copy fallback and limits metadata-lock waiting to five seconds. No migration automatically adds this index.

The production calculator publication-row table has approximately 30.6 million rows. Existing publication-key reads returned 112–394 contracts in 0.83–1.62 ms. Another large calculator index is not justified by that evidence.

The inventory identifies equivalent or prefix indexes on `option_expirations`, `option_chain_data`, `prices_daily`, and `unusual_activity`. Foreign-key support and non-equivalent column orders must be preserved. The inspected `option_live_counters` table had only its primary key and the existing natural-key unique index; no duplicate removal is proposed there. GEX-012 still governs nullable totals.

### Local index measurements

A 100,000-row synthetic fixture retained the exact full-row hash before and after index addition. A separate process inserted 2,000 rows during the online operation; all 2,000 rows and their volume sum were verified. Original indexes, upsert identity and the timestamp-prune plan were preserved.

| Local SQL measurement | Before | After |
| --- | ---: | ---: |
| SPY recent-IV median | 125.873 ms | 2.729 ms |
| AAPL recent-IV median | 16.335 ms | 0.743 ms |
| 1,000-row correction-upsert median | 99.549 ms | 113.541 ms |
| 1,000-row fresh-key upsert median | 61.455 ms | 76.720 ms |

Writes became slower: correction median increased 14.1%, fresh-insert median 24.8%. Each write measurement used the same SQL and payload, four 250-row statements, one warmup and seven samples. Samples were rolled back; commit/rollback cost is excluded. Their noisy p95 values also worsened. These are statement costs, not committed production ingestion throughput.

Online apply plus post-operation verification took 1,172.983 ms. This is not an isolated ALTER duration. A forced metadata-lock conflict failed within about five seconds and restored the session timeout. The index suite passed 10 tests and 199 assertions.

## GEX-024: one expiration universe

The resolver loads the symbol's semantically bounded expiration catalog once, then derives every requested/UI timeframe in memory. UI catalog queries decrease from seven to one; aliases decrease from eight to one. There is no arbitrary expiration cap or reduced coverage window.

The existing strike aggregation was already linear following the earlier hotfix. It is retained, including Greek fallback, signs, rounding, sorting, prior-date logic and response fields. Optional shadow mode freezes one clock, compares ordered expiration sets and ID sets, logs only hashes/counts, and serves the legacy selection on a mismatch.

Tests preserve catalog-only availability, sparse and heavy chains, missing Greeks, zero-DTE cases, stale snapshots, aliases, weekday counting, New York boundaries, DST, and the existing monthly app-timezone/overflow behavior. This card does not change the exchange-calendar rules.

## GEX-025: completed snapshot manifests and early cache reads

Three additive tables record mutation receipts, certified symbol revisions and immutable policy-specific health facts. Writers record a mutation before changing raw/catalog data. Failed or active receipts block certification. Successful exact-scope retries may supersede failed receipts, but never a possibly active owner. Recovery publication/rollback has an intent-bound replay path and exact persisted postconditions.

Successful GEX publication certifies an observed, fully finished revision and queues a coalesced rebuild. Rebuilds check both delivery ownership and the current raw revision before storing facts. Missing or corrupt manifests fall back and request repair only for an already certified, clean revision. Repair does not fetch market data or certify arbitrary existing rows.

Selection preserves a subtle existing distinction: MySQL's rounded side-ratio decision chooses the selected date, while the legacy summary uses PHP's ratio decision. Both fields are preserved and tested at a boundary where they differ.

A valid warm GEX request uses two manifest/state queries plus the existing WorkRun query in the local no-bootstrap fixture. It issues no expiration or raw-chain query. Bootstrap readiness/status checks remain in their original position. Cached payloads use one retrieval and a validated checksum envelope. Keys include symbol, timeframe, revision, publication version, selector policy, New York day and app day.

Dirty manifests may locate an existing last-good payload, but cannot select dates for a new raw read. A cold build rechecks the revision and calendar before caching. A racing mutation causes one legacy retry without populating the old generation. The enabled fallback does not trust v4 entries, which lack policy/day identity.

Raw rows are still updated in place. This is not immutable raw-snapshot isolation. Crashed active writer receipts require explicit investigation; they do not expire into a false healthy state. Failed historical prune receipts may also require recovery when no eligible rows remain to revisit. Normal rollback disables the read switch and leaves tracking enabled. An interval with tracking disabled is an unobserved-write gap and requires a reviewed fresh-publication procedure before trusting old heads again.

### Paired local endpoint measurements

The same frozen MySQL fixtures and array cache were used for each phase. AAPL had 200 raw rows; SPY had 12,800. Each phase/symbol had three forced cold and ten warm samples. All 78 decoded payloads and canonical hashes matched exactly.

| Local endpoint median | Legacy | GEX-024 | GEX-025 |
| --- | ---: | ---: | ---: |
| AAPL warm | 6.232 ms | 3.058 ms | 2.323 ms |
| SPY warm | 24.277 ms | 21.451 ms | 3.907 ms |
| SPY cold | 266.906 ms | 269.143 ms | 191.603 ms |
| Warm total SQL | 10 | 4 | 3 |
| Warm market-table SQL | 9 | 3 | 0 |

SPY warm p95 was 30.557 → 25.364 → 5.475 ms. With these small sample sizes, p95 is the largest observed sample, not a market-hours tail-latency certification. AAPL warm p95 was 9.014 → 3.700 → 4.250 ms; GEX-025 was not better than GEX-024 on every measured tail.

GEX-025 adds checksum/manifest overhead. SPY warm peak-allocation delta was 94,032 → 80,144 → 257,616 bytes. Cold peak delta remained near 7 MiB. Windows CPU readings have 15.625-ms granularity and do not establish a stable CPU improvement. The measured gain is mainly fewer database discovery queries and lower elapsed request time.

All samples, CPU/memory readings and methodology are in the [paired benchmark report](reports/gex-024-025-local-paired-benchmark.json).

## Verification and remaining work

The final complete PHP suite finished with zero errors or failures: 1,073 tests, 1,064 passed, 9 skipped and 12,241 assertions. Elapsed time was 2 minutes 21.565 seconds; peak process memory was 188 MiB. It used the guarded local MySQL test database and two disposable loopback Redis instances. The CLI memory limit was raised to 512 MiB for this test process only. Production PHP memory settings were not changed.

The nine skips are three disabled email-verification cases, three disabled two-factor cases, one registration-disabled branch, and two existing option-live concurrency cases requiring the unavailable Windows `pcntl` extension. The new index concurrent-ingestion proof uses a separate process and passed on Windows.

The full-suite run found and corrected two test-isolation issues: the new autocommit recovery fixture left its own manifest-rebuild WorkRuns behind, and an existing transport test hardcoded the default Redis port. Cleanup now removes only the fixture's run kind, with a regression preserving unrelated ownership. The transport test verifies that switching queues preserves the configured ports, including disposable test ports.

Targeted MySQL groups also cover index guards/concurrent inserts, resolver parity, durable manifests, raw writers, recovery atomicity, cached/uncached reads, corruption, policy boundaries, and racing publication. Their groups overlap and their counts must not be added to the full-suite result.

The [final local verification artifact](reports/gex-023-025-final-local-verification.json) records the result and a separate full-suite replication of the index benchmark. That replication again preserved all hashes and concurrent rows. Its read medians improved from 129.550 to 2.639 ms for SPY and 17.421 to 0.658 ms for AAPL. Correction/fresh-write medians increased from 111.575/69.867 to 115.354/88.854 ms. Timing variation does not remove the write-cost tradeoff.

All 105 frontend tests passed and `npm run build` succeeded. The existing old Browserslist database warning remains. No browser automation or authenticated production candidate check has been performed for these undeployed changes.

The [rollout and manual-check guide](operations/gex-023-025-rollout.md) defines the remaining backup, deployment, activation, rollback and market-session gates.
