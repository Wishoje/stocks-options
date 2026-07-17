# GEX-002 and GEX-003 verification

Verified: 2026-07-16
Scope: Local code, MySQL regression tests, queue contracts, frontend tests, and production build
Production changes: None

## Status

GEX-002 is implemented and its local automated proof passes against MySQL. The Node 20/MySQL GitHub Actions definition is included; its first hosted CI run remains pending until this working tree is committed and pushed.

The GEX-003 queue-contract foundation is implemented locally. Full card acceptance remains open. It still requires the later bounded-publication work listed below, safe Forge settings, and controlled worker termination tests against a disposable environment. Do not run worker-kill tests against the live production databases.

This work does not claim to fix calculator first-load expiration completeness or the complete new-symbol fast path. Those are owned by GEX-004 and GEX-007 through GEX-010, followed by the bounded singleton and provider-limiter work in GEX-018 and GEX-021.

## Automated results

| Check | Result |
|---|---|
| Complete PHP suite on MySQL | 72 tests passed, 627 assertions, 7 feature-configuration skips |
| Focused queue failure-safety suite | 12 tests passed, 46 assertions |
| JavaScript component/unit suite | 8 tests passed across 2 files |
| Production frontend build | Passed; 225 modules transformed |
| Repository PHP syntax | 252 files passed |
| Composer metadata | Valid in strict mode |
| Patch whitespace validation | Passed |

The seven PHP skips are existing tests disabled by application feature configuration. There were no failed or errored tests in the final run.

The local frontend run used the installed Node 18.17 runtime. CI reads `.nvmrc` and runs the required Node 20 release.

## GEX-002 proof

The MySQL fixture covers heavy, normal, stale, partial, empty, and newly added symbols. It captures raw EOD data, expirations, intraday counters, daily snapshots, calculator state, watchlists, unusual activity, expiration pressure, positioning, and frontend-facing API responses.

The tests prove that comparison fails for:

- A removed expiration.
- A changed aggregate.
- A removed response field.
- A newer but thinner calculator publication.
- An older timestamp replacing fresher intraday data.
- A numeric API field changing to a string while still allowing small floating-point noise.

Artifacts are canonical JSON. Generated API identifiers are normalized, common PII and secret fields are redacted recursively, secret-looking authorization values are removed, and the comparison command prints hashes instead of raw differing values.

## GEX-003 proof

The contract tests inventory every queued application job. They enforce this ordering for each supported lane:

    job timeout < worker timeout < retry_after < Supervisor stopwaitsecs

The regression tests also prove:

- Older quote data cannot overwrite a newer provider timestamp.
- A first quote without provider time remains replaceable by real provider time.
- An incomplete intraday response cannot replace the last complete totals.
- A completed export cannot be changed to failed by a late failure callback.
- A later Polygon page failure does not publish earlier accumulated pages.
- A Finnhub 5xx response falls back to Yahoo for historical prices.
- Failure of both historical-price providers throws so a chained job does not advance.
- Interrupted Massive EOD pagination does not publish the accumulated partial chain.
- A complete per-expiration repair after a bulk-page failure is accepted.
- A complete but empty repair removes partial broad-page rows instead of publishing them.
- A known-sparse Finnhub result remains incomplete when Massive cannot confirm coverage.
- A duplicate export delivery cannot reopen an already completed export.
- Session-bound EOD, intraday, and derived jobs keep the date selected at dispatch across retries.

Scheduled producers have overlap and single-leader policies. Long exports use a separate queue connection and queue. Provider requests have connection and request timeouts. Terminal failures use structured categories without provider response bodies or credentials.

## Local test procedure

Use a disposable MySQL 8 database whose name ends in `_test`. Never point this procedure at production.

1. Copy `.env.testing.example` to `.env.testing`.
2. Set only the local test database host, database, username, and password in `.env.testing`.
3. Run the PHP suite:

       composer test:mysql

The PHPUnit bootstrap checks the effective process environment before test discovery. It refuses `DB_URL`, non-MySQL connections, and database names that do not end in `_test` or `_testing`, including values that override `.env.testing`.

4. Run frontend tests and the production build:

       npm ci --no-audit --no-fund
       npm run test:ci
       npm run build

5. Validate project metadata and formatting:

       composer validate --strict --no-check-publish
       git diff --check

To capture API contracts as well as database state, save each response body as a named `.json` file under a private ignored directory such as `storage/app/regression/api`. Do not save request headers or cookies. The harness applies another redaction pass to the JSON values.

To capture a production-shaped artifact from a safe database:

    php artisan market-data:baseline capture --symbols=SPY,QQQ,IWM,AAPL,MSFT,COLD --date=YYYY-MM-DD --api-dir=storage/app/regression/api --output=storage/app/regression/baseline.json

To compare the current database with that artifact:

    php artisan market-data:baseline compare --baseline=storage/app/regression/baseline.json --api-dir=storage/app/regression/api

The compare command exits unsuccessfully and prints field paths when the candidate differs.

## Open correctness work

These results do not certify every original symptom as fixed:

- Calculator first-load completeness, truthful run state, fabricated spot fallback, and bounded expiration/page publication remain GEX-007 through GEX-009 and GEX-015.
- New-symbol fast/fill delivery, dedicated lanes, singleton intraday jobs, and provider-wide backpressure remain GEX-004, GEX-010, GEX-018, and GEX-021.
- Intraday nullable aggregate uniqueness and atomic EOD/intraday generations remain GEX-012 and GEX-013.
- Historical backfill currently proves provider/fallback failure handling, not full requested-range coverage for every listing age.
- Lifecycle email still has an accepted-by-provider/before-commit duplicate window. Closing it requires an outbox or a provider idempotency key.
- Watchlist preload retains the existing global cache flush until GEX-014 adds targeted versioned invalidation with regression proof.
- Real Redis kill/retry equivalence and hosted Node 20 CI remain unverified.

## Production acceptance still required

Before deployment:

1. Rotate the credentials disclosed during infrastructure collection.
2. Configure and verify MySQL and Redis backups and a restore procedure.
3. Set the queue lease and Supervisor values documented in `queue-runtime-contracts.md`.
4. Add and verify the dedicated `redis-long:exports` worker before the web release can dispatch to it.
5. Deploy the worker release before the web release.

Then use a disposable staging database and Redis instance to terminate one worker during each major job family: quotes, historical prices, daily EOD, option chain, intraday, calculator, derived calculations, exports, and lifecycle email. Restart the worker and compare the final artifact with a clean single run. Production GEX-003 acceptance remains open until those results are recorded.
