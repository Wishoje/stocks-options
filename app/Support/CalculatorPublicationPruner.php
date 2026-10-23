<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CalculatorPublicationPruner
{
    private const TERMINAL = ['complete', 'superseded', 'failed', 'capped'];

    /** One bounded metadata inventory; contract payloads are not loaded here. */
    public function candidates(CarbonInterface $cutoff): array
    {
        $heads = $this->protectedIds();
        $runIds = DB::table('calculator_publication_runs')->whereIn('status', self::TERMINAL)
            ->where('completed_at', '<', $cutoff)->pluck('id')->reject(fn ($id) => isset($heads['runs'][$id]))->all();
        $crossReferences = [];
        foreach (DB::table('calculator_run_expirations')->whereNotNull('publication_id')->get(['run_id', 'publication_id']) as $row) {
            $crossReferences[$row->publication_id][] = $row->run_id;
        }
        $rows = DB::table('calculator_expiry_publications')->whereIn('run_id', $runIds)
            ->where('created_at', '<', $cutoff)->orderBy('id')->limit(50001)->get(['id', 'run_id', 'row_count']);
        if ($rows->count() > 50000) {
            throw new RuntimeException('Calculator pruning metadata limit exceeded; operator review required.');
        }

        return $rows->reject(fn ($row) => isset($heads['publications'][$row->id])
            || array_filter($crossReferences[$row->id] ?? [], fn ($run) => $run !== $row->run_id))
            ->map(fn ($row) => (array) $row)->values()->all();
    }

    /** Recheck references under publication/rollback locks and archive before deletion. */
    public function prune(array $candidates, CarbonInterface $cutoff): array
    {
        $result = DB::transaction(function () use ($candidates, $cutoff): array {
            $runIds = array_values(array_unique(array_column($candidates, 'run_id')));
            $runs = DB::table('calculator_publication_runs')->whereIn('id', $runIds)->orderBy('id')
                ->whereIn('status', self::TERMINAL)->where('completed_at', '<', $cutoff)->lockForUpdate()->get();
            $publications = DB::table('calculator_expiry_publications')->whereIn('id', array_column($candidates, 'id'))
                ->whereIn('run_id', $runs->pluck('id')->all())->where('created_at', '<', $cutoff)
                ->orderBy('id')->lockForUpdate()->get();
            $symbols = $runs->pluck('symbol')->unique()->sort()->values()->all();
            $catalogs = DB::table('calculator_catalog_heads')->whereIn('symbol', $symbols)->orderBy('symbol')->lockForUpdate()->get();
            $expiryHeads = DB::table('calculator_expiry_heads')->whereIn('symbol', $symbols)->orderBy('id')->lockForUpdate()->get();
            $protected = $this->protectedIds($catalogs, $expiryHeads);
            $references = DB::table('calculator_run_expirations')->whereIn('publication_id', $publications->pluck('id')->all())
                ->orderBy('id')->lockForUpdate()->get();
            $runSymbols = $runs->pluck('symbol', 'id');
            $publications = $publications->reject(fn ($p) => $p->symbol !== $runSymbols[$p->run_id]
                || isset($protected['runs'][$p->run_id])
                || isset($protected['publications'][$p->id])
                || $references->contains(fn ($r) => $r->publication_id === $p->id && $r->run_id !== $p->run_id))
                ->map(fn ($p) => (array) $p)->values()->all();
            if ($publications === []) {
                return ['publications' => 0, 'rows' => 0, 'archive' => null];
            }
            $ids = array_column($publications, 'id');
            $archive = app(CalculatorPruneArchive::class)->write($publications, $runs->all(),
                $references->filter(fn ($r) => in_array($r->publication_id, $ids, true))->all());
            $deleted = DB::table('calculator_expiry_publication_rows')->whereIn('publication_id', $ids)->delete();
            if ($deleted !== $archive['rows']) {
                throw new RuntimeException('Archived and deleted calculator row counts differ; rolling back.');
            }
            DB::table('calculator_run_expirations')->whereIn('publication_id', $ids)->update([
                'publication_id' => null, 'readiness' => 'pruned', 'failure_code' => 'retention_expired',
                'failure_reason' => 'Archived after retention: '.basename($archive['path']),
            ]);
            DB::table('calculator_expiry_publications')->whereIn('id', $ids)->delete();

            return ['publications' => count($ids), 'rows' => $deleted, 'archive' => $archive];
        });
        if ($result['archive']) {
            app(CalculatorPruneArchive::class)->markCommitted($result['archive']);
        }

        return $result;
    }

    private function protectedIds($catalogs = null, $heads = null): array
    {
        $result = ['runs' => [], 'publications' => []];
        foreach ($catalogs ?? DB::table('calculator_catalog_heads')->get(['current_run_id', 'previous_run_id']) as $head) {
            foreach ([$head->current_run_id, $head->previous_run_id] as $id) {
                if ($id) {
                    $result['runs'][$id] = true;
                }
            }
        }
        foreach ($heads ?? DB::table('calculator_expiry_heads')->get(['current_publication_id', 'previous_publication_id']) as $head) {
            foreach ([$head->current_publication_id, $head->previous_publication_id] as $id) {
                if ($id) {
                    $result['publications'][$id] = true;
                }
            }
        }

        return $result;
    }
}
