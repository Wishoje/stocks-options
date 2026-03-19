# EOD Trust Plan

This document defines a practical plan for making EOD indicators trustworthy by default instead of relying on manual spot checks after the fact.

It is meant to complement:

- [EOD Data Integrity Audit](eod-data-integrity-audit.md) for current-state risks
- [EOD GEX Cache and Prewarm](eod-gex-cache.md) for cache behavior and warm paths

## Goal

For any anchor date, the system should answer one question clearly:

"Is this EOD run good enough to publish?"

If the answer is no, APIs should continue serving the last known-good EOD run instead of exposing partially rebuilt or mixed-generation data.

## Current Status

As of 2026-03-19, the EOD stack is materially healthier after:

- selector-based snapshot choice per expiry
- provenance columns like `source_chain_date`
- bounded DEX history / forward windows
- current-forward filtering for vol tables
- delete-and-rebuild behavior for `unusual_activity`

Those changes reduce bad outputs, but they do not yet provide a full publish gate or run-level health model.

## Design Principles

1. Raw ingest and derived publish should be separated.
2. Each EOD build should be auditable as a single run.
3. Derived tables should be rebuilt by slice, not incrementally mixed.
4. Failures should be classified as hard-fail vs soft-fail.
5. APIs should prefer the latest published-good run, not simply the latest available rows.

## Target Operating Model

### 1. Introduce an EOD run record

Add an `eod_runs` table that represents one EOD build attempt.

Suggested fields:

- `id`
- `anchor_date`
- `status` (`building`, `auditing`, `published`, `failed`)
- `started_at`
- `finished_at`
- `published_at`
- `code_version` or deploy SHA
- `meta_json`

Each daily rebuild writes against one `run_id`.

### 2. Add per-run health results

Add an `eod_health_runs` or `eod_run_checks` table.

Suggested fields:

- `run_id`
- `symbol`
- `check_name`
- `severity` (`hard`, `soft`, `info`)
- `status` (`pass`, `warn`, `fail`)
- `details_json`
- `created_at`

This becomes the source of truth for whether a run is publishable.

### 3. Gate publish on audits

Do not publish a new run until audits complete.

If a run fails hard checks:

- mark the run `failed`
- keep serving the last `published` run
- alert operators

If a run only has soft warnings:

- allow publish
- record warnings in health tables and API metadata

### 4. Read APIs from published runs

The strongest model is:

- derived tables store `run_id`
- APIs resolve the latest `published` run for the requested anchor date
- APIs read only rows from that run

This prevents mixed-generation tables from leaking to users.

## Required Audit Checks

The following checks should run automatically after EOD compute.

### Hard-fail checks

- raw chain exists for required symbols on or before anchor date
- selector resolves a snapshot for required expiries
- `iv_term` has no rows with `exp_date < data_date`
- `iv_skew` has no rows with `exp_date < data_date`
- `dex_by_expiry` stays inside the allowed history / forward window
- `unusual_activity` has no duplicate `symbol/data_date/exp_date/strike`
- major APIs build successfully for canary symbols

### Soft-fail checks

- selector falls back from balanced snapshot to latest-any snapshot
- `source_chain_date` lags the anchor date for some expiries
- sparse-history `unusual_activity` rows emit with `confidence = low`
- row counts are materially below trailing median for non-core symbols

### Canary checks

At minimum run:

- `SPY` `30d`
- `QQQ` `90d`

Suggested canary commands:

```bash
php artisan gex:audit-eod SPY --timeframe=30d --refresh
php artisan gex:audit-eod QQQ --timeframe=90d --refresh
```

## Rebuild Rules

For derived daily slices, prefer full slice rebuilds over partial upserts.

Apply this rule to:

- `dex_by_expiry`
- `iv_term`
- `iv_skew`
- `expiry_pressure`
- `unusual_activity`

Recommended pattern:

1. choose the EOD slice (`symbol`, `anchor_date`, optional `run_id`)
2. delete existing derived rows for that slice
3. insert the freshly computed set
4. audit the resulting slice

## API Metadata

Every EOD-facing response should eventually expose enough metadata to explain freshness.

Recommended fields:

- `data_date`
- `run_id`
- `published`
- `stale`
- `source_chain_date` or a summarized source map
- `quality_warnings`

This makes frontend trust decisions and support debugging much easier.

## Recommended Rollout

### Phase 1. Low-risk hardening

Scope:

- keep current schema mostly intact
- add scheduled audits
- add hard-fail / soft-fail classification
- add a daily operator runbook

Outcome:

- bad runs are detected consistently
- publication is still implicit, but visibility improves

### Phase 2. Publish gate

Scope:

- add `eod_runs`
- add health results table
- mark a run `published` only after audits pass

Outcome:

- the system knows whether a date is good
- operators no longer infer trust from table spot checks alone

### Phase 3. Run-scoped reads

Scope:

- add `run_id` to derived tables
- update APIs to read from the latest published run

Outcome:

- partial rebuilds cannot leak into production views

### Phase 4. UI trust surfacing

Scope:

- show freshness and warning badges in EOD views
- expose health summary to admins

Outcome:

- users and operators can distinguish fresh, stale, and degraded outputs

## Minimal First Version

If we want the smallest high-value implementation first, do this:

1. Add one daily audit command that checks DEX, vol, skew, and UA integrity.
2. Store audit results in a DB table.
3. Add scheduled canary audits for `SPY` and `QQQ`.
4. Add a simple published-good marker for each `anchor_date`.

That gets most of the operational value without fully converting every table to `run_id` immediately.

## Suggested Daily Flow

1. ingest raw EOD chain data
2. compute derived tables
3. run audits
4. if hard-fail checks pass, mark run published
5. warm heavy-symbol caches
6. alert on warnings / failures

## Operator Runbook

If an audit fails:

1. confirm deploy version and restarted workers
2. rerun the failed compute jobs synchronously for affected symbols
3. rerun canary audits
4. publish only after hard-fail checks pass

If a rebuild succeeds but warnings remain:

1. publish if warnings are soft only
2. record the warning details
3. investigate input coverage or selector fallback quality

## Open Implementation Questions

- Should publish be global per date or symbol-scoped?
- Which symbols are hard requirements for publish?
- How much source-date lag is acceptable before a warning becomes a hard fail?
- Should APIs expose run-level health to all users or admin-only consumers?

## Recommendation

Build this in order:

1. audit table + scheduled audits
2. published-good marker per date
3. `eod_runs` and run-level state
4. `run_id` on derived tables and run-scoped API reads

That sequence keeps risk low while steadily reducing the chance of silent bad EOD output.
