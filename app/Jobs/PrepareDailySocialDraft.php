<?php

namespace App\Jobs;

use App\Models\SocialSetting;
use App\Support\Social\SocialSchedule;
use App\Support\Social\SocialWorkflow;
use DomainException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrepareDailySocialDraft implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public string $session, public string $slot, public string $preparedOn)
    {
        $this->onConnection(config('social.connection'));
        $this->onQueue(config('social.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'daily-social:'.$this->session.':'.$this->slot.':'.$this->preparedOn;
    }

    public function handle(SocialWorkflow $workflow): void
    {
        if (! config('social.schedule_enabled') || SocialSetting::current()->paused || ! in_array($this->slot, ['primary', 'qqq', 'tsla'], true)
            || ! SocialSchedule::dailyPreparationWindow() || SocialSchedule::manualSession() !== $this->session
            || now('America/New_York')->toDateString() !== $this->preparedOn) {
            return;
        }
        $post = $workflow->generate($this->session, $this->slot, $this->preparedOn);
        if (! $post->snapshot || $post->scheduled_prepared_on !== $this->preparedOn || ! in_array($post->status, ['draft', 'blocked'], true)
            || $this->slot !== 'primary' || ! config('social.automatic_spy_enabled') || ! SocialSchedule::inWindow(true)) {
            return;
        }
        try {
            $workflow->approveAutomatically($post);
        } catch (DomainException $e) {
            $post->update(['issue' => $e->getMessage()]);
        }
    }
}
