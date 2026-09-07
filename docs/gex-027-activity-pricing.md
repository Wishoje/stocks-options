# Activity pricing lookup batching

GEX-027 reduces repeated database and schema lookups when unusual-activity rows need estimated premiums. Stored and live premiums retain their existing precedence. Responses that already contain premiums do not load pricing inputs.

## Configuration

`ACTIVITY_BATCH_PRICING_ENABLED` defaults to `false`. Enabling it selects the request-local batched input loader and a separate `ua:v3` response namespace. Disabling it restores the existing per-row lookup path and `ua:v2` namespace. A rollback cannot serve a candidate response from the shared cache. Both paths retain the same cache arguments, publication version, 15-minute lifetime, response limit, and publication behavior.

`activity_performance.pricing_batch_size` is 100 distinct expiration/strike keys per SQL statement. The helper bounds this setting to 1–250. Larger requests continue through additional batches; the batch size is not a response cap. The current interface's Show More action stops at 200 rows, but the API's existing uncapped limit behavior is retained.

## Preserved pricing behavior

- If the `option_quotes` table exists, its rows take precedence even when a requested quote is absent. Absence does not trigger chain pricing.
- Without that table, optional chain pricing columns retain their existing precedence and penny floor. Their presence suppresses theoretical fallback even when their values are null.
- Theoretical pricing retains the existing formula, arithmetic order, native PHP clock and timezone, IV treatment, first available underlying price, and same-date close or chain-average fallback.
- Historical chain lookups keep all existing data-date coverage. No newest-snapshot rule is introduced.
- Expiration grouping, top-N selection, sorting, filtering, intraday overrides, metadata, rounding, and response fields are unchanged.

The loader groups the existing exact-key SELECT shapes with `UNION ALL`. This avoids introducing a broad join that could change duplicate-row precedence. The legacy queries have no explicit row ordering; parity fixtures therefore include multiple historical rows and duplicate quote keys. A later change to deterministic snapshot selection is a separate behavior change.

## Verification

`Gex027ActivityPricingTest` uses a disposable MySQL database and synthetic data only. Its ten-row JSON oracle was captured before batching was added. The golden fixture fixes the first-spot and last-IV values observed during capture, so a different legacy index choice cannot change its numerical inputs. Separate mixed-history fixtures compare the current legacy and batched responses without normalizing historical values. Tests compare complete response bytes for 1, 10, 100, 200, and 501 rows and verify bounded query counts.

Additional cases cover quote and optional-column precedence, duplicate and fractional keys, missing inputs, all activity filters, intraday overrides, stored premiums, disabled estimation, and separate candidate/rollback warm cache hits. A seven-sample paired benchmark alternates legacy and batched requests after one warmup per mode. It reports response and SQL timing without imposing unstable speed assertions. Session handler counters record database row work, and a separate replay of only the captured pricing SELECTs excludes schema and publication metadata from the access-work comparison. Grouped EXPLAIN steps record the chosen access paths. A synthetic regression guard rejects greater candidate pricing row work even if fewer SQL calls improve latency.

The benchmark deliberately exercises missing-premium fallback. Its improvement should not be presented as a gain for every production activity response: stored-premium and warm-cache responses already avoid the repeated pricing work.

The local feature and capability suites passed 28 tests and 97 assertions. The 200-row benchmark returned identical complete JSON payloads in every paired sample:

| Measurement | Legacy | Batched |
| --- | ---: | ---: |
| SQL statements | 1,263 | 7 |
| Median response time | 952.887 ms | 45.004 ms |
| p95 response time | 981.383 ms | 49.743 ms |
| Median summed SQL time | 863.270 ms | 32.220 ms |
| p95 summed SQL time | 896.090 ms | 36.490 ms |
| Pricing SELECT handler reads | 1,204 | 1,204 |
| Pricing rows returned | 688 | 688 |

These are local synthetic measurements, not production timings. With seven samples, nearest-rank p95 is the slowest sample. Query counts include schema discovery and fallback spot lookup. A separate test confirms durable publication metadata is also included when enabled. Both pricing plans use the same strike/type range index and expiration primary-key lookup; batching does not reduce or increase the pricing rows read in this fixture. No speed threshold is used as a test assertion.
