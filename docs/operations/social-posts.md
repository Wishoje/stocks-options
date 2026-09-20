# Social post operations

The owner-only page is `/admin/social`. It creates SPY and either QQQ or TSLA drafts from the same GEX calculation used by the dashboard. Subscribers have no access to this page, its images, or its source downloads.

## Initial production mode

Use the verified owner's user ID in `SOCIAL_ADMIN_IDS`. Keep both `SOCIAL_SCHEDULE_ENABLED=false` and `SOCIAL_PUBLISHING_ENABLED=false`. Manual draft generation and PNG downloads work without X credentials. `SOCIAL_QUEUE_CONNECTION=sync` is suitable for initial manual review; scheduled operation can use an existing supervised queue connection and its consumed queue name through `SOCIAL_QUEUE`.

Run the additive `2026_09_20_100000_create_social_posts_tables` migration. PHP GD with FreeType is required. The bundled Atkinson Hyperlegible fonts are licensed under the accompanying SIL OFL license; their source is the Google Fonts `ofl/atkinsonhyperlegible` directory. No browser runtime or new Composer package is needed to render PNGs.

Both web and worker must have matching social configuration and refreshed Laravel configuration caches. Images are stored as hidden base64 fields in the shared database, alongside their hash and frozen source, because web and worker hosts do not share local disks. The database backup therefore includes the reviewed image.

## Data and image rules

Choose the target trading session. A Monday premarket draft uses Friday's completed EOD snapshot, except when the market calendar requires an earlier session. Every included expiry must have that same expected snapshot date. Missing or stale dates block generation.

The scope is the dashboard 2W window, resolved for the target session. Social drafts consume the same published `next_session` GEX response as the dashboard. Total net GEX and the largest positive and negative exposures use every returned numeric strike. The signed chart keeps individual strikes, trimming at most 1% of absolute exposure from each tail. Its caption discloses the displayed range and coverage. The source JSON retains all rows.

Incomplete nonzero or unknown open-interest rows produce a blocked draft with diagnostics in the admin page and JSON. The PNG omits internal diagnostics. An owner may explicitly acknowledge missing inputs in the admin page and approve the available-data chart. Missing expirations, mixed source dates, absent snapshots, and invalid images remain blocked. Approval records the owner, time, missing-row count, and snapshot/image hashes in `quality_acknowledgment`. It does not change source quality flags or automatically approve future drafts. Editing or regenerating revokes the acknowledgment. Missing gamma, underlying price, or open interest must be repaired upstream; the social feature does not manufacture inputs. Zero-open-interest rows can safely contribute zero without gamma.

## Review workflow

1. Generate SPY and the selected secondary symbol for the next trading session.
2. Compare the snapshot date, expiry scope, total, positive peak, and negative peak with the matching dashboard source. Download the PNG and source JSON.
3. Review the caption and image description. Saving an edit revokes approval. Return an approved post to draft before regenerating it.
4. Review the image and caption, and explicitly acknowledge disclosed input gaps when accepting an available-data chart. Approval does not enable posting when the server publishing switch is off.

Changing QQQ to TSLA revokes existing future secondary approvals. Refresh the list after background generation. Local review can use the existing historical review clock; production publishing always uses the actual clock.

## Future scheduled posting

After image review and a separate decision to activate posting, install the four OAuth 1.0a values from the X app in server environment configuration: `X_CONSUMER_KEY`, `X_CONSUMER_SECRET`, `X_ACCESS_TOKEN`, and `X_ACCESS_TOKEN_SECRET`. The app needs Read and write permission. The connection check only reads the account identity and requires @GexOptions.

The client uses OAuth 1.0a media upload and X v2 post creation. HTTP requests are covered by mocked tests. Live media upload and posting have not been verified during the draft-only rollout; verify compatibility and account access before enabling publishing. Never commit credentials.

The existing Laravel scheduler invokes `social:tick` each minute. With scheduling enabled, it prepares missing drafts between 08:30 and 09:00 America/New_York on trading days. It never approves a draft. Approved SPY posts can submit from 08:45 up to 08:55; secondary posts from 09:00 up to 09:10. Late jobs are rejected. Pausing in the admin page stops scheduled work and publishing checks.

Only one record exists for each session and slot. An atomic status claim prevents duplicate submissions. An unconfirmed X submission becomes `needs_review` and is never automatically retried. Check the account manually before reconciling it. If a process remains in `publishing` for more than five minutes, the enabled scheduler also sends it to review. A failed preparation requires a fresh approval. No automatic catch-up posting is performed.

## Manual review checklist

- Owner sees Social posts in desktop and mobile navigation; another subscriber receives 403 on social routes.
- Both symbols generate the expected dated card, or clearly explain why source data is blocked.
- PNG contains all three headline metrics, signed bars, readable date/scope, and branding. No chart controls or browser chrome appear.
- Downloads return PNG and complete JSON. Preview images are private and not indexed.
- Caption edits, approval, and return to draft behave consistently; changing the secondary symbol revokes its old approval.
- Publishing and scheduling remain off during draft review. No X post is sent by generation, downloads, or approval.
