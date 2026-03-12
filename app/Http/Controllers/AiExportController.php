<?php

namespace App\Http\Controllers;

use App\Jobs\BuildAiExportJob;
use App\Models\AiExport;
use App\Models\Watchlist;
use App\Services\AiExportBuilder;
use App\Support\Symbols;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiExportController extends Controller
{
    public function index(): JsonResponse
    {
        $items = AiExport::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AiExport $export) => $this->serializeExport($export))
            ->values();

        return response()->json(['items' => $items]);
    }

    public function queue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbols' => ['nullable', 'array', 'max:250'],
            'symbols.*' => ['required', 'string', 'max:32'],
            'indicators' => ['nullable', 'array', 'max:20'],
            'indicators.*' => ['required', 'string', 'in:'.implode(',', AiExportBuilder::EXPORTABLE_INDICATORS)],
            'timeframe' => ['nullable', 'string', 'in:'.implode(',', AiExportBuilder::GEX_TIMEFRAMES)],
        ]);

        $watchlistSymbols = Watchlist::query()
            ->where('user_id', Auth::id())
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(fn ($symbol) => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->values();

        $symbols = collect($validated['symbols'] ?? $watchlistSymbols->all())
            ->map(fn ($symbol) => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($symbols)) {
            return response()->json(['message' => 'No watchlist symbols selected.'], 422);
        }

        $indicators = collect($validated['indicators'] ?? AiExportBuilder::EXPORTABLE_INDICATORS)
            ->map(fn ($indicator) => strtolower(trim((string) $indicator)))
            ->filter(fn ($indicator) => in_array($indicator, AiExportBuilder::EXPORTABLE_INDICATORS, true))
            ->unique()
            ->values()
            ->all();

        if (empty($indicators)) {
            return response()->json(['message' => 'Select at least one indicator.'], 422);
        }

        $timeframe = strtolower((string) ($validated['timeframe'] ?? '30d'));

        $export = AiExport::query()->create([
            'user_id' => Auth::id(),
            'status' => 'queued',
            'symbols' => $symbols,
            'indicators' => $indicators,
            'options' => [
                'gex_timeframe' => $timeframe,
                'format' => 'json',
                'watchlist_count' => $watchlistSymbols->count(),
            ],
        ]);

        BuildAiExportJob::dispatch($export->id)->onQueue('default');

        return response()->json([
            'item' => $this->serializeExport($export->fresh()),
        ], 202);
    }

    public function show(AiExport $export): JsonResponse
    {
        $this->authorizeExport($export);

        return response()->json([
            'item' => $this->serializeExport($export),
        ]);
    }

    public function download(AiExport $export): StreamedResponse|JsonResponse
    {
        $this->authorizeExport($export);

        if ($export->status !== 'completed') {
            return response()->json(['message' => 'Export is not ready yet.'], 409);
        }

        if (is_string($export->payload_json) && $export->payload_json !== '') {
            return response()->streamDownload(
                function () use ($export) {
                    echo $export->payload_json;
                },
                $export->file_name ?: "watchlist-eod-ai-export-{$export->id}.json",
                ['Content-Type' => 'application/json']
            );
        }

        if (!$export->file_disk || !$export->file_path || !Storage::disk($export->file_disk)->exists($export->file_path)) {
            $export->forceFill([
                'status' => 'failed',
                'error_message' => 'Export payload missing from database and storage.',
                'completed_at' => $export->completed_at ?? now(),
            ])->save();

            return response()->json(['message' => 'Export file is missing.'], 404);
        }

        return Storage::disk($export->file_disk)->download(
            $export->file_path,
            $export->file_name ?: basename($export->file_path),
            ['Content-Type' => 'application/json']
        );
    }

    private function authorizeExport(AiExport $export): void
    {
        abort_unless((int) $export->user_id === (int) Auth::id(), 403);
    }

    private function serializeExport(AiExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'symbols' => $export->symbols ?? [],
            'symbol_count' => count($export->symbols ?? []),
            'indicators' => $export->indicators ?? [],
            'indicator_count' => count($export->indicators ?? []),
            'options' => $export->options ?? [],
            'file_name' => $export->file_name,
            'error_message' => $export->error_message,
            'created_at' => optional($export->created_at)?->toIso8601String(),
            'started_at' => optional($export->started_at)?->toIso8601String(),
            'generated_at' => optional($export->generated_at)?->toIso8601String(),
            'completed_at' => optional($export->completed_at)?->toIso8601String(),
            'download_url' => $export->status === 'completed'
                ? route('api.ai-export.download', ['export' => $export->id])
                : null,
        ];
    }
}
