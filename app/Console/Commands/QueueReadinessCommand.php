<?php

namespace App\Console\Commands;

use App\Support\QueueReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueReadinessCommand extends Command
{
    protected $signature = 'queue:readiness
                            {--json : Emit safe machine-readable evidence only}
                            {--log : Record this safe snapshot in the queue_monitor log}';

    protected $description = 'Read Redis durability, queue leases, monitor coverage and legacy backlog without changing state';

    public function handle(QueueReadiness $readiness): int
    {
        $report = $readiness->inspect();
        if ($this->option('log')) {
            try {
                Log::channel('queue_monitor')->log(
                    $report['checks_passed'] ? 'info' : 'warning',
                    'queue.readiness',
                    $report,
                );
            } catch (Throwable) {
                $report['errors'][] = 'queue_readiness_log_write_failed';
                $report['checks_passed'] = false;
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($report['checks_passed'] ? 'Application/Redis checks passed.' : 'Application/Redis checks failed.');
            $this->table(['Queue', 'Ready', 'Reserved', 'Delayed', 'Lease (s)', 'Job ceiling (s)'], array_map(
                static fn (array $row): array => [
                    $row['target'], $row['ready'] ?? '?', $row['reserved'] ?? '?', $row['delayed'] ?? '?',
                    $row['retry_after_seconds'], $row['job_timeout_ceiling_seconds'] ?? '?',
                ],
                $report['queues'],
            ));
            foreach ($report['errors'] as $error) {
                $this->error($error);
            }
            foreach ($report['warnings'] as $warning) {
                $this->warn($warning);
            }
            $this->line('Activation still requires external Supervisor, scheduler, durable-intent, recovery and market-hours verification.');
        }

        return $report['checks_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
