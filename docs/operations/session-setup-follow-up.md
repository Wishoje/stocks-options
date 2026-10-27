# Qualified intraday setup follow-up

Outcome rules `frozen-levels-session-close.v2` preserve the first qualified entry, target and invalidation. Follow completed five-minute closes until the original target, original invalidation or regular-session close. Early-close sessions use the market calendar. The entry window and future wall changes do not cancel a qualified scenario. Qualification rules, alerts for new entries and the data subscription are unchanged.

A missing completed price interval pauses the review as `unresolved` / Needs update. Later bars are not used to infer which boundary came first across a gap. Replaying complete retained bars can resume the review. At session close the outcome is `session_ended`; incomplete reviews retain `review_complete=false` and do not claim that neither price boundary was crossed.

Scanner and Alerts move an elapsed qualified entry from Conditions met to In progress, with an Entry window closed explanation. Recorded assessments and levels remain accessible. Terminal results are still excluded from the active lists and remain available in the wall-tracking results review. Session ended is distinct from a price invalidation.

The review version is exported with the supporting completed closes in timeline JSON and AI Export. Existing saved qualifications and raw market records remain unchanged. New derived lifecycle checkpoints are appended; old snapshots are retained under their original version.

After deployment, run `php8.3 artisan walls:refresh-reviews` once on the web node to update current saved symbols from retained observations and price bars. Use `--symbol=NVDA --symbol=IWM` for a targeted run, or `--session=YYYY-MM-DD` for a selected date. The command takes the collector’s per-symbol lock, reads at most 500 model observations per symbol, appends compact checkpoints and makes no provider requests or queue dispatches. Busy symbols can be retried; the scheduled collector also writes the new review version naturally. No environment or schema changes are required.
