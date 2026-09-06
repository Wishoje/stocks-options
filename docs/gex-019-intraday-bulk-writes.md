# GEX-019: bounded intraday writes

## Behavior

Intraday contracts use the same normalization and contract/capture identity as before. MySQL upserts replace per-contract reads and writes. The default chunk size is 250, bounded to 1–1,000. There is no schema migration, provider scope reduction, or concurrency increase.

Each raw chunk commits independently. If a later contract or chunk fails, the job retries without publishing incomplete strike counters or totals. Completed raw chunks remain available and replay idempotently. Strike-counter updates reject an older `asof`; the existing canonical totals repository retains its freshness protection.

Historical captures remain separate rows. Corrections sharing the same contract and capture timestamp retain last-write-wins behavior. The provider does not supply an independent revision sequence for these ties. This change does not claim to order same-time corrections or solve the separate GEX-020 timestamp semantics.

## Deployment and rollback

Deploy the same revision to web and worker. Forge pushes automatically deploy both. No environment change is required: `INTRADAY_BULK_WRITES_ENABLED` defaults to `true` and `INTRADAY_WRITE_CHUNK_SIZE` defaults to `250`. Confirm both release SHAs and restarted workers before changing queue infrastructure.

To restore the legacy raw writer, set `INTRADAY_BULK_WRITES_ENABLED=false` on both hosts, rebuild cached configuration, and restart workers. Keep the freshness and incomplete-publication protections. Do not clear Redis or truncate tables to roll back.

## Repeatable validation

On the worker, from the current release:

```bash
git rev-parse HEAD
php8.3 artisan intraday:benchmark-writes --rows=1000 --chunk=250 --json
sudo supervisorctl status
```

The benchmark uses two owned MySQL temporary tables, never inserts into application tables, and removes its temporary tables on exit. It compares every stored field except generated IDs. Both insert and correction phases must report `matches=true`. At 1,000 rows and chunk size 250, each bulk phase should issue four data statements versus 2,000 for the legacy path. Transaction-control statements are excluded. Memory is PHP process peak memory, not MySQL server memory.

Local MySQL measurement on 2026-09-06: inserts 1,070.684 ms legacy / 196.411 ms bulk; corrections 2,247.459 ms / 217.337 ms. SQL count fell 99.8%; exact field hashes matched. Bulk peak was 32 MiB with a 2 MiB incremental peak. These synthetic database measurements are not browser or market-hours ingest measurements.

Regression coverage includes decimal precision, large counters, null versus zero, missing optional values, duplicate corrections, capture ordering, generator/chunk bounds, partial failure, legacy rollback, and stale strike-counter responses.

## Manual check

1. Open SPY, QQQ, and a normal symbol such as V. Check EOD Strikes and Intraday. Existing charts and totals should remain populated.
2. During the next open market session, request one intraday refresh. Confirm it finishes and timestamps advance. Refresh again and check that totals are not doubled.
3. Compare call volume, put volume, and their sum with the existing page behavior. Inspect Network for successful responses rather than 504 errors.
4. Inspect queue failures and worker logs for new ingest errors. Measure full-job duration during market hours before claiming end-to-end speedup.

Pre-deploy server-controller baseline: SPY/QQQ/V returned HTTP 200 with nonempty EOD and intraday payloads. Cold EOD reads took about 10.4 s / 12.9 s / 0.54 s; intraday reads took 8.3 / 3.5 / 3.6 ms. Cold EOD query cost is separate from this intraday write optimization. These checks bypass browser authentication and do not replace the manual UI check.
