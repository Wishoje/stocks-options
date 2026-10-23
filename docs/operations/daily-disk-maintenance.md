# Daily disk maintenance

Run `scripts/disk_maintenance.py` once per day at 04:00 America/New_York on each production host. Run the database portion only on the MySQL host. The job uses the OS scheduler and a local file lock, not a Laravel queue, Redis lock or database-backed scheduler.

## Storage and policy

Install the two scripts in a private `<site>/.disk-maintenance/bin` directory. Keep private configuration, reports and the lock in `<site>/.disk-maintenance`. This location survives application release changes and is outside the public document root.

Example private `config.json`:

```json
{
  "site": "/home/forge/gexoptions.com",
  "php": "/usr/bin/php8.3",
  "database": true,
  "email": "OPERATIONS_EMAIL"
}
```

Set `database` to false on the worker. Use permissions 0700 for directories and 0600 for configuration, scripts and reports. Invoke the Python script explicitly with `/usr/bin/python3`.

Pressure means at least 80% of total filesystem space is unavailable to the Forge user, or less than 10 GiB is available. Filesystem reserved space is included in this calculation. Cleanup stops as soon as pressure clears.

Eligible filesystem objects:

- Recognized Laravel, scheduler, queue-monitor and EOD-repair rotated logs older than 30 days. Undated active logs are protected.
- Recognized release directories older than 14 days, including every contained object. Preserve the active release, the two newest other releases and the previous release named in the latest deployment audit. Shared `.env` and storage symlinks are unlinked without following them.
- Regular Forge-owned files older than seven days under `/tmp/node-compile-cache`. Other temporary files, application caches, sessions, uploaded files and backup archives are excluded.

Only `option_snapshots`, `intraday_option_volumes` and `calculator_expiry_publication_rows` are eligible for compaction. No database rows are deleted by this command. Existing pruning jobs retain their current retention periods and schedule.

Compaction requires a local InnoDB file-per-table table without fulltext indexes, at least 1 GiB and 30% estimated reclaimable allocation, and free data/temp filesystem space exceeding twice the estimated live table size plus a 128 MiB DDL log allowance and a 5 GiB reserve. Statistics are refreshed on the inspection connection. Estimates are conservative, not guarantees. The job rechecks immediately before DDL and skips a busy database. It explicitly requests online DDL with no copy/locking fallback and bounds lock waits to three seconds. A separate connection requests cancellation if free space falls below 5 GiB or runtime exceeds ten minutes. No further table starts after failure or incomplete cancellation. Cancellation and rollback can themselves take time.

Compaction runs only under disk pressure and between 03:00 and 06:00 ET. Insufficient workspace produces an attention report and direct email rather than aggressive retention changes. Large tables can require additional capacity or a separate maintenance plan.

## Preview and scheduling

Preview performs read-only inspection, acquires a local lock and prints JSON. It does not delete files, rebuild tables, send mail or update daily reports:

```bash
/usr/bin/python3 SITE/.disk-maintenance/bin/disk_maintenance.py --config SITE/.disk-maintenance/config.json
```

After reviewing the preview and testing direct email, add the following user cron entry, replacing `SITE` with the absolute site directory. Production hosts use UTC. Both UTC candidates are intentional: the script permits disk inspection only at 04:00 ET, once per date, accommodating daylight saving changes.

```cron
0 8,9 * * * /usr/bin/python3 SITE/.disk-maintenance/bin/disk_maintenance.py --config SITE/.disk-maintenance/config.json --apply --scheduled >/dev/null 2>&1
```

`--apply` without `--scheduled` runs immediately, but still cannot compact during market hours. `--test-email` sends a configuration test using the application's existing mail credentials. Existing Mailtrap accounts use its transactional HTTPS API with the configured SMTP API token. This avoids the installed Laravel version's implicit TLS mapping on SMTP port 587. Other real mail transports use direct Laravel delivery. It does not use the application queue or database for delivery. Log/array mailers are rejected. No application-wide mail settings are changed.

`latest.json` contains the last applied result. At most one daily report is retained per date, with a 30-day report retention. Pressure, maintenance errors and recovery trigger direct email. Provider acceptance does not prove inbox delivery. A failed send is recorded in `latest.json`.

## Verification and rollback

Run `python3 -m unittest discover -s tests/operations -p 'test_disk_maintenance.py' -v` on Linux. Tests use temporary directories and do not access the production database.

Verify installed script hashes against the repository files, run previews on both hosts, send a test email, inspect the applied report and verify the dedicated cron entry. The daily gate is tested for both summer and winter offsets.

To disable automation, remove only the cron entry containing `.disk-maintenance/bin/disk_maintenance.py`. Do not remove unrelated scheduler entries. Existing retention jobs continue to run. Retained releases permit application rollback; deleted eligible objects are not recoverable through this tool.

The daily job cannot catch every rapid within-day disk increase. Keep independent resource/uptime alerts and database backups. It does not stop running queues, change collection schedules or automatically buy storage.

## Production installation, October 21, 2026

The daily schedule is enabled on both production hosts. The web host runs database and filesystem checks; the worker runs filesystem checks. Existing application scheduler entries were preserved. Both initial applied runs were healthy and performed no deletion or compaction. The first scheduled run is October 22 at 04:00 ET.

Nineteen Linux safety tests passed, including rollback preservation, symlink handling, stale statistics, space admission, market-hours refusal, direct-alert failures and duplicate daily-run prevention. Both hosts' test alerts were accepted by Mailtrap over HTTPS using existing credentials. No application-wide environment changes or process restarts were required.

The initial web preview identified about 13 GiB of reusable calculator-publication allocation, but required about 30 GiB of available working space to compact it. The host had about 15 GiB available. The job correctly skipped that table. Automatic maintenance does not guarantee sufficient reclaimable space; an attention result can still require a capacity increase or a separate maintenance operation.

## Disk recovery and growth reduction, October 23, 2026

The October 22 scheduled run completed and its attention email was accepted. It found no eligible filesystem cleanup and could not admit the calculator-table rebuild. That table occupied about 25.7 GiB, with about 12.9 GiB reusable internally. Deleting expired rows makes this space reusable by MySQL; it does not necessarily return it to the operating system. The guarded rebuild required about 30.2 GiB free, above the 8.8 GiB available before recovery.

An explicitly approved online undo-log operation reclaimed 5.8 GiB, raising available space to 14.6 GiB. A third 16 MiB undo tablespace, `gex_undo_maintenance`, permits the two default undo tablespaces to be reclaimed one at a time while at least two remain active. Both default tablespaces were returned to active state. MySQL's automatic undo truncation remains enabled. No application rows, retention periods or service configuration were removed, and no service restart was needed. The operation audit is private in `.disk-maintenance/undo-online-reclamation-20261023.json`. Manual SQL undo reclamation is not part of the daily Python job.

New wall-interaction assessments use `wall-interaction-checkpoint.v1` storage metadata. They preserve current reactions and setups, all events, first-eligibility review seeds, rules, the original price-bundle hash and the ordered set of source wall-observation hashes. The existing source wall observations and completed-bar bundles remain stored. Repeated derived `readings`, `setups.readings` and `bars` arrays are omitted from these new assessment records. If the required input hashes cannot be verified, the full assessment is retained instead. Existing records are unchanged.

The dashboard and exports still build complete timelines from preserved source observations and price bars. Scanner, alerts and historical review read the retained current states and seeds. Storage metadata distinguishes checkpoints from older full assessment records. There are no endpoint, calculation, collection-cadence, migration or environment changes.

A read-only benchmark of 30 late-session production assessments reduced their combined encoded size from 15,264,234 to 820,366 bytes, a 94.63% reduction. Current reactions, setup states, events and review seeds matched exactly. This sample describes assessment storage, not all database growth. Thirty-one focused tests passed with 194 assertions across checkpoint integrity, collection, timeline reconstruction, setup history and Scanner/alert projection.

The `walls:prune-snapshots` schedule covers `symbol_wall_snapshots`, not the append-only `wall_observations` evidence table; `wall_foundation.planned_retention_days` is a plan rather than an implemented evidence-deletion job. Retained evidence has not been removed to clear this incident. Monitor its growth separately; the calculator recovery below does not introduce a wall-evidence retention policy.

## Daily calculator-version retention

`calculator:prune-publications --hours=168 --batch=25 --max-seconds=900` runs at 03:15 ET on the scheduler host when `CALCULATOR_PUBLICATION_PRUNING_ENABLED=true`. Keep this flag disabled on the web host. The command can run manually outside the regular market session; `--dry-run` previews candidates without archives or database deletion. A shared lock prevents overlapping invocations. The minimum supported retention is seven days.

The existing whole-run pruning could not remove obsolete sibling versions when one expiration still protected the run. A read-only October 23 inventory found 30,774 eligible old publications containing 5,410,757 contract rows. Individual-version cleanup preserves all current and previous catalog runs, all current and previous expiration publications, recent versions, incomplete runs and cross-run references. It rechecks those conditions under the same run/head locks used by publication and rollback. Parent run metadata and protected sibling versions remain available. Historical references for removed versions are marked `pruned` and retain the archive filename.

Before each deletion, the worker writes a private gzip JSON-lines archive containing full run headers, publication headers, original expiration references and every contract row, including its ID. It verifies per-publication counts, closes and re-reads the gzip stream, and compares the complete decoded SHA-256. A failed archive or count mismatch prevents deletion. The archive manifest records compressed and decoded hashes and is marked committed only after the database transaction succeeds. An uncommitted or incomplete archive is preserved for operator review. No current or previous head is moved by cleanup.

Archives live in the worker's shared `storage/app/private/maintenance/calculator-prune` directory. The separate worker filesystem supplies the archive capacity; it does not consume the web database disk. The daily disk job removes only committed, checksum-verified archive/manifest pairs older than 30 days, even when the worker has ample free space. Recent, uncommitted, mismatched, unknown and symlinked archives are preserved. Other backups remain excluded. Keep the archive and its manifest together if manually copying them for recovery. Restoration is an operator database operation, not an automatic rollback or head change.

The command writes `latest.json` and one dated report in the same private directory. Add its canonical path to the worker disk-check configuration as `calculator_retention_report`. The daily check alerts when this report is absent, older than 26 hours, failed or incomplete, even if the worker disk itself is healthy. Manual disk checks retain the scheduled-run date so a second daily invocation cannot bypass the once-per-date gate. Reports also record the free-space change since the preceding check.

EOD `options:prune-chain-data` now runs daily at 03:35 ET instead of weekly. Its 180-day retention and existing publication fences remain unchanged. Intraday-volume, legacy calculator, wall-summary and hot-symbol retention retain their existing windows. Wall-observation evidence, saved AI exports, failed-job diagnostic history and user/account records are not removed by this change.

After a version cleanup that deleted rows, the command refreshes calculator-table optimizer statistics. The 04:00 database maintenance job then re-evaluates physical reclamation under its original workspace, idle-database and time-window guards. It still refuses a rebuild without sufficient room. An archived row removal can create reusable database space before a table rebuild returns it to the filesystem.

Validation passed 28 focused PHP tests with 271 assertions under isolated SQLite and 24 tests with 216 assertions under guarded local MySQL. The first combined MySQL run encountered an existing fixture-order mismatch in `option_snapshots`; the successful rerun initialized the full repository fixture before the older minimal pruning fixture. No production schema was changed for these tests. Twenty-five Linux maintenance tests passed using temporary files only, including archive checksums, retention age, pending archives, protected releases, manual daily-gate preservation and cleanup-failure monitoring.

The initial production catch-up archived and removed 30,774 old calculator publications containing 5,410,757 contract rows. All 1,232 committed archive checksums were independently verified; their combined size was 296.53 MiB on the worker. Current and previous catalog/expiry heads remained byte-for-byte unchanged, with no orphan pointers. The calculator retained 1,597,623 contract rows. The worker's daily retention flag was then enabled and both independent disk-check installations were updated without changing their cron entries.

A physical maintenance step rebuilt the fragmented calculator contract index by creating an equivalent temporary unique index and atomically restoring the original name. An equivalent unique index remained present throughout. The initial same-name drop/add was optimized away by MySQL, so the explicit copy was required. All retained rows and every final index definition were preserved. The exact procedure was first validated against 10,000 synthetic rows in local MySQL. It used the same conservative workspace and cancellation reserve for the target index and ran outside market hours. After this step, the original whole-table compaction admission check passed; no workspace guard was weakened.

The busy-query guard excludes idle MySQL daemon threads. It counts executing `Query` and `Execute` statements with SQL text rather than every non-sleeping process. Active transactions and pending metadata locks remain separate admission checks. An actual nightly retention delete still blocks compaction until it finishes.

Intraday-volume pruning keeps its 24-hour window and 03:00 ET schedule, but caps each transaction at 10,000 rows. A primary-key cursor avoids rescanning earlier deleted pages for every batch. The command freezes its maximum ID at startup, leaves later inserts for the next run, and rechecks each row's capture time in the DELETE. Rows refreshed after candidate selection are preserved. This change does not interrupt an already-running older pruning process.

## Verified production recovery

At 03:21 ET on October 23, the existing intraday-volume pruning process finished. Two separate read-only checks confirmed no expired rows remained under that run's cutoff, no active transactions or long statements were present, and no metadata locks were pending. The normal guarded maintenance command then rebuilt only `calculator_expiry_publication_rows`. It reclaimed 25.086 GiB, reducing that table's physical file from 27,611,103,232 to 675,282,944 bytes. Available web-server space increased from 14.22 to 39.31 GiB; its pressure measurement fell from 80.99% to 47.45%. No workspace or cancellation guard was bypassed.

The final audit retained all 1,597,623 remaining contract rows, identical current/previous head hashes, and zero orphan heads. Watchlist, expiry/strike GEX, DEX, GEX levels and intraday-wall endpoints returned HTTP 200. All 26 queue workers were running with no recent worker errors. The maintenance report was healthy and its recovery email was accepted. Both hosts' installed maintenance scripts matched the deployed source, and the worker's first scheduled calculator-version cleanup completed successfully. Thirteen focused retention/schedule tests passed with 113 assertions after the intraday batch change. No disk resize was required.
