# Direct wall assessments

Intraday → Wall tracking shows What to watch now above the gamma context and chart. Each wall has its latest price response and the next unfinished condition. A qualifying assessment shows direction, entry area, invalidation close and reference target together. Supporting explanations, earlier reactions and setup reviews remain collapsed below the chart.

The backend adds `next_condition` to each setup reading and current assessment. It explains the existing versioned rules: a second consecutive close, a later retest, a separate close after that retest, or the final continuation threshold. Close counts reset with the existing sequence. Final current-state overrides replace explanations when an assessment expires, changes comparison or awaits a fresh assessment. No confirmation thresholds, setup identities, eligibility, grades or targets change.

`continuation_confirmation_price` on interaction readings exposes the existing failure-close-plus-band threshold. The final continuation comparison includes equality; acceptance and reversal boundaries remain strict. The next-condition object declares its presentation version, setup rule version, status, threshold and comparison semantics. Timeline JSON and the existing optional Intraday walls & setups AI export include these additive fields.

Older responses without this explanation use a general watch message. The frontend does not infer a completed-close count from a chart shape or an event label. Paused and ended sessions show no active entry. Inspecting an event uses its dated setup reading, including its original next condition, rather than a later assessment.

Chart event popovers offer View assessment, which focuses the matching summary above the chart. View supporting bars opens its evidence below; Back to latest exits event inspection. The change adds no provider requests, polling, queues, migrations, environment settings or subscriptions.

## Local review

1. Open `/dashboard?symbol=SPY&mode=intraday&tab=walls` at `http://127.0.0.1:8000` and choose Preview local demonstration. The example remains labeled synthetic and is unavailable in production.
2. Select the put-wall bounce at 10:20 AM ET and choose View assessment. It should show one of two confirming closes, waiting for a second close above 755.3775. It must not show an eligible entry.
3. Inspect the failed retest at 10:40 AM. The next condition is a later close at or below 754.1225. Inspect the confirmed break at 10:45 AM to review direction, entry, invalidation and target.
4. Choose View supporting bars, then Back to latest. Change symbol and confirm the previous symbol's progress clears.
5. Review the two wall cards at a narrow width. They should stack, with entry details in one column. Confirm no duplicate current-assessment cards remain below the chart.

The local dashboard and synthetic endpoint returned HTTP 200 under the existing review account. Fifty-three focused PHP tests passed with 268 assertions, and 41 frontend tests passed. The frontend build and PHP formatting checks passed. Browser automation timed out when binding the local review tab, so desktop and mobile visual review remain manual. Release uses the normal application and frontend deployment with no additional configuration.
