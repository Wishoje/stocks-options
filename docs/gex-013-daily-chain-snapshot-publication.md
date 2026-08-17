# GEX-013 daily chain snapshot publication

`chain:snapshot` now keeps the prior complete day visible until a replacement
has been fully aggregated, inserted, and verified.

## Publication contract

The command aggregates `option_chain_data` before changing the visible
`daily_chain_snapshot` rows. It then performs the date-scoped delete, chunked
insert, row-count check, and payload-checksum check in one database transaction.
The transaction commit is the completion marker. A normal reader sees either
the prior committed rows or the complete replacement; it cannot see the delete
or a partially inserted set.

A shared per-date cache lock prevents two scheduler or manual processes from
publishing the same date concurrently. Production must keep `CACHE_STORE=redis`
with the same Redis service on every scheduler host. The existing production
configuration already meets that requirement.

An empty aggregate is rejected. Database errors, process exceptions before
commit, and verification failures roll back to the prior complete rows. If the
caller loses its connection after commit, rerunning the command publishes the
same natural keys and checksum safely.

This design does not create staged or superseded database generations. There
is therefore no new data-retention cleanup. The complete rows already in
`daily_chain_snapshot` are the retained last-known-good publication, and the
existing option-chain retention policy remains unchanged.

## Configuration

The defaults require no production `.env` change:

```dotenv
DAILY_CHAIN_SNAPSHOT_LOCK_SECONDS=7200
DAILY_CHAIN_SNAPSHOT_LOCK_WAIT_SECONDS=5
DAILY_CHAIN_SNAPSHOT_INSERT_CHUNK_SIZE=1000
```

The lock lifetime must stay longer than the slowest expected aggregate build.
Do not reduce it below the observed production runtime. Changing the chunk size
only changes statement size; all chunks remain inside one transaction.

## Deployment and verification

Deploy normally. There is no migration and no feature-flag cutover. On the
worker server, run a completed session manually:

```bash
cd /home/forge/stocks-options-ss7u2nu2.on-forge.com/current
php8.3 artisan chain:snapshot 2026-08-14
```

A successful run prints the row count and SHA-256 checksum. Run it a second
time. The row count and checksum must match:

```text
Snapshot published for 2026-08-14 (rows: ..., checksum: ...)
```

Confirm there is exactly one aggregate per symbol, date, and expiration:

```bash
php8.3 artisan tinker --execute='$d="2026-08-14"; $q=\Illuminate\Support\Facades\DB::table("daily_chain_snapshot")->whereDate("data_date",$d); echo "rows=".$q->count().PHP_EOL; echo "duplicate_keys=".\Illuminate\Support\Facades\DB::query()->fromSub((clone $q)->select("symbol","data_date","expiration_id")->selectRaw("COUNT(*) n")->groupBy("symbol","data_date","expiration_id")->having("n",">",1),"d")->count().PHP_EOL;'
```

`duplicate_keys=0` is required. The focused regression test also injects
failures before deletion, after deletion, after insertion, after verification,
and after commit.

## Rollback

Revert the application commit and redeploy. No schema rollback or data rewrite
is needed. A failed new-code publication leaves the prior committed rows in
place. Do not manually delete `daily_chain_snapshot` during rollback.
