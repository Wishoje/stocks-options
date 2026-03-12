<?php

namespace App\Jobs;

use App\Models\AiExport;
use App\Services\AiExportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class BuildAiExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public int $exportId)
    {
    }

    public function handle(AiExportBuilder $builder): void
    {
        $export = AiExport::query()->find($this->exportId);
        if (!$export) {
            return;
        }

        $export->forceFill([
            'status' => 'processing',
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        $payload = $builder->build(
            $export->symbols ?? [],
            $export->indicators ?? [],
            (string) data_get($export->options, 'gex_timeframe', '30d')
        );

        $stamp = now('America/Chicago')->format('Y-m-d_H-i-s');
        $fileName = "watchlist-eod-ai-export-{$stamp}-{$export->id}.json";
        $filePath = "ai-exports/user-{$export->user_id}/{$fileName}";

        Storage::disk('local')->put(
            $filePath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $export->forceFill([
            'status' => 'completed',
            'file_disk' => 'local',
            'file_path' => $filePath,
            'file_name' => $fileName,
            'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
            'completed_at' => now(),
        ])->save();
    }

    public function failed(\Throwable $e): void
    {
        AiExport::query()
            ->whereKey($this->exportId)
            ->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
    }
}
