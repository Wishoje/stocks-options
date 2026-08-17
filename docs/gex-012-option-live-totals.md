# GEX-012 option-live totals rollout

## Purpose

`option_live_counters` stores contract buckets and aggregate total rows. Aggregate rows use `NULL` for expiration, strike, and option type. MySQL permits multiple rows containing `NULL` through a composite unique index, so that index does not enforce one aggregate row per symbol and trade date.

GEX-012 adds `option_live_totals`, keyed uniquely by `symbol` and `trade_date`. It keeps contract buckets and the legacy aggregate rows unchanged during rollout. The canonical row is selected by source `asof`, then `updated_at`, then `id`, so a backfill does not choose an older total merely because it has a lower ID.

## Production audit baseline

The pre-change production audit found:

- 43,650 legacy aggregate rows.
- 897 distinct symbol/trade-date keys.
- 42,753 rows beyond one row per key.
- All 897 keys had duplicate aggregate rows.

Keep this audit with the ticket evidence. Re-run the SQL audit immediately before rollout because production data continues to change.

## Configuration

The three flags default to `false`:

```dotenv
OPTION_LIVE_TOTALS_DUAL_WRITE=false
OPTION_LIVE_TOTALS_COMPARE_WRITES=false
OPTION_LIVE_TOTALS_READ_FROM_CANONICAL=false
```

- `DUAL_WRITE` publishes totals to both the legacy and canonical stores.
- `COMPARE_WRITES` compares both stores after publication and reports differences while legacy reads remain authoritative.
- `READ_FROM_CANONICAL` switches aggregate-total reads to `option_live_totals`.

Set the same values on the web and worker servers, run `php8.3 artisan optimize:clear`, and restart long-running workers after every flag change.

## Staged rollout

1. Verify a current database backup and restore procedure.
2. Deploy the web site first so the additive migration runs, then deploy the same SHA to the worker server. Keep all three flags `false`. Do not remove legacy rows or indexes.
3. Set `OPTION_LIVE_TOTALS_DUAL_WRITE=true`, `OPTION_LIVE_TOTALS_COMPARE_WRITES=true`, and keep `OPTION_LIVE_TOTALS_READ_FROM_CANONICAL=false` on both servers. Clear configuration and restart workers. Enabling dual-write before backfill closes races with concurrent ingestion.
4. Backfill the retained legacy window. The command is chunked and idempotent, so it is safe to run again after interruption:

   ```bash
   php8.3 artisan intraday:backfill-live-totals --from=2026-08-10 --to=2026-08-16 --chunk=500
   ```

5. Compare the complete window. The command exits nonzero for missing, extra, or different canonical totals:

   ```bash
   php8.3 artisan intraday:compare-live-totals --from=2026-08-10 --to=2026-08-16 --chunk=500
   ```

6. Observe one complete market session with dual-write and comparison enabled. Re-run the comparison for that session and require zero differences before read cutover:

   ```bash
   php8.3 artisan intraday:compare-live-totals --from=2026-08-17 --to=2026-08-17 --chunk=500
   ```

7. Set `OPTION_LIVE_TOTALS_READ_FROM_CANONICAL=true` on both servers. Keep dual-write and comparison enabled through the rollback window.
8. Verify API fixtures and production metrics. GEX-012 does not delete duplicate legacy rows or remove the nullable unique index.

Use `--symbols=SPY,QQQ,IWM` on either command for a small canary. A symbol filter narrows validation only; production cutover still requires a complete-session comparison across all keys.

## Manual verification

Check that the new table enforces one row per key:

```sql
SELECT symbol, trade_date, COUNT(*) AS rows_n
FROM option_live_totals
GROUP BY symbol, trade_date
HAVING COUNT(*) > 1;
```

Expected result: no rows.

Check coverage against the legacy totals:

```sql
SELECT COUNT(*) AS legacy_rows,
       COUNT(DISTINCT symbol, trade_date) AS legacy_keys
FROM option_live_counters
WHERE exp_date IS NULL
  AND strike IS NULL
  AND option_type IS NULL;

SELECT COUNT(*) AS canonical_rows
FROM option_live_totals;
```

Then run `intraday:compare-live-totals`. A successful comparison prints equal `compared` and `matched` counts, with all mismatch counters at zero.

The existing `intraday:prune-counters --days=7` command deletes expired rows from both tables in one transaction. Rows inside the retention window remain available in both stores.

## Rollback

1. Set `OPTION_LIVE_TOTALS_READ_FROM_CANONICAL=false` on both servers.
2. Clear configuration and restart workers. Reads return to the unchanged legacy table immediately.
3. Leave `OPTION_LIVE_TOTALS_DUAL_WRITE=true` while investigating if safe, or disable it if the canonical write path is contributing to the incident.
4. Leave `option_live_totals`, legacy rows, and legacy indexes in place. No destructive database rollback is required.

After correction, rerun the idempotent backfill and comparison before attempting read cutover again.
