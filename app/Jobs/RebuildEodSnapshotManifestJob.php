<?php

namespace App\Jobs;

use App\Support\EodSnapshotHealth;
use App\Support\EodSnapshotManifestBuilder;
use App\Support\Symbols;
use App\Support\WorkRunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RebuildEodSnapshotManifestJob extends QueueJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 3;

    public function __construct(
        public string $workRunId,
        public string $workRunDeliveryToken,
        public string $symbol,
        public int $revision,
        public string $cacheVersion,
        public array $policy
    ) {
        if (! \Illuminate\Support\Str::isUuid($workRunId) || ! \Illuminate\Support\Str::isUuid($workRunDeliveryToken)
            || $symbol !== Symbols::canon($symbol) || ! Symbols::isValid($symbol) || $revision < 1
            || $cacheVersion === '' || $cacheVersion === 'initial' || strlen($cacheVersion) > 128) {
            throw new \InvalidArgumentException('EOD manifest rebuild identity is invalid.');
        }
        $this->policy = EodSnapshotManifestBuilder::normalizePolicy($policy);
    }

    public function handle(EodSnapshotHealth $health, WorkRunCoordinator $runs): void
    {
        $attempt = max(1, $this->attempts());
        if (! $runs->markStarted($this->workRunId, $this->workRunDeliveryToken, $attempt)) {
            return;
        }
        $health->rebuild($this->symbol, $this->revision, $this->cacheVersion, $this->policy, [
            'run_id' => $this->workRunId, 'delivery_token' => $this->workRunDeliveryToken, 'attempt' => $attempt,
        ]);
        // An obsolete certified revision is terminal for this intent. A later
        // read can claim its own correctly versioned rebuild. Unexpected errors
        // retain ownership for the normal queue retry; failed() is terminal.
        $runs->markCompleted($this->workRunId, $this->workRunDeliveryToken, $attempt);
    }

    public function failed(Throwable $exception): void
    {
        app(WorkRunCoordinator::class)->markTerminalException(
            $this->workRunId, $this->workRunDeliveryToken, max(1, $this->attempts()), $exception
        );
        parent::failed($exception);
    }
}
