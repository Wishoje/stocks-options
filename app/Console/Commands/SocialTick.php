<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialPost;
use App\Models\SocialPost;
use App\Models\SocialSetting;
use Illuminate\Console\Command;

class SocialTick extends Command
{
    protected $signature = 'social:tick';

    protected $description = 'Prepare three fresh daily drafts; automatically post SPY on Sunday, Tuesday, and Thursday mornings';

    public function handle(): int
    {
        if (! config('social.schedule_enabled')) {
            return self::SUCCESS;
        }
        SocialPost::where('status', 'publishing')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'needs_review', 'issue' => 'Worker stopped during publishing. Check X manually; no automatic retry.']);
        SocialPost::where('status', 'generating')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'blocked', 'issue' => 'Generation was interrupted. Regenerate this draft.']);
        if (SocialSetting::current()->paused || ! \App\Support\Social\SocialSchedule::dailyPreparationWindow()) {
            return self::SUCCESS;
        }
        $date = now('America/New_York')->toDateString();
        $session = \App\Support\Social\SocialSchedule::manualSession();
        foreach (['primary' => 'SPY', 'qqq' => 'QQQ', 'tsla' => 'TSLA'] as $slot => $symbol) {
            $post = SocialPost::where('session_date', $session)->where('symbol', $symbol)->first();
            if (! $post || ($post->scheduled_prepared_on !== null && $post->scheduled_prepared_on !== $date && in_array($post->status, ['draft', 'blocked'], true))) {
                \App\Jobs\PrepareDailySocialDraft::dispatch($session, $slot, $date);
            }
        }
        if (config('social.publishing_enabled') && \App\Support\Social\SocialSchedule::inWindow()) {
            $post = SocialPost::where('session_date', \App\Support\Social\SocialSchedule::session())->where('slot', 'primary')->where('symbol', 'SPY')->where('status', 'approved')->first();
            if ($post) {
                PublishSocialPost::dispatch($post->id);
            }
        }

        return self::SUCCESS;
    }
}
