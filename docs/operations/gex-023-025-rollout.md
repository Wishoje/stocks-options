# GEX-023 through GEX-025 rollout and manual checks

## Current gate

Do not push this release into the automatic production deployment until a current database backup and restore procedure have been confirmed. The release includes three new snapshot-health tables. No production schema operation has been performed during local implementation.

The web/MySQL release is `/home/forge/gexoptions.com/current`. The worker release is `/home/forge/stocks-options-ss7u2nu2.on-forge.com/current`. Use `php8.3` on both. Confirm both servers reach the intended commit before enabling a phase.

## Phase 1: additive index

1. Record backup identifier, completion time, scope and restore procedure. Verify fresh free space on the database data/temp filesystems, not the worker disk. Check for long transactions and metadata-lock waits.
2. Deploy the reviewed code with new behavior flags disabled. Allow the normal web migration step to create the additive health tables. Do not enable tracking before both releases and the schema are ready.
3. On the web/MySQL host, run `php8.3 artisan gex:indexes:intraday`. This is read-only. Review row count, schema, existing index and disk admission results.
4. Only after preflight, apply with a 120-second outer deadline:

   ```bash
   timeout 120 php8.3 artisan gex:indexes:intraday \
     --apply \
     --backup-reference='VERIFIED_BACKUP_ID' \
     --database-free-bytes=VERIFIED_FREE_BYTES
   ```

   Replace both placeholders with verified evidence. Do not reuse a historical free-space value. The command does not independently verify these attestations.
5. Re-run the read-only inspection. Verify the exact index, unchanged unique constraints, current IV results and prune query plan. Observe write latency, queue wait and database load.

Admission refuses more than one million actual rows or insufficient space. Five seconds bounds metadata waiting, not total DDL duration. If timeout/disconnection occurs, inspect active DDL, metadata locks and index state before retrying; server cleanup can continue after the client exits. Leave an added index installed on application rollback. There is no automatic DROP path.

Do not remove duplicates in this release. Observe at least one full release and market session, then review one removal at a time with fresh plans, write metrics and foreign-key/uniqueness evidence.

## Phase 2: expiration queries

The guarded helper creates a protected environment backup and changes only the named flags. From each current release, run `php8.3 docs/operations/gex-023-025-configure.php universe-on`, then rebuild configuration. `universe-off` is the corresponding rollback mode.

Set on both web and worker:

```dotenv
GEX_EXPIRATION_UNIVERSE_ENABLED=true
GEX_EXPIRATION_SHADOW_ENABLED=false
```

Rebuild Laravel's config cache on each release and restart this site's queue workers using the existing Forge workflow. Shadow may be enabled briefly for comparison, but its extra legacy queries must not be included in performance claims. A mismatch logs `gex.expiration_shadow.mismatch` and returns legacy selection.

Compare all six timeframes against the pre-activation baseline using the same clock and unchanged source data. Check response hashes, ordered expiration lists, data dates, strike counts, totals, walls and deltas. Revert the universe flag to false if parity fails.

## Phase 3: prepare and activate manifest reads

Prepare tracking before switching request reads:

```dotenv
EOD_SNAPSHOT_HEALTH_ENABLED=true
EOD_SNAPSHOT_HEALTH_READ_ENABLED=false
```

For initial activation, drain this site's in-flight raw-data jobs before enabling tracking. Do not mix untracked old workers with tracked writers. Pause only the relevant site workers, let current jobs finish, confirm no old raw-writer CLI process remains, update both config caches, then start the site's workers. The website can remain available. Do not delete queued jobs.

After verifying that drain, run `php8.3 docs/operations/gex-023-025-configure.php tracking-on --writers-drained` from each current release. The argument is an operator attestation, not an automatic process check. Rebuild both configuration caches before restarting the stopped workers.

Wait for tracked successful EOD publication. Existing raw rows are not automatically certified by deployment. Keep request reads on the legacy path until the intended symbols have clean, verified manifests.

On both releases, inspect:

```bash
php8.3 artisan gex:snapshot-health --symbols=SPY,QQQ,IWM,TSLA,AAPL,V
```

Expected before activation: tracking enabled, read switch false, clean heads and `state=ready` for covered symbols. `no_certified_generation` means no qualified publication is available yet. `raw_mutation_unpublished` requires checking the writer, not forcing a health rebuild.

For a missing/corrupt manifest whose head is already certified and clean:

```bash
php8.3 artisan gex:snapshot-health --symbols=SPY,QQQ,IWM,TSLA,AAPL,V --repair
```

This queues a coalesced internal job. It does not fetch raw data, clear failed ownership or manufacture a completed generation. Wait for the repair run to finish and inspect again.

Web and worker currently have different side-ratio policies. Preserve both. Run inspection/repair on the web release to prepare the web policy; the queued job retains that policy when it runs on the worker. A manifest prepared only for the worker policy is not interchangeable.

After old/new selector and payload checks pass for the intended symbols, set globally on both nodes:

```dotenv
EOD_SNAPSHOT_HEALTH_READ_ENABLED=true
```

Use `php8.3 docs/operations/gex-023-025-configure.php reads-on` on each release after readiness checks. Its six-symbol gate is a smoke check, not proof that every symbol has a manifest. Before global activation, also test uncovered symbols such as MSFT and NVDA: correct repeated requests must use the separately fenced compatibility cache rather than repeatedly rebuilding from raw rows. This short-lived response cache does not certify historical data. Dirty or present-but-uncertified mutations remain ineligible for it.

The production deployment check found that native MySQL JSON numbers could change the final bit of a stored ratio. Deploy the lossless canonical-JSON envelope fix before activating reads. Existing invalid manifests are repaired through the normal command above; no new provider fetch or raw-data rewrite is required. A rebuild must pass its persisted integrity check before completing.

Rebuild config caches and restart the site's workers. On the web release, warm the web-policy payloads:

```bash
php8.3 artisan gex:warm-cache \
  --symbols=SPY,QQQ,IWM,TSLA,AAPL \
  --timeframes=7d,14d,30d,90d
```

Inspect health first. A successful warm command alone does not prove that the manifest path was used. Do not treat worker-policy warming as web-policy warming.

## Manual browser checks

1. Open Strikes for SPY, QQQ, IWM and TSLA. Select 1M, then 3M, then return to 1M. Confirm data loads without a 504 and the repeat view is responsive.
2. Repeat with AAPL and a normal watchlist symbol such as V. Check 0D, 1D, 1W and 2W where expirations exist.
3. Check expiration lists, data date, strike count, call/put open interest, volume, walls and daily/weekly deltas against the pre-activation capture. Net GEX does not have to grow as a timeframe gets longer; calls and puts can offset.
4. Refresh the browser and revisit the same symbol/timeframe. With an idle, completed publication, displayed values should remain consistent. A new completed publication can legitimately change them.
5. Check a newly added symbol. Existing preparing/partial states must remain visible until its phases complete. A missing expiration set is not a timeout; for example, the measured MCK 0D/1D/1W sets were empty on September 6.
6. Open Intraday and Calculator for the same symbols. Verify their latest-data indicators and expiration controls still work. These cards do not change calculator arithmetic or live-total storage.

For repeatable server measurements, use `docs/operations/gex-023-025-read-probe.php` from the web release. It uses the current clock by default. Set `GEX_PROBE_CLOCK` only for an explicitly frozen comparison with unchanged source rows. It makes bounded market-data reads, forbids provider HTTP calls, and may use normal response caching or coalesced manifest repair. It is not an authenticated-browser benchmark.

## Rollback and completion

Rollback reads with `EOD_SNAPSHOT_HEALTH_READ_ENABLED=false`; leave tracking enabled. Roll back expiration resolution separately with `GEX_EXPIRATION_UNIVERSE_ENABLED=false`. Rebuild config caches and restart the relevant workers. Keep additive tables and indexes. Do not run destructive migration rollback or clear shared Redis.

The guarded rollback commands are `php8.3 docs/operations/gex-023-025-configure.php reads-off` and `php8.3 docs/operations/gex-023-025-configure.php universe-off`. They do not require a working database and preserve unrelated environment values.

If tracking must be disabled, record that interval as an unobserved-write gap. Do not re-enable reads against old heads without a reviewed fresh-publication check. Crashed active mutation receipts require proving the old writer is gone and validating the affected data; `--repair` intentionally cannot clear them.

Completion requires production parity/timing checks after each activation and a market-session observation of writer latency, database load, queue age and failures. Sunday fixture timings do not replace that observation. Duplicate-index removal is still a later GEX-023 step.
