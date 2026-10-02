# Intraday wall collection recovery

## Incident

On October 2, SPY's 2W wall timeline stopped after its 9:35 AM ET reading. The worker's scheduler and all 26 managed queue processes were running. Wall collection completed in about eight seconds, below its 90-second budget.

Two conditions interrupted collection:

- The bounded work-run retry scan sorted by original request time. More than 100 older calculator requests were repeatedly deferred by queue backpressure. They occupied the scan ahead of overdue quote and intraday requests, even when those queues were empty.
- A subsequent prior-session chain update failed SPY's model row-count threshold: 361 of 3,558 rows were excluded (10.15%, against a 10% maximum). OI input coverage remained 98.52%, above the separate 98% minimum. The earlier accepted session basis remained stored and usable.

Price-event collection reads completed bars separately. Its later timestamp did not mean that wall capture had continued.

The calculator backlog was self-blocking. The fill congestion check treated old, undispatched fill intents as busy queue transport. At 15:18 UTC, all 106 pending calculator requests had zero dispatch attempts, the calculator and interactive queues were empty, and SPY had accumulated 121 admission deferrals. These were internal postponements before any provider request. The scheduler prepares current calculator catalogs for watchlist symbols; an older request does not ask for historical market data.

## Changes

With provider backpressure enabled, work-run retry scans put background calculator fills behind other eligible work before applying the scan limit. Interactive calculator requests retain normal priority. Within each group, retries sort by their next eligible dispatch time, followed by original request time and ID. A repeatedly deferred job moves behind older overdue retries. Provider concurrency limits, retry deadlines, reservation fencing, and scan limits remain enforced.

Calculator fill admission now measures actual fill queue depth and ready-head age. Undispatched fill intent age remains in telemetry but cannot block its own admission. Waiting interactive work still takes priority.

Scheduled calculator fills also yield whenever quote or intraday queues have ready or reserved deliveries, or due durable requests. This check runs before enqueue and again before fetching a catalog. Future retries alone do not reserve idle capacity. The added live-market priority applies specifically to background calculator jobs; existing direct-user and bootstrap policies remain intact. Telemetry is sampled at most once every two seconds. A calculator fetch already in progress may finish; this change does not interrupt in-flight HTTP requests.

If refreshed model inputs fail validation, wall capture can use the last accepted basis for the exact symbol, session, source date, model, and expiration set. It verifies the stored hash, reconstructs the basis, and reruns the existing quality checks. Every new observation still requires a fresh provider-timestamped quote. A valid newer basis is used normally and starts a separate comparison segment.

The repair does not change stored observations, fill historical gaps, or relax input thresholds. Long gaps remain separate comparison windows. A scope without an accepted basis still waits until it qualifies.

Follow-up production checks found capacity waits consuming the failure budget after successful HTTP pages. Admission deferrals now retain their attempt credit even after successful pages. HTTP errors, timeouts, and crashed deliveries still consume the existing retry budget; fixed deferral deadlines still bound admission waits. Intraday volume requests bypass response replay so a later delivery cannot stamp an older cached page with a new receipt time.

Laravel reconstructs a command for its terminal failure callback without its queue transport object. Reading attempts from that command returned one even after a third failed attempt, leaving the durable run active. Intraday and calculator callbacks now lock the current slot and run, verify the delivery token, and finish the persisted attempt. A stale token cannot fail a replacement delivery.

## Validation

- Wall capture, model, and coordinator suites: 47 tests, 325 assertions.
- MySQL quote dispatch, lost-delivery recovery, and provider deferral suites: 52 tests, 731 assertions, using a dedicated local test database.
- Congestion policy and calculator queue integration regressions cover self-blocking intent, actual queue pressure, live-data priority at admission and execution, and duplicate dispatch protection. The expanded repair has 119 distinct targeted tests.
- Production read-only verification confirmed that the stored SPY and QQQ 2W bases match their hashes and current expiration sets. SPY's saved basis covered 99.86% of its own open interest inputs; this is not a claim about the newer chain.

## Rollout and verification

Deploy the PHP repair to both application nodes through the existing release process and gracefully restart managed queue workers. No environment change, migration, provider-plan upgrade, or higher concurrency setting is required by this repair. The approved release also includes Batch 7 wall OI analysis, so build its frontend assets through the normal release pipeline.

After activation, verify fresh quote timestamps, successful capture status, and increasing stored observation counts across consecutive collection cycles. Check default 2W for SPY and QQQ and sample other symbols. Do not use a successful scheduler exit alone as proof of capture. Existing gaps before recovery remain visible.

For calculator recovery, compare actual ready/reserved queue counts with pending requests and their next eligible retry times. Calculator admission must yield to live-data demand and resume when it drains. Retain the existing provider concurrency cap and avoid manually enqueuing the entire backlog.
