# Historical EOD session recovery

This runbook restores a missing EOD option-chain session without publishing a
partial chain. The recovery records
`source_policy=archive_exact_target_else_current_snapshot_v1` and selects
sources from the immutable reference catalog:

- If the catalog contains an expiration on the target date, that expiration
  comes only from the frozen post-close intraday archive. Later expirations
  come from exact current snapshot partitions.
- If the catalog has no target-date expiration, every catalog expiration comes
  from exact current snapshot partitions. The archive is validation evidence,
  not a row source. At least one overlapping catalog expiration must provide a
  complete post-close archive witness.

The archive and catalog must agree on whether a target-date expiration exists.
A contradiction fails capture.

The capture and validation modes do not write `option_chain_data` or `option_expirations`. Publication requires the exact validated SHA-256 and refuses to overwrite any existing symbol/date slice.

## Safety gates

Capture fails unless all applicable checks pass:

- The command is still inside the safe window before the next options session.
- `prices_daily` contains the exact target-date closing price.
- The historical reference catalog matches the symbol and recovery window.
- Every current snapshot partition has exact reference ticker coverage for one
  expiration and side, valid contract definitions, an acceptable market
  timestamp, and a spot price consistent with the target close.
- An archive target group or witness is post-close, has exact reference ticker
  coverage, has valid definitions, and agrees with the target close.
- Any archive metric fallback matches the exact snapshot ticker and comes from
  validated post-close evidence.

Capture writes and hashes the reference, archive, snapshot, and candidate
artifacts. Offline validation verifies those hashes and rebuilds the expected
expiration catalog from the reference artifact. It does not trust the
candidate's declared expiration or source sets.

## July 17 example inputs

The commands below retain the July 17, 2026 recovery inputs. For another
session, replace the date, symbol list, archive path and SHA-256, and run
directory consistently.

Target date:

```text
2026-07-17
```

Symbols:

```text
AMD,CAR,CAT,COST,CRWD,DE,DELL,EWY,GLD,GOOGL,LLY,META,MU,NFLX,QQQ,SLV,SNDK,SPCX,SPY,STX,TSLA,TSM,USO
```

Frozen server archive:

```text
/home/forge/recovery/intraday-option-volumes-2026-07-17.jsonl.gz
```

Expected archive SHA-256:

```text
4e4f7837f141c14157e67368d0edd331ac29c2091a5826d996e44cec103aced7
```

The off-server copy must retain the same SHA-256 before capture begins.

## Capture deadline

Capture must finish before the next options session opens at 09:30 America/New_York. The command checks this window before and during provider requests. If the window closes, the run fails closed and cannot be published.

Do not loosen this check. Once a newer options session begins, the current snapshot is no longer a defensible source for the July 17 future expirations.

## Deploy and verify the command

Deploy the same commit to the worker site, then connect to the worker server:

```bash
ssh forge@178.156.205.230
cd /home/forge/stocks-options-ss7u2nu2.on-forge.com/current
git rev-parse HEAD
php8.3 artisan list --raw | grep '^eod:recover-session '
```

No Forge worker, queue, scheduler, migration, or environment change is required. This is an isolated synchronous command.

## Verify the frozen source

```bash
chmod 0600 /home/forge/recovery/intraday-option-volumes-2026-07-17.jsonl.gz
sha256sum /home/forge/recovery/intraday-option-volumes-2026-07-17.jsonl.gz
```

The result must be:

```text
4e4f7837f141c14157e67368d0edd331ac29c2091a5826d996e44cec103aced7
```

Confirm that no target rows already exist for the recovery symbols:

```bash
php8.3 artisan tinker --execute='$symbols=explode(",","AMD,CAR,CAT,COST,CRWD,DE,DELL,EWY,GLD,GOOGL,LLY,META,MU,NFLX,QQQ,SLV,SNDK,SPCX,SPY,STX,TSLA,TSM,USO"); $rows=\Illuminate\Support\Facades\DB::table("option_chain_data as o")->join("option_expirations as e","e.id","=","o.expiration_id")->whereIn("e.symbol",$symbols)->whereDate("o.data_date","2026-07-17")->selectRaw("e.symbol, COUNT(*) AS rows_n")->groupBy("e.symbol")->orderBy("e.symbol")->get(); echo $rows->isEmpty()?"target_rows=0".PHP_EOL:$rows->toJson(JSON_PRETTY_PRINT).PHP_EOL;'
```

If any symbol has rows, stop. Do not delete it. Build a new recovery scope that excludes the existing slice.

## Capture immutable artifacts

The run directory must not already exist.

```bash
php8.3 -d memory_limit=2G artisan eod:recover-session \
  --capture \
  --date=2026-07-17 \
  --symbols=AMD,CAR,CAT,COST,CRWD,DE,DELL,EWY,GLD,GOOGL,LLY,META,MU,NFLX,QQQ,SLV,SNDK,SPCX,SPY,STX,TSLA,TSM,USO \
  --archive=/home/forge/recovery/intraday-option-volumes-2026-07-17.jsonl.gz \
  --archive-sha=4e4f7837f141c14157e67368d0edd331ac29c2091a5826d996e44cec103aced7 \
  --run-directory=/home/forge/recovery/runs/eod-2026-07-17-v1
```

Success requires `"ok":true`, `"symbols_captured":23`, `"symbols_requested":23`, and an empty `errors` object. A failed symbol leaves canonical EOD tables unchanged.

## Validate offline

Validation reads only the frozen run artifacts. It does not call Massive and does not publish rows.

```bash
php8.3 -d memory_limit=2G artisan eod:recover-session \
  --validate \
  --run=/home/forge/recovery/runs/eod-2026-07-17-v1
```

Success requires:

- `"ok":true`;
- 23 symbol reports;
- no validation errors;
- an exact `candidate_sha256`;
- for a symbol with a July 17 expiration, July 17 sourced from `archive` and
  every later expiration sourced from `current_snapshot`;
- for a symbol without a July 17 expiration, every expected expiration sourced
  from `current_snapshot` and a nonempty `archive_witness_expiries` set;
- the candidate expiration and source sets exactly matching the catalog rebuilt
  from the hashed reference artifact.

Keep the returned `candidate_sha256`. Review `manifest.json` and `validation.json` before publication.

## Publish

Run the zero-row check again. Publication will also enforce it inside one database transaction.

Replace `<VALIDATED_SHA256>` with the exact validation value:

```bash
php8.3 -d memory_limit=2G artisan eod:recover-session \
  --publish \
  --run=/home/forge/recovery/runs/eod-2026-07-17-v1 \
  --confirm-sha=<VALIDATED_SHA256>
```

All 23 symbols publish in one transaction. The command holds the normal EOD symbol/date locks, rereads the inserted rows, and compares every persisted field with the candidate before committing. It writes a durable publish intent before the transaction and a `publish-receipt.json` with per-symbol row, natural-key, and full-slice hashes afterward. If PHP stops between the database commit and receipt write, rerun the same publish command. It will finalize only when every target slice exactly matches the signed intent.

## Verify publication

```bash
php8.3 artisan watchlist:repair-missing \
  --date=2026-07-17 \
  --profile=broad \
  --chunk=1 \
  --days=90 \
  --dry-run
```

The 23 recovered symbols must no longer appear as missing or incomplete. Also check recent terminal failures and the EOD audit UI before accepting the recovery.

## Roll back this exact run

Rollback is available only while the persisted rows still match the publication receipt byte-for-byte at the canonical field level. It refuses to delete a changed row, even when the row count is unchanged.

```bash
php8.3 -d memory_limit=2G artisan eod:recover-session \
  --rollback \
  --run=/home/forge/recovery/runs/eod-2026-07-17-v1 \
  --confirm-sha=<VALIDATED_SHA256>
```

Rollback deletes only the symbol/date slices created by this run. It preserves unrelated symbols and dates. If PHP stops after the delete transaction but before writing the rollback receipt, rerun the same rollback command to finalize the already-empty slices.
