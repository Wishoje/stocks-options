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
    // SUBSTR uses character offsets, so a UTF-8 chunk is at most 4 MiB.
    private const PAYLOAD_CHUNK_CHARACTERS = 1024 * 1024;

    private const METADATA_COLUMNS = [
        'id',
        'user_id',
        'status',
        'symbols',
        'indicators',
        'options',
        'file_name',
        'error_message',
        'created_at',
        'started_at',
        'generated_at',
        'completed_at',
    ];

    private const DOWNLOAD_COLUMNS = [
        'id',
        'user_id',
        'status',
        'file_disk',
        'file_path',
        'file_name',
        'error_message',
        'completed_at',
    ];

    public function index(): JsonResponse
    {
        $items = AiExport::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->limit(20)
            ->get(self::METADATA_COLUMNS)
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
            'gex_view' => ['nullable', 'in:latest_eod,next_session'],
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
                'gex_view' => $validated['gex_view'] ?? \App\Support\EodViewContext::defaults()['default_view'],
                'target_session' => ($validated['gex_view'] ?? \App\Support\EodViewContext::defaults()['default_view']) === 'next_session' ? \App\Support\EodViewContext::defaults()['next_session'] : null,
                'format' => 'json',
                'watchlist_count' => $watchlistSymbols->count(),
            ],
        ]);

        BuildAiExportJob::dispatch($export->id);

        return response()->json([
            'item' => $this->serializeExport($export->fresh()),
        ], 202);
    }

    public function show(string $export): JsonResponse
    {
        $item = AiExport::query()
            ->select(self::METADATA_COLUMNS)
            ->findOrFail($export);

        $this->authorizeExport($item);

        return response()->json([
            'item' => $this->serializeExport($item),
        ]);
    }

    public function download(string $export): StreamedResponse|JsonResponse
    {
        $item = AiExport::query()
            ->select(self::DOWNLOAD_COLUMNS)
            ->findOrFail($export);

        $this->authorizeExport($item);

        if ($item->status !== 'completed') {
            return response()->json(['message' => 'Export is not ready yet.'], 409);
        }

        $firstChunk = $this->readPayloadChunk($item, 1);

        if (is_string($firstChunk) && $firstChunk !== '') {
            return response()->streamDownload(
                function () use ($item, $firstChunk) {
                    $offset = 1;
                    $chunk = $firstChunk;

                    do {
                        echo $chunk;

                        $offset += self::PAYLOAD_CHUNK_CHARACTERS;
                        $chunk = $this->readPayloadChunk($item, $offset);
                    } while (is_string($chunk) && $chunk !== '');
                },
                $item->file_name ?: "watchlist-eod-ai-export-{$item->id}.json",
                ['Content-Type' => 'application/json', 'X-Accel-Buffering' => 'no']
            );
        }

        if (! $item->file_disk || ! $item->file_path || ! Storage::disk($item->file_disk)->exists($item->file_path)) {
            $item->forceFill([
                'status' => 'failed',
                'error_message' => 'Export payload missing from database and storage.',
                'completed_at' => $item->completed_at ?? now(),
            ])->save();

            return response()->json(['message' => 'Export file is missing.'], 404);
        }

        return Storage::disk($item->file_disk)->download(
            $item->file_path,
            $item->file_name ?: basename($item->file_path),
            ['Content-Type' => 'application/json']
        );
    }

    private function readPayloadChunk(AiExport $export, int $offset): ?string
    {
        // Slice in SQL: streaming a string already loaded in PHP still exhausts
        // the web process's memory for large exports.
        return $export->newQuery()
            ->whereKey($export->id)
            ->toBase()
            ->selectRaw('SUBSTR(payload_json, ?, ?) AS payload_chunk', [$offset, self::PAYLOAD_CHUNK_CHARACTERS])
            ->value('payload_chunk');
    }

    private function authorizeExport(AiExport $export): void
    {
        abort_unless((int) $export->user_id === (int) Auth::id(), 403);
    }

    private function serializeExport(AiExport $export): array
    {
        $symbols = is_array($export->symbols) ? array_values($export->symbols) : [];
        $indicators = is_array($export->indicators) ? array_values($export->indicators) : [];
        $options = is_array($export->options) ? $export->options : [];

        return [
            'id' => $export->id,
            'status' => $export->status,
            'symbols' => $symbols,
            'symbol_count' => count($symbols),
            'indicators' => $indicators,
            'indicator_count' => count($indicators),
            'options' => $options,
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
