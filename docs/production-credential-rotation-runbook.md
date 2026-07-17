# Production credential rotation runbook

Status: Required
Incident date: 2026-07-16
Scope: GEXOptions web and worker releases

Production environment contents containing active credentials were pasted into an external support conversation. Treat every disclosed secret as compromised. Deleting or redacting the message reduces further exposure but does not make the old values safe again.

Never place current or retired credential values in this repository, a ticket, logs, screenshots, shell history, or chat. Store them only in the intended provider dashboard/Forge environment and an approved password manager.

## Immediate controls

1. Delete/redact the message and attachment where the environment contents were pasted, if the interface permits it.
2. Do not paste either full environment again.
3. Verify that no credential value was committed. Workspace and Git-history signature scans on 2026-07-16 found no recognizable production secret; .env.example contains only empty/null placeholders.
4. Take a verified MySQL backup before database-user or application-key work.
5. Perform rotations during a controlled off-market window, with both Forge sites and provider dashboards available.
6. Keep the current release commit and rollback procedure available.

## Rotation principles

- Generate a unique replacement; never derive it from the exposed value.
- Prefer an old/new overlap only long enough to update both releases and verify service.
- Update Forge environments without copying values into deployment scripts.
- Rebuild cached Laravel configuration and restart long-lived workers through the deployment process.
- Test the affected integration before revoking the old credential.
- Revoke the old credential immediately after verification.
- Record service, owner, rotation time, verification, and revocation status without recording either value.

## Required order

### 1. Stripe secret key

1. In Stripe Workbench/API keys, roll the live secret key with a short overlap.
2. Replace STRIPE_SECRET in the web site Forge environment.
3. Deploy the web release.
4. Verify a read-only Stripe/Cashier request and inspect Stripe request logs.
5. Expire the old key.

Stripe publishable keys and price identifiers are not secret credentials.

Reference: [Stripe API key rotation](https://docs.stripe.com/keys#rolling-keys).

### 2. Stripe webhook signing secret

1. In Stripe Workbench/Webhooks, select the production endpoint and roll its signing secret.
2. Use the shortest practical overlap.
3. Replace STRIPE_WEBHOOK_SECRET in the web Forge environment and deploy.
4. Send a test event and verify signature validation plus a successful 2xx delivery.
5. Expire the old signing secret and recheck deliveries.

Reference: [Stripe webhook secret rotation](https://docs.stripe.com/webhooks#roll-endpoint-secrets).

### 3. Laravel application key

The repository uses Laravel 11 and supports APP_PREVIOUS_KEYS. Because the prior key is compromised, the safest response is a direct rotation without retaining it as a previous key. This keeps the service online but logs users out and makes old encrypted cookies invalid.

1. Confirm the database backup.
2. Generate a replacement without modifying a live environment:

       php8.3 artisan key:generate --show

3. Put the same new APP_KEY into both Forge site environments. Do not paste it elsewhere.
4. Do not keep the compromised key in APP_PREVIOUS_KEYS unless uninterrupted sessions are an explicit requirement.
5. Deploy both releases in a coordinated window.
6. Verify web login, subscription authorization, queue dispatch/consumption, scheduler, mail, and representative API responses.
7. Invalidate existing database sessions as part of the announced global logout.

If APP_PREVIOUS_KEYS is required for the few minutes of the coordinated rollout, remove it immediately afterward, redeploy both sites, and invalidate sessions. Retaining a compromised previous key continues accepting data encrypted/signed with that key.

The application repository contains no direct Crypt usage or encrypted model casts, but production/package behavior must still be verified before discarding the old key.

Reference: [Laravel graceful key rotation](https://laravel.com/docs/11.x/encryption#gracefully-rotating-encryption-keys).

### 4. Mailtrap SMTP credential

1. Reset/create the replacement credential in Mailtrap.
2. Update MAIL_PASSWORD on both web and worker Forge environments because background jobs may send mail.
3. Make MAIL_ENCRYPTION consistent between releases.
4. Deploy/restart both releases.
5. Send a test message through the production mailer.
6. Confirm delivery and revoke/allow expiry of the old credential.

Reference: [Mailtrap API/SMTP token management](https://docs.mailtrap.io/email-api-smtp/setup/api-tokens).

### 5. MySQL application user

Do not manually change Forge’s managed root or default forge password. Forge documents that those passwords should be managed from its database panel.

For a staged application cutover:

1. In Forge’s database panel on the database host, create a new application-specific MySQL user with a generated password and access to only the production application database.
2. Preserve the current application’s required migration privileges during this incident cutover. Reduce privileges later in a separate tested change.
3. Update DB_USERNAME and DB_PASSWORD on the worker site, deploy, and verify queue reads/writes plus failed-job storage.
4. Update the web site, deploy, and verify reads/writes, login/session behavior, and a safe migration-status command.
5. Confirm the old account has no application connections.
6. Revoke/delete the old application credential only after both releases are verified.

References: [Forge database users and password management](https://forge.laravel.com/docs/resources/databases), [MySQL account creation](https://dev.mysql.com/doc/refman/8.4/en/create-user.html).

### 6. Redis credential

Queue, cache, and locks share one Redis process using separate logical databases. Never flush Redis during rotation.

1. Record Redis version, persistence mode, and ACL configuration first.
2. For Redis 6+, prefer a named application ACL user or a short multi-password overlap.
3. Ensure the ACL/password change is persisted through the Forge-managed Redis configuration.
4. Update REDIS_USERNAME where applicable and REDIS_PASSWORD on both Forge sites.
5. Deploy the web site and restart all queue workers through Forge deployment/restart controls.
6. Verify:
   - Queue dispatch and consumption.
   - All seven queue sizes/status.
   - Cache read/write.
   - Distributed lock acquisition.
   - Scheduler overlap locks.
   - No authentication errors or evictions.
7. Remove the old password/user and terminate old authenticated connections.

If the installed Redis/configuration supports only one requirepass value, schedule a short coordinated cutover through Forge’s Redis password recipe rather than editing Forge-managed configuration ad hoc.

References: [Forge Redis password configuration](https://forge.laravel.com/docs/resources/caches), [Redis ACL password rotation](https://redis.io/docs/latest/commands/acl-setuser/).

### 7. Massive/Polygon market-data key

The same provider credential currently supplies both POLYGON_API_KEY and MASSIVE_API_KEY.

1. Create a replacement key in the provider dashboard.
2. Set both variables to the replacement on both Forge sites.
3. Deploy/restart the worker site, then deploy the web site.
4. Test quote, EOD, intraday, and calculator requests without logging request authorization.
5. Delete the old key.

Reference: [Massive authentication](https://massive.com/docs/rest).

### 8. Finnhub

1. Generate/regenerate a key in the Finnhub dashboard.
2. Update both Forge sites.
3. Deploy/restart and test daily-price/backfill fallbacks.
4. Revoke the old key. If the provider does not permit overlapping keys, use a controlled off-market cutover.

Reference: [Finnhub API](https://finnhub.io/docs/api).

### 9. SteadyAPI

1. Generate a new Personal Access Token.
2. Update both Forge environments.
3. Deploy/restart and test the SteadyAPI integration.
4. Delete the old token.

Reference: [SteadyAPI documentation](https://docs.steadyapi.com/).

### 10. Yahoo OAuth cleanup

The repository does not reference YAHOO_CLIENT_ID or YAHOO_CLIENT_SECRET; the current Yahoo fallback uses a public chart endpoint.

1. Confirm no external application uses this Yahoo app.
2. Remove both unused variables from both Forge environments.
3. Revoke/delete the Yahoo application credentials.
4. If an external integration does use them, rotate through a new Yahoo application and explicitly migrate its authorization instead.

## Verification matrix

| Capability | Required proof |
|---|---|
| Web | Homepage, login, authenticated dashboard, subscription entitlement |
| Sessions | Old sessions invalidated as planned; new login persists |
| MySQL | Read/write, failed-job table, migration status |
| Redis queue | Web dispatch reaches worker; one test job completes once |
| Redis cache/locks | Cache read/write and distributed lock succeeds |
| Scheduler | One leader; scheduled command reports normally |
| Stripe | Read-only API request and signed test webhook |
| Mail | Test message delivered |
| Massive/Polygon | Quote, EOD, intraday, and calculator smoke calls |
| Finnhub | Price fallback/backfill smoke call |
| SteadyAPI | Authenticated smoke call |
| Logs | No credentials or authorization headers printed |

## Completion criteria

- Every disclosed secret is replaced.
- Every old secret is revoked or deleted.
- Both Forge releases use the intended new values.
- Users are logged out after APP_KEY rotation and can sign in again.
- Database, Redis, queues, cache, locks, scheduler, payments, webhooks, mail, and market-data providers pass verification.
- The repository and Git history contain no credential values.
- The incident record contains dates/status only, never the values.
