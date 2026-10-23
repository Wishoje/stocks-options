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

Headroom is improved but remains limited. The calculator rebuild still lacks its required workspace. The `walls:prune-snapshots` schedule covers `symbol_wall_snapshots`, not the append-only `wall_observations` evidence table; `wall_foundation.planned_retention_days` is a plan rather than an implemented evidence-deletion job. Retained evidence has not been removed to clear this incident. Sustained growth still requires an explicit archival/retention policy or added capacity.
