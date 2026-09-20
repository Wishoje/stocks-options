<?php

namespace App\Jobs;

use App\Support\Social\SocialWorkflow;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateSocialDraft implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public string $session, public string $slot)
    {
        $this->onQueue(config('social.queue', 'default'));
        $this->onConnection(config('social.connection'));
    }

    public function uniqueId(): string
    {
        return $this->session.':'.$this->slot;
    }

    public function handle(SocialWorkflow $workflow): void
    {
        $workflow->generate($this->session, $this->slot);
    }
}
