<?php

namespace App\Jobs;

use App\Models\AiExport;
use App\Services\AiExportBuilder;
use RuntimeException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class BuildAiExportJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 2;

    public array $backoff = [60];

    public function __construct(public int $exportId)
    {
        $this->onConnection((string) config('queue.long_connection'));
        $this->onQueue((string) config('queue.long_queue', 'exports'));
    }

    public function handle(AiExportBuilder $builder): void
    {
        $lock = Cache::lock("ai-export:run:{$this->exportId}", $this->timeout + 60);
        if (! $lock->get()) {
            return;
        }

        try {
            // A Redis redelivery after the completion save but before ACK must
            // be a no-op. The conditional transition also prevents a late
            // duplicate from reopening a terminal success.
            $claimed = AiExport::query()
                ->whereKey($this->exportId)
                ->where('status', '!=', 'completed')
                ->update([
                    'status' => 'processing',
                    'started_at' => now(),
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                return;
            }

            $export = AiExport::query()->find($this->exportId);
            if (! $export) {
                return;
            }

            $payload = $builder->build(
                $export->symbols ?? [],
                $export->indicators ?? [],
                (string) data_get($export->options, 'gex_timeframe', '30d')
            );

            $stamp = now('America/Chicago')->format('Y-m-d_H-i-s');
            $fileName = "watchlist-eod-ai-export-{$stamp}-{$export->id}.json";
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                throw new RuntimeException('Failed to encode AI export JSON.');
            }

            $export->forceFill([
                'status' => 'completed',
                'payload_json' => $json,
                'file_disk' => null,
                'file_path' => null,
                'file_name' => $fileName,
                'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
                'completed_at' => now(),
            ])->save();
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        AiExport::query()
            ->whereKey($this->exportId)
            ->where('status', '!=', 'completed')
            ->update([
                'status' => 'failed',
                'error_message' => 'Export failed ('.$this->errorCategory($e).').',
                'completed_at' => now(),
            ]);

        parent::failed($e);
    }
}
