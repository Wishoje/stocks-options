<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Support\Social\SocialWorkflow;
use DomainException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishSocialPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $postId)
    {
        $this->onQueue(config('social.queue', 'default'));
        $this->onConnection(config('social.connection'));
    }

    public function handle(SocialWorkflow $workflow): void
    {
        $post = SocialPost::find($this->postId);
        if (! $post || $post->status !== 'approved') {
            return;
        }
        try {
            $workflow->publish($post);
        } catch (DomainException $e) {
            $post->update(['issue' => $e->getMessage()]);
        }
    }
}
