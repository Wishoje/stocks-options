<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSocialDraft;
use App\Jobs\PublishSocialPost;
use App\Models\SocialPost;
use App\Models\SocialSetting;
use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SocialTick extends Command
{
    protected $signature = 'social:tick';

    protected $description = 'Prepare daily GEX drafts and dispatch only approved posts in their New York time slots';

    public function handle(): int
    {
        if (! config('social.schedule_enabled')) {
            return self::SUCCESS;
        }
        SocialPost::where('status', 'publishing')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'needs_review', 'issue' => 'Worker stopped during publishing. Check X manually; no automatic retry.']);
        SocialPost::where('status', 'generating')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'blocked', 'issue' => 'Generation was interrupted. Regenerate this draft.']);
        $now = CarbonImmutable::now('America/New_York');
        if (SocialSetting::current()->paused || ! MarketSession::isTradingDay($now)) {
            return self::SUCCESS;
        }
        $date = $now->toDateString();
        if ($now->format('H:i') >= '08:30' && $now->format('H:i') < '09:00') {
            foreach (['primary', 'secondary'] as $slot) {
                if (! SocialPost::where('session_date', $date)->where('slot', $slot)->exists()) {
                    GenerateSocialDraft::dispatch($date, $slot);
                }
            }
        }
        if (! config('social.publishing_enabled')) {
            return self::SUCCESS;
        }
        foreach (['primary' => '08:45', 'secondary' => '09:00'] as $slot => $time) {
            $start = $now->setTimeFromTimeString($time);
            if ($now->lt($start) || $now->gte($start->addMinutes(10))) {
                continue;
            }
            $post = SocialPost::where('session_date', $date)->where('slot', $slot)->where('status', 'approved')->first();
            if ($post) {
                PublishSocialPost::dispatch($post->id);
            }
        }

        return self::SUCCESS;
    }
}
