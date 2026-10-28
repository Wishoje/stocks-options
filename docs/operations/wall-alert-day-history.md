# Current-day wall alert history

The alert inbox recovers recorded setups for the signed-in user's saved symbols from the server. It does not depend on a browser being open when the conditions occurred. The history covers the current calendar date in America/New_York and resets when that date changes. New alerts accumulate as their conditions are recorded; there are no alerts for events that have not happened.

## History and notifications

The server already saves wall interaction checkpoints during collection. The account feed projects original qualified setup levels, their qualification times and subsequent lifecycle states into `alert_history`. Optional wall-test history comes from the same saved checkpoint events. The feed uses bounded reads and reports truncation rather than silently presenting a partial result as complete. It adds no provider requests, queue jobs or database tables.

History includes setups that subsequently reached a target, crossed invalidation or ended with the session. Their original levels remain fixed. Returning after the close can recover that day's history. The initial recovery does not play a backlog of sounds or popups. Subsequent fresh eligible events can produce notifications while the site is open.

Popup preferences do not disable history recovery. Browser storage caches records and account-specific read marks. Server records allow history to be rebuilt in another browser, but read marks remain local to each browser. Repeated confirmations of a continuing price plan are grouped; a new qualification after an earlier plan ended remains a separate episode.

The active-only Scanner filters remain unchanged. The inbox has its own grouping and retains recorded outcomes for the rest of the day. Proven terminal results remain visible during a feed interruption; unfinished records retain their recorded timestamps and cannot imply current entry eligibility.

## Returning to the site

The shared account feed refreshes on a visible-tab return after the normal request cooldown, including after the post-close finalization window. This reads saved history without restarting idle overnight polling. The existing current-event freshness rules and delayed-price cutoff still apply to popups.

No migration, environment flag, additional worker or data subscription is required. Collection must still be running to create source evidence; history recovery cannot manufacture observations that were never recorded.

## Complete saved-qualification reads

Setup follow-up previously stopped after the first 160 saved checkpoints for a symbol and session. A busy session could therefore omit later original qualifications. The reader now scans thin checkpoint identities and loads seed fields in primary-key pages of five. A resumable cache stores compact original qualifications and the last processed ID. Cache loss or failure falls back to the retained database evidence.

The reader admits at most 4,096 checkpoint identities per pass, with continuation on the next read. A seed field is capped at 2 MB before it reaches the PHP driver buffer. Individual retained seeds are capped at 8 KB, the registry at 512 seeds and the serialized cache at 1 MB. The cache expires after 36 hours. Actual omissions remain flagged. This changes evidence recovery rather than setup qualification or outcome rules.

## Verification

Check a first visit after a qualified setup has ended, an after-close return and a reload with empty browser storage. The same day's alert should appear with its original levels and recorded outcome. Turn popups off and verify that history still loads. Mark an item read and reload; duplicate confirmations should not create another unread copy. Switch accounts and verify isolation. At midnight Eastern time, prior-day records must leave the inbox without deleting the server evidence used by the owner study.

Focused automated checks cover day-history projection, source paging, notification recovery, Scanner behavior, owner-study regression and restored swing behavior. The source reader suite also passes with a 128 MB PHP limit; its measured peak was approximately 50 MB. A read-only production sample confirmed that completed NVDA and IWM records remained saved and were accepted by the local history projection. These checks did not deploy or change production data.
