<?php

namespace App\Jobs;

use App\Support\EodCacheVersion;
use App\Support\Symbols;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Advance EOD response caches only after all preceding jobs in a chain finish.
 */
class PublishEodCacheVersionJob extends QueueJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    /** @var string[] */
    public array $symbols;

    public int $issuedAtMicroseconds;

    public function __construct(
        array $symbols,
        public array $domains = EodCacheVersion::ALL_DOMAINS,
        public ?string $publicationToken = null,
        ?int $issuedAtMicroseconds = null
    ) {
        $this->symbols = collect($symbols)
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->publicationToken = $publicationToken ?: (string) Str::orderedUuid();
        $this->issuedAtMicroseconds = $issuedAtMicroseconds
            ?? (int) floor(microtime(true) * 1_000_000);
    }

    public function handle(EodCacheVersion $versions): void
    {
        $versions->publish(
            $this->symbols,
            $this->domains,
            $this->publicationToken,
            $this->issuedAtMicroseconds
        );
    }
}
