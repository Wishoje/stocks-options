# Local market-data review

Use one of two local review modes:

- Historical replay uses the recorded February 2026 data already in the development database and moves only the HTTP request clock into that market calendar.
- Fresh snapshot review uses a separate, filtered database containing recent market data for `AAPL`, `IWM`, `QQQ`, and `SPY`. The current review snapshot has a market-data date of September 11, 2026.

Laravel migrations create the tables and indexes required by either mode. They do not populate market data. Run all migrations before importing a snapshot, then confirm that every migration reports `Ran`.

## Historical replay

The HTTP review clock lets the Laravel and Vue application resolve recorded data inside its original market calendar. It does not rewrite database rows or alter CLI and queue-worker time.

The middleware is active only for API requests when `APP_ENV=local` and `UI_REVIEW_NOW` is nonblank. It sets Carbon's clock while the API action runs and restores the previous clock before stateful session cookies are written. Web routes, CLI commands, queue workers, and other environments keep real time.

### Setup

1. Back up the local database. Run pending migrations without using `migrate:fresh` or reseeding the recorded database.

2. Add these local-only values to `.env`:

   ```dotenv
   APP_ENV=local
   APP_URL=http://127.0.0.1:8000
   UI_REVIEW_NOW=2026-02-10T23:30:00-05:00
   SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8000,127.0.0.1,localhost:8000,localhost,gex-profile.test:8000,gex-profile.test
   GA4_ID=
   MAIL_MAILER=log
   QUEUE_CONNECTION=database
   ```

   Keep provider and Stripe values private. Do not print or copy them into documentation.

3. From the repository root, clear cached configuration and check migrations with Herd PHP:

   ```powershell
   $herdPhp = "$env:USERPROFILE\.config\herd\bin\php83\php.exe"
   & $herdPhp artisan config:clear
   & $herdPhp artisan migrate:status
   & $herdPhp artisan migrate
   & $herdPhp artisan migrate:status
   ```

   The final status must have no pending migrations.

   Start the PHP application in a dedicated terminal. The Laravel router expects the process working directory to be `public`:

   ```powershell
   $router = (Resolve-Path '.\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php').Path
   Push-Location '.\public'
   & $herdPhp -S 127.0.0.1:8000 $router
   ```

4. Use Node 20 for source changes. If it is not installed in Herd NVM, install it once, select it, and verify the version before starting Vite:

   ```powershell
   $herdNvm = "$env:USERPROFILE\.config\herd\bin\nvm\nvm.exe"
   & $herdNvm install 20.20.2
   & $herdNvm use 20.20.2
   $env:Path = "$env:USERPROFILE\.config\herd\bin\nvm\v20.20.2;$env:Path"
   node --version
   npm run dev
   ```

   The reported version must begin with `v20.`. The PHP application is available at `http://127.0.0.1:8000`; Vite supplies the current frontend assets. The `gex-profile.test` hostname is optional when Herd DNS is available to the browser.

5. Create one dedicated local review account in Tinker. Replace the password placeholder. The configured plan price is read inside Laravel and is never displayed:

   ```powershell
   & $herdPhp artisan tinker
   ```

   ```php
   DB::transaction(function () {
       $user = App\Models\User::firstOrNew(['email' => 'test@example.com']);
       $user->forceFill([
           'name' => 'Local Review',
           'password' => Illuminate\Support\Facades\Hash::make('replace-with-a-local-password'),
           'email_verified_at' => now(),
           'trial_ends_at' => null,
       ])->save();

       $price = config('plans.plans.earlybird.prices.monthly');
       throw_unless(filled($price), RuntimeException::class, 'Local plan price configuration is missing.');

       $subscription = Laravel\Cashier\Subscription::updateOrCreate(
           ['stripe_id' => 'local_review_subscription'],
           [
               'user_id' => $user->id,
               'type' => config('plans.default_subscription_name'),
               'stripe_status' => 'active',
               'stripe_price' => $price,
               'quantity' => 1,
               'trial_ends_at' => null,
               'ends_at' => null,
           ],
       );

       Laravel\Cashier\SubscriptionItem::updateOrCreate(
           ['stripe_id' => 'local_review_item'],
           [
               'subscription_id' => $subscription->id,
               'stripe_product' => 'local_review_product',
               'stripe_price' => $price,
               'quantity' => 1,
           ],
       );
   });
   ```

   In the same Tinker session, verify the local entitlement without printing its configured price:

   ```php
   $user = App\Models\User::where('email', 'test@example.com')->first();
   [
       'subscribed' => $user?->subscribed(config('plans.default_subscription_name')),
       'subscription_items' => $user?->subscription(config('plans.default_subscription_name'))?->items()->count(),
   ];
   ```

   The expected result is `subscribed => true` and `subscription_items => 1`.

   Do not use a generic `trial_ends_at` shortcut. The current User model does not cast that column to a date, while Cashier expects a date object.

## Fresh four-symbol snapshot

Create a separate local database for the snapshot instead of replacing or expanding the historical development database. Run the full migration set in the new database, then import a filtered, data-only market snapshot for these symbols:

- `AAPL`
- `IWM`
- `QQQ`
- `SPY`

Keep the import limited to market-data tables used by the dashboard, scanner, and calculator. The calculator needs its current canonical publication heads, manifests, chains, and row-count metadata so every published expiry reads the same way it does in production. These calculator read models are separate from provider work queues. Never copy production users, authentication records, subscriptions or payments, sessions, queued or failed jobs, `work_runs`, broad application cache, EOD publication workflow state, or other operational state. Use a local review account and local entitlement as described above.

Point `DB_DATABASE` at the separate snapshot database and leave `UI_REVIEW_NOW` blank so requests use the latest imported dates:

```dotenv
DB_DATABASE=gex_profile_ui_review_20260911
UI_REVIEW_NOW=
ACTIVITY_BATCH_PRICING_ENABLED=true
EXPIRY_PRESSURE_BATCH_ENABLED=true
GEX_EXPIRATION_UNIVERSE_ENABLED=true
GEX_EXPIRATION_SHADOW_ENABLED=false
OPTION_LIVE_TOTALS_READ_FROM_CANONICAL=true
OPTION_LIVE_TOTALS_DUAL_WRITE=false
OPTION_LIVE_TOTALS_COMPARE_WRITES=false
INTRADAY_FRESHNESS_ENABLED=false
EOD_SNAPSHOT_HEALTH_ENABLED=false
EOD_SNAPSHOT_HEALTH_READ_ENABLED=false
EOD_CACHE_PUBLICATIONS_WRITE_ENABLED=false
EOD_CACHE_PUBLICATIONS_READ_ENABLED=false
```

The enabled flags select existing market read models. The operational freshness, health, and EOD publication controls remain disabled because the local snapshot does not include production workflow state. Keep the queue worker stopped. Clear Laravel's configuration cache after changing databases or flags, then confirm the migration status before starting the review server.

The current filtered snapshot uses September 11, 2026 as its EOD and intraday market-data date. Unusual Activity should display the same contract set as the production snapshot. Premium estimates can differ because the application calculates some prices dynamically from the available chain records and cache state. Recreate only a narrowly scoped derived market cache entry when a production-backed visual depends on it; do not copy the general production cache.

This is a static review snapshot. Date labels and freshness states will age while the queue remains stopped. If an exact-date indicator becomes neutral or a freshness label changes after the snapshot date, refresh the filtered snapshot instead of starting provider workers.

## Review boundaries

Keep the queue worker stopped. Do not run `composer run dev`, because that command starts `queue:listen`. Some screens can enqueue provider work when data appears missing; a database queue without a worker keeps those jobs from making provider requests.

Review `/`, `/features`, `/pricing`, `/login`, and `/register` while signed out. Sign in with the local review account, then review `/dashboard`, `/scanner`, `/options-calculator`, and `/ai-export`. The `/eod-health` page has a separate user-ID gate and is not part of normal subscriber review.

Do not submit the contact form, add or remove watchlist symbols, start exports, refresh or prime market data, switch to live provider actions, or use checkout, billing portal, cancel, or resume controls. The fake local subscription IDs must never be sent to Stripe.

Check the dashboard EOD timeframes, Positioning, Volatility, Unusual Activity, and the recorded-data date labels. Intraday may show a closed, stale, or pending state depending on the recorded snapshot. Check desktop, tablet, and 390 px mobile layouts, keyboard focus, and reduced-motion behavior.

To leave historical replay, blank or remove `UI_REVIEW_NOW`, run `artisan config:clear`, and reload the application. To leave fresh snapshot review, point `DB_DATABASE` back to the normal local database, clear configuration, and reload.
