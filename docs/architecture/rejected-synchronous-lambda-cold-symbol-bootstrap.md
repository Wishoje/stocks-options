# Rejected synchronous Lambda cold-symbol bootstrap

Status: Rejected
Cards: GEX-006, GEX-036
Decision date: 2026-07-17

## Context

An uncommitted experiment attempted to accelerate newly added symbols with a synchronous AWS Lambda Function URL. Laravel waited 2.5 seconds for the function. If that request timed out, Laravel released its invocation claim and immediately queued the existing local bootstrap.

The Lambda invocation continued after the client timeout. It could still fetch provider data and call Laravel ingestion endpoints while the local queue processed the same symbol. The Lambda and local jobs did not share one durable ownership record or idempotency key. This created two authoritative writers for one user action.

The experiment also introduced public internal-ingestion routes, HMAC callback middleware, request-provided callback destinations, synchronous database writes, and generated Python bytecode. Those surfaces increased authentication, partial-write, deployment, and rollback risk.

## Decision

The production cold-symbol path remains local Laravel queue work through `BootstrapUserSymbolJob::dispatchIfNeeded()`.

The repository contains no Function URL client, Lambda fallback, cold-symbol callback route, callback middleware, Lambda configuration, or tracked Lambda artifact. The former callback paths return `404`:

- `POST /api/internal/cold-symbol/eod-ingest`
- `POST /api/internal/cold-symbol/intraday-ingest`

Configuration that resembles the removed experiment does not activate another path. There is no disabled callback surface to maintain.

The local cache claims suppress immediate duplicate dispatches. They do not provide durable ownership for the entire child graph because their TTLs are shorter than the maximum graph runtime. GEX-010 owns the durable bootstrap manifest and resume model.

## Consequences

- One code path owns cold-symbol production writes.
- A Function URL timeout cannot start a local fallback because no Function URL request exists.
- GEX-006 itself does not change the local path's output, ordering, retries, or failure behavior. Queue scheduling changes are owned separately by GEX-004.
- Any manually created AWS function, Function URL, IAM permission, log group, or copied secret must be removed separately from AWS. None can be deployed from the current repository.

## Conditions for reconsideration

GEX-036 may evaluate asynchronous Lambda only after isolated Forge workers are measured and still miss the agreed service-level objective. A future design must use bounded asynchronous units, one durable run manifest, one ownership lease, authenticated result ingestion, provider-wide concurrency control, stale-write protection, and a shadow data-equivalence period. A synchronous Function URL must not coordinate a long-running bootstrap.
