# Wall Intelligence: Batch 1

Batch 1 establishes a versioned audit contract and local observation storage before wall classification or setup logic is introduced. It includes a local review page at `/wall-foundation`. Existing dashboard, scanner, AI export, and social-post calculations retain their current behavior.

## Delivered scope

- Audit of existing GEX, HVL, DEX, intraday repricing, and wall-snapshot storage.
- `wall-foundation.v1` snapshot contract with explicit units, scope, source coverage, provenance, and unavailable-field reasons.
- Additive `wall_observations` table and a local command for deduplicated audit capture.
- Local review of SPY, QQQ, and TSLA using either local database responses or an explicitly labeled production capture.
- Synthetic test fixture and regression coverage for calculation, scope, storage, access, and frontend request behavior.

Classification, setup predicates, price interaction detection, evidence grades, numeric scores, and a scheduled market-observation collector are not implemented in this batch. Audit captures are never eligible historical outcomes.

## Calculation audit

| Existing source | Finding | Required follow-up |
| --- | --- | --- |
| `GexController::buildGexPayload` | Legacy GEX is gamma × open interest × 100 × spot², using recorded contract inputs. Net equals call minus put. | Explicitly introduce normalized units in new consumers, with parity checks. |
| `GexController::findHVL` | HVL is the first negative-to-nonnegative transition across strike bars, with a first-strike fallback. | Compute a separate spot-scenario curve before exposing a modeled gamma flip. |
| `IntradayController::repricedGexCompute` | Repricing uses EOD OI, a common expiry approximation, possible fallback spot 100 and IV 20%, and a zero baseline for the delta field. | Certify inputs and observation timing before using the result for migration or interaction history. |
| `ComputeSymbolWallSnapshots::handle` | The writer updates a symbol/date/timeframe record, overwriting earlier observations for that key. | Add genuine timestamped market observations through a separate collector. |
| `ComputePositioningJob` / `PositioningController` | DEX uses delta × OI × 100 in share equivalents. Gamma context uses a fixed 14-day scope. | Align explicit expiry sets before combining signals. |
| Published GEX response | Source dates exist, but provider spot and Greeks observation timestamps and a distinct OI date are not certified. | Capture source provenance directly; never manufacture observation timestamps. |
| Existing consumers | Legacy fields have established interpretations. | Use the additive contract; migrate consumers deliberately in later batches. |

## Contract semantics

The adapter is `App\Support\WallIntelligence\WallSnapshotContract`. Its model version is `legacy-eod-adapter.v1`.

- `raw.gex_response` preserves the complete parsed source response, including all strike rows and existing quality metadata.
- `normalized_by_strike` multiplies each valid legacy GEX value by `0.01`. The resulting unit is USD per 1% underlying-price move. This is a unit conversion of the existing model, not a newly observed dealer position or a full repricing model.
- `available_input_net_gex_per_1pct` sums valid returned inputs. `complete_net_gex_per_1pct` remains null when input coverage, scope, or reconciliation is incomplete.
- Call-minus-put reconciliation uses an absolute/relative floating-point tolerance. All legs and strikes must contain finite numeric values. Invalid values produce an explicit reason.
- Real zero values remain zero. Missing or non-finite values remain null. No missing snapshot becomes a zero-exposure snapshot.
- The scope key includes symbol, horizon, view, sorted expiry set, timezone, inventory convention, multiplier assumption, exposure basis, schema version, and model version. It intentionally excludes observation date so later observations can be compared only when their scope still matches.
- A rolling expiry set, different horizon, or different view changes the scope key. Equal keys alone do not certify temporal alignment or fresh inputs.
- `distancePct` returns absolute distance in percentage points. It requires a positive spot. Distance is not an evidence-quality score.
- `magnitudeChangePct` compares absolute magnitudes, requires caller-certified comparability, and returns null for a zero baseline. It is not currently used to generate history. A future comparison must expose sign changes separately.
- `rule_version` remains null. `interaction_status` is `unknown`; evidence grade, gamma flip, strength, hold, break-risk, and acceleration scores remain null with reasons.
- Every audit is non-actionable and ineligible for historical outcome analysis, including an audit whose source inputs reconcile completely.

The `partial` label records incomplete source inputs. It does not replace separate action blockers for missing observation provenance and unimplemented interaction/setup rules. Missing-gamma OI percentage measures input coverage; it cannot estimate how much GEX is missing.

## Production capture used for local verification

The capture was copied read-only from the production published GEX response on September 25, 2026 at 07:22:28 UTC. It contains SPY, QQQ, and TSLA for `14d`, both `latest_eod` and `next_session`. All six responses returned HTTP 200 with source date September 24.

| Latest-EOD symbol | Strike rows | Missing-input contract rows / source rows | OI with missing gamma |
| --- | ---: | ---: | ---: |
| SPY | 329 | 45 / 3,572 | 0.28% |
| QQQ | 282 | 63 / 3,710 | 0.77% |
| TSLA | 128 | 56 / 924 | 0.36% |

All three reconcile call minus put to net. These are existing source gaps, not values removed by the adapter. Source quality is preserved without changing social-post approval policy.

The file lives under ignored private storage at `storage/app/private/wall-foundation/production-capture.json`. It is a recorded sample, not a live connection. It does not overwrite the local market database. The local database still contains September 11 review data for SPY/QQQ and lacks TSLA; that alternative deliberately demonstrates the unavailable state.

No provider requests or additional subscriptions were needed for Batch 1. Existing account entitlements for future underlying bars, trade/quote history, and observation cadence have not been verified. Check those before dependent work; a paid upgrade is not assumed.

## Storage and operation

Migration: `2026_09_25_080000_create_wall_observations_table.php`.

Records include schema/model versions, scope key, dataset, symbol, analysis session, source date, captured/recorded timestamps, quality state, payload size, and full JSON. Exact observation time stays null. Indexes support scope lookup and symbol/dataset/source-date lookup. The unique content hash deduplicates identical evidence while ignoring audit generation/capture timestamps. Changed evidence appends a new record.

`WallObservation` rejects model updates and deletes. This is an application-level append-only rule, not a database-level immutable ledger: direct SQL and bulk query operations can bypass model events. No update/delete UI is provided.

The proposed planning envelope is three symbols, one horizon, five-minute cadence over a 390-minute session: 234 observations per regular session. The review reports an estimate based on current payload sizes. It excludes indexes, backups, and underlying bars. The 180-day retention value is a proposal; no collector, pruning job, or production storage policy is enabled.

Run from the repository with PHP 8.3:

```powershell
php artisan migrate --path=database/migrations/2026_09_25_080000_create_wall_observations_table.php
php artisan walls:audit-foundation --dataset=production_capture --view=latest_eod
php artisan walls:audit-foundation --dataset=production_capture --view=next_session --record
php artisan walls:audit-foundation --dataset=local_review --json
```

Only `--record` writes observations. Repeating it for the same evidence returns the same IDs. HTTP review requests never write observations or trigger symbol-bootstrap jobs. The command and review routes require `APP_ENV=local`. Web/API access additionally requires the existing authenticated product entitlement; the audit API is limited to 10 requests per minute.

Report and capture timestamps use the actual clock. Only local market selection follows the existing local review clock. A frozen market replay date does not become the time a report was generated.

## Manual review

1. Open `http://127.0.0.1:8000/wall-foundation` using the existing local review account.
2. Keep Recorded production capture selected. Switch SPY, QQQ, and TSLA. Check source date, strike count, missing-input coverage, raw GEX, and normalized GEX. The normalized value should be 1/100 of the raw value.
3. Change Latest EOD snapshot to Next-session preparation. Check analysis session and included expiry dates. Switch quickly and verify old results do not remain under the new selection.
4. Select Local review database. Check the clearly labeled September 11 replay data; TSLA should show unavailable instead of a fabricated zero.
5. Download audit JSON. Confirm the full raw response, normalized rows, explicit units, null reasons, and scope/version metadata are retained.
6. Check that gamma flip, grades, and scores remain unavailable and Eligible historical outcomes is zero. Open audit findings and collection planning for the remaining dependencies.
7. Check the page at desktop and phone widths, then open the existing dashboard to confirm its normal controls and values are unchanged.

## Validation

Focused tests cover raw preservation, normalization, real zeros, invalid values, partial inputs, scope rollovers, source-date mixing, unavailable snapshots, append-only model behavior, content deduplication, changed evidence, authentication, entitlement, production-route denial, bounded requests, cancellation, and stale-response protection.

The generic SQLite test configuration cannot run the existing AI-export suite because an older migration queries MySQL `information_schema.statistics`. Use the repository's `phpunit.mysql.xml` with a disposable local database ending in `_test` or `_testing` for that suite. Never point destructive tests at the local review database.

Later batches need the complete RET-25/26 predicates and evidence rubric before setup output can be implemented. No missing thresholds or quality weights were invented in Batch 1.

### Local verification result â€” September 25, 2026

- 18 PHP contract/storage/access/calendar tests passed (83 assertions).
- 6 existing AI-export regression tests passed on isolated local MySQL (42 assertions).
- 5 frontend tests passed.
- Production asset build and tracked diff whitespace checks passed.
- Applied only the new observation migration to the local review database.
- Repeated final production-capture recording returned the same IDs (10, 11, 12).
- Browser checked desktop and 390px phone layouts, symbol switching, both expiry views, both data sources, TSLA's unavailable state in the local database, and the JSON download control. No horizontal overflow was present in the checked layouts.
- Review server: `http://127.0.0.1:8000/wall-foundation`.
- No production writes, provider requests, commit, push, or deployment were performed for this batch.


## Dashboard UI follow-up

The EOD Overview and Strikes tabs now include a Wall levels panel. It uses the existing GEX response and adds no requests or dependencies. Primary and secondary put/call levels retain the source ranking: negative net GEX by magnitude for puts and positive net GEX for calls. Selecting a level displays its net, call, and put GEX in USD per 1% move. Existing chart/export values remain unchanged.

The panel follows symbol, timeframe, and expiry view. It hides while the response belongs to a different selection. Level selection resets when the source changes. Missing readings stay unavailable; actual zero stays zero. Numeric scores and price-interaction confirmations are not generated.

The customer panel focuses on levels, units, dates, and interpretation. Coverage percentages and exclusion diagnostics remain in the local audit. Backend quality gates and raw exports are unchanged.

Validation: 84 frontend tests passed, production assets built, and desktop/390px browser checks passed. Additional-level selection and navigation to the existing strike chart were checked. The local dashboard continues to use its September 11 review data; the separately labeled September 24 production capture remains on the audit page.

Manual review: open `/dashboard?symbol=SPY&mode=eod&tab=overview&timeframe=14d&view=latest_eod`, select additional walls, switch timeframes and symbols, expand How to read these levels, and use Explore strike chart. Check the cards stack at phone width. No commit or deployment is included.


## Release requirements

No new environment values, API keys, packages, paid services, or scheduled jobs are required. Keep the production application environment unchanged; the audit routes and command remain local-only.

Build the frontend with `npm run build`. The batch includes the additive `2026_09_25_080000_create_wall_observations_table.php` migration for future observation storage; apply it through the normal migration step. The customer dashboard uses the existing GEX response and does not query this table. Private production captures and local review settings are not part of the release.
