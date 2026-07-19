# GEXOptions performance and reliability backlog

Status: implementation-ready backlog
Infrastructure: Laravel Forge on Hetzner
Primary constraint: improve responsiveness and throughput without dropping data, shortening retention, removing fields, reducing expiration coverage, or removing a feature.

## Target outcomes

- A newly added symbol shows useful quote, expiration, EOD, and intraday data during market hours even while scheduled workers are busy.
- SPY, QQQ, IWM, and any other high-work symbol cannot block normal symbols or user-triggered work.
- The calculator shows every available expiration on its first visit and reports partial work truthfully.
- Queue depth, oldest-job age, provider usage, job failures, and data completeness are measurable.
- Performance work preserves endpoint contracts and calculated values. The characterized fixes in GEX-009A, GEX-009B, and GEX-009C are explicit, versioned exceptions for known incorrect behavior.
- Every production change has a tested rollback that does not require deleting data or rolling back a schema.

## Priority model

| Priority | Meaning |
|---|---|
| P0 | Correctness, data integrity, queue safety, or a prerequisite for safe rollout. Complete before performance changes reach production. |
| P1 | Removes a confirmed bottleneck or fixes a major market-hours reliability problem. |
| P2 | Improves efficiency, visibility, or capacity after the main bottlenecks are fixed. |
| P3 | Optional architecture work. Do only when production evidence shows it is needed. |

Priority is separate from queue priority. The proposed runtime order is:

1. User-triggered new-symbol fast work.
2. User-triggered calculator work for the active symbol.
3. Scheduled quote and intraday freshness work.
4. Scheduled heavy-symbol work with its own capacity.
5. Calculator fill, historical fill, derived analytics, and exports.

A Laravel worker does not preempt a job that is already running. Separate workers and bounded jobs provide latency isolation. Queue order alone does not.

## Non-negotiable zero-regression gate

This gate applies to every ticket. Ticket-specific checks are additional.

1. Capture a production baseline before changing behavior:
   - Row counts and stable checksums by symbol, trade date, expiration, option type, and strike.
   - Exact expiration sets.
   - Sums of open interest and volume, plus current derived GEX/DEX values.
   - Latest source timestamp, captured timestamp, and completed-ingestion timestamp.
   - API fixtures for SPY, QQQ, IWM, AAPL, a normal watchlist symbol, and a newly added cold symbol.
   - Calculator fixtures for 0d, 1d, 7d, 14d, 30d, and 90d selections.
   - Queue wait time, run time, oldest-job age, failure rate, provider calls, SQL query count, response p50/p95/p99, CPU, memory, and disk.
2. Run behavior tests against MySQL. SQLite-only success is not sufficient for index, upsert, locking, transaction, and nullable-unique behavior.
3. Compare old and new results in shadow mode where calculations, aggregation, caching, or persistence changes. Canonical endpoint payloads must match except for documented ordering/timestamp differences, normalized generated IDs/ingestion timestamps, an agreed floating-point tolerance, and the explicitly approved GEX-009A/GEX-009B/GEX-009C corrections.
4. Keep schema changes additive for at least one release. Backfill and verify before switching reads. Do not drop old columns, tables, indexes, or code paths in the same release that introduces their replacement.
5. Preserve every current field, contract, expiration, retention window, endpoint contract, and background enrichment unless a separate product decision explicitly changes it.
6. Do not improve speed by lowering page caps, sampling contracts, discarding queued jobs, truncating a queue, deleting history, or globally flushing the cache.
7. Gate behavioral changes with configuration or a feature flag. Rollback must be possible by switching configuration and deploying the previous code. It must not require a destructive database rollback.
8. Drain or complete jobs during queue-driver and queue-name cutovers. Old and new workers must not process the same unprotected work concurrently.
9. Verify a current database backup and a restore procedure before data migrations. Log no credentials, provider tokens, callback secrets, or raw authorization headers.
10. Attach test output, before/after measurements, data-equivalence results, rollout steps, and rollback steps to the ticket before closing it.

## Ordered implementation index

The sequence below is the recommended delivery order. Tickets at the same priority still follow their dependency order.

The GEX identifiers are planning IDs for this document. Jira may assign different issue keys.
The index is the authoritative execution order. Detailed sections use stable planning-ID order for lookup.

| Order | ID | Priority | Summary | Depends on |
|---:|---|:---:|---|---|
| 0 | GEX-000 | P0 | Rotate exposed production credentials and verify containment | — |
| 1 | GEX-001 | P0 | Inventory the Forge/Hetzner production baseline | — |
| 2 | GEX-002 | P0 | Build the data and API no-regression harness | GEX-000, GEX-001 |
| 3 | GEX-003 | P0 | Define safe queue timeout, retry, and idempotency contracts | GEX-001 |
| 4 | GEX-004 | P0 | Isolate queue lanes with dedicated Forge workers | GEX-003 |
| 5 | GEX-005 | P0 | Make Forge deployments, worker restarts, monitoring, and rollback safe | GEX-004 |
| 6 | GEX-006 | P0 | Disable the incomplete synchronous cold-symbol Lambda path | GEX-002 |
| 7 | GEX-007 | P0 | Stop calculator scheduler amplification and use truthful job state | GEX-002, GEX-003 |
| 8 | GEX-011 | P0 | Authenticate, throttle, and coalesce work-producing endpoints | GEX-003 |
| 9 | GEX-008 | P0 | Add a calculator run manifest and atomic completeness model | GEX-002, GEX-007, GEX-011 |
| 10 | GEX-009 | P0 | Fix calculator first-load expiration completeness end to end | GEX-008 |
| 10.1 | GEX-009A | P0 | Keep calculator option type, contract, premium, and payoff coherent | GEX-002 |
| 10.2 | GEX-009B | P0 | Remove fabricated underlying prices from calculator results | GEX-002, GEX-011 |
| 10.3 | GEX-009C | P0 | Calculate DTE with exchange-date semantics | GEX-002 |
| 11 | GEX-012 | P0 | Replace nullable option-total uniqueness safely | GEX-002 |
| 12 | GEX-013 | P0 | Publish daily chain snapshots atomically | GEX-002 |
| 13 | GEX-014 | P0 | Remove global cache flushing from watchlist preload | GEX-002 |
| 14 | GEX-010 | P0 | Deliver new symbols through fast and fill phases | GEX-002, GEX-003, GEX-004, GEX-006, GEX-012, GEX-013 |
| 15 | GEX-015 | P1 | Split calculator fetching into bounded expiration jobs | GEX-008, GEX-004 |
| 16 | GEX-016 | P1 | Coalesce calculator prime requests and reserve interactive capacity | GEX-011, GEX-015 |
| 17 | GEX-017 | P1 | Move the production queue to Redis with a lossless drain | GEX-003, GEX-005 |
| 18 | GEX-018 | P1 | Dispatch one intraday symbol per job and isolate heavy symbols | GEX-004, GEX-017 |
| 19 | GEX-019 | P1 | Replace per-contract intraday writes with chunked upserts | GEX-002, GEX-018 |
| 20 | GEX-020 | P1 | Separate market-data time from ingestion freshness | GEX-011, GEX-018 |
| 21 | GEX-021 | P1 | Harden provider rate limits and add adaptive queue backpressure | GEX-004, GEX-018 |
| 22 | GEX-022 | P1 | Coalesce quote refreshes and restore market-session scheduling | GEX-003, GEX-004, GEX-021 |
| 23 | GEX-023 | P1 | Add missing indexes and remove proven duplicate indexes | GEX-002, GEX-008, GEX-012, GEX-013, GEX-015, GEX-019 |
| 24 | GEX-024 | P1 | Make GEX expiration and strike aggregation set-based | GEX-002, GEX-023 |
| 25 | GEX-025 | P1 | Add snapshot-health manifests and early cache hits | GEX-013, GEX-024 |
| 26 | GEX-026 | P1 | Use symbol-versioned invalidation and isolated Redis cache/locks | GEX-014, GEX-017, GEX-025 |
| 27 | GEX-027 | P1 | Batch unusual-activity pricing lookups | GEX-002, GEX-023 |
| 28 | GEX-028 | P1 | Make the expiration batch endpoint set-based | GEX-002, GEX-023 |
| 29 | GEX-029 | P1 | Read wall spot prices directly from underlying quotes | GEX-002 |
| 30 | GEX-030 | P1 | Remove duplicate frontend requests, polling, and chart renders | GEX-009, GEX-020 |
| 31 | GEX-031 | P2 | Batch AppShell unusual-activity loading by watchlist | GEX-027 |
| 32 | GEX-032 | P2 | Add queue dashboards, wait-time alerts, and capacity reports | GEX-005, GEX-017 |
| 33 | GEX-033 | P2 | Tune MySQL, PHP-FPM, and worker memory from production evidence | GEX-001, GEX-023, GEX-032 |
| 33.1 | GEX-033A | P2 | Characterize, test, and version calculator numerical assumptions | GEX-002, GEX-009A, GEX-009C |
| 34 | GEX-034 | P2 | Run a market-hours load and failover certification | GEX-010 through GEX-033A |
| 35 | GEX-035 | P2 | Define thresholds for a dedicated Hetzner worker server | GEX-032, GEX-034 |
| 36 | GEX-036 | P3 | Re-evaluate asynchronous Lambda only if local isolation misses the SLO | GEX-034, GEX-035 |

## P0 tickets

### GEX-000 — Rotate exposed production credentials and verify containment

Type: Security incident
Epic: Security and operations
Depends on: none

Problem and scope:

- Production environment contents containing active credentials were disclosed in an external support conversation.
- Follow docs/production-credential-rotation-runbook.md without copying any current or retired value into the repository, tickets, logs, screenshots, shell history, or chat.
- Rotate Stripe secret/webhook, Laravel APP_KEY, SMTP, MySQL, Redis, Massive/Polygon, Finnhub, SteadyAPI, and any externally used Yahoo credentials.
- Use staged old/new overlap only where the provider supports it and only for the verification window. Revoke every old credential afterward.
- Remove/redact the disclosed message/attachment where possible and scan the repository/history for credential material.

Acceptance criteria:

- Every disclosed credential is replaced and the former value is revoked/deleted.
- Both Forge releases use the intended replacements and no secret value is stored in repository documentation.
- APP_KEY rotation invalidates existing sessions as planned; users can sign in again.
- Database, Redis queues/cache/locks, scheduler, Stripe/webhooks, mail, and market-data integrations pass the runbook verification matrix.
- A redacted incident record contains owners, dates, and status only.
- Repository and history scans report no production credential values.

Ticket-specific regression proof:

- Execute the runbook verification matrix, prove one queue job completes exactly once, confirm payment webhook signature validation, and attach redacted provider revocation/rotation status without values.

### GEX-001 — Inventory the Forge/Hetzner production baseline

Type: Task
Epic: Safety and observability
Depends on: none

Problem and scope:

- Record the Hetzner server type, vCPU, RAM, disk type/free space, network, operating system, PHP version, MySQL version, Redis version, and whether web, database, Redis, scheduler, and workers share the same server.
- Export the active Forge queue-worker and background-process definitions without secrets.
- Record queue connection, queue names, process counts, timeout, tries, backoff, sleep, memory limit, and restart behavior.
- Record PHP-FPM pool settings, MySQL buffer and connection settings, Redis persistence and eviction policy, cron/scheduler ownership, deploy script, and provider rate limits.
- Capture 24 hours that include market hours for the global regression metrics.

Acceptance criteria:

- A redacted production topology and configuration inventory is attached.
- Baseline metrics include normal and peak market-hour windows.
- Every queue has an owner, producer list, consumer list, oldest-job-age metric, and service-level target.
- Numeric closure thresholds are approved for interactive queue-wait p95/p99, first quote/EOD/intraday/selected-expiry time, full fill time, maximum job wall time/memory, provider concurrency/calls, status-poll request count/backoff, API p95/p99, render/request count, and allowable regression.
- The inventory states whether the current cache and queue use database or Redis drivers.
- No production setting is changed in this ticket.

Ticket-specific regression proof:

- Compare counts from Forge, Supervisor, process list, and Laravel queue configuration; unresolved differences block GEX-003.

### GEX-002 — Build the data and API no-regression harness

Type: Story
Epic: Safety and observability
Depends on: GEX-000, GEX-001

Local implementation and automated proof: [GEX-002 and GEX-003 verification](gex-002-003-verification.md). Production-shaped baseline capture remains subject to the GEX-000 credential and GEX-001 infrastructure gates.

Problem and scope:

- Add repeatable MySQL-backed fixtures and comparison commands for raw EOD chains, intraday volumes, option totals, daily snapshots, GEX endpoints, calculator endpoints, watchlists, unusual activity, expiration pressure, and positioning.
- Add a JavaScript component/unit test command and make CI run PHP tests, JavaScript tests, and the production frontend build.
- Canonicalize unordered JSON before comparison.
- Produce counts, checksums, expiration coverage, sums, timestamps, and field-level samples.
- Include heavy, normal, stale, partial, empty, and newly added symbol cases.

Acceptance criteria:

- The harness fails when a row, expiration, response field, or aggregate is removed or changed unexpectedly.
- It detects partial calculator publication and a stale value overwriting a fresher value.
- It runs locally and in CI against MySQL.
- Test artifacts can compare a production-shaped baseline with a candidate implementation without copying credentials into the repository.

Ticket-specific regression proof:

- Demonstrate one deliberate missing expiration, one changed aggregate, and one response-contract change being caught.

### GEX-003 — Define safe queue timeout, retry, and idempotency contracts

Type: Story
Epic: Queues and infrastructure
Depends on: GEX-001

Local implementation and automated proof: [GEX-002 and GEX-003 verification](gex-002-003-verification.md). Forge rollout and controlled worker-kill acceptance remain open.

Problem and scope:

- Inventory all jobs and set explicit timeout, tries, backoff, uniqueness, overlap, failure, and retry semantics.
- Ensure each worker timeout is safely shorter than its connection retry-after value.
- Move long exports and other exceptional jobs to a separate queue connection configuration when they require a different retry-after/reservation window; changing only the queue name on the same connection is insufficient.
- Give external provider calls connection and request timeouts below the job timeout.
- Define idempotency keys for each write-producing job and a terminal failed state that can be replayed safely.
- Audit every scheduled producer for intentional without-overlap and single-server behavior. If a separate worker server is added, exactly one scheduler leader dispatches shared work; scheduler locks supplement rather than replace durable job idempotency.

Acceptance criteria:

- No supported job can become visible for retry while its original worker may still be writing.
- A retried job cannot duplicate rows, regress an as-of timestamp, or mark a partial run complete.
- Failed jobs include a run ID, symbol, phase, attempt, safe error category, and replay command.
- Timeout and retry settings are documented in code/config and match Forge worker settings.
- Repeated scheduler ticks or multiple hosts cannot dispatch unintended overlapping command runs.

Ticket-specific regression proof:

- Kill a worker during each major job family, restart it, and prove that the final data equals a clean single run.

### GEX-004 — Isolate queue lanes with dedicated Forge workers

Type: Story
Epic: Queues and infrastructure
Depends on: GEX-003
Status: In progress — implemented behind a disabled feature flag; Forge rollout and mixed-load proof remain

Problem and scope:

- Replace one multi-queue worker with separately supervised Forge workers so a running long job cannot consume interactive capacity.
- Start with these logical lanes: bootstrap-fast, intraday-interactive, calculator-interactive, quotes, intraday, intraday-heavy, calculator-fill, calculator-fill-heavy, enrichment/default, and exports.
- Give bootstrap-fast, intraday-interactive, and calculator-interactive reserved processes. Give heavy symbols capped processes. Do not put multiple heavy symbols into one sequential job.
- Add a minimum shared provider concurrency semaphore across every web/queue lane before any new lane or calculator fan-out is enabled. Reserve or fairly allocate provider capacity so scheduled work cannot consume every token. GEX-021 later adds plan-aware rate windows, Retry-After handling, and adaptive backpressure.
- Choose process counts from GEX-001 CPU, RAM, database, and provider limits. Do not blindly multiply workers.
- Document runtime priority and a fairness rule so scheduled work continues during sustained interactive demand.
- Map every current queue name to its replacement and keep temporary consumers for bootstrap, calculator, prime/default, and other legacy queues until each is verified empty.

Acceptance criteria:

- A synthetic 15-symbol scheduled batch cannot prevent a newly added symbol from starting its fast phase within the agreed SLO.
- A long SPY or QQQ job does not block normal-symbol intraday, quotes, or interactive calculator work.
- If one bootstrap-fast or intraday-interactive process hangs, a second cold symbol still starts within the SLO through two reserved processes or a proven maximum job runtime shorter than that SLO.
- Low-priority fill work makes measurable progress under sustained high-priority traffic.
- Supervisor/Forge restarts each process and uses the settings from GEX-003.
- Every Forge definition names one concrete connection/queue, and every legacy queue remains consumed until depth, reserved count, and delayed count are zero.

Ticket-specific regression proof:

- Run mixed heavy, normal, interactive, failed, and retried jobs; compare all completed data with a serial baseline and prove no job is lost.

### GEX-005 — Make Forge deployments, worker restarts, monitoring, and rollback safe

Type: Task
Epic: Queues and infrastructure
Depends on: GEX-004

Problem and scope:

- Update the Forge deploy procedure so new code is activated before queue workers receive a restart signal.
- Add health checks for web, scheduler, every active worker lane, MySQL, disk, and provider reachability. Redis checks are conditional until GEX-017 provisions/validates Redis, then become required.
- Document queue draining, feature-flag rollback, failed-job replay, worker-log locations, and rollback without schema reversal.
- Configure CPU, memory, disk, queue oldest-age, job failure, scheduler, and application-error alerts.
- Set and verify Supervisor graceful-stop/stopwaitsecs above the longest supported job timeout so deployments do not kill a valid transaction prematurely.

Acceptance criteria:

- A zero-downtime deployment restarts all long-lived workers onto the new release.
- A failed health check halts or rolls back the release before old and new behavior process the same unprotected job.
- Operators can identify the worker lane, run ID, and symbol from logs without exposing secrets.
- A tabletop rollback and one non-production rollback drill are attached.
- A deploy/SIGTERM during an active transaction follows the GEX-003 idempotent shutdown/retry contract and does not duplicate or lose work.

Ticket-specific regression proof:

- Deploy while bounded test jobs are queued and running; every job finishes once, and all workers report the new release afterward.

### GEX-006 — Disable the incomplete synchronous cold-symbol Lambda path

Type: Task
Epic: New-symbol reliability
Depends on: GEX-002
Status: Implementation complete — fixture proof and the production AWS-resource check remain

Problem and scope:

- Keep the existing local bootstrap as the production path until GEX-010 is complete.
- Remove or disable the uncommitted synchronous Function URL flow, callback endpoints, configuration, generated artifacts, and fallback behavior that can run Lambda and local work at the same time.
- Preserve unrelated GEX freshness, selector compatibility, dashboard, queue-list, and test-infrastructure changes after reviewing them independently. Do not perform a blanket worktree revert.
- Record the experiment in [the synchronous Lambda rejection ADR](architecture/rejected-synchronous-lambda-cold-symbol-bootstrap.md) so useful observations are retained for GEX-036.

Acceptance criteria:

- Adding a symbol can produce only one authoritative bootstrap run.
- No public/internal callback surface remains enabled without authentication.
- No Lambda timeout can trigger a duplicate local fetch.
- The standard local path passes the cold-symbol fixtures.

Ticket-specific regression proof:

- Simulate a slow/failed bootstrap and prove provider requests and writes occur only once per idempotency key.
- The current local cache claims prove immediate dispatch deduplication and release-on-dispatch-failure. They do not provide durable ownership for the full child graph. Strict provider/write exactly-once proof remains gated by the GEX-010 durable run manifest.

### GEX-007 — Stop calculator scheduler amplification and use truthful job state

Type: Bug
Epic: Calculator correctness
Depends on: GEX-002, GEX-003

Problem and scope:

- Fix the every-five-minute scheduler so “all watchlist symbols are fresh” does not fall through to fallback SPY/QQQ/IWM dispatch.
- Keep the fallback only for the intentional “no symbols configured” case.
- Replace the current pre-dispatch primed marker with separate pending, started, completed, and failed state.
- Coalesce duplicate scheduled work by symbol and run generation.
- Read calculator freshness/version markers in bulk instead of issuing one cache read per candidate symbol.

Acceptance criteria:

- An all-fresh watchlist schedules zero calculator fetches.
- A truly empty configured set follows one documented fallback policy.
- Waiting or failed jobs are never reported as completed/fresh.
- Repeated scheduler runs cannot create a second active job for the same symbol and generation.
- When a dispatch cap applies, candidates are ordered by oldest successful publication so later symbols cannot starve indefinitely.

Ticket-specific regression proof:

- Cover empty, all-fresh, partly stale, fully stale, pending, failed, and recovered scheduler states; compare the dispatched symbol set exactly.

### GEX-008 — Add a calculator run manifest and atomic completeness model

Type: Story
Epic: Calculator correctness
Depends on: GEX-002, GEX-007, GEX-011

Problem and scope:

- Create a run manifest with symbol, purpose/scope, generation, frozen expected expirations, completed expirations, failures, terminal-cursor/capped state, started time, heartbeat, and completed time. Do not infer an expected page count unless the provider supplies an authoritative total.
- Persist a versioned expiration catalog with authoritative source/precedence, discovery horizon, discovered/last-seen times, and per-expiration readiness. Define provider-only versus EOD-only expirations, legitimate no-options versus failed/empty discovery, 0DTE/expired filtering in exchange time, and how catalog changes start the next generation.
- Freeze the expected expiration set only after catalog discovery reaches its terminal cursor. A capped/partial discovery cannot become the authoritative catalog.
- Use a published catalog pointer plus monotonic per-expiration publication pointers. Each expiry response identifies its publication generation, chain source/as-of, snapshot time, and readiness; underlying quote price/time remains separate.
- Publish an expiration only after its staged data is committed and a compare-and-swap proves the candidate is not older than the current pointer. An older bulk child or retry cannot replace a newer interactive publication.
- Publish the full run complete only when every frozen expected expiration succeeds, zero expirations fail, and no discovery/fetch is capped. A terminal failure ends orchestration but yields partial/failed, never complete.
- Prevent a recent partial snapshot from hiding valid expirations from the last complete generation.
- Keep partial completed expirations readable while clearly labeling the overall run as preparing/partial.
- Associate staged snapshot rows with their run/generation and advance pointers transactionally. Cleanup must honor the existing retention window and always retain the current and previous complete publications.

Acceptance criteria:

- API metadata distinguishes no data, preparing, partial, complete, stale, failed, and capped.
- The API adds run ID, catalog state, selected-chain state, expected/completed/failed counts, requested/resolved expiry, publication generation, source time, and safe failure reason while retaining legacy fields for a compatibility release.
- Complete means completed equals expected, failed equals zero, and capped equals false. Partial means at least one usable expiry and at least one missing/failed/capped unit. Failed means no usable candidate or a defined fatal condition.
- A selected expiration cannot make the whole symbol look complete.
- A worker crash between chunks cannot publish a complete generation.
- Responses may use the latest safe per-expiry publications from different runs only when every expiry carries its own generation/source timestamps and the response does not claim one false global snapshot time.
- Catalog and per-expiry pointers can be rolled back independently without rewriting or deleting rows.
- Older complete data remains available until a safe replacement is complete and then remains subject to the existing retention policy.

Ticket-specific regression proof:

- Inject failures before the first chunk, between chunks, after one expiration, at a capped cursor, and before final publication. Complete interactive and older bulk runs in both orders, retry an old run, change the provider catalog mid-run, and verify exact pointers, visible expiration sets, timestamps, rollback, and states.

### GEX-009 — Fix calculator first-load expiration completeness end to end

Type: Bug
Epic: Calculator correctness
Depends on: GEX-008

Problem and scope:

- Make the option-chain API return the latest authoritative completed catalog. Show every catalog expiration with its per-expiry readiness/publication metadata, including expirations whose chain is still preparing.
- Return and consume the backend-resolved expiration.
- Keep polling the lightweight manifest/status endpoint until the run is complete or terminal, rather than stopping when one selected expiration is healthy.
- Use server-directed bounded backoff, an SLO-defined request ceiling, and a visible slow-background state if work exceeds the active polling window.
- Use AbortController/request sequence IDs so a response for an old symbol/expiration cannot replace the current selection; cancel polling, requests, and sleeps on change/unmount.
- Render completed expirations immediately without requiring a browser refresh.

Acceptance criteria:

- First visit shows every expiration in the completed authoritative catalog without a refresh. The oracle is the frozen catalog, not another UI refresh.
- Selected expiration remains valid when the backend resolves a fallback.
- Preparing and partial states are visible and do not erase last-known-good data.
- Polling does not fetch a full chain; it stops at complete, terminal failure, request ceiling, or unmount and can resume status observation safely.
- Each expiration exposes accurate ready/preparing/failed state; GEX-015 owns bounded hydration of every expected expiration, and mere presence in the menu is never reported as hydrated.

Ticket-specific regression proof:

- Automated browser tests cover a run beyond 90 seconds, server Retry-After/backoff, maximum request count, partial chunks, visible last-known-good data, a stale prior generation, a missing selected expiry, rapid symbol/expiry races, navigation away, terminal failure, and recovery.

### GEX-009A — Keep calculator option type, contract, premium, and payoff coherent

Type: Bug
Epic: Calculator correctness
Depends on: GEX-002

Problem and scope:

- The Long Call/Long Put control currently changes the option type without changing or clearing the selected contract and premium.
- Make selection atomic: option type, contract symbol, strike, expiration, premium, IV, payoff inputs, breakeven, and labels must all come from the same call or put row.
- Add contract_symbol/ticker to the API additively, or expose a documented stable composite identity when the source has no contract ticker.
- When switching type, select the matching strike/expiration counterpart only if it exists; otherwise clear the contract-dependent result and require a valid selection.
- Initial automatic selection uses the closest valid strike to the real spot, rather than exact five-dollar rounding followed by an unrelated first row.
- Preserve an explicitly manual entry price across a type switch only when the UI clearly labels it manual; automatic premium follows the newly selected contract.

Acceptance criteria:

- A put calculation can never use a previously selected call premium, and the reverse cannot occur.
- Switching type updates every contract-derived field together or shows a clear unselected state.
- Payoff, maximum loss, breakeven, IV, and chart labels use one identifiable contract.
- The selected identity, type, expiration, strike, premium, and IV switch atomically in the response/UI.
- Existing call and put formulas and display fields remain available.

Ticket-specific regression proof:

- Component tests use call and put contracts with intentionally different premiums and IV. Assert every displayed/calculated value after selection, type switches, missing counterparts, expiration switches, and data refreshes.

### GEX-009B — Remove fabricated underlying prices from calculator results

Type: Bug
Epic: Calculator correctness
Depends on: GEX-002, GEX-011

Problem and scope:

- Remove the backend/job fallback that substitutes an underlying price of 100 when no trustworthy quote exists.
- Return an explicit unavailable state. A timestamped last-known quote may be used only under a documented stale-data policy and must be labeled stale.
- Define live/stale quote age separately for regular, pre/post, and closed sessions. A read response never queues work; an explicit GEX-011 start/refresh action may coalesce a quote refresh.
- Do not persist or calculate from a fabricated price.

Acceptance criteria:

- Missing spot produces null/unavailable status, not 100.
- Payoff and derived values that require spot do not claim a valid result until a real or explicitly stale quote is available.
- API schema remains backward compatible through an additive status/meta field before any old field behavior is retired.
- A legitimate underlying price of 100 remains distinguishable from unavailable.

Ticket-specific regression proof:

- Test missing, zero/invalid, exactly 100, live, and stale quotes. Prove no stored row or response invents a spot and that existing valid-price calculations are unchanged.

### GEX-009C — Calculate DTE with exchange-date semantics

Type: Bug
Epic: Calculator correctness
Depends on: GEX-002

Problem and scope:

- Stop parsing a date-only expiration as UTC midnight in the browser.
- Make the API authoritative: return as_of_exchange_date, expiration_date, and integer dte using the market/exchange calendar-date convention; the frontend consumes that integer rather than recalculating it.
- Keep the current product convention for calendar versus trading days; this ticket fixes timezone drift rather than redefining the metric.

Acceptance criteria:

- The same expiration and as-of date produce the same DTE in UTC, Chicago, New York, Pacific, and browser test timezones.
- Same-day expiration is 0 and the next calendar day is 1 under the documented convention.
- DST transitions and year/month boundaries do not shift DTE.
- All DTE-dependent calculator values use the corrected integer.

Ticket-specific regression proof:

- Run timezone-parameterized tests across same-day, next-day, DST start/end, month end, year end, pre-market, and after-market cases.

### GEX-010 — Deliver new symbols through fast and fill phases

Type: Story
Epic: New-symbol reliability
Depends on: GEX-002, GEX-003, GEX-004, GEX-006, GEX-012, GEX-013

Problem and scope:

- Create one authoritative durable data run keyed by symbol, market session/frozen catalog generation, and purpose. Concurrent user requests subscribe to that shared run rather than creating independent provider work.
- Fast phase on bootstrap-fast validates the symbol, loads a quote, freezes the authoritative expiration catalog, and fetches the explicitly defined minimum EOD scope needed for the initial view. It then hands first-use intraday to intraday-interactive rather than doing a long intraday fetch on bootstrap-fast.
- Define “90-day” from the current production bootstrap in GEX-001, including whether it is a trade-date history, forward expiration horizon, or both. Fill phase on normal/heavy/fill lanes preserves that exact scope, every catalog expiration, expiry pressure, positioning, intraday detail, and all enrichments.
- Make fast data a scoped, merge-only candidate. It cannot replace a prior complete full-range publication or make full-range calculations appear complete.
- Remove or make manifest-aware the existing delayed missing-expiration retry so a 45-second fallback cannot duplicate a completed/in-progress fast run.
- Expose per-phase status to the watchlist/dashboard and retry failed phases without repeating completed work.
- Prioritize time to first useful data, not false full completion.

Acceptance criteria:

- During a saturated 15-symbol market-hours batch, a new normal symbol begins the fast phase within the agreed SLO and shows first useful data within the measured provider-dependent SLO.
- A new heavy symbol uses bounded heavy/fill work and cannot monopolize bootstrap-fast.
- The final row counts, expiration set, fields, and derived features equal the current complete bootstrap.
- Full completion requires every expiration in the run’s frozen catalog and every existing enrichment phase; partial fast readiness is labeled with its exact coverage.
- Removing/re-adding or concurrent adding of the same symbol does not duplicate work or regress fresher data.
- One hung fast or intraday-interactive job does not prevent a second new symbol from meeting the start SLO.

Ticket-specific regression proof:

- Test normal, invalid, no-options, heavy, provider-429, timeout, partial, concurrent users/adds, delayed fallback firing after completion, delete-during-run, worker hang, stale retry, and resume scenarios against the old final dataset.

### GEX-011 — Authenticate, throttle, and coalesce work-producing endpoints

Type: Story
Epic: API and queue safety
Depends on: GEX-003

Problem and scope:

- Apply the intended authentication/authorization policy to intraday pull, calculator prime, option-chain-triggered work, ingest health, and debug endpoints.
- Make read endpoints side-effect free. Start/refresh work through an explicit write endpoint that returns a durable run ID.
- Remove or production-disable synchronous full calculator execution from an HTTP request.
- Inventory current callers and maximum authorized watchlist size before validating/capping batches. Use server-side chunking or a versioned migration when an existing authorized request exceeds the safe per-job unit.
- Add per-user, IP, symbol, and provider-aware rate limits plus a per-symbol pending/run-generation guard.
- Keep read-only public product endpoints public only where that is an explicit product requirement.

Acceptance criteria:

- Unauthorized callers cannot enqueue or synchronously execute expensive internal work.
- Calculator start/status/refresh follows the existing subscription and feature-entitlement policy.
- Option-chain/status GET requests are pure reads. Only an explicit authenticated write command creates work and it returns the compatible active run/unit when coalesced.
- Repeated allowed calls return the existing run/status instead of creating duplicate jobs.
- Rate-limit responses preserve the documented API contract and include a safe retry hint.
- Health endpoints are cheap, cached where suitable, and do not execute ingestion.

Ticket-specific regression proof:

- Security and concurrency tests prove one accepted run for simultaneous identical requests and unchanged authorized read responses.

### GEX-012 — Replace nullable option-total uniqueness safely

Type: Bug
Epic: Data integrity
Depends on: GEX-002

Problem and scope:

- Replace the ineffective nullable unique key for aggregate option-live-counter rows.
- Prefer an additive totals table keyed by symbol and trade date, or introduce an explicit non-null bucket key if one table is retained.
- Backfill the freshest row by source as-of/updated time, not the lowest/oldest ID.
- Dual-write, compare, switch reads behind a flag, and retain the old rows for a rollback window.

Acceptance criteria:

- MySQL prevents more than one totals bucket for a symbol/trade date.
- Backfill chooses the freshest authoritative total and preserves all component data.
- Dual-write comparisons match for a full market session before read cutover.
- No destructive duplicate cleanup or old-index removal occurs in this ticket.

Ticket-specific regression proof:

- MySQL tests insert NULL-containing legacy variants, concurrent upserts, stale retries, and out-of-order events; exactly one fresh total remains visible.

### GEX-013 — Publish daily chain snapshots atomically

Type: Bug
Epic: Data integrity
Depends on: GEX-002

Problem and scope:

- Replace delete-then-insert publication with a staged generation, transactional swap, or idempotent upsert plus completion marker.
- Keep the last complete generation readable until the new one is complete.
- Make replay safe after a timeout or process kill.

Acceptance criteria:

- Readers never observe a missing or half-built daily snapshot.
- A failed build leaves the prior complete generation unchanged.
- A successful build contains the same rows and calculations as the current clean run.
- Cleanup of superseded generations is deferred and retention-safe.

Ticket-specific regression proof:

- Terminate the build at multiple checkpoints and compare visible data, counts, and checksums before and after retry.

### GEX-014 — Remove global cache flushing from watchlist preload

Type: Bug
Epic: Cache safety
Depends on: GEX-002

Problem and scope:

- Remove Cache::flush from watchlist preload.
- Invalidate only the successfully updated symbol/version keys.
- Preserve queue locks, scheduler locks, bootstrap idempotency, unrelated sessions/cache entries, and other symbols.
- Use versioned keys so stale values expire naturally.

Acceptance criteria:

- Preloading one watchlist invalidates only data affected by completed writes.
- Cache, scheduler, and idempotency locks survive the command.
- A partial/failed preload does not invalidate the last good version.
- Cache miss rate and duplicate-job dispatch do not spike after preload.

Ticket-specific regression proof:

- Seed unrelated cache values and locks, run success and failure cases, and prove only intended symbol keys change.

## P1 tickets

### GEX-015 — Split calculator fetching into bounded expiration jobs

Type: Story
Epic: Calculator performance
Depends on: GEX-008, GEX-004

Problem and scope:

- Replace one full-symbol job of up to hundreds of pages with a short discovery/coordinator job and bounded expiration jobs.
- Stream/normalize/write page chunks instead of retaining both the full raw response and a second normalized full-chain array.
- Persist a credential-free cursor/checkpoint after committed work so retries resume instead of restarting a large expiration from page one.
- If one expiration still exceeds the safe job window, split it into bounded page units without publishing until its terminal cursor is reached.
- Key work by purpose/scope, symbol, frozen catalog generation, expiration, and freshness bucket. Reuse an already compatible expiry unit, but do not make an interactive selected-expiry request wait for unrelated full-catalog completion.
- Publish with the GEX-008 source-as-of/generation compare-and-swap so an old bulk child or retry cannot replace a newer interactive result.
- Window coordinator fan-out and refill round-robin so one SPY/QQQ/IWM run cannot occupy the entire ready queue. Route measured heavy fill to calculator-fill-heavy with capped capacity.
- Use the GEX-004 shared provider semaphore and measured worker/process limits so fan-out cannot overwhelm provider, Redis/database queue, MySQL, CPU, or memory.
- Preserve all pages, expirations, contracts, and existing normalized fields.

Acceptance criteria:

- Every child job finishes within the queue contract from GEX-003 under its supported workload.
- Completion is derived from the GEX-008 manifest, not dispatch success.
- A failed expiration retries independently while completed expirations remain readable.
- Peak worker memory, job wall time, interactive wait, full-fill time, and ready-queue share per run meet the numeric GEX-001 thresholds.
- Final expiration/contract counts and canonical payloads match one frozen provider response/catalog fixture; live shadow comparison uses documented timestamp/number tolerances.

Ticket-specific regression proof:

- Replay frozen heavy and normal provider fixtures, including pagination, retries, duplicates, one failed expiration, interactive/bulk races in both completion orders, an old-run retry, and out-of-order completion. Normalize generated IDs/ingestion times and compare every stored source field and API result.

### GEX-016 — Coalesce calculator prime requests and reserve interactive capacity

Type: Story
Epic: Calculator performance
Depends on: GEX-011, GEX-015

Problem and scope:

- Route active-user calculator requests to calculator-interactive workers; send scheduled/full completion to calculator-fill.
- Make AppShell, Calculator mount, option-chain fallback, refresh, and scheduler use one coordinator and one run key.
- A force request may promote or refresh an eligible run but cannot bypass the active-run lock and create duplicate work.
- Define explicit commands: refresh selected expiry uses bounded calculator-interactive work; refresh all creates/reuses one catalog/fill run; quote or catalog refresh is requested separately when its freshness policy requires it.
- Remove production synchronous execution from the HTTP process.

Acceptance criteria:

- Opening one calculator page creates at most one actionable run per symbol/generation.
- AppShell and Calculator requests share status instead of dispatching independently.
- Interactive work begins within its SLO during calculator-fill saturation.
- Manual refresh remains available and returns a run/status immediately.
- Neither refresh scope bypasses an active compatible expiry unit or full run, and selected-expiry readiness remains separate from full-catalog completion.
- Fill work continues fairly and all final data remains identical.

Ticket-specific regression proof:

- Send concurrent mount, refresh, force, scheduler, and API requests. Assert one provider work graph, one manifest, correct priority promotion, and unchanged final results.

### GEX-017 — Move the production queue to Redis with a lossless drain

Type: Infrastructure story
Epic: Queues and infrastructure
Depends on: GEX-003, GEX-005

If production already uses Redis, validate the configuration and close the migration portion with evidence. GEX-018 still depends on this validation before high-message-count singleton ingestion is enabled.

Problem and scope:

- Provision a private Redis queue instance/process on Hetzner with documented AOF/fsync or equivalent persistence, recovery-point objective, restore/reconstruction procedure, health checks, sufficient memory, and no-eviction behavior.
- Keep queue data isolated from general application cache data.
- Persist a durable MySQL work intent/run before enqueue and add reconciliation that safely re-enqueues incomplete intent after Redis data loss; Redis is delivery state, not the only record that work is required.
- Deploy producers to Redis and start Redis workers while database workers continue draining existing jobs.
- Keep failed-job inspection and correlation IDs intact.
- Verify QUEUE_CONNECTION, queue-monitor connection, Redis prefix/database/instance, cached Laravel configuration, scheduler process, and every Forge worker connection/queue argument after deployment.

Acceptance criteria:

- New jobs enter Redis and every existing database intent is completed, terminally resolved, or explicitly replayed/remapped before database workers stop. A legacy drain worker remains while any retryable/reserved/delayed work exists.
- No job payload is truncated, manually copied, or deleted during cutover.
- Redis reports no eviction, persistence errors, or unacceptable memory pressure.
- Rollback sends new work to the former connection while Redis workers safely drain.
- Queue depth, oldest age, wait time, failures, and Redis memory are monitored.

Ticket-specific regression proof:

- Perform a non-production cutover, cold restart, simulated Redis data loss/reconciliation, and rollback with queued, reserved, delayed, retried, and failed jobs. Every durable test intent reaches exactly one correct final result.

### GEX-018 — Dispatch one intraday symbol per job and isolate heavy symbols

Type: Story
Epic: Intraday ingestion
Depends on: GEX-004, GEX-017

Problem and scope:

- Change the scheduler, warmup command, controllers, bootstrap, and retries so every new intraday job contains one symbol.
- Select canonical watchlist symbols distinctly in the database rather than plucking duplicates and de-duplicating the full collection in PHP.
- Temporarily support legacy array payloads until old queues are empty.
- Put singleton production behind a routing flag. Enable only after GEX-017 validation and the GEX-004 cross-lane provider semaphore; raise concurrency only after GEX-021 adaptive limits pass.
- Centralize heavy-symbol classification using measured page count, contract count, duration, and memory. Include SPY, QQQ, IWM, and other symbols only according to production measurements/configuration.
- Route scheduled normal and heavy work to separate capped workers. User-triggered work uses the reserved interactive/bootstrap lane.

Acceptance criteria:

- One slow or failing symbol cannot delay or retry fourteen unrelated symbols.
- Every producer applies the same normal/heavy classification.
- Heavy jobs cannot consume interactive or normal worker capacity.
- Normal and heavy lanes both make progress within documented SLOs.
- The same symbol/provider fixture produces the same stored data as the former batch path.
- Remove legacy array deserialization only after database and Redis legacy queues have zero queued, reserved, delayed, and retryable jobs for the agreed soak window; record that cleanup as this ticket’s final rollout subtask.

Ticket-specific regression proof:

- Compare a 15-symbol legacy batch with 15 singleton jobs, including one slow, one failed, and multiple heavy symbols. Validate exact data equivalence and failure isolation.

### GEX-019 — Replace per-contract intraday writes with chunked upserts

Type: Performance story
Epic: Intraday ingestion
Depends on: GEX-002, GEX-018

Problem and scope:

- Replace Eloquent updateOrCreate inside the contract loop with normalization followed by configurable chunked MySQL upserts.
- Preserve every current field and the existing contract/capture identity.
- Keep transactions bounded and protect fresher values from an out-of-order stale attempt.
- Reuse the same writer in every remaining local ingestion path.

Acceptance criteria:

- SQL statement count is bounded by chunks rather than contracts.
- Duplicate contract/capture rows update idempotently.
- Null handling, numeric precision, timestamps, source values, and all stored fields match the current writer.
- Peak memory remains within the GEX-003 job contract.
- Market-hours ingest duration and database load materially improve from baseline.

Ticket-specific regression proof:

- Replay identical large and small responses through old and shadow writers. Compare keys, fields, null coverage, counts, OI/volume sums, timestamp ordering, SQL count, runtime, and memory.

### GEX-020 — Separate market-data time from ingestion freshness

Type: Bug
Epic: Intraday ingestion
Depends on: GEX-011, GEX-018

Problem and scope:

- Store and expose separate source as-of, captured/received, ingestion-completed, and run-state times.
- Use completed/pending state and a documented freshness window to decide whether to enqueue. Do not use a vendor timestamp shifted by one minute as both display time and fetch-throttle time.
- Define pre-market, regular-session, post-market, closed-session, and holiday freshness behavior.
- Keep the true market-data as-of visible to users.

Acceptance criteria:

- A just-completed fetch does not immediately enqueue again because source data is one minute old.
- Pending work is reused and failed work becomes retryable after its safe backoff.
- Dashboard stale/fresh labels use the documented source-time rule while enqueue decisions use ingestion/run state.
- Market-session transitions behave deterministically.

Ticket-specific regression proof:

- Time-controlled tests cover exact threshold boundaries, delayed provider data, RTH, pre/post market, weekend/holiday, pending, completed, failed, and browser polling cases.

### GEX-021 — Harden provider rate limits and add adaptive queue backpressure

Type: Story
Epic: Intraday ingestion
Depends on: GEX-004, GEX-018

Problem and scope:

- Expand the minimum GEX-004 cross-lane semaphore into a shared Redis-backed provider concurrency/rate limiter used by every provider-calling web/job path.
- Honor Retry-After and release jobs with jittered backoff instead of blocking a worker with sleep.
- Apply execution-time freshness/coalescing before acquiring provider capacity.
- Define queue-depth/oldest-age backpressure that delays scheduled fill without dropping required work or delaying reserved interactive capacity.

Acceptance criteria:

- Aggregate concurrency stays within the provider plan under multiple Forge worker groups.
- Provider 429/5xx responses release capacity and retry according to GEX-003.
- A scheduled backlog cannot consume reserved interactive tokens indefinitely.
- Every delayed requirement remains represented by a durable intent/manifest state.
- Provider calls per successful symbol generation fall or remain equal.

Ticket-specific regression proof:

- Simulate burst traffic, 429 with Retry-After, timeouts, 5xx responses, worker death, and recovery. Prove bounded calls, no lost work, and equivalent final data.

### GEX-022 — Coalesce quote refreshes and restore market-session scheduling

Type: Bug
Epic: Quote ingestion
Depends on: GEX-003, GEX-004, GEX-021

Problem and scope:

- Restore an explicit exchange calendar/session guard rather than running the same refresh unconditionally every day.
- Coalesce duplicate quote intents by symbol and freshness bucket.
- Recheck freshness when a job starts so old queued refreshes exit before provider access.
- Keep efficient bounded provider batches where supported, but prevent a slow batch from monopolizing a worker.
- Define an intentional after-close final refresh policy.

Acceptance criteria:

- Weekend/holiday and closed-session schedules follow the documented policy.
- Repeated scheduler ticks do not create an accumulating quote backlog for the same symbols.
- Fresh queued symbols skip provider calls safely; stale symbols remain represented and eventually refresh.
- Quote fields, timestamps, and endpoint behavior remain unchanged.

Ticket-specific regression proof:

- Replay the same quote universe through legacy and new paths across all sessions, with slow/failed batches. Compare every stored quote and measure backlog/oldest age.

### GEX-023 — Add missing indexes and remove proven duplicate indexes

Type: Database story
Epic: Database performance
Depends on: GEX-002, GEX-008, GEX-012, GEX-013, GEX-015, GEX-019

Problem and scope:

- Capture production SHOW INDEX, table sizes, write rates, and EXPLAIN plans first.
- Add the intraday index supporting intraday_option_volumes(symbol, captured_at, strike_price), adjusted only if production EXPLAIN proves a better column order.
- Add calculator catalog/publication read indexes only after GEX-008/GEX-015 finalize that query shape. Essential uniqueness/idempotency indexes ship with the additive GEX-008 schema; do not optimize a legacy option_snapshots grouping path that is about to be retired without evidence.
- Identify exact and prefix duplicates on option_expirations, option_chain_data, prices_daily, unusual_activity, and option_live_counters.
- Add needed replacements online and observe at least one full production release/market-session soak before removing one proven duplicate at a time.

Acceptance criteria:

- Target read queries use an intended index and improve from baseline.
- Insert/upsert cost and table/index size improve after duplicate removal.
- No unique constraint, foreign key support, query path, or data is weakened.
- The nullable totals problem remains governed by GEX-012; an equivalent nullable index is not treated as its fix.
- Each production DDL step has lock-duration, disk-space, abort, and rollback plans.
- Index removal is a later rollout step in this ticket and cannot occur in the release that introduces its replacement.

Ticket-specific regression proof:

- Attach before/after SHOW INDEX, EXPLAIN, read/write latency, counts, checksums, and representative concurrent-ingestion results for every index change.

### GEX-024 — Make GEX expiration and strike aggregation set-based

Type: Performance story
Epic: GEX API and calculations
Depends on: GEX-002, GEX-023

Problem and scope:

- Fetch the future expiration universe once and derive the 0d/1d/7d/14d/30d/90d sets from that result instead of running similar queries for each timeframe.
- Replace repeated full-collection scans for every strike/type/metric with one SQL grouping or one linear pass that builds all required strike/type aggregates.
- Preserve current snapshot-selection, Greek fallback, sign, rounding, sorting, and response fields.
- Keep the old aggregation available in shadow mode.

Acceptance criteria:

- Strike aggregation scales linearly with option rows rather than strikes multiplied by rows.
- Expiration-query and total SQL counts materially decrease.
- Heavy- and normal-symbol response CPU, memory, and p95 improve from GEX-001.
- Canonical payloads match the old implementation within the global numerical tolerance for every timeframe.

Ticket-specific regression proof:

- Shadow both implementations over representative complete, sparse, zero-DTE, missing-Greek, stale, and heavy snapshots. Attach payload diffs plus query, CPU, memory, and latency measurements.

### GEX-025 — Add snapshot-health manifests and early cache hits

Type: Performance story
Epic: GEX API and calculations
Depends on: GEX-013, GEX-024

Problem and scope:

- Publish a small per-symbol snapshot-health record after successful ingestion containing complete generation/version, usable dates, expiration coverage, Greek coverage, latest timestamps, and source state.
- Make EodSnapshotSelector and GEX cache-version selection use this record instead of repeatedly aggregating the full history.
- On a valid warm cache entry, avoid market-table discovery queries.
- Replace separate cache existence/read operations with one retrieval path and safe miss fallback.

Acceptance criteria:

- A warm GEX request performs no full option-history coverage scan.
- The manifest advances only after complete publication and never points to a partial generation.
- Missing/corrupt manifest state falls back to the existing safe selector and repairs asynchronously.
- Cached and uncached responses remain identical.

Ticket-specific regression proof:

- Compare selector decisions and responses for complete, partial, stale, missing-Greek, fallback, and concurrent-publication cases. Record warm/cold SQL count and latency.

### GEX-026 — Use symbol-versioned invalidation and isolated Redis cache/locks

Type: Infrastructure story
Epic: Cache safety and performance
Depends on: GEX-014, GEX-017, GEX-025

Problem and scope:

- Define cache keys by symbol, endpoint parameters, and completed data version so a new publication makes only affected results obsolete.
- Move application cache and distributed locks from the database to Redis only after targeted invalidation is proven.
- Use a separate Redis instance/service/process for cache when its eviction policy differs from the durable queue instance; a different Redis database number alone is not eviction isolation.
- Keep coordination locks on a no-eviction lock service or make a MySQL unique intent/lease authoritative so eviction of ordinary cached values cannot create duplicate ownership.
- Keep durable run/completion state in MySQL; cache and locks are accelerators.

Acceptance criteria:

- Publishing one symbol does not evict other symbols, queue data, sessions, or unrelated application cache.
- All Forge workers observe the same locks and symbol versions.
- Cache migration and rollback are independent of the queue connection.
- Database cache-query volume and warm endpoint latency materially improve.
- A cache eviction or Redis cache restart cannot lose durable work or cause stale publication.

Ticket-specific regression proof:

- Run concurrent publish/read/preload/lock tests, Redis cache restart, eviction, and rollback. Verify correct payloads, one work intent, and untouched queue data.

### GEX-027 — Batch unusual-activity pricing lookups

Type: Performance story
Epic: API query performance
Depends on: GEX-002, GEX-023

Problem and scope:

- Remove schema and pricing lookups from the per-row unusual-activity loop.
- Resolve schema capability once and load required underlying/option pricing inputs in grouped queries keyed by the returned expirations, strikes, types, and timestamps.
- Calculate the same premiums and derived fields from the batched maps.

Acceptance criteria:

- SQL query count does not grow linearly with the number of returned activity rows.
- Responses for 1, 10, and 100 rows match the legacy payload and ordering.
- Missing price inputs retain the existing documented null/fallback behavior.
- p95 response time and database time improve from baseline.

Ticket-specific regression proof:

- Golden payload and query-count tests cover calls, puts, duplicate pricing keys, missing quotes, multiple expirations, and maximum page size.

### GEX-028 — Make the expiration batch endpoint set-based

Type: Performance story
Epic: API query performance
Depends on: GEX-002, GEX-023

Problem and scope:

- Replace per-symbol spot and expiration-pressure queries with bounded set-based queries for the requested canonical symbol list.
- Preserve requested ordering, missing-symbol behavior, freshness metadata, and authorization limits.
- Support at least the largest authorized watchlist found in GEX-011. Chunk internally where database parameter limits require it; use a versioned client migration before reducing any existing authorized request size.

Acceptance criteria:

- Query count remains approximately constant as symbol count grows to the supported maximum.
- Every existing response field/value and per-symbol error state remains compatible.
- Duplicate/case-variant symbols are canonicalized once without duplicate work.
- Database rows examined and p95 improve for a full watchlist.

Ticket-specific regression proof:

- Compare 1-, 15-, and maximum-symbol old/new payloads, including missing, stale, heavy, duplicate, and invalid symbols; attach query plans/counts.

### GEX-029 — Read wall spot prices directly from underlying quotes

Type: Performance story
Epic: API query performance
Depends on: GEX-002

Problem and scope:

- Stop building the full intraday composite when WallService only needs a spot price.
- Read the freshest eligible underlying quote directly using the same freshness/fallback policy as other spot consumers.
- Return the existing wall payload and status semantics.

Acceptance criteria:

- A wall request does not execute the intraday composite path for spot.
- Spot value and timestamp match the authoritative underlying-quote policy.
- Missing/stale quote behavior remains explicit and compatible.
- Query count, rows processed, memory, and latency improve.

Ticket-specific regression proof:

- Golden tests cover live, stale, missing, and concurrent quote updates and compare all wall levels and response fields.

### GEX-030 — Remove duplicate frontend requests, polling, and chart renders

Type: Performance story
Epic: Frontend performance
Depends on: GEX-009, GEX-020

Problem and scope:

- Run independent Dashboard term-structure and VRP requests concurrently.
- Lazy-load inactive heavy tabs and reuse already loaded data until its documented freshness expires.
- Stop preparing/refresh loops when ready; cancel every timer, fetch, and sleep on unmount or symbol change.
- Use AbortController or request sequence IDs for Dashboard symbol/tab work so a stale response cannot replace current state; Calculator correctness owns this behavior in GEX-009.
- Coalesce Calculator chart work into one scheduled render per state change and destroy it on unmount.

Acceptance criteria:

- Initial dashboard request count and network waterfall improve without hiding active-tab data.
- Ready state produces no further preparing polls.
- Navigation/symbol changes leave no orphaned timers or network work and old responses cannot update current state.
- One option selection causes at most one chart recreation.
- Visual output, keyboard/mouse interaction, data freshness, and existing tabs/features remain unchanged.

Ticket-specific regression proof:

- Fake-timer/component tests and browser traces cover mount, slow responses, ready transition, tab activation, symbol/expiration races, repeated selection, and unmount. Attach before/after request and render counts.

## P2 tickets

### GEX-031 — Batch AppShell unusual-activity loading by watchlist

Type: Performance story
Epic: Frontend and API performance
Depends on: GEX-027

Problem and scope:

- Replace one unusual-activity request per watchlist symbol with one bounded batch request or one existing batch-shaped service call.
- Return per-symbol data/status so a missing or failed symbol does not fail the whole watchlist.
- Reuse the GEX-027 batched pricing path and cache by symbol/data version.

Acceptance criteria:

- Initial AppShell request count does not grow one-for-one with watchlist size.
- Per-symbol cards retain their current data, empty, loading, stale, and error behavior.
- Canonical ordering and every response field/value remain unchanged.
- Batch authorization and maximum size match watchlist ownership rules.

Ticket-specific regression proof:

- Compare 1-, 15-, and maximum-watchlist network traces and payloads, including partial errors, duplicate symbols, stale data, and rapid watchlist changes.

### GEX-032 — Add queue dashboards, wait-time alerts, and capacity reports

Type: Infrastructure story
Epic: Operations and observability
Depends on: GEX-005, GEX-017

Problem and scope:

- Choose one consumer operating model: keep dedicated Forge/Supervisor queue workers with an equivalent metrics dashboard, or replace them with one Forge-managed Horizon daemon whose separate Horizon supervisors reproduce the lane isolation. Never run both consumers for the same queues.
- Record per-lane depth, oldest wait, wait/run p50/p95/p99, throughput, retries, failures, timeouts, memory, provider 429/5xx, and bootstrap/calculator milestone durations.
- Schedule metrics snapshots and configure wait thresholds for interactive, normal, heavy, fill, quote, and export lanes.
- Correlate queue/run IDs without using contract symbols as unbounded metric labels.
- If Horizon is selected, disable the corresponding Forge queue-worker definitions before cutover, configure lane supervisors explicitly, use horizon:terminate during deploys, and verify no duplicate consumers.

Acceptance criteria:

- A queued, running, delayed, failed, timed-out, retried, and stuck-heartbeat test job is visible and alertable.
- Interactive alerts use wait time, not only queue size.
- Forge/Hetzner CPU, memory, disk, and Redis memory/evictions appear beside application metrics.
- Dashboard and metrics collection do not expose payloads or credentials and have negligible measured overhead.
- The runbook names exactly one active worker-control/restart command for the selected operating model.

Ticket-specific regression proof:

- Exercise every alert in non-production, verify routing/recovery, and compare application throughput with metrics on and off.

### GEX-033 — Tune MySQL, PHP-FPM, and worker memory from production evidence

Type: Infrastructure task
Epic: Hetzner capacity
Depends on: GEX-001, GEX-023, GEX-032

Problem and scope:

- After query/job changes settle, size MySQL buffer pool/connections, PHP-FPM children, OPcache, Redis memory, and each Forge worker memory/process count from observed peak usage.
- Budget total RAM for co-located services and operating-system headroom. Avoid swap-based steady state.
- Inspect disk latency/free-space growth and slow-query evidence before changing database settings.
- Change one resource group at a time with configuration snapshots and rollback values.

Acceptance criteria:

- The documented memory and connection budget cannot overcommit the Hetzner host at configured maxima.
- Market-hours CPU, memory, disk latency, MySQL connection use, and worker recycling remain inside agreed thresholds.
- No setting change masks a query/job defect or weakens durability.
- Each change has before/after evidence and a canary interval.

Ticket-specific regression proof:

- Run the representative workload after every setting change; require data/API parity and no regression in errors, queue wait, web latency, or background throughput.

### GEX-033A — Characterize, test, and version calculator numerical assumptions

Type: Story
Epic: Calculator correctness
Depends on: GEX-002, GEX-009A, GEX-009C

Problem and scope:

- Move payoff, breakeven, Black-Scholes, implied-volatility, decay, contract-multiplier, and DTE logic into pure tested functions.
- Make risk-free rate configurable/sourced and timestamped. Decide and document dividend-yield handling for equities and ETFs.
- Add a calculation-model version and input assumptions to safe metadata.
- Treat any formula/default change as a product-approved version change, not a hidden performance refactor.

Acceptance criteria:

- Known-answer tests cover calls, puts, intrinsic bounds, monotonicity, IV convergence/failure, zero time, zero volatility, manual premium, multiplier, and expiration boundaries.
- Version 1 reproduces current valid outputs exactly after the confirmed P0 bug fixes.
- A candidate model runs in shadow mode and emits a difference report before becoming selectable/default.
- Existing stored market data is untouched and users never receive mixed model versions in one result.

Ticket-specific regression proof:

- Compare trusted test vectors plus production-shaped fixtures across model versions, symbols, timezones, dividends, rates, and edge cases. Product sign-off is required for changed defaults.

### GEX-034 — Run a market-hours load and failover certification

Type: Test and release story
Epic: Release certification
Depends on: GEX-010 through GEX-033A

Problem and scope:

- Run a production-shaped market-hours test with scheduled normal symbols, a 15-symbol burst, SPY/QQQ/IWM and measured heavy symbols, an interactive new symbol, calculator selected-expiry and full fill, quotes, GEX reads, dashboard reads, and enrichment.
- Inject provider 429/5xx/timeouts, one slow symbol, worker termination, deploy restart, Redis cache restart, queue cutover rollback, MySQL contention, and partial generation failures.
- Measure every SLO and run the global data/API equivalence gate.

Acceptance criteria:

- Interactive time-to-start/time-to-first-data and every queue oldest-age SLO pass.
- Normal, heavy, and low-priority work all make progress without provider or database overload.
- Worker/deploy/cache failures recover with no missing, duplicate, stale-overwrite, or falsely complete data.
- API p95/p99, frontend convergence, CPU, memory, disk, Redis, and MySQL remain within the agreed budgets.
- The release checklist has named go/no-go owners and exact rollback triggers.

Ticket-specific regression proof:

- Attach test scripts, configuration, timelines, metrics, failure observations, canonical data comparisons, and successful rollback/recovery evidence.

### GEX-035 — Define thresholds for a dedicated Hetzner worker server

Type: Architecture and infrastructure story
Epic: Hetzner capacity
Depends on: GEX-032, GEX-034

Problem and scope:

- Decide from measured evidence whether the web/database host can meet SLOs with safe headroom.
- Define triggers such as sustained queue-SLO misses, CPU saturation, insufficient RAM headroom, disk contention, or PHP-FPM latency caused by workers.
- If triggered, provision a Forge Worker Server on Hetzner private networking with release parity, shared durable queue/database access, clock synchronization, restricted firewall rules, monitoring, and a drain/rollback plan.
- If not triggered, record the capacity ceiling and re-evaluation alert rather than adding infrastructure prematurely.

Acceptance criteria:

- The decision document includes current capacity, safe maximum worker counts, cost, failure modes, security boundaries, and quantitative move/no-move thresholds.
- If moved, web p95 and queue SLOs improve without increasing database/provider errors or changing any data.
- Deployments keep web and worker releases compatible, and either host can be drained safely.
- A worker-server failure leaves durable jobs available for recovery.

Ticket-specific regression proof:

- Run GEX-034 before/after any move, terminate the worker host in non-production, restore workers, and prove exactly equivalent final data.

## P3 ticket

### GEX-036 — Re-evaluate asynchronous Lambda only if local isolation misses the SLO

Type: Architecture spike
Epic: Future scale
Depends on: GEX-034, GEX-035

Problem and scope:

- Revisit Lambda only if tuned, isolated Forge/Hetzner workers still miss an agreed SLO or have an unfavorable capacity/cost result.
- Compare a dedicated worker server with asynchronous SQS/EventBridge-to-Lambda bounded units. Do not use a synchronous Function URL as a long-running coordinator.
- Require the same durable MySQL run manifest, idempotency, per-expiration/page boundaries, concurrency/rate limits, stale-write guards, authenticated result ingestion, observability, cost limits, and rollback.
- Prevent Lambda and local workers from processing the same intent unless both paths use one ownership lease.

Acceptance criteria:

- The spike contains measured need, options, cost, provider-limit impact, security threat model, failure modes, proof-of-concept parity, and a go/no-go architecture decision.
- Any proof of concept uses asynchronous invocation and bounded payloads/work.
- Timeout/retry cannot launch an uncoordinated local duplicate.
- A no-go result removes/keeps disabled all experimental runtime surfaces and records why.

Ticket-specific regression proof:

- If pursued, shadow representative heavy/normal/cold symbols and compare all rows, expirations, timestamps, calculations, retries, and failure recovery before any production write ownership changes.

## Queue-boundary decision

| Work | Boundary | Reason | Cost and mitigation |
|---|---|---|---|
| New-symbol coordinator/fast EOD | One symbol and one short phase | Guarantees first-use progress and makes status/retry precise | More messages; Redis and durable manifests absorb this safely |
| First-use intraday | One symbol | A slow/heavy symbol cannot block another new symbol | Provider concurrency can rise; GEX-021 supplies a global limiter |
| Scheduled intraday | One symbol | Fair scheduling, per-symbol priority, isolated failure/retry | More queue reservations; GEX-017 precedes the fan-out |
| Calculator discovery | One symbol/run | One authoritative catalog and generation | Coordinator must be idempotent |
| Calculator fetch | One run/expiration, with bounded page checkpoints where needed | Heavy chains become resumable and partial work is observable | More coordination; GEX-008 is the completion source of truth |
| Underlying quotes | Bounded provider-efficient batch | Provider batch endpoints can reduce call and connection overhead | Recheck/coalesce each symbol and cap batch runtime |
| Derived calculations/enrichment | One symbol/generation unless measurement proves a smaller safe unit | Prevents cross-symbol retry and stale overwrite | Low-priority workers preserve fairness |
| Exports | One export on an isolated queue/connection | Export runtime must not consume market-data leases/workers | Dedicated retry-after, timeout, and memory contract |

The current 15-symbol intraday batch saves queue-message overhead, but it makes fourteen symbols wait behind the slowest symbol and retries unrelated work together. Implement singleton symbol jobs behind a flag after GEX-017 and the GEX-004 semaphore; complete the concurrency rollout after GEX-021 proves adaptive limits. This changes scheduling granularity only; it does not reduce data.

## Initial Forge worker layout

GEX-001 determines process counts. The invariant is reserved capacity and isolation, not a hard-coded process number.

| Queue lane | Work | Capacity rule |
|---|---|---|
| bootstrap-fast | Short new-symbol coordination, quote, catalog, minimum EOD readiness | Two reserved processes, or a proven hard runtime below the second-symbol start SLO; never run full 90-day fill here |
| intraday-interactive | First-use or active-user intraday symbol | Two reserved processes, or a proven hard runtime below the next-symbol start SLO; provider token share keeps heavy work bounded |
| calculator-interactive | Active selected-expiration calculator work | Reserved process; alert on p95 wait |
| quotes | Scheduled/session quote refresh | Bounded batches and execution-time freshness check |
| intraday | Scheduled normal symbols | Scale from provider, DB, CPU, and RAM budgets |
| intraday-heavy | Measured heavy symbols such as SPY/QQQ/IWM where evidence supports it | Capped processes so heavy work cannot consume the host |
| calculator-fill | Full catalog/background expiration fill | Bounded fan-out; cannot use interactive processes |
| calculator-fill-heavy | Measured heavy catalog/background fill | Capped workers plus windowed/round-robin fan-out |
| enrichment/default | Pressure, positioning, seasonality, and other derived work | Must make fair progress but may yield to first-use work |
| exports | Long exports | Separate lease/timeout and memory budget |

Initially, Forge should run these as separate supervised worker definitions. A comma-ordered queue list is not sufficient because a worker cannot preempt the job it has already started. If GEX-032 selects Horizon later, the Forge workers are replaced by equivalent isolated Horizon supervisors rather than run alongside them.

## Release waves and stop conditions

### Security prerequisite

Ticket: GEX-000.

GEX-001 read-only evidence collection may continue, but no additional production mutation proceeds until all disclosed credentials are rotated, verified, and revoked.

### Wave 0 — Measure and make rollback safe

Tickets: GEX-001 through GEX-006.

Exit gate:

- Production topology is known.
- Data/API harness catches intentional corruption.
- Timeout/lease contracts and worker restart procedure are tested.
- The synchronous Lambda experiment is disabled without reverting unrelated work.

### Wave 1 — Correct data and completion semantics

Tickets: GEX-007 through GEX-014, including GEX-009A through GEX-009C.

Exit gate:

- Calculator catalog/menu completeness, publication safety, and truthful first-load partial/status tests pass; full per-expiration bounded hydration is certified in Wave 2.
- Call/put, spot, and DTE correctness tests pass.
- New symbols meet fast/fill completeness tests.
- Nullable totals, daily snapshots, and cache locks are safe.

### Wave 2 — Remove ingestion and queue bottlenecks

Tickets: GEX-015 through GEX-023.

Exit gate:

- Redis drain is complete where applicable.
- Singleton intraday and bounded calculator jobs pass parity tests.
- Every frozen calculator catalog expiration becomes ready or remains explicitly failed/retryable without being counted complete.
- Provider backpressure is proven.
- No queue has an increasing oldest age under representative load.

### Wave 3 — Optimize reads and frontend work

Tickets: GEX-024 through GEX-031.

Exit gate:

- Golden API/frontend results match.
- Query count, rows examined, request count, CPU, memory, and p95 improve.
- Cache restart/eviction cannot affect durable work.

### Wave 4 — Certify and size infrastructure

Tickets: GEX-032 through GEX-035, including GEX-033A.

Exit gate:

- Market-hours/failure certification passes.
- Forge/Hetzner capacity has safe headroom or workers are moved according to measured thresholds.
- Operational owners approve go/no-go and rollback evidence.

GEX-036 remains optional. It starts only after the local and dedicated-worker-server options have measured results.

Stop any rollout and keep the last complete publication active if any of these occur:

- A row, expiration, contract, field, aggregate, or endpoint contract differs without an approved explanation.
- Partial or stale data becomes labeled complete/fresh.
- A stale retry overwrites a newer as-of value.
- Queue oldest age grows continuously, provider limits are exceeded, or MySQL/Redis/host thresholds breach the agreed budget.
- Error rate, p95/p99, memory, disk, lock time, duplicate work, or frontend request count regresses beyond the ticket budget.
- Rollback or worker-drain behavior is not observable.

## Coverage map

| Confirmed concern | Owning tickets |
|---|---|
| Disclosed production credentials | GEX-000 |
| Calculator queue backlog, fallback storm, dispatch-time freshness | GEX-003, GEX-004, GEX-007, GEX-032 |
| Missing expirations until browser refresh | GEX-008, GEX-009, GEX-015, GEX-016 |
| Call/put premium mismatch, fake spot 100, timezone-sensitive DTE | GEX-009A, GEX-009B, GEX-009C, GEX-033A |
| SPY/QQQ/IWM and other heavy symbols blocking work | GEX-004, GEX-015, GEX-018, GEX-021 |
| Fifteen-symbol intraday batches | GEX-017 through GEX-021 |
| Newly added symbol shows no data while workers are busy | GEX-004, GEX-010, GEX-018, GEX-020 |
| Duplicate/poll-driven intraday fetches | GEX-011, GEX-020, GEX-021, GEX-030 |
| Per-contract ORM reads/writes | GEX-019 |
| Quote backlog and closed-market scheduling | GEX-022 |
| Queue lease/timeout mismatch and stuck jobs | GEX-003, GEX-004, GEX-005, GEX-032 |
| Database queue/cache contention | GEX-017, GEX-026 |
| Nullable aggregate duplicates/freshness loss | GEX-012 |
| Partial daily snapshot visibility | GEX-013 |
| Duplicate/missing indexes | GEX-023 |
| Repeated GEX expiration queries/collection scans | GEX-024 |
| Repeated snapshot health discovery before cache use | GEX-025 |
| Global cache flush and stampedes | GEX-014, GEX-026 |
| Unusual activity and expiration endpoint N+1 queries | GEX-027, GEX-028, GEX-031 |
| Wall endpoint builds unused intraday composite | GEX-029 |
| Sequential/duplicate/orphaned frontend work | GEX-009, GEX-030, GEX-031 |
| Public or weakly guarded expensive dispatch routes | GEX-011, GEX-016 |
| Forge deployment restart, logs, monitoring, and Hetzner capacity | GEX-001, GEX-004, GEX-005, GEX-032 through GEX-035 |
| Incomplete synchronous Lambda implementation | GEX-006, GEX-036 |

## Repository evidence

- Queue backlog sample: storage/logs/queue-monitor-2026-04-27.log.
- Calculator scheduler: routes/console.php, calculator priming schedule.
- Calculator worker: app/Jobs/FetchCalculatorChainJob.php.
- Calculator API and dispatch paths: routes/api.php.
- Calculator UI: resources/js/Pages/Options/Calculator.vue.
- Duplicate calculator prime producer: resources/js/Components/AppShell.vue.
- New-symbol pipeline: app/Jobs/BootstrapUserSymbolJob.php and app/Jobs/PrimeSymbolJob.php.
- Intraday scheduler/job/writer: routes/console.php, app/Jobs/FetchPolygonIntradayOptionsJob.php, and app/Services/IntradayOptionVolumeIngestor.php.
- Freshness decision: app/Support/PolygonClient.php and resources/js/Components/Dashboard.vue.
- Queue connection defaults: config/queue.php and .env.example.
- Nullable live-counter keys: database/migrations/2025_10_29_075045_create_option_live_counters_table.php and database/migrations/2025_11_14_083137_de_dupe_option_live_counters.php.
- Cache flush: app/Console/Commands/PreloadWatchlistSymbols.php.
- GEX query and aggregation path: app/Http/Controllers/GexController.php and app/Support/EodSnapshotSelector.php.
- Secondary APIs: app/Http/Controllers/ActivityController.php, app/Http/Controllers/ExpiryController.php, and app/Services/WallService.php.

## Operational references

- [Laravel Forge queue workers](https://forge.laravel.com/docs/sites/queues)
- [Laravel Forge background processes](https://forge.laravel.com/docs/resources/background-processes)
- [Laravel Forge deployments](https://forge.laravel.com/docs/sites/deployments)
- [Laravel Forge server monitoring](https://forge.laravel.com/docs/servers/monitoring)
- [Laravel Forge real-time metrics for supported providers, including Hetzner](https://forge.laravel.com/docs/servers/real-time-metrics)
- [Laravel Forge server types](https://forge.laravel.com/docs/servers/types)
- [Laravel Forge site and daemon logs](https://forge.laravel.com/docs/sites/logs)
- [Laravel queue timeout, retry, uniqueness, and rate-limit behavior](https://laravel.com/docs/11.x/queues)
- [Laravel scheduler overlap and single-server controls](https://laravel.com/docs/11.x/scheduling)
- [Laravel cache and atomic locks](https://laravel.com/docs/11.x/cache)
- [Laravel Horizon metrics and queue balancing](https://laravel.com/docs/11.x/horizon)

## Backlog completion definition

The program is complete when:

- All P0 and P1 tickets satisfy their acceptance and regression gates.
- GEX-034 passes against the agreed market-hours SLOs.
- Every current feature and dataset remains available with matching complete results.
- A new symbol and active calculator request receive reserved capacity during scheduled heavy-symbol load.
- Operators can identify and recover a failed/stuck run without deleting queue or market data.
- P2 infrastructure decisions are recorded and any required capacity move is complete.
- GEX-036 is not required unless GEX-035 records that local/dedicated Forge workers still miss the approved SLO or cost/capacity target. If triggered, its go/no-go decision is required.
