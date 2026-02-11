## Email Lifecycle Testing (Signup Flow)

Use this playbook to test signup lifecycle emails end-to-end in local.

### Scope

This document covers signup lifecycle only:

- `welcome_signup` (immediate)
- `checkout_reminder_1` (+1 hour)
- `checkout_reminder_2` (+24 hours)
- `trial_started` (after Stripe subscription webhook)

Not included:

- Trial ending reminders (removed)
- Payment failed and cancellation lifecycle

### Prerequisites

- Migrations are up to date:
  - `php artisan migrate`
- Queue worker running:
  - `php artisan queue:work`
- Scheduler running (for background automation jobs):
  - `php artisan schedule:work`
- Mail transport is configured in `.env`:
  - `MAIL_MAILER=smtp` (or `log` for log-only testing)

Optional for Stripe webhook testing:

- Stripe CLI installed and authenticated:
  - `stripe login`
  - `stripe listen --forward-to http://127.0.0.1:8000/stripe/webhook`

### Useful tables and classes

- Email dedupe log table: `lifecycle_email_logs`
- Dispatch listener on signup: `app/Listeners/StartOnboardingEmailSequence.php`
- Trial-start dispatch from webhook: `app/Listeners/HandleCashierWebhookLifecycleEmails.php`
- Send guard logic: `app/Jobs/SendLifecycleEmailJob.php`

### Quick verification commands

Count lifecycle logs:

```powershell
php artisan tinker --execute "echo DB::table('lifecycle_email_logs')->count();"
```

See recent lifecycle logs:

```powershell
php artisan tinker --execute "print_r(DB::table('lifecycle_email_logs')->select('id','user_id','event_key','sent_at')->orderByDesc('id')->limit(20)->get()->toArray());"
```

### Test 1: Signup triggers welcome and delayed reminders

1. Register a brand new user from:
   - `/register?plan=earlybird&billing=monthly`
2. Confirm immediate welcome send:
   - `lifecycle_email_logs.event_key` should contain `welcome_signup:{timestamp}`
3. Confirm delayed jobs were scheduled:
   - You should see queue jobs for `checkout_reminder_1` and `checkout_reminder_2` in `jobs` table.

Check queued jobs quickly:

```powershell
php artisan tinker --execute "echo DB::table('jobs')->where('payload','like','%checkout_reminder_%')->count();"
```

Expected result:

- `welcome_signup` sent once.
- Reminder jobs exist with delay.

### Test 2: Reminder suppression after successful checkout

Goal: ensure checkout reminders do not send if user has already started trial.

1. Complete Stripe checkout for the same user.
2. Ensure subscription/trial is active.
3. Force-run reminder jobs (or wait until due).
4. Verify no new `checkout_reminder_1:*` / `checkout_reminder_2:*` log entries were added after activation.

Why this works:

- `SendLifecycleEmailJob::shouldSend()` blocks checkout reminders when user is subscribed or on trial.

### Test 3: Trial started email after Stripe webhook

1. Make sure Stripe webhooks are forwarded:
   - `stripe listen --forward-to http://127.0.0.1:8000/stripe/webhook`
2. Complete checkout in test mode.
3. Confirm `trial_started:{subscription_id}` appears in `lifecycle_email_logs`.

Expected result:

- One `trial_started` email per Stripe subscription id.

### Test 4: Dedupe behavior

Goal: same `event_key` should never send twice.

1. Pick an existing user id.
2. Dispatch the exact same job twice with same `event_key`.

```powershell
php artisan tinker --execute "App\\Jobs\\SendLifecycleEmailJob::dispatch(1,'welcome_signup:test-dedupe','welcome_signup'); App\\Jobs\\SendLifecycleEmailJob::dispatch(1,'welcome_signup:test-dedupe','welcome_signup');"
```

3. Verify only one row exists for that key:

```powershell
php artisan tinker --execute "echo DB::table('lifecycle_email_logs')->where('event_key','welcome_signup:test-dedupe')->count();"
```

Expected result:

- Count is `1`.

### Fast testing without waiting 1h/24h

For local QA, dispatch reminders immediately with unique keys:

```powershell
php artisan tinker --execute "App\\Jobs\\SendLifecycleEmailJob::dispatch(1,'checkout_reminder_1:manual-'.time(),'checkout_reminder_1');"
php artisan tinker --execute "App\\Jobs\\SendLifecycleEmailJob::dispatch(1,'checkout_reminder_2:manual-'.time(),'checkout_reminder_2');"
```

Note:

- If user is already trialing/subscribed, these jobs intentionally no-op.

### Expected subjects (current templates)

- `Welcome to GEX Options - your terminal is ready`
- `Finish setup to start your 7-day trial`
- `Still deciding? Your trial is waiting`
- `Your 7-day trial is live`

### Troubleshooting

- No emails sent:
  - Verify `php artisan queue:work` is running.
  - Check failed jobs:
    - `php artisan queue:failed`
- No `trial_started` email:
  - Verify Stripe webhook forwarding and endpoint secret config.
  - Check logs:
    - `storage/logs/laravel.log`
- Duplicate sends:
  - Check `lifecycle_email_logs` unique key behavior (`user_id + event_key`).

### Cleanup (optional for repeated local tests)

Remove lifecycle logs:

```powershell
php artisan tinker --execute "DB::table('lifecycle_email_logs')->truncate();"
```

