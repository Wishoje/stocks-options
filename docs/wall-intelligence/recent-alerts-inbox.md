# Recent alerts inbox

The Alerts bell sits beside the account menu in the signed-in top navigation. It remains visible beside the navigation toggle on mobile. Opening it shows a scrollable panel without changing the selected dashboard symbol or tab. Escape and the backdrop close the panel. Keyboard focus stays inside while it is open and returns to the bell when dismissed.

The inbox keeps the latest 100 real notifications received today in this browser for the signed-in account. Each entry retains the original symbol, wall, completed-close time, scenario, price levels and assessment reference. Later feed readings cannot replace that notification's assessment. Setup records show their recorded entry, five-minute invalidation boundary and reference target. Optional wall-testing records keep their pending-confirmation meaning and show no entry levels. Saved entries explain that their entry area may no longer apply.

The bell displays an unread count. Mark all read preserves the entries. Opening an individual assessment marks that entry read and follows its original assessment reference. The tracker retains its existing unavailable-reference behavior if that evidence is no longer available. Toast dismissal and timeout do not delete the saved entry. Disabling alerts, a feed error or removing a symbol from the watchlist leaves received history available.

Storage is browser-local and account-scoped. Read status synchronizes across tabs. The list resets at midnight Eastern time while the site is open, or on the next visit. Blocked storage falls back to memory for the visit with an explicit message. Only compact notification fields are stored; full feed responses, evidence bars and contract chains are excluded. Preview examples are clearly synthetic and never enter saved history or the unread count. Signed-out users have no bell, entries or monitoring.

The inbox receives only notifications already emitted by the existing alert engine. It does not replay initial feed data or generate alerts while the site is closed. Opening the panel, marking entries read and previewing an example add no requests. Existing shared polling, eligibility, opt-outs, deduplication, sound settings and queue schedules are unchanged. There are no migrations, environment changes or new provider dependencies.

## Review

1. Open `/scanner` and click the Alerts bell in the top navigation. Preview example shows a labeled synthetic setup, including its recorded levels, without creating an unread alert.
2. After a real notification arrives, dismiss its popup and reload the page. The entry and unread count should remain in the bell's panel. Its assessment link should retain the original symbol and assessment ID.
3. Mark all read, then reopen the panel. The entries remain and the badge clears. Turning monitoring off leaves the saved list available.
4. Review at a phone width. The bell remains outside the account controls that collapse on mobile. The panel scrolls vertically without horizontal page overflow.

Sixty-seven focused frontend tests passed, and the frontend build passed. Checks cover persisted snapshots, account isolation, cross-tab updates, Eastern-time rollover, bounded retention, blocked and corrupt storage, synthetic-data exclusion, exact assessment navigation, read status, focus restoration and the existing alert/polling contracts. Desktop and 390-pixel browser review confirmed the example panel and mobile fit, with no horizontal page overflow.
