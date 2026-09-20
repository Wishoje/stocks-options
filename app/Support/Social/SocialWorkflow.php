<?php

namespace App\Support\Social;

use App\Models\SocialPost;
use App\Models\SocialSetting;
use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class SocialWorkflow
{
    public function __construct(private SocialGexSource $source, private SocialCard $card, private XPublisher $x) {}

    public function generate(string $session, string $slot): SocialPost
    {
        if (! in_array($slot, ['primary', 'secondary'], true)) {
            throw new DomainException('Invalid posting slot.');
        }
        $symbol = $slot === 'primary' ? 'SPY' : SocialSetting::current()->second_symbol;
        $post = SocialPost::firstOrCreate(['session_date' => $session, 'slot' => $slot], ['symbol' => $symbol]);
        $claimed = SocialPost::whereKey($post->id)->whereIn('status', ['draft', 'blocked'])->update([
            'status' => 'generating', 'symbol' => $symbol, 'approved_at' => null, 'approved_by' => null, 'quality_acknowledgment' => null,
            'issue' => null, 'updated_at' => now(),
        ]);
        if (! $claimed) {
            return $post->refresh();
        }
        try {
            $snapshot = $this->source->capture($symbol, $session);
            $body = SocialText::draft($snapshot, $session);
            SocialText::validate($body);
            $png = $this->card->render($snapshot, $session);
            // Web and queue workers can live on separate hosts. Keep the small PNG
            // in the shared database, hidden from Inertia and API serialization.
            $path = 'social/'.$post->id.'/'.hash('sha256', $png).'.png';
            $peaks = SocialCard::peaks($snapshot);
            $incomplete = ($snapshot['social_quality']['publishable'] ?? false) !== true;
            $post->refresh()->update([
                'status' => $incomplete ? 'blocked' : 'draft', 'snapshot' => $snapshot, 'body' => $body,
                'issue' => $incomplete ? ($snapshot['social_quality']['missing_input_rows'] ?? 'Unknown number of').' source rows lack required GEX inputs. Review the source-quality details before publishing. Review the gaps and explicitly acknowledge them to approve the available-data chart, or repair the source and regenerate.' : null,
                'image_path' => $path, 'image_sha256' => hash('sha256', $png), 'image_base64' => base64_encode($png),
                'alt_text' => $symbol.' net GEX by strike. EOD '.$snapshot['data_date'].', 2W scope. Total net GEX '.SocialCard::exposure($peaks['total']).'. Largest positive '.SocialCard::exposure($peaks['positive_value']).' at strike '.SocialCard::level($peaks['positive']).'; largest negative '.SocialCard::exposure($peaks['negative_value']).' at strike '.SocialCard::level($peaks['negative']).'. Focused range retains at least 98 percent of absolute GEX. Each bar is one strike; no grouping.'.($incomplete ? ' Incomplete source inputs: review only, do not publish.' : ''),
            ]);
        } catch (Throwable $e) {
            // Do not expose provider payloads, credentials, or infrastructure exception text.
            $post->refresh()->update(['status' => 'blocked', 'snapshot' => null, 'image_path' => null,
                'image_sha256' => null, 'image_base64' => null, 'body' => null, 'alt_text' => null,
                'issue' => $e instanceof DomainException ? $e->getMessage() : 'Draft generation failed. Check data availability and PHP GD/font support, then regenerate.']);
        }

        return $post->refresh();
    }

    public function edit(SocialPost $post, string $body, string $alt): void
    {
        SocialText::validate($body);
        $changed = SocialPost::whereKey($post->id)->whereIn('status', ['draft', 'approved'])->update([
            'body' => $body, 'alt_text' => $alt, 'status' => 'draft', 'approved_at' => null, 'approved_by' => null, 'quality_acknowledgment' => null, 'updated_at' => now(),
        ]);
        if (! $changed) {
            throw new DomainException('This post can no longer be edited.');
        }
    }

    public function approve(SocialPost $post, int $userId, bool $acknowledgeMissingInputs = false, ?string $reviewToken = null): void
    {
        DB::transaction(function () use ($post, $userId, $acknowledgeMissingInputs, $reviewToken) {
            $locked = SocialPost::whereKey($post->id)->lockForUpdate()->firstOrFail();
            $complete = ($locked->snapshot['social_quality']['publishable'] ?? false) === true;
            $acknowledged = ! $complete && $acknowledgeMissingInputs && $this->canAcknowledgeMissingInputs($locked)
                && $reviewToken !== null && hash_equals($this->reviewToken($locked), $reviewToken);
            if (! in_array($locked->status, ['draft', 'blocked'], true) || ! $locked->snapshot || ! $locked->image_path
                || (! $complete && ! $acknowledged) || ($locked->status === 'blocked' && ! $acknowledged)) {
                throw new DomainException('Refresh and review the draft. Missing inputs require explicit acknowledgment of the current content.');
            }
            SocialText::validate($locked->body);
            $this->assertImage($locked);
            $review = $acknowledged ? [
                'approved_by' => $userId, 'acknowledged_at' => now()->toIso8601String(),
                'missing_input_rows' => $locked->snapshot['social_quality']['missing_input_rows'],
                'snapshot_sha256' => $this->snapshotHash($locked), 'image_sha256' => $locked->image_sha256,
                'reason' => 'Owner accepts the disclosed missing inputs and approves the available-data chart.',
            ] : null;
            $locked->update([
                'status' => 'approved', 'approved_at' => now(), 'approved_by' => $userId, 'quality_acknowledgment' => $review,
                'issue' => $acknowledged ? 'Owner acknowledged '.$review['missing_input_rows'].' source rows with missing inputs. Approved using available data; source quality is unchanged.' : null,
                'alt_text' => $acknowledged ? str_replace(' Incomplete source inputs: review only, do not publish.', ' Chart uses available gamma inputs.', $locked->alt_text) : $locked->alt_text,
            ]);
        });
    }

    public function canAcknowledgeMissingInputs(SocialPost $post): bool
    {
        $quality = $post->snapshot['social_quality'] ?? [];
        $expected = SocialGexSource::expectedDate($post->session_date);

        return ($quality['publishable'] ?? null) === false
            && is_int($quality['missing_input_rows'] ?? null) && $quality['missing_input_rows'] > 0
            && ($quality['source_rows'] ?? 0) > $quality['missing_input_rows']
            && ($quality['missing_expiration_count'] ?? null) === 0
            && ($quality['source_dates'] ?? []) === [$expected]
            && ($post->snapshot['data_date'] ?? null) === $expected
            && ($post->snapshot['symbol'] ?? null) === $post->symbol
            && ($post->slot === 'primary' ? $post->symbol === 'SPY' : $post->symbol === SocialSetting::current()->second_symbol)
            && ! empty($post->snapshot['strike_data']);
    }

    private function snapshotHash(SocialPost $post): string
    {
        return hash('sha256', \App\Support\Regression\CanonicalJson::encode($post->snapshot));
    }

    public function reviewToken(SocialPost $post): string
    {
        return hash('sha256', \App\Support\Regression\CanonicalJson::encode([$post->snapshot, $post->image_sha256, $post->body, $post->alt_text]));
    }

    private function hasAcceptedInputs(SocialPost $post): bool
    {
        if (($post->snapshot['social_quality']['publishable'] ?? false) === true) {
            return true;
        }
        $review = $post->quality_acknowledgment;

        return $this->canAcknowledgeMissingInputs($post) && $post->approved_at !== null
            && is_array($review) && (int) ($review['approved_by'] ?? 0) === (int) $post->approved_by
            && hash_equals((string) ($review['snapshot_sha256'] ?? ''), $this->snapshotHash($post))
            && hash_equals((string) ($review['image_sha256'] ?? ''), (string) $post->image_sha256);
    }

    public function assertImage(SocialPost $post): string
    {
        $bytes = $post->image_base64 ? base64_decode($post->image_base64, true) : null;
        if (! is_string($bytes) || ! hash_equals((string) $post->image_sha256, hash('sha256', $bytes))) {
            throw new DomainException('The saved image is missing or changed. Regenerate and review the draft.');
        }

        return $bytes;
    }

    public function publish(SocialPost $post, ?CarbonImmutable $at = null): SocialPost
    {
        $now = ($at ?? CarbonImmutable::now('America/New_York'))->setTimezone('America/New_York');
        if (! config('social.publishing_enabled') || SocialSetting::current()->paused) {
            throw new DomainException('Publishing is disabled or paused.');
        }
        if (! MarketSession::isTradingDay($now) || $post->session_date !== $now->toDateString()
            || ! $this->hasAcceptedInputs($post)
            || substr((string) ($post->snapshot['data_date'] ?? ''), 0, 10) !== SocialGexSource::expectedDate($now->toDateString())) {
            throw new DomainException('Only a current-session draft with the previous completed snapshot can be published.');
        }
        $time = $post->slot === 'primary' ? '08:45' : '09:00';
        $start = $now->setTimeFromTimeString($time);
        if ($now->lt($start) || $now->gte($start->addMinutes(10))) {
            throw new DomainException('This draft is outside its 10-minute publishing window.');
        }

        return $this->submitApproved($post);
    }

    public function publishNextSessionNow(SocialPost $post, int $ownerId, ?CarbonImmutable $at = null): SocialPost
    {
        $now = ($at ?? CarbonImmutable::now('America/New_York'))->setTimezone('America/New_York');
        $session = \App\Support\EodViewContext::defaults($now)['next_session'];
        if (! in_array($ownerId, config('social.admin_ids', []), true) || (int) $post->approved_by !== $ownerId) {
            throw new DomainException('The approving owner must authorize early publication.');
        }
        if (! config('social.publishing_enabled') || SocialSetting::current()->paused) {
            throw new DomainException('Publishing is disabled or paused.');
        }
        if (MarketSession::describe($now)['state'] === 'rth' || $post->session_date !== $session
            || ! $this->hasAcceptedInputs($post)
            || substr((string) ($post->snapshot['data_date'] ?? ''), 0, 10) !== SocialGexSource::expectedDate($session)) {
            throw new DomainException('Early publication requires the upcoming session and its previous completed EOD snapshot.');
        }

        return $this->submitApproved($post);
    }

    private function submitApproved(SocialPost $post): SocialPost
    {
        // Atomic status claim prevents simultaneous manual/scheduled workers from posting twice.
        if (! SocialPost::whereKey($post->id)->where('status', 'approved')->update(['status' => 'publishing', 'updated_at' => now()])) {
            return $post->refresh();
        }
        $submitted = false;
        try {
            $post->refresh();
            SocialText::validate($post->body);
            $png = $this->assertImage($post);
            $this->x->verifyAccount();
            $media = $this->x->upload($png, $post->alt_text);
            if (! config('social.publishing_enabled') || SocialSetting::current()->paused) {
                throw new DomainException('Publishing was paused before submission.');
            }
            $submitted = true;
            $id = $this->x->publish($post->body, $media);
            $post->update(['status' => 'published', 'x_post_id' => $id, 'published_at' => now(), 'issue' => null]);
        } catch (Throwable $e) {
            $post->update(['status' => $submitted ? 'needs_review' : 'draft', 'approved_at' => null, 'approved_by' => null, 'quality_acknowledgment' => null,
                'issue' => $submitted
                    ? 'X submission was not confirmed. Check the account manually. Automatic retry is blocked to prevent duplicates.'
                    : 'X preparation failed or publishing was paused. Check connection and credits, then review and approve again.']);
        }

        return $post->refresh();
    }
}
