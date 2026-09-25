# Customer copy review

Customer pages focus on levels, readings, units, dates, chart controls, and interpretation. The Wall Levels panel uses a collapsed "How to read these levels" guide. Routine input-coverage percentages and exclusion counts no longer appear there.

The wording sweep covers dashboard guides, positioning, volatility, strike charts, intraday flow, scanner summaries, calculator labels, and marketing copy. Intraday session totals remain available without the provider-clock and ingestion-metadata disclosure. Dates, delayed states, actual unavailable readings, estimates, and calculation assumptions remain clear.

Internal quality checks, API contracts, raw export data, admin diagnostics, and approval gates are unchanged. The local audit retains its coverage information. No data is relabeled as complete and no missing reading is converted to zero.

## Verification

- Frontend production build passed.
- 460 tests passed across 50 frontend test files. The unchanged AppShell symbol-selection file remains excluded because of its five previously identified request-option assertion failures.
- Browser review confirmed that the loaded local Wall Levels panel and its expanded guide contain no coverage or missing-input explanation.
- Marketing URLs, canonical handling, and structured FAQ generation remain intact. Existing screenshots retain their historical pixels; this sweep updates rendered text and captions.
- No commit, push, or deployment was performed.

## Local review

Open http://127.0.0.1:8000/dashboard. Review Wall Levels under Overview or Strikes, expand its reading guide, and switch to Positioning, Volatility, and Intraday. Check Scanner summaries, Calculator quote labels, and /features. Levels, dates, and interactions should remain the same while explanations focus on the task.
