<?php

namespace App\Http\Controllers;

use App\Models\WorkRun;
use App\Support\CalculatorPublicationRepository;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\WorkRunCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkRunController extends Controller
{
    public function show(
        Request $request,
        string $runId,
        WorkRunCoordinator $runs,
        CalculatorPublicationRepository $publications,
        SymbolBootstrapCoordinator $bootstrap
    ): JsonResponse {
        $workRun = $request->attributes->get('workRun');
        if (! $workRun instanceof WorkRun || $workRun->id !== $runId) {
            $workRun = WorkRun::query()->findOrFail($runId);
        }
        $payload = $runs->payload($workRun);
        if ($workRun->kind === 'calculator_refresh') {
            $payload['calculator'] = $this->calculatorManifest(
                $publications->runForWorkRun($workRun->id)
            );
        }
        if ($workRun->kind === 'symbol_bootstrap') {
            $bootstrapPayload = $bootstrap->payload($workRun);
            if ($bootstrapPayload !== null) {
                $payload['bootstrap'] = $bootstrapPayload;
            }
        }
        $response = response()->json($payload);

        if (! $payload['terminal']) {
            $response->headers->set('Retry-After', (string) $payload['retry_after_seconds']);
        }

        return $response;
    }

    /**
     * Keep the polling response small and omit internal ownership/fencing keys.
     *
     * @param  array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null  $manifest
     * @return array<string,mixed>|null
     */
    private function calculatorManifest(?array $manifest): ?array
    {
        if ($manifest === null) {
            return null;
        }

        $run = $manifest['run'];

        return [
            'run_id' => $run['id'],
            'generation' => (int) $run['generation'],
            'scope' => $run['scope'],
            'status' => $run['status'],
            'discovery_terminal' => (bool) $run['discovery_terminal'],
            'discovery_capped' => (bool) $run['discovery_capped'],
            'expected_count' => (int) $run['expected_count'],
            'completed_count' => (int) $run['completed_count'],
            'failed_count' => (int) $run['failed_count'],
            'failure_code' => $run['failure_code'],
            'failure_reason' => $run['failure_reason'],
            'started_at' => $run['started_at'],
            'heartbeat_at' => $run['heartbeat_at'],
            'completed_at' => $run['completed_at'],
            'expirations' => collect($manifest['expirations'])->map(static fn (array $expiry): array => [
                'expiration' => $expiry['expiration'],
                'readiness' => $expiry['readiness'],
                'publication_id' => $expiry['publication_id'],
                'source_asof' => $expiry['source_asof'],
                'failure_code' => $expiry['failure_code'],
                'failure_reason' => $expiry['failure_reason'],
                'ready_at' => $expiry['ready_at'],
                'failed_at' => $expiry['failed_at'],
            ])->values()->all(),
        ];
    }
}
