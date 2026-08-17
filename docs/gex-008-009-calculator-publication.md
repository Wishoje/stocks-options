# GEX-008 and GEX-009 calculator publication

This release makes calculator data complete by construction and fixes the first-load, contract-selection, underlying-price, and DTE defects covered by GEX-008, GEX-009, GEX-009A, GEX-009B, and GEX-009C.

## Runtime contract

`GET /api/option-chain` is a pure read. It does not dispatch a job. `POST /api/prime-calculator` is the explicit authenticated refresh action introduced by GEX-011. It returns a durable WorkRun ID and status URL.

A full-catalog calculator run follows this sequence:

1. Create or reuse the durable WorkRun and its calculator publication run.
2. Follow provider pagination to a terminal cursor.
3. Freeze the expected expiration set. A capped cursor, malformed response, repeated cursor, or HTTP failure cannot freeze or publish a catalog.
4. Commit each expiration as an immutable publication and advance its pointer only when its generation and source time are not older than the pointer high-water mark.
5. Advance the catalog pointer only when every frozen expiration is ready, no expiration failed, and discovery was not capped.

An expected expiration failure terminalizes the candidate manifest as failed while preserving its per-expiration readiness and any ready immutable publications. It never advances the catalog pointer.

A selected-expiration refresh can advance only that expiration's pointer. It cannot make the symbol catalog complete.

Scheduled full-catalog refreshes still use the GEX-007 scheduler claim, while API refreshes use a durable GEX-011 WorkRun. Both write through the same generation and source-time publication fences, so an older result cannot replace a newer one. Coalescing one provider request across those two producer types remains GEX-016 scope.

The read API serves the current complete catalog. If a newer run is preparing, partial, failed, or capped, the response keeps the last complete catalog and usable rows, reports the candidate state separately, and returns an overall partial state. Every expiration includes its own readiness and publication metadata. The server also returns the resolved expiration when a requested expiration is unavailable.

The API retains the legacy response fields for compatibility and adds catalog, run, publication, quote, and DTE metadata. Calculator rows include a stable contract identity, ticker, expiration date, integer DTE, and implied volatility.

## Correctness changes

- The underlying price comes only from a timestamped `underlying_quotes` record that passes the configured session freshness policy. Missing or untrusted data returns `price=null`, `status=unavailable`, and disables calculations. A real price of exactly 100 remains valid.
- The API calculates DTE from the `America/New_York` calendar date and returns an integer. The browser does not parse a date-only expiration or recalculate DTE.
- Call and put selection is atomic. Contract identity, type, strike, expiration, premium, IV, breakeven, payoff, and labels come from one row. A type switch selects the same-strike contract from the same provider contract family or clears the selection. Adjusted contracts are not paired across different deliverables.
- Distinct standard and adjusted contracts remain separate even when they share type, strike, and expiration. Exact duplicate provider rows collapse deterministically; conflicting rows for one contract identity fail closed.
- A manual entry price remains manual and is labeled as such. Automatic entry follows the selected contract's current premium.
- The browser sends at most one refresh start for an active request. It polls only the lightweight WorkRun status URL, follows `Retry-After`, stops after 50 requests by default, and offers a continue-checking action without starting a second run.
- Symbol, expiration, and navigation changes abort pending reads, status requests, and delays. Older responses cannot replace the current selection.
- Preparing and failed candidates do not erase the last-known-good chain.
- A complete empty catalog is reported as `no_options` and is not refreshed repeatedly. A non-empty completed catalog whose members have all expired is due for refresh and is not mislabeled as no-options.
- A first terminal empty catalog may publish `no_options` only when there is no current or future chain evidence. A later empty response cannot replace an existing non-empty catalog or hide rollout-era `option_snapshots`, EOD expirations, or selected-expiration publications; it fails with `provider_empty_after_nonempty` and keeps the last-known-good menu and rows.

## Storage and retention

The release adds these MySQL tables:

- `work_runs` and `work_run_slots` for durable GEX-011 work ownership.
- `calculator_symbol_generations` for monotonic per-symbol generations.
- `calculator_publication_runs` and `calculator_run_expirations` for the frozen run manifest.
- `calculator_expiry_publications` and `calculator_expiry_publication_rows` for immutable expiration data.
- `calculator_catalog_heads` and `calculator_expiry_heads` for current, previous, and high-water pointers.

It also makes `option_snapshots.underlying_price` nullable and adds `option_snapshots.implied_volatility`.

Canonical publication rows use a non-null stable contract key derived from the normalized provider ticker, with a deterministic type-and-strike fallback when no ticker exists. Adjusted contracts with the same expiration, type, and strike remain distinct when their provider tickers differ.

`option_snapshots` remains a compatibility store while older non-calculator consumers are migrated. A successful fresh catalog performs the compatibility upsert only after canonical publication, in one transaction, when the legacy key can represent the full cohort. If adjusted contracts collide under the legacy type/strike/expiration key, the projection keeps the prior legacy cohort instead of collapsing contracts. Failed, capped, partial, superseded, selected-expiration, resumed, and stale-pointer candidates do not write a new legacy cohort. This full-chain transaction can briefly delay a concurrent selected-expiration publication for a very large symbol. It is a temporary bridge; later work should move remaining readers to immutable heads and remove the dual-write.

The prune command keeps current and previous catalog and expiration pointers. It locks and revalidates the retention cutoff and protected heads before deleting an old unreferenced run. Generation high-water rows are retained.

## Configuration

No new worker process or queue is required. With `QUEUE_LANES_ISOLATED=false`, the existing `calculator` workers consume calculator refreshes. With isolation enabled, the existing `calculator-interactive`, `calculator-fill`, and `calculator-fill-heavy` workers remain the owners.

All new settings have defaults. Production may pin the `WORK_RUN_*` values from `.env.example` on both web and worker sites. The calculator quote policy should also match on both sites:

```dotenv
CALCULATOR_QUOTE_EXTENDED_START=04:00
CALCULATOR_QUOTE_EXTENDED_END=20:00
CALCULATOR_QUOTE_REGULAR_LIVE_SECONDS=600
CALCULATOR_QUOTE_REGULAR_USABLE_SECONDS=3600
CALCULATOR_QUOTE_EXTENDED_LIVE_SECONDS=1800
CALCULATOR_QUOTE_EXTENDED_USABLE_SECONDS=43200
CALCULATOR_QUOTE_CLOSED_LIVE_SECONDS=0
CALCULATOR_QUOTE_CLOSED_USABLE_SECONDS=259200
CALCULATOR_QUOTE_ALLOW_STALE=true
CALCULATOR_QUOTE_FUTURE_TOLERANCE_SECONDS=30
```

`VITE_CALCULATOR_STATUS_MAX_REQUESTS=50` is an optional web build-time setting. The default is 50 and values are clamped to 5 through 100. Leave it unset unless production needs a different active polling window.

## Deployment prerequisites

This release changes a populated table and creates durable state tables. Deploy after market hours.

1. Take a complete MySQL backup and copy it off the production servers.
2. Restore that backup into an isolated database and record the successful restore check.
3. Check the size of `option_snapshots`, available disk, and long-running MySQL transactions. Stop if MySQL does not have enough temporary space for the alter.
4. Confirm the candidate commit passed GitHub's MySQL CI, the focused PHP suite, the JavaScript suite, and the production frontend build.

The previous production inventory recorded no database backups. Do not run these migrations until a restorable backup exists.

If Forge push-to-deploy is enabled for both sites, temporarily disable the worker site's automatic deployment. The web release must apply the shared migrations before the worker release starts the new jobs.

## Controlled Forge rollout

1. Put `gexoptions.com` in maintenance mode. Leave the old worker release running so existing queue work can finish.
2. Deploy the web site. Its deploy script runs `php artisan migrate --force` before activation.
3. Confirm migrations `2026_08_16_000001`, `000002`, and `000003` completed. Keep the web site in maintenance mode.
4. Deploy the same commit SHA to `stocks-options-ss7u2nu2.on-forge.com` on the GexOptions-workers server.
5. Confirm both sites have the same SHA. Rebuild the configuration cache on both sites.
6. Restart the queue workers once, then confirm every configured process is `RUNNING`.
7. Confirm `work-runs:reconcile` is registered on the worker scheduler and the calculator queue is stable or draining.
8. Bring the web site out of maintenance mode.
9. Run the read-only checks and one normal-symbol smoke test below. Run the SPY heavy-symbol check only after the normal test passes.

Do not flush Redis, clear queues, delete WorkRuns, delete publication rows, or drop the additive tables during deployment or rollback.

## Production preflight

On the web site:

```bash
ssh forge@178.156.219.172
cd /home/forge/gexoptions.com/current
git rev-parse HEAD
php8.3 artisan migrate:status | grep -E '2026_08_16_000001|2026_08_16_000002|2026_08_16_000003'
php8.3 artisan route:list --path=api/option-chain
```

On the worker site:

```bash
ssh forge@178.156.205.230
cd /home/forge/stocks-options-ss7u2nu2.on-forge.com/current
git rev-parse HEAD
php8.3 artisan list --raw | grep -E '^work-runs:reconcile|^calculator:prime-watchlist'
php8.3 artisan schedule:list | grep -E 'work-runs:reconcile|calculator:prime-watchlist'
sudo supervisorctl status | grep -E 'RUNNING|FATAL|BACKOFF'
```

Verify resolved configuration without printing credentials:

```bash
php8.3 artisan tinker --execute='$keys=["queue_lanes.isolated","queue.connections.redis.retry_after","work_runs.pending_ttl_seconds","work_runs.running_ttl_seconds.calculator_refresh","work_runs.status_poll_seconds","calculator_underlying.freshness_seconds.regular.live","calculator_underlying.freshness_seconds.regular.usable","calculator_underlying.freshness_seconds.extended.live","calculator_underlying.freshness_seconds.extended.usable","calculator_underlying.freshness_seconds.closed.live","calculator_underlying.freshness_seconds.closed.usable","calculator_underlying.allow_stale_for_calculation"]; foreach($keys as $key){echo $key."=".var_export(config($key),true).PHP_EOL;}'
```

Check queue depth:

```bash
php8.3 artisan tinker --execute='$q=\Illuminate\Support\Facades\Queue::connection("redis"); foreach(["calculator","calculator-interactive","calculator-fill","calculator-fill-heavy"] as $name){echo $name."=".$q->size($name).PHP_EOL;}'
```

## Read-purity and data-integrity checks

Run this on the worker site. It calls the same read service three times and proves it did not increase calculator queue depth:

```bash
php8.3 artisan tinker --execute='$q=\Illuminate\Support\Facades\Queue::connection("redis"); $names=["calculator","calculator-interactive","calculator-fill","calculator-fill-heavy"]; $before=collect($names)->mapWithKeys(fn($n)=>[$n=>$q->size($n)]); for($i=0;$i<3;$i++){app(\App\Services\CalculatorChainReadService::class)->read("AAPL",null);} $after=collect($names)->mapWithKeys(fn($n)=>[$n=>$q->size($n)]); echo json_encode(["before"=>$before,"after"=>$after]).PHP_EOL;'
```

Expected: `before` and `after` are identical, except for unrelated production work observed separately.

Check that no row claims an invalid complete catalog:

```bash
php8.3 artisan tinker --execute='$bad=\Illuminate\Support\Facades\DB::table("calculator_publication_runs")->where("status","complete")->where(function($q){$q->where("discovery_terminal",false)->orWhere("discovery_capped",true)->orWhereColumn("completed_count","!=","expected_count")->orWhere("failed_count",">",0);})->count(); echo "invalid_complete_runs={$bad}".PHP_EOL;'
```

Expected: `invalid_complete_runs=0`.

For a symbol with a complete catalog, compare the frozen catalog and API expiration sets:

```bash
php8.3 artisan tinker --execute='$s="AAPL"; $repo=app(\App\Support\CalculatorPublicationRepository::class); $catalog=$repo->authoritativeCatalog($s); $read=app(\App\Services\CalculatorChainReadService::class)->read($s,null)["payload"]; echo json_encode(["catalog_run"=>$catalog["run_id"]??null,"catalog_state"=>$read["catalog_state"],"expected"=>collect($catalog["expirations"]??[])->pluck("expiration")->values(),"api"=>collect($read["expirations"]??[])->pluck("value")->values(),"resolved_expiry"=>$read["resolved_expiry"],"underlying"=>$read["underlying"]]).PHP_EOL;'
```

Expected: the API contains the non-expired authoritative expirations, the selected expiration is resolved by the server, and `underlying` includes price/status/source/as-of/age/usable metadata. An unavailable quote has `price=null`; it is never replaced with 100.

## Browser smoke test

Use a signed-in account with calculator access and open the browser Network panel.

1. Open the calculator for AAPL. The initial request is one `GET /api/option-chain`.
2. If data is due, the browser sends at most one `POST /api/prime-calculator`. It then polls only the returned `/api/work-runs/{id}` URL.
3. Full option-chain reads occur only when the selected expiration becomes ready and when the run becomes terminal. There must not be a full-chain request every two seconds.
4. Reload the page. Every expiration in the authoritative catalog should appear immediately with Ready, Preparing, or Unavailable status. A browser refresh must not reveal extra expirations.
5. Request an expiration that is not present. Confirm the response contains both `requested_expiry` and a different valid `resolved_expiry`, and the UI selects the resolved value.
6. During a partial refresh, confirm the prior complete chain remains visible and the UI reports background progress.
7. Switch rapidly between two symbols and two expirations. An older response must not replace the current symbol or selection, and the refresh button must not remain disabled after cancellation.
8. If polling reaches its request ceiling, confirm the UI shows the slow-background state. Click Continue checking and confirm it resumes the same run ID without another refresh POST.

Then verify calculator inputs:

1. Select a call and note its contract, premium, IV, breakeven, and payoff.
2. Switch to Long Put. The same-strike put must replace every contract-derived field together. If no counterpart exists, the selection and calculations must clear.
3. Enter a manual premium, switch type, and confirm the value remains labeled Manual. Switch back to live pricing and confirm it follows the selected contract.
4. Confirm the displayed DTE equals the calendar-day difference between `as_of_exchange_date` and `expiration_date` in the API response.
5. If the quote is unavailable, confirm the chain remains visible but payoff, chart, and derived calculations are paused. A legitimate quoted value of exactly 100 remains usable when it has trusted source and as-of metadata.
6. If the chain contains standard and adjusted contracts at the same strike, confirm each contract family appears as a separate selectable row. Switching call/put must stay within that family; if its counterpart is absent, the selection must clear.

## Controlled heavy-symbol check

After AAPL succeeds, refresh SPY once. Record the returned WorkRun ID. On the worker site inspect it without changing state:

```bash
php8.3 artisan tinker --execute='$id="REPLACE_WITH_RUN_ID"; $work=\App\Models\WorkRun::query()->find($id); $publication=app(\App\Support\CalculatorPublicationRepository::class)->runForWorkRun($id); echo json_encode(["work_run"=>$work?->only(["id","symbol","status","queue","attempt","requested_at","started_at","heartbeat_at","completed_at","error_category","error_code"]),"calculator"=>$publication]).PHP_EOL;'
```

Expected: one active WorkRun, one calculator publication run, a heartbeat that advances during pagination, and a terminal `completed` or truthful `failed` state. A capped provider response must not replace the prior complete SPY catalog.

## Stop conditions and rollback

Stop the rollout if any of these occur:

- the migration fails, disk becomes constrained, or MySQL shows a long metadata lock;
- web and worker sites run different SHAs after the controlled deployment;
- repeated identical starts create different active WorkRun IDs;
- a GET increases queue depth;
- a run reports complete with a nonterminal cursor, a cap, failed expirations, or mismatched counts;
- a partial/capped run removes the previous expiration menu or selected chain;
- an unavailable underlying is returned or displayed as 100;
- status polling starts more refresh POSTs or repeatedly downloads the full chain;
- calculator queues grow without a matching running worker.

For an application rollback, deploy the previous SHA to both sites outside market hours, rebuild both configuration caches, and restart the workers. Leave all additive tables and nullable columns in place. Old code ignores the new tables, and retaining them preserves rollback pointers and incident evidence. Do not run the migration `down()` methods or delete publication data during the rollback window.

## Local and CI verification

Run these checks before release:

```bash
php8.3 artisan test tests/Feature/Gex011WorkEndpointContractTest.php tests/Feature/WorkRunCoordinatorTest.php tests/Feature/CalculatorPublicationRepositoryTest.php tests/Feature/FetchCalculatorChainJobStateTest.php tests/Feature/CalculatorUnderlyingResolverTest.php tests/Feature/CalculatorChainApiTest.php tests/Feature/PruneCalculatorPublicationsTest.php tests/Unit/ExchangeCalendarDteTest.php
npm test
npm run build
php8.3 vendor/bin/pint --test
git diff --check
```

GitHub's MySQL job is a required release gate. The isolated local calculator tests use SQLite for speed and do not replace the MySQL migration and locking checks.
