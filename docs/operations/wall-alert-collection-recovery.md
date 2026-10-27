# Wall alert collection recovery

## October 27, 2026 incident

The production account feed returned zero usable current assessments for 96 saved symbols. Queue readiness checks passed and queues had no backlog. Wall observations continued to be recorded, but many completed-price assessments were older than the alert freshness limit. Sampled SPY, QQQ, TSLA and NVDA assessments had stopped at 11:30 AM ET.

The price client caught every provider exception and returned `provider_unavailable`, discarding structured retry metadata. After a request timed out, provider backpressure deferred the remaining symbols in that collector process. The scheduled command continued through those symbols and finished without saving a continuation cursor. Its next run started at the beginning of the same alphabetical list. Repeated failures near the beginning could therefore starve later symbols. Stale assessments were correctly excluded from current alerts.

## Repair

The price client preserves the safe reason, retry deadline, HTTP status and whether the request was attempted when the shared provider limiter defers it. No provider URL, response body or credential is added to that metadata.

The collector stops on a provider deferral and keeps its continuation position. After an actual failed request, the next batch starts after that symbol. An admission deferral made no request, so collection resumes at that symbol. The provider retry deadline is observed before another batch starts. A deferred request does not mark the entire five-minute bucket successfully checked.

The scheduler checks for resumable work every minute under its existing non-overlap lock. A successful symbol is still checked only once per five-minute bucket; deferred work observes the shared retry deadline. This prevents each provider timeout from adding another five-minute pause before later symbols can be reached. The command retains its 120-second admission budget.

Concurrency caps, model thresholds, setup confirmation rules and alert eligibility remain unchanged. No worker process, migration or environment flag is added. Existing completed-price and wall records are retained. The repair restores collection; it does not create a trading signal or replay expired notifications.

## Verification

Regression tests cover repeated timeouts, continuation across the end of the symbol list, admission deferrals, no requests before the retry deadline, and recovery within the same five-minute bucket. Existing tests retain delayed-bar validation, scope isolation, rate-limit handling and source-checkpoint behavior.

After deployment, inspect actual collector statuses and recent recorded assessments across the saved-symbol universe. Verify the account feed's assessed count, timestamp freshness and eligible setup counts. A successful scheduler exit or an empty job queue alone does not establish alert health. The collectors run as scheduled commands rather than dedicated queue jobs.
